<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\SetupWizard;
use CoquiBot\Coqui\Config\UpdateManager;
use CoquiBot\Coqui\Exception\InteractionCancelledException;
use CoquiBot\Coqui\Exception\ShutdownRequestedException;
use CoquiBot\Coqui\Repl\InterruptiblePrompt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /config, /update commands and startup update checking.
 */
final class ConfigHandler
{
    public const RESTART_EXIT_CODE = 10;

    public function __construct(
        private readonly BootManager $boot,
        private readonly string $workDir,
    ) {}

    public function handle(SymfonyStyle $io, string $subCommand): int|true
    {
        return match (trim($subCommand)) {
            'edit' => $this->runConfigWizard($io),
            'show' => (function () use ($io) {
                $this->showConfigFile($io);
                return true;
            })(),
            default => (function () use ($io) {
                $this->showConfigSummary($io);
                return true;
            })(),
        };
    }

    public function runConfigWizard(SymfonyStyle $io): int|true
    {
        $outputPath = $this->boot->configManager()->path();
        $existingConfig = $this->boot->configManager()->toArray();
        $prompt = new InterruptiblePrompt($io);

        try {
            $wizard = new SetupWizard($io, $this->boot->defaultsLoader(), $this->boot->credentialResolver());
            $saved = $wizard->runAndSave($outputPath, $existingConfig !== [] ? $existingConfig : null);
        } catch (InteractionCancelledException) {
            $io->text('<fg=gray>Configuration edit cancelled.</>');
            return true;
        } catch (ShutdownRequestedException) {
            if (getenv('COQUI_LAUNCHER') !== '1') {
                $io->newLine();
                $io->info('Shutting down Coqui.');
            }

            return Command::SUCCESS;
        }

        if ($saved && file_exists($outputPath)) {
            if ($prompt->confirm('Restart now to apply the new configuration?', true)) {
                $io->info('Restarting Coqui...');
                return self::RESTART_EXIT_CODE;
            }
            $io->success('Configuration saved. Use /restart when ready to apply changes.');
        }

        return true;
    }

    public function runWizardAndExit(SymfonyStyle $io): int
    {
        $outputPath = $this->boot->configManager()->path();
        try {
            $wizard = new SetupWizard($io, $this->boot->defaultsLoader(), $this->boot->credentialResolver());
            $saved = $wizard->runAndSave($outputPath);
        } catch (InteractionCancelledException) {
            $io->text('<fg=gray>Configuration edit cancelled.</>');
            return Command::SUCCESS;
        } catch (ShutdownRequestedException) {
            return Command::SUCCESS;
        }

        return $saved ? Command::SUCCESS : Command::FAILURE;
    }

    public function runUpdate(SymfonyStyle $io): int|true
    {
        $updateManager = new UpdateManager($this->workDir, $this->boot->workspacePath());
        $prompt = new InterruptiblePrompt($io);

        $io->text('<fg=gray>Checking for updates...</>');
        $check = $updateManager->checkForUpdates();

        if (!$check->hasUpdates) {
            $io->success('All packages are up to date.');
            return true;
        }

        $io->writeln($check->summary());
        $io->newLine();

        try {
            if (!$prompt->confirm('Apply updates now?', true)) {
                return true;
            }
        } catch (InteractionCancelledException) {
            $io->text('<fg=gray>Update cancelled.</>');
            return true;
        } catch (ShutdownRequestedException) {
            return Command::SUCCESS;
        }

        $io->text('<fg=gray>Updating dependencies...</>');
        $result = $updateManager->applyUpdates();

        if ($result->error !== '') {
            $io->error($result->error);
            return true;
        }

        $io->success('Updates applied successfully. Restarting...');

        return self::RESTART_EXIT_CODE;
    }

    /**
     * Checks for outdated packages on startup; optionally auto-applies updates.
     *
     * @return bool True if a restart was requested (auto-update applied).
     */
    public function checkForUpdatesOnStartup(SymfonyStyle $io): bool
    {
        $updateManager = new UpdateManager($this->workDir, $this->boot->workspacePath());

        if (!$updateManager->isCheckEnabled()) {
            return false;
        }

        $check = $updateManager->checkForUpdates();

        if (!$check->hasUpdates) {
            return false;
        }

        $count = count($check->packages);
        $io->text("<fg=yellow>{$count} update(s) available.</> Run <fg=cyan>/update</> or <fg=cyan>coqui --update</> to apply.");

        if ($updateManager->isAutoUpdateEnabled()) {
            $io->text('<fg=gray>Auto-update enabled. Applying updates...</>');
            $result = $updateManager->applyUpdates();

            if ($result->error !== '') {
                $io->warning("Auto-update failed: {$result->error}");
                return false;
            }

            $postCheck = $updateManager->checkForUpdates();
            $preCount = count($check->packages);
            $postCount = count($postCheck->packages);

            if ($postCount >= $preCount) {
                $io->warning(
                    "{$postCount} package(s) still outdated after update — "
                    . 'manual intervention may be required. Run /update for details.',
                );
                return false;
            }

            $io->success('Updates applied. Restarting...');
            return true;
        }

        return false;
    }

    private function showConfigFile(SymfonyStyle $io): void
    {
        $configPath = $this->boot->configManager()->path();

        if (!file_exists($configPath)) {
            $io->warning('No openclaw.json found. Run /config edit to create one.');
            return;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            $io->error('Unable to read openclaw.json.');
            return;
        }

        $io->section('openclaw.json (' . $configPath . ')');
        $io->writeln($content);
    }

    private function showConfigSummary(SymfonyStyle $io): void
    {
        $io->section('Current Configuration');

        $primary = $this->boot->config()->getPrimaryModel();
        $io->writeln('<fg=gray>Primary model:</> ' . ($primary !== '' ? $primary : '<fg=yellow>not set</>'));

        $roles = $this->boot->roleResolver()->toArray();
        if (!empty($roles)) {
            $io->newLine();
            $rows = [];
            foreach ($roles as $role => $model) {
                $rows[] = [$role, $model['model'] ?? ''];
            }
            $io->table(['Role', 'Model'], $rows);
        }

        $io->writeln('<fg=gray>Config:</> ' . $this->boot->configManager()->path());
        $io->writeln('<fg=gray>Workspace:</> ' . $this->boot->workspacePath());
        $io->writeln('<fg=gray>Project root:</> ' . $this->workDir);
        $io->newLine();
        $io->text('<fg=gray>Use <fg=cyan>/config edit</> to re-run the setup wizard, or <fg=cyan>/config show</> to view raw JSON.</>');
    }
}
