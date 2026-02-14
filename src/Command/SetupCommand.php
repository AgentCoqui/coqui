<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\SetupWizard;
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
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory', getcwd() ?: '.')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output path for openclaw.json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $workDirOption = $input->getOption('workdir');
        $workDir = is_string($workDirOption) ? $workDirOption : (getcwd() ?: '.');

        $outputOption = $input->getOption('output');
        $outputPath = is_string($outputOption) ? $outputOption : $workDir . '/openclaw.json';

        // Check for existing config
        if (file_exists($outputPath)) {
            $io->warning("An openclaw.json already exists at: {$outputPath}");

            if (!$io->confirm('Overwrite the existing configuration?', false)) {
                $io->info('Setup cancelled. Existing config preserved.');
                return Command::SUCCESS;
            }
        }

        $defaults = new DefaultsLoader();
        $wizard = new SetupWizard($io, $defaults);

        $saved = $wizard->runAndSave($outputPath);

        return $saved ? Command::SUCCESS : Command::FAILURE;
    }
}
