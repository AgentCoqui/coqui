<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Contract\ToolkitLoadingMode;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /toolkits [enable|stub|disable|promote|demote|auto <pkg|tool:name>] slash command.
 *
 * Table includes: package name, visibility, loading mode (applied at runtime),
 * token estimate, and aggregate tool usage count sourced from ToolUsageTracker.
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
            $appliedModes = $preview['applied_loading_modes'];

            $rows = [];
            foreach ($discovery->allWithVisibility() as $entry) {
                $pkgTokens = 0;
                foreach ($entry['classes'] as $cls) {
                    if (isset($tokensByClass[$cls])) {
                        $pkgTokens += $tokensByClass[$cls]['total_tokens'];
                    }
                }

                // Resolve applied loading mode from the first class basename
                $loadingDisplay = '-';
                if ($entry['classes'] !== []) {
                    $parts = explode('\\', $entry['classes'][0]);
                    $basename = end($parts);

                    // Prefer applied runtime mode, fall back to registry mode
                    if (isset($appliedModes[$basename])) {
                        $loadingDisplay = $appliedModes[$basename]->value;
                    } elseif ($loadingRegistry !== null) {
                        $loadingDisplay = $loadingRegistry->getMode($basename)->value;
                    }
                }

                $rows[] = [
                    $entry['package'],
                    $entry['visibility'],
                    $loadingDisplay,
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
                    '<fg=gray>Use /toolkits promote|demote|auto <toolkit> to change loading mode</>',
                ]);
            }

            return;
        }

        $parts = explode(' ', trim($arg), 2);
        $action = strtolower($parts[0]);
        $target = $parts[1] ?? '';

        // Loading mode actions: promote, demote, auto
        if (in_array($action, ['promote', 'demote', 'auto'], strict: true)) {
            $this->handleLoadingAction($io, $action, $target);
            return;
        }

        // Visibility actions: enable, stub, disable
        if (!in_array($action, ['enable', 'stub', 'disable'], strict: true)) {
            $io->error("Unknown action: {$action}. Use enable|stub|disable or promote|demote|auto.");
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

    private function handleLoadingAction(SymfonyStyle $io, string $action, string $target): void
    {
        $loadingRegistry = $this->boot->loadingRegistry();

        if ($loadingRegistry === null) {
            $io->error('Loading registry is not available.');
            return;
        }

        if ($target === '') {
            $io->error("Usage: /toolkits {$action} <ToolkitClassName>");
            return;
        }

        try {
            match ($action) {
                'promote' => $loadingRegistry->setMode($target, ToolkitLoadingMode::Eager),
                'demote' => $loadingRegistry->setMode($target, ToolkitLoadingMode::Deferred),
                'auto' => $loadingRegistry->resetMode($target),
                default => throw new \InvalidArgumentException("Unknown loading action: {$action}"),
            };

            $resultLabel = match ($action) {
                'promote' => 'eager (always loaded)',
                'demote' => 'deferred (always stubbed)',
                'auto' => 'auto (budget-gated)',
            };

            $io->success("Toolkit \"{$target}\" loading mode set to {$resultLabel}. Restart to apply.");
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
        }
    }
}
