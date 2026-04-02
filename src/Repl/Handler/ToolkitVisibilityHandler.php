<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /toolkits [enable|stub|disable <pkg|tool:name>] slash command.
 *
 * Table includes: package name, visibility, loading mode, token estimate,
 * and aggregate tool usage count sourced from ToolUsageTracker.
 */
final class ToolkitVisibilityHandler
{
    public function __construct(
        private readonly BootManager $boot,
        private readonly AgentRunner $agentRunner,
    ) {}

    public function handle(SymfonyStyle $io, string $arg): void
    {
        $registry = $this->boot->visibilityRegistry();
        $discovery = $this->boot->discovery();

        if (trim($arg) === '') {
            $preview = $this->agentRunner->buildPromptPreview();
            $tokensByClass = [];
            foreach ($preview['toolkit_breakdown'] as $entry) {
                $tokensByClass[$entry['class']] = $entry;
            }

            $loadingRegistry = $this->boot->loadingRegistry();
            $usageTracker = $this->boot->usageTracker();

            $rows = [];
            foreach ($discovery->allWithVisibility() as $entry) {
                $pkgTokens = 0;
                foreach ($entry['classes'] as $cls) {
                    if (isset($tokensByClass[$cls])) {
                        $pkgTokens += $tokensByClass[$cls]['total_tokens'];
                    }
                }

                // Resolve loading mode from the first class basename
                $loadingMode = '-';
                if ($loadingRegistry !== null && $entry['classes'] !== []) {
                    $parts = explode('\\', $entry['classes'][0]);
                    $basename = end($parts);
                    $loadingMode = $loadingRegistry->getMode($basename);
                }

                $rows[] = [
                    $entry['package'],
                    $entry['visibility'],
                    $loadingMode,
                    number_format($pkgTokens),
                ];
            }

            $state = $registry->all();
            foreach ($state['tools'] as $toolName => $vis) {
                $rows[] = ['tool:' . $toolName, $vis, '-', '-'];
            }

            if (empty($rows)) {
                $io->text('No toolkits registered. Install a toolkit package first.');
            } else {
                $io->table(['Package / Tool', 'Visibility', 'Loading', 'Tokens'], $rows);

                // Show deferred toolkit summary if any are deferred
                $deferredCount = 0;
                foreach ($rows as $row) {
                    if ($row[2] === 'deferred') {
                        $deferredCount++;
                    }
                }

                $summaryParts = [
                    '<fg=gray>Prompt tokens:</> ' . number_format($preview['prompt_tokens']),
                    '<fg=gray>Tool schema tokens:</> ' . number_format($preview['tool_tokens']),
                    '<fg=gray>Total:</> ' . number_format($preview['total_tokens']),
                ];

                if ($deferredCount > 0) {
                    $summaryParts[] = '<fg=yellow>Deferred:</> ' . $deferredCount;
                }

                $io->text([
                    implode(' • ', $summaryParts),
                    '<fg=gray>Use /toolkits enable|stub|disable <pkg> or tool:<name></>',
                ]);
            }

            return;
        }

        $parts = explode(' ', trim($arg), 2);
        $action = strtolower($parts[0]);
        $target = $parts[1] ?? '';

        if (!in_array($action, ['enable', 'stub', 'disable'], strict: true)) {
            $io->error("Unknown action: {$action}. Use enable, stub, or disable.");
            return;
        }

        if ($target === '') {
            $io->error("Usage: /toolkits {$action} <package/name|tool:<name>>");
            return;
        }

        $visibility = match ($action) {
            'enable'  => ToolkitVisibility::Enabled,
            'stub'    => ToolkitVisibility::Stub,
            'disable' => ToolkitVisibility::Disabled,
        };

        try {
            if (str_starts_with($target, 'tool:')) {
                $toolName = substr($target, 5);
                $registry->setToolVisibility($toolName, $visibility);
                $io->success("Tool \"{$toolName}\" set to {$visibility->value}. Restart to apply.");
            } else {
                $registry->setPackageVisibility($target, $visibility);
                $io->success("Package \"{$target}\" set to {$visibility->value}. Restart to apply.");
            }
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
        }
    }
}
