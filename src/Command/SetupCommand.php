<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\SetupWizard;
use CoquiBot\Coqui\Exception\InteractionCancelledException;
use CoquiBot\Coqui\Exception\ShutdownRequestedException;
use CoquiBot\Coqui\Repl\InterruptiblePrompt;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'setup',
    description: 'Create or edit an openclaw.json configuration file',
)]
final class SetupCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('workdir', null, InputOption::VALUE_REQUIRED, 'Working directory (project root)', getcwd() ?: '.')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output path for openclaw.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $prompt = new InterruptiblePrompt($io);

        $workDirOption = $input->getOption('workdir');
        $workDir = is_string($workDirOption) ? $workDirOption : (getcwd() ?: '.');

        $outputOption = $input->getOption('output');
        $outputPath = is_string($outputOption) ? $outputOption : $workDir . '/openclaw.json';

        // Check for existing config — offer section-based editing instead of full overwrite
        $existingConfig = null;
        if (file_exists($outputPath)) {
            $json = file_get_contents($outputPath);
            $decoded = ($json !== false) ? json_decode($json, true) : null;
            $existingConfig = is_array($decoded) ? $decoded : null;

            if ($existingConfig !== null) {
                try {
                    $editMode = $prompt->choice(
                        "An openclaw.json already exists at: {$outputPath}",
                        [
                            'Edit specific sections (preserves other settings)',
                            'Start fresh (overwrite everything)',
                            'Cancel',
                        ],
                        'Edit specific sections (preserves other settings)',
                    );
                } catch (InteractionCancelledException) {
                    $io->info('Setup cancelled. Existing config preserved.');
                    return Command::SUCCESS;
                } catch (ShutdownRequestedException) {
                    return Command::SUCCESS;
                }

                if ($editMode === 'Cancel') {
                    $io->info('Setup cancelled. Existing config preserved.');
                    return Command::SUCCESS;
                }

                if ($editMode === 'Start fresh (overwrite everything)') {
                    $existingConfig = null;
                }
            }
        }

        $defaults = new DefaultsLoader();
        $wizard = new SetupWizard($io, $defaults);

        try {
            $saved = $wizard->runAndSave($outputPath, $existingConfig);
        } catch (InteractionCancelledException) {
            $io->info('Setup cancelled. Existing config preserved.');
            return Command::SUCCESS;
        } catch (ShutdownRequestedException) {
            return Command::SUCCESS;
        }

        return $saved ? Command::SUCCESS : Command::FAILURE;
    }
}
