<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Agent\TitleGenerator;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Executes a queued session title generation job.
 */
#[AsCommand(
    name: 'session-title:run',
    description: 'Execute a queued session title job (internal)',
    hidden: true,
)]
final class SessionTitleRunCommand extends Command
{
    public function __construct(
        private readonly ?TitleGenerator $titleGenerator = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('job-id', InputArgument::REQUIRED, 'The session title job ID to execute')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to openclaw.json')
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory', getcwd() ?: '.')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace directory (overrides config and default)')
            ->addOption('unsafe', null, InputOption::VALUE_NONE, 'Disable script sanitization');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = $input->getArgument('job-id');
        if (!is_string($jobId) || $jobId === '') {
            $output->writeln('<error>Job ID is required</error>');
            return Command::FAILURE;
        }

        $workDir = is_string($input->getOption('workdir'))
            ? $input->getOption('workdir')
            : (getcwd() ?: '.');

        $configOption = $input->getOption('config');
        $configPath = is_string($configOption) ? $configOption : null;
        $workspaceOverride = WorkspaceOverrideResolver::resolve($input);

        $boot = new BootManager($workDir, $workspaceOverride);
        $result = $boot->boot(io: null, configPath: $configPath, skipMaintenance: true);
        if (!$result) {
            $output->writeln('<error>Boot failed</error>');
            return Command::FAILURE;
        }

        $storage = new SessionStorage($boot->workspacePath() . '/data/coqui.db');
        $job = $storage->getSessionTitleJob($jobId);
        if ($job === null) {
            $output->writeln(sprintf('<error>Session title job %s not found</error>', $jobId));
            return Command::FAILURE;
        }

        if (!in_array((string) $job['status'], ['pending', 'running'], true)) {
            return Command::SUCCESS;
        }

        $sessionId = (string) ($job['session_id'] ?? '');
        $session = $storage->getSession($sessionId);
        if ($session === null) {
            $storage->updateSessionTitleJobStatus($jobId, 'failed', ['error' => 'Target session not found']);
            return Command::FAILURE;
        }

        if (is_string($session['title'] ?? null) && trim((string) $session['title']) !== '') {
            $storage->updateSessionTitleJobStatus($jobId, 'completed');
            return Command::SUCCESS;
        }

        $generator = $this->titleGenerator ?? new TitleGenerator(
            roleResolver: $boot->roleResolver(),
            config: $boot->config(),
            roleDiscovery: $boot->roleDiscovery(),
            providerFactory: $boot->providerFactory(),
        );

        try {
            $title = $generator->generate((string) ($job['prompt'] ?? ''));
            if ($title !== null) {
                $storage->updateSessionTitle($sessionId, $title);

                $turnProcessId = $job['turn_process_id'] ?? null;
                if (is_string($turnProcessId) && $turnProcessId !== '') {
                    $storage->appendTurnEvent($turnProcessId, 'title', ['title' => $title]);
                }
            }

            $storage->updateSessionTitleJobStatus($jobId, 'completed');

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $storage->updateSessionTitleJobStatus($jobId, 'failed', ['error' => $e->getMessage()]);
            return Command::FAILURE;
        }
    }
}