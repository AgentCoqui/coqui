<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Contract\SystemRole;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\TodoStore;

/**
 * Agent-facing tool that summarizes conversation history to reduce token usage.
 *
 * The agent can call this tool proactively when it detects the conversation is
 * getting long, or the system can auto-trigger it when approaching context limits.
 * Summaries are stored as session-scoped memories and injected as system messages.
 */
final class SummarizeConversationTool implements ToolInterface
{
    public function __construct(
        private readonly ConversationSummarizer $summarizer,
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly string $sessionId,
        private readonly ?TodoStore $todoStore = null,
        private readonly ?ArtifactStore $artifactStore = null,
        private readonly ?ProviderFactory $providerFactory = null,
    ) {}

    public function name(): string
    {
        return 'summarize_conversation';
    }

    public function description(): string
    {
        return <<<'DESC'
            Summarize the conversation history to free up context window space.

            Use this when:
            - The conversation is getting long and you need more room for tool results
            - You want to preserve key context before older messages get pruned
            - The user asks you to summarize the conversation

            The summary replaces older messages in the current session with a compact
            overview while keeping recent turns intact. This preserves important context
            (decisions, file changes, preferences, continuity anchors, developmental context)
            while significantly reducing token usage.

            After summarization, older messages are condensed into a summary. The most
            recent turns remain in full detail for continuity.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'scope',
                description: 'What to summarize: "all" keeps only the last few turns, "recent" summarizes all but the most recent turns.',
                values: ['all', 'recent'],
                required: false,
            ),
            new NumberParameter(
                name: 'keep_recent',
                description: 'Number of recent user turns to keep unsummarized (default: 3). Higher values preserve more recent context.',
                required: false,
            ),
            new StringParameter(
                name: 'focus',
                description: 'Optional topic to emphasize in the summary (e.g. "file modifications", "user preferences", "error debugging", "identity continuity", "phenomenological observations").',
                required: false,
            ),
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $scope = (string) ($arguments['scope'] ?? 'recent');

        // Read configurable keepRecentTurns from config
        $configKeepRecent = $this->config->get('agents.defaults.context.keepRecentTurns');
        $defaultKeepRecent = is_numeric($configKeepRecent) ? (int) $configKeepRecent : CoquiDefaults::KEEP_RECENT_TURNS;
        $keepRecent = (int) ($arguments['keep_recent'] ?? ($scope === 'all' ? max(2, $defaultKeepRecent - 1) : $defaultKeepRecent));
        $focus = isset($arguments['focus']) ? (string) $arguments['focus'] : null;

        // Clamp keep_recent to reasonable bounds
        $keepRecent = max(1, min(20, $keepRecent));

        // Resolve a cheap provider for summarization (use title-generator model)
        $provider = $this->resolveSummarizationProvider();
        if ($provider === null) {
            return ToolResult::error('No provider available for summarization. Cannot generate summary.');
        }

        $memoriesExtracted = 0;
        $result = $this->summarizer->summarizeAndPersist(
            sessionId: $this->sessionId,
            provider: $provider,
            keepRecentTurns: $keepRecent,
            focus: $focus,
            workflowContext: $this->buildWorkflowContext(),
            onExtraction: function (int $saved) use (&$memoriesExtracted): void {
                $memoriesExtracted = $saved;
            },
        );

        if (!$result->wasSummarized()) {
            return ToolResult::success('Conversation is too short to summarize. No changes made.');
        }

        $saved = $result->tokensSaved();
        $extractionNote = $memoriesExtracted > 0
            ? "- Memories extracted: {$memoriesExtracted}\n"
            : '';

        return ToolResult::success(
            "Conversation summarized successfully.\n"
            . "- Messages condensed: {$result->messagesSummarized}\n"
            . "- Tokens before: {$result->tokensBefore}\n"
            . "- Tokens after: {$result->tokensAfter}\n"
            . "- Tokens saved: {$saved}\n"
            . $extractionNote . "\n"
            . "Summary:\n{$result->summary}",
        );
    }

    public function toFunctionSchema(): array
    {
        $schema = [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => trim($this->description()),
                'parameters' => [
                    'type' => 'object',
                    'properties' => new \stdClass(),
                    'required' => [],
                ],
            ],
        ];

        $properties = [];
        $required = [];

        foreach ($this->parameters() as $parameter) {
            $properties[$parameter->name] = $parameter->toSchema();
            if ($parameter->required) {
                $required[] = $parameter->name;
            }
        }

        $schema['function']['parameters']['properties'] = empty($properties)
            ? new \stdClass()
            : $properties;
        $schema['function']['parameters']['required'] = $required;

        return $schema;
    }

    /**
     * Build a workflow context string from active todos and artifacts.
     */
    private function buildWorkflowContext(): ?string
    {
        $sections = [];

        if ($this->todoStore !== null) {
            try {
                $stats = $this->todoStore->getStats($this->sessionId);
                $total = $stats['total'];

                if ($total > 0) {
                    $lines = ["Todos: {$stats['completed']}/{$total} completed"];

                    $activeTodos = $this->todoStore->list($this->sessionId, 'in_progress');
                    foreach ($activeTodos as $todo) {
                        $lines[] = "  - [in_progress] {$todo['title']}";
                    }

                    $pendingTodos = $this->todoStore->list($this->sessionId, 'pending');
                    foreach (array_slice($pendingTodos, 0, 5) as $todo) {
                        $lines[] = "  - [pending] {$todo['title']}";
                    }
                    if (count($pendingTodos) > 5) {
                        $lines[] = '  - ... and ' . (count($pendingTodos) - 5) . ' more pending';
                    }

                    $sections[] = implode("\n", $lines);
                }
            } catch (\Throwable) {
                // Non-critical
            }
        }

        if ($this->artifactStore !== null) {
            try {
                $artifacts = $this->artifactStore->list($this->sessionId);
                if ($artifacts !== []) {
                    $lines = ['Artifacts:'];
                    foreach (array_slice($artifacts, 0, 5) as $artifact) {
                        $type = $artifact['type'] ?? 'unknown';
                        $stage = $artifact['stage'] ?? 'draft';
                        $title = $artifact['title'] ?? 'Untitled';
                        $lines[] = "  - [{$type}/{$stage}] {$title}";
                    }
                    if (count($artifacts) > 5) {
                        $lines[] = '  - ... and ' . (count($artifacts) - 5) . ' more';
                    }
                    $sections[] = implode("\n", $lines);
                }
            } catch (\Throwable) {
                // Non-critical
            }
        }

        return $sections !== [] ? implode("\n", $sections) : null;
    }

    /**
     * Resolve a cheap LLM provider for summarization.
     *
     * Uses the utility model resolution chain:
     * agents.defaults.model.utility → title-generator role → primary model.
     * Falls back to the orchestrator model on error.
     */
    private function resolveSummarizationProvider(): ?ProviderInterface
    {
        try {
            $factory = $this->providerFactory ?? new ProviderFactory($this->config);

            $utilityModel = $this->roleResolver->resolveUtility();
            if ($utilityModel !== '') {
                return $factory->create($utilityModel);
            }

            // Fall back to orchestrator model
            $orchestratorModel = $this->roleResolver->resolve(SystemRole::Orchestrator->value);
            return $factory->create($orchestratorModel);
        } catch (\Throwable) {
            return null;
        }
    }
}
