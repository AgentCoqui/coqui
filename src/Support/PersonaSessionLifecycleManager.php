<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Memory\MemoryExtractor;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Config\RoleResolver;

/**
 * Finalizes personaScoped sessions by preserving continuity before closure.
 */
final readonly class PersonaSessionLifecycleManager
{
    public function __construct(
        private SessionStorage $storage,
        private ProviderFactory $providerFactory,
        private RoleResolver $roleResolver,
        private ?MemoryStore $memoryStore = null,
        private ?ArtifactStore $artifactStore = null,
    ) {}

    /**
     * @return list<string>
     */
    public function finalizeOtherActiveInteractiveSessionsForPersona(string $persona, string $keepSessionId, string $reason): array
    {
        $sessions = $this->storage->listActiveInteractiveSessionsForPersona($persona);
        $finalized = [];

        foreach ($sessions as $session) {
            $sessionId = (string) ($session['id'] ?? '');
            if ($sessionId === '' || $sessionId === $keepSessionId) {
                continue;
            }

            $this->finalizeSession($sessionId, $reason);
            $finalized[] = $sessionId;
        }

        return $finalized;
    }

    public function finalizeSession(string $sessionId, string $reason): void
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null || (int) ($session['is_closed'] ?? 0) === 1) {
            return;
        }

        $personaId = $this->normalizePersona($session['persona_id'] ?? null);
        $provider = $this->resolveProvider();

        if ($provider !== null && $this->memoryStore !== null) {
            $conversation = $this->storage->loadConversation($sessionId);

            try {
                $summarizer = new ConversationSummarizer(
                    storage: $this->storage,
                    memoryStore: $this->memoryStore,
                );

                $summarizer->summarizeAndPersist(
                    sessionId: $sessionId,
                    provider: $provider,
                    keepRecentTurns: CoquiDefaults::KEEP_RECENT_TURNS,
                    workflowContext: $this->buildWorkflowContext($sessionId),
                    personaId: $personaId,
                );
            } catch (\Throwable) {
                // Best-effort continuity preservation should never block closure.
            }

            try {
                if ($conversation->messages() !== []) {
                    $extractor = new MemoryExtractor($this->memoryStore);
                    $extractor->extractFromConversation(
                        conversation: $conversation,
                        provider: $provider,
                        recentTurns: 8,
                        bypassCooldown: true,
                        personaId: $personaId,
                    );
                }
            } catch (\Throwable) {
                // Best-effort continuity preservation should never block closure.
            }
        }

        $this->storage->closeSession($sessionId, $reason, true);
    }

    private function resolveProvider(): ?ProviderInterface
    {
        try {
            $utilityModel = $this->roleResolver->resolveUtility();
            if ($utilityModel !== '') {
                return $this->providerFactory->create($utilityModel);
            }
        } catch (\Throwable) {
            // Fall through.
        }

        try {
            $orchestratorModel = $this->roleResolver->resolve('orchestrator');
            if ($orchestratorModel !== '') {
                return $this->providerFactory->create($orchestratorModel);
            }
        } catch (\Throwable) {
            // Fall through.
        }

        return null;
    }

    private function buildWorkflowContext(string $sessionId): ?string
    {
        $sections = [];

        try {
            if ($this->artifactStore !== null) {
                $artifacts = $this->artifactStore->list($sessionId);
                if ($artifacts !== []) {
                    $lines = ['Artifacts:'];
                    foreach (array_slice($artifacts, 0, 5) as $artifact) {
                        $type = (string) ($artifact['type'] ?? 'unknown');
                        $stage = (string) ($artifact['stage'] ?? 'draft');
                        $title = (string) ($artifact['title'] ?? 'Untitled');
                        $lines[] = "  - [{$type}/{$stage}] {$title}";
                    }

                    if (count($artifacts) > 5) {
                        $lines[] = '  - ... and ' . (count($artifacts) - 5) . ' more';
                    }

                    $sections[] = implode("\n", $lines);
                }
            }
        } catch (\Throwable) {
            // Non-critical context.
        }

        return $sections !== [] ? implode("\n", $sections) : null;
    }

    private function normalizePersona(mixed $persona): ?string
    {
        return is_string($persona) && $persona !== '' ? $persona : null;
    }
}