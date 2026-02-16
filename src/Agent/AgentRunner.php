<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Config\InteractiveApprovalPolicy;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\ScriptSanitizer;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles agent creation, execution, and turn message persistence.
 *
 * Extracted from RunCommand to isolate agent orchestration from
 * the REPL loop and session management concerns.
 */
final class AgentRunner
{
    /** Tool names that require user confirmation in interactive mode. */
    private const GATED_TOOLS = [
        'composer' => ['require', 'remove', 'update'],
        'exec' => ['*'],
        'php_execute' => ['*'],
        'restart_coqui' => ['*'],
    ];

    public function __construct(
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly SessionStorage $storage,
        private readonly TerminalObserver $observer,
        private readonly ToolkitDiscovery $discovery,
        private readonly CatastrophicBlacklist $blacklist,
        private readonly CredentialResolverInterface $credentialResolver,
        private readonly ?SkillDiscovery $skillDiscovery = null,
        private readonly bool $unsafeMode = false,
        private readonly bool $autoApprove = false,
        private readonly ?ProviderFactory $providerFactory = null,
    ) {}

    /**
     * Run a single agent turn: create agent, execute, persist messages, display output.
     *
     * @return bool True if a restart was requested by the agent.
     */
    public function run(string $prompt, string $sessionId, SymfonyStyle $io): bool
    {
        // Load prior conversation history from database
        $history = $this->storage->loadConversation($sessionId);

        // Resolve the orchestrator model string for turn tracking
        $modelString = $this->roleResolver->resolve('orchestrator');

        // Create turn record before execution
        $turnId = $this->storage->createTurn($sessionId, $prompt, $modelString);
        $startTime = hrtime(true);

        // Save user message to database before running agent
        $this->storage->addMessage($sessionId, 'user', $prompt, turnId: $turnId);

        // Build execution policy based on flags
        $executionPolicy = $this->buildExecutionPolicy($sessionId, $io, $turnId);

        // Build sanitizer for PHP execution
        $sanitizer = new ScriptSanitizer(
            unsafe: $this->unsafeMode,
            blacklist: $this->blacklist,
        );

        // Track restart request via closure
        $restartRequested = false;

        $agent = $this->createAgent(
            sessionId: $sessionId,
            executionPolicy: $executionPolicy,
            sanitizer: $sanitizer,
            onRestart: function () use (&$restartRequested): void {
                $restartRequested = true;
            },
        );

        $agent->attach($this->observer);

        $io->newLine();

        try {
            // Apply context window pruning — conservative 100K token budget (80% of 128K)
            if ($history->count() > 0) {
                $history = $history->fitWithinBudget(100000);
            }

            $output = $agent->run(new UserMessage($prompt), $history);

            // Persist intermediate messages from this turn (tool calls + results)
            if ($output->conversation !== null) {
                $this->persistTurnMessages($output->conversation, $history->count(), $sessionId, $turnId);
            }

            // Save final assistant response if not already persisted
            if ($output->conversation === null) {
                $this->storage->addMessage($sessionId, 'assistant', $output->content, turnId: $turnId);
            }

            // Display response
            $io->newLine();
            $io->writeln('<fg=green>Assistant:</>');
            $io->writeln($output->content);
            $io->newLine();

            // Display stats
            $stats = [];
            $stats[] = "Iterations: {$output->iterations}";
            if ($output->usage !== null) {
                $stats[] = "Tokens: {$output->usage->totalTokens}";
                $this->storage->updateTokenCount($sessionId, $output->usage->totalTokens);
            }
            $io->comment(implode(' | ', $stats));
            $io->newLine();

            // Complete turn with metadata
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $toolsUsed = $this->extractToolsUsed($output->conversation);
            $childAgentCount = $agent->getSpawnTool()->getChildRunCount();

            $this->storage->completeTurn(
                turnId: $turnId,
                responseText: $output->content,
                promptTokens: $output->usage !== null ? $output->usage->promptTokens : 0,
                completionTokens: $output->usage !== null ? $output->usage->completionTokens : 0,
                totalTokens: $output->usage !== null ? $output->usage->totalTokens : 0,
                iterations: $output->iterations,
                durationMs: $durationMs,
                toolsUsed: json_encode($toolsUsed, JSON_UNESCAPED_SLASHES) ?: '[]',
                childAgentCount: $childAgentCount,
            );
        } catch (\Throwable $e) {
            $io->error("Agent error: {$e->getMessage()}");

            // Complete turn even on error so duration/state is tracked
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $this->storage->completeTurn(
                turnId: $turnId,
                responseText: "Error: {$e->getMessage()}",
                promptTokens: 0,
                completionTokens: 0,
                totalTokens: 0,
                iterations: 0,
                durationMs: $durationMs,
                toolsUsed: '[]',
                childAgentCount: 0,
            );
        }

        return $restartRequested;
    }

