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

        // Save user message to database before running agent
        $this->storage->addMessage($sessionId, 'user', $prompt);

        // Build execution policy based on flags
        $executionPolicy = $this->buildExecutionPolicy($sessionId, $io);

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
                $this->persistTurnMessages($output->conversation, $history->count(), $sessionId);
            }

            // Save final assistant response if not already persisted
            if ($output->conversation === null) {
                $this->storage->addMessage($sessionId, 'assistant', $output->content);
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
        } catch (\Throwable $e) {
            $io->error("Agent error: {$e->getMessage()}");
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
        );
    }

    private function buildExecutionPolicy(
        string $sessionId,
        SymfonyStyle $io,
    ): ToolExecutionPolicyInterface {
        if ($this->autoApprove) {
            return new AutoApprovalPolicy(
                blacklist: $this->blacklist,
                storage: $this->storage,
                sessionId: $sessionId,
            );
        }

        return new InteractiveApprovalPolicy(
            io: $io,
            gatedTools: self::GATED_TOOLS,
            blacklist: $this->blacklist,
            storage: $this->storage,
            sessionId: $sessionId,
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
     */
    private function persistTurnMessages(
        Conversation $conversation,
        int $historyCount,
        string $sessionId,
    ): void {
        $messages = $conversation->messages();

        // Offset = 1 (system prompt) + historyCount + 1 (user message from this turn)
        $newMessageStart = 1 + $historyCount + 1;

        for ($i = $newMessageStart; $i < count($messages); $i++) {
            $msg = $messages[$i];
            $role = $msg->role();

            match ($role) {
                Role::Assistant => $this->storage->addMessage(
                    $sessionId,
                    'assistant',
                    is_string($msg->content()) ? $msg->content() : (json_encode($msg->content()) ?: ''),
                    !empty($msg->toolCalls()) ? (json_encode(
                        array_map(fn(ToolCall $tc) => [
                            'id' => $tc->id,
                            'name' => $tc->name,
                            'arguments' => $tc->arguments,
                        ], $msg->toolCalls()),
                        JSON_UNESCAPED_SLASHES,
                    ) ?: null) : null,
                ),

                Role::Tool => $this->storage->addMessage(
                    $sessionId,
                    'tool',
                    is_string($msg->content()) ? $msg->content() : (json_encode($msg->content()) ?: ''),
                    null,
                    $msg->toolCallId(),
                ),

                // User and System messages mid-turn are unexpected but harmless — skip
                default => null,
            };
        }
    }
}
