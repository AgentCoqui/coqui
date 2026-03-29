<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Storage\SessionStorage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /summarize command.
 */
final class ConversationHandler
{
    public function __construct(
        private readonly BootManager $boot,
        private readonly SessionStorage $storage,
    ) {}

    public function handleSummarize(SymfonyStyle $io, string $arg, string $sessionId): true
    {
        $config = $this->boot->config();
        $configKeepRecent = $config->get('agents.defaults.context.keepRecentTurns');
        $keepRecent = is_numeric($configKeepRecent) ? (int) $configKeepRecent : CoquiDefaults::KEEP_RECENT_TURNS;
        $focus = null;

        $arg = trim($arg);
        if ($arg !== '') {
            if (preg_match('/^recent\s+(\d+)/i', $arg, $matches)) {
                $keepRecent = max(1, min(20, (int) $matches[1]));
                $arg = trim(substr($arg, strlen($matches[0])));
            }
            if (preg_match('/focus\s+"([^"]+)"/i', $arg, $matches)) {
                $focus = $matches[1];
            } elseif (preg_match('/focus\s+(\S+)/i', $arg, $matches)) {
                $focus = $matches[1];
            }
        }

        $summarizer = new ConversationSummarizer(
            storage: $this->storage,
            memoryStore: $this->boot->memoryStore(),
        );

        $factory = new ProviderFactory($config);
        $provider = null;

        try {
            $utilityModel = $this->boot->roleResolver()->resolveUtility();
            if ($utilityModel !== '') {
                $provider = $factory->create($utilityModel);
            }
        } catch (\Throwable) {
            // Fall through
        }

        if ($provider === null) {
            try {
                $orchestratorModel = $this->boot->roleResolver()->resolve('orchestrator');
                $provider = $factory->create($orchestratorModel);
            } catch (\Throwable) {
                $io->error('Could not resolve a provider for summarization.');
                return true;
            }
        }

        $io->text('<fg=gray>Summarizing conversation...</>');

        try {
            $workflowContext = $this->buildWorkflowContext($sessionId);
            $memoriesExtracted = 0;
            $result = $summarizer->summarizeAndPersist(
                sessionId: $sessionId,
                provider: $provider,
                keepRecentTurns: $keepRecent,
                focus: $focus,
                workflowContext: $workflowContext,
                onExtraction: function (int $saved) use (&$memoriesExtracted): void {
                    $memoriesExtracted = $saved;
                },
            );
        } catch (\Throwable $e) {
            $io->error('Summarization failed: ' . $e->getMessage());
            return true;
        }

        if (!$result->wasSummarized()) {
            $io->info('Conversation is too short to summarize.');
            return true;
        }

        if ($memoriesExtracted > 0) {
            $io->text(sprintf(
                '<fg=yellow>🧠 Memory extraction (summarization): %d %s saved</>',
                $memoriesExtracted,
                $memoriesExtracted === 1 ? 'memory' : 'memories',
            ));
        }

        $io->success(sprintf(
            'Summarized %d messages — %s → %s tokens (saved %s)',
            $result->messagesSummarized,
            number_format($result->tokensBefore),
            number_format($result->tokensAfter),
            number_format($result->tokensSaved()),
        ));

        return true;
    }

    public function buildWorkflowContext(string $sessionId): ?string
    {
        $sections = [];

        try {
            $todoStore = $this->boot->todoStore();
            if ($todoStore !== null) {
                $stats = $todoStore->getStats($sessionId);
                $total = $stats['total'];

                if ($total > 0) {
                    $lines = ["Todos: {$stats['completed']}/{$total} completed"];

                    foreach ($todoStore->list($sessionId, 'in_progress') as $todo) {
                        $lines[] = "  - [in_progress] {$todo['title']}";
                    }

                    $pending = $todoStore->list($sessionId, 'pending');
                    foreach (array_slice($pending, 0, 5) as $todo) {
                        $lines[] = "  - [pending] {$todo['title']}";
                    }
                    if (count($pending) > 5) {
                        $lines[] = '  - ... and ' . (count($pending) - 5) . ' more pending';
                    }

                    $sections[] = implode("\n", $lines);
                }
            }
        } catch (\Throwable) {
            // Non-critical
        }

        try {
            $artifactStore = $this->boot->artifactStore();
            if ($artifactStore !== null) {
                $artifacts = $artifactStore->list($sessionId);
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
            }
        } catch (\Throwable) {
            // Non-critical
        }

        return $sections !== [] ? implode("\n", $sections) : null;
    }
}
