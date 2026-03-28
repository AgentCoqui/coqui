<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Memory\MemoryExtractor;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Agent-facing tool for explicit memory extraction from conversation history.
 *
 * Analyzes recent conversation turns and extracts noteworthy facts,
 * preferences, solutions, and context into persistent memory entries.
 * Always bypasses the extraction cooldown since it is user/agent-initiated.
 */
final class ExtractMemoriesTool implements ToolInterface
{
    public function __construct(
        private readonly MemoryStore $memoryStore,
        private readonly SessionStorage $storage,
        private readonly string $sessionId,
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
    ) {}

    public function name(): string
    {
        return 'extract_memories';
    }

    public function description(): string
    {
        return <<<'DESC'
            Explicitly extract and save noteworthy memories from recent conversation history.

            Use this when:
            - The user shares important preferences, facts, or context worth remembering
            - A significant solution or debugging insight was discovered
            - You want to ensure key information is persisted before the conversation ends
            - The user asks you to remember something from the conversation

            This analyzes recent turns and saves structured memory entries (facts,
            preferences, solutions, context) after deduplication against existing memories.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new NumberParameter(
                name: 'recent_turns',
                description: 'Number of recent conversation turns to analyze (default: 5, max: 20).',
                required: false,
            ),
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $recentTurns = (int) ($arguments['recent_turns'] ?? 5);
        $recentTurns = max(1, min(20, $recentTurns));

        $provider = $this->resolveProvider();
        if ($provider === null) {
            return ToolResult::error('No provider available for memory extraction.');
        }

        $conversation = $this->storage->loadConversation($this->sessionId);
        if ($conversation === null || $conversation->count() < 2) {
            return ToolResult::error('Conversation is too short for memory extraction.');
        }

        try {
            $extractor = new MemoryExtractor($this->memoryStore);
            $saved = $extractor->extractFromConversation(
                conversation: $conversation,
                provider: $provider,
                recentTurns: $recentTurns,
                bypassCooldown: true,
            );
        } catch (\Throwable $e) {
            return ToolResult::error('Memory extraction failed: ' . $e->getMessage());
        }

        if ($saved === 0) {
            return ToolResult::success('No new memories found in the recent conversation. Existing memories may already cover this content.');
        }

        $label = $saved === 1 ? 'memory' : 'memories';

        return ToolResult::success(
            "Extracted and saved {$saved} new {$label} from the last {$recentTurns} conversation turns.",
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

        $props = [];
        foreach ($this->parameters() as $param) {
            $props[$param->name] = $param->toSchema();
        }

        if ($props !== []) {
            $schema['function']['parameters']['properties'] = $props;
        }

        return $schema;
    }

    private function resolveProvider(): ?ProviderInterface
    {
        $factory = new ProviderFactory($this->config);

        $utilityModel = $this->roleResolver->resolveUtility();
        if ($utilityModel !== '') {
            try {
                return $factory->create($utilityModel);
            } catch (\Throwable) {
                // Fall through to orchestrator model
            }
        }

        try {
            $orchestratorModel = $this->roleResolver->resolve('orchestrator');
            return $factory->create($orchestratorModel);
        } catch (\Throwable) {
            return null;
        }
    }
}
