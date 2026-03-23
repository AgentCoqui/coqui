<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Conversation summarization endpoint.
 *
 * POST /api/v1/sessions/{id}/summarize — summarize conversation history
 */
final readonly class SummarizeHandler
{
    public function __construct(
        private SessionStorage $storage,
        private ConfigInterface $config,
        private RoleResolver $roleResolver,
        private ?MemoryStore $memoryStore = null,
        private ?TodoStore $todoStore = null,
        private ?ArtifactStore $artifactStore = null,
    ) {}

    /**
     * POST /api/v1/sessions/{id}/summarize
     *
     * Body (all optional):
     *   { "keep_recent": 3, "focus": "topic" }
     */
    public function summarize(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);
        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, "Session not found: {$id}");
        }

        $body = (array) json_decode((string) $request->getBody(), true);

        // Read configurable keepRecentTurns from config
        $configKeepRecent = $this->config->get('agents.defaults.context.keepRecentTurns');
        $defaultKeepRecent = is_numeric($configKeepRecent) ? (int) $configKeepRecent : CoquiDefaults::KEEP_RECENT_TURNS;
        $keepRecent = max(1, min(20, (int) ($body['keep_recent'] ?? $defaultKeepRecent)));
        $focus = isset($body['focus']) && is_string($body['focus']) ? $body['focus'] : null;

        $summarizer = new ConversationSummarizer(
            storage: $this->storage,
            memoryStore: $this->memoryStore,
        );

        // Resolve a cheap provider for summarization via utility model chain
        $factory = new ProviderFactory($this->config);
        $provider = null;

        try {
            $utilityModel = $this->roleResolver->resolveUtility();
            if ($utilityModel !== '') {
                $provider = $factory->create($utilityModel);
            }
        } catch (\Throwable) {
            // Fall through
        }

        if ($provider === null) {
            try {
                $orchestratorModel = $this->roleResolver->resolve('orchestrator');
                $provider = $factory->create($orchestratorModel);
            } catch (\Throwable) {
                return Router::errorResponse(
                    ApiErrorCode::INTERNAL_ERROR,
                    'Could not resolve a provider for summarization.',
                );
            }
        }

        try {
            $result = $summarizer->summarizeAndPersist(
                sessionId: $id,
                provider: $provider,
                keepRecentTurns: $keepRecent,
                focus: $focus,
                workflowContext: $this->buildWorkflowContext($id),
            );
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Summarization failed: ' . $e->getMessage(),
            );
        }

        if (!$result->wasSummarized()) {
            return Router::jsonResponse([
                'summarized' => false,
                'reason' => 'Conversation too short to summarize.',
            ]);
        }

        return Router::jsonResponse([
            'summarized' => true,
            'messages_summarized' => $result->messagesSummarized,
            'tokens_before' => $result->tokensBefore,
            'tokens_after' => $result->tokensAfter,
            'tokens_saved' => $result->tokensSaved(),
            'summary' => $result->summary,
        ]);
    }

    private function buildWorkflowContext(string $sessionId): ?string
    {
        $sections = [];

        if ($this->todoStore !== null) {
            try {
                $stats = $this->todoStore->getStats($sessionId);
                $total = $stats['total'];

                if ($total > 0) {
                    $lines = ["Todos: {$stats['completed']}/{$total} completed"];

                    foreach ($this->todoStore->list($sessionId, 'in_progress') as $todo) {
                        $lines[] = "  - [in_progress] {$todo['title']}";
                    }

                    $pending = $this->todoStore->list($sessionId, 'pending');
                    foreach (array_slice($pending, 0, 5) as $todo) {
                        $lines[] = "  - [pending] {$todo['title']}";
                    }
                    if (count($pending) > 5) {
                        $lines[] = '  - ... and ' . (count($pending) - 5) . ' more pending';
                    }

                    $sections[] = implode("\n", $lines);
                }
            } catch (\Throwable) {
                // Non-critical
            }
        }

        if ($this->artifactStore !== null) {
            try {
                $artifacts = $this->artifactStore->list($sessionId);
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
}