    private function createAgent(
        string $sessionId,
        ToolExecutionPolicyInterface $executionPolicy,
        ScriptSanitizer $sanitizer,
        \Closure $onRestart,
    ): OrchestratorAgent {
        $modelString = $this->roleResolver->resolve('orchestrator');
        $factory = $this->providerFactory ?? new ProviderFactory($this->config);
        $provider = $factory->create($modelString);

        return new OrchestratorAgent(
            provider: $provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspacePath,
            storage: $this->storage,
            sessionId: $sessionId,
            observer: $this->observer,
            discovery: $this->discovery,
            executionPolicy: $executionPolicy,
            sanitizer: $sanitizer,
            onRestart: $onRestart,
            credentialResolver: $this->credentialResolver,
            skillDiscovery: $this->skillDiscovery,
        );
    }

    private function buildExecutionPolicy(
        string $sessionId,
        SymfonyStyle $io,
        ?string $turnId = null,
    ): ToolExecutionPolicyInterface {
        if ($this->autoApprove) {
            return new AutoApprovalPolicy(
                blacklist: $this->blacklist,
                storage: $this->storage,
                sessionId: $sessionId,
                turnId: $turnId,
            );
        }

        return new InteractiveApprovalPolicy(
            io: $io,
            gatedTools: self::GATED_TOOLS,
            blacklist: $this->blacklist,
            storage: $this->storage,
            sessionId: $sessionId,
            turnId: $turnId,
        );
    }

    /**
     * Persist the new messages generated during a single agent turn.
     *
     * The conversation from AbstractAgent::run() contains:
     * [SystemMessage, ...history..., UserMessage, ...new turn messages...]
     *
     * Skip the system message and history (already persisted), and the
     * new user message (already saved before run()). Persist only the
     * assistant and tool messages from this turn's loop.
     *
     * Content is sanitized for valid UTF-8 before storage to prevent
     * malformed bytes (e.g., from web scraping) from poisoning the
     * conversation on reload.
     */
    private function persistTurnMessages(
        Conversation $conversation,
        int $historyCount,
        string $sessionId,
        ?string $turnId = null,
    ): void {
        $messages = $conversation->messages();

        // Offset = 1 (system prompt) + historyCount + 1 (user message from this turn)
        $newMessageStart = 1 + $historyCount + 1;

        for ($i = $newMessageStart; $i < count($messages); $i++) {
            $msg = $messages[$i];
            $role = $msg->role();

            try {
                match ($role) {
                    Role::Assistant => $this->storage->addMessage(
                        $sessionId,
                        'assistant',
                        $this->sanitizeContent($msg->content()),
                        !empty($msg->toolCalls()) ? json_encode(
                            array_map(fn(ToolCall $tc) => [
                                'id' => $tc->id,
                                'name' => $tc->name,
                                'arguments' => $tc->arguments,
                            ], $msg->toolCalls()),
                            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                        ) : null,
                        turnId: $turnId,
                    ),

                    Role::Tool => $this->storage->addMessage(
                        $sessionId,
                        'tool',
                        $this->sanitizeContent($msg->content()),
                        null,
                        $msg->toolCallId(),
                        turnId: $turnId,
                    ),

                    // User and System messages mid-turn are unexpected but harmless — skip
                    default => null,
                };
            } catch (\Throwable) {
                // Skip messages that fail to serialize — prevents partial persistence
                continue;
            }
        }
    }

    /**
     * Sanitize message content for safe UTF-8 storage.
     */
    private function sanitizeContent(mixed $content): string
    {
        if (!is_string($content)) {
            return json_encode($content, JSON_THROW_ON_ERROR) ?: '';
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            return mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        }

        return $content;
    }

    /**
     * Extract unique tool names from the conversation's tool calls.
     *
     * @return string[]
     */
    private function extractToolsUsed(?Conversation $conversation): array
    {
        if ($conversation === null) {
            return [];
        }

        $tools = [];

        foreach ($conversation->messages() as $msg) {
            if ($msg->role() === Role::Assistant && !empty($msg->toolCalls())) {
                foreach ($msg->toolCalls() as $tc) {
                    $tools[$tc->name] = true;
                }
            }
        }

        return array_keys($tools);
    }
}
