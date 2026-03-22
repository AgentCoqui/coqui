<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\TodoStore;

/**
 * Automatically generates todos from a finalized plan artifact.
 *
 * Uses the utility model to extract actionable implementation steps from
 * a plan's content and creates linked todos via TodoStore::bulkCreate().
 * Follows the TitleGenerator pattern: single-shot LLM call, best-effort,
 * never propagates errors.
 */
final class PlanTodoGenerator
{
    private const string ROLE = 'plan-todo-generator';

    private const string FALLBACK_INSTRUCTIONS = <<<'PROMPT'
        You are a plan-to-task extraction assistant. Given a plan document, extract the concrete
        implementation steps as a JSON array. Each step becomes a todo item.

        Rules:
        - Extract ONLY actionable implementation steps (not background context or decisions).
        - Each step should be a clear, self-contained task a developer can start working on.
        - Keep titles concise: 5-15 words, action-oriented (e.g. "Create TodoStore class with CRUD methods").
        - Assign priority: "high" for blockers/foundations, "medium" for standard work, "low" for polish/optional.
        - Include brief notes when the step references specific files, classes, or patterns.
        - Preserve the plan's ordering — steps should be returned in implementation order.
        - Return 1-25 steps. If the plan has more, consolidate minor steps.

        Return ONLY a valid JSON array, no markdown fences, no explanation:
        [{"title": "...", "priority": "medium", "notes": "..."}, ...]
        PROMPT;

    public function __construct(
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly TodoStore $todoStore,
        private readonly ?RoleDiscovery $roleDiscovery = null,
        private readonly ?ProviderFactory $providerFactory = null,
    ) {}

    /**
     * Generate todos from a plan artifact's content.
     *
     * Returns the created todo IDs, or an empty array if generation fails.
     * This is intentionally best-effort and must never block the stage transition.
     *
     * @return list<string> Created todo IDs
     */
    public function generate(string $artifactId, string $sessionId, string $planContent): array
    {
        try {
            $provider = $this->resolveProvider();

            $response = $provider->chat([
                new SystemMessage($this->resolveInstructions()),
                new UserMessage($planContent),
            ]);

            $content = trim($response->content);

            // Strip markdown code fences if present
            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```(?:json)?\s*/', '', $content) ?? $content;
                $content = preg_replace('/\s*```$/', '', $content) ?? $content;
            }

            $items = json_decode($content, true);
            if (!is_array($items) || $items === []) {
                error_log('[Coqui] PlanTodoGenerator: LLM returned invalid JSON or empty array');

                return [];
            }

            // Cap at 25 items
            $items = array_slice($items, 0, 25);

            // Validate and normalize each item
            $validPriorities = ['high', 'medium', 'low'];
            $normalized = [];
            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['title']) || trim((string) $item['title']) === '') {
                    continue;
                }

                $title = trim((string) $item['title']);
                if (mb_strlen($title) > 200) {
                    $title = mb_substr($title, 0, 197) . '...';
                }

                $priority = isset($item['priority']) && in_array($item['priority'], $validPriorities, true)
                    ? $item['priority']
                    : 'medium';

                $todoItem = [
                    'title' => $title,
                    'priority' => $priority,
                ];

                if (isset($item['notes']) && trim((string) $item['notes']) !== '') {
                    $todoItem['notes'] = trim((string) $item['notes']);
                }

                $normalized[] = $todoItem;
            }

            if ($normalized === []) {
                error_log('[Coqui] PlanTodoGenerator: no valid items after normalization');

                return [];
            }

            return $this->todoStore->bulkCreate(
                sessionId: $sessionId,
                items: $normalized,
                createdBy: 'plan',
                artifactId: $artifactId,
            );
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[Coqui] PlanTodoGenerator failed: %s in %s:%d',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));

            return [];
        }
    }

    private function resolveProvider(): \CarmeloSantana\PHPAgents\Contract\ProviderInterface
    {
        $modelString = $this->roleResolver->resolveUtility();

        $factory = $this->providerFactory ?? new ProviderFactory($this->config);

        return $factory->create($modelString);
    }

    private function resolveInstructions(): string
    {
        if ($this->roleDiscovery !== null) {
            try {
                return $this->roleDiscovery->readInstructions(self::ROLE);
            } catch (\Throwable) {
                // Fall through to hardcoded fallback
            }
        }

        return self::FALLBACK_INSTRUCTIONS;
    }
}
