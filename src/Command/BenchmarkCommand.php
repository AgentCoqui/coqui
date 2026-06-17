<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Config\BootManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Performance benchmark command that measures key hot paths.
 *
 * Runs micro-benchmarks for boot time, prompt assembly, tool lookup,
 * token estimation, and SQLite queries. Useful for profiling after
 * configuration changes or code optimizations.
 */
#[AsCommand(
    name: 'benchmark',
    description: 'Run performance benchmarks on key Coqui subsystems',
)]
final class BenchmarkCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('iterations', 'i', InputOption::VALUE_REQUIRED, 'Number of iterations per benchmark', '100')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON')
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to openclaw.json')
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $iterations = max(1, (int) ($input->getOption('iterations') ?? '100'));
        $jsonOutput = (bool) $input->getOption('json');

        if (!$jsonOutput) {
            $io->title('Coqui Performance Benchmark');
            $io->text("Running {$iterations} iterations per benchmark...");
            $io->newLine();
        }

        $results = [];

        // 1. Autoloader performance
        $results['autoloader'] = $this->benchmarkAutoloader($io, $iterations, $jsonOutput);

        // 2. Boot performance
        $workDir = is_string($input->getOption('workdir'))
            ? $input->getOption('workdir')
            : (getcwd() ?: '.');
        $configOption = $input->getOption('config');
        $configPath = is_string($configOption) ? $configOption : null;
        $results['boot'] = $this->benchmarkBoot($io, $workDir, $configPath, $jsonOutput);

        // 3. SQLite PRAGMA overhead
        $results['sqlite_pragmas'] = $this->benchmarkSqlitePragmas($io, $iterations, $jsonOutput);

        // 4. Token estimation
        $results['token_estimation'] = $this->benchmarkTokenEstimation($io, $iterations, $jsonOutput);

        // 5. Runtime info
        $results['runtime'] = $this->collectRuntimeInfo();

        if ($jsonOutput) {
            $output->writeln(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $io->newLine();
            $io->section('Runtime Info');
            $runtime = $results['runtime'];
            $io->listing([
                "PHP: {$runtime['php_version']}",
                "OPcache: " . ($runtime['opcache_enabled'] ? 'enabled' : 'disabled'),
                "JIT: " . ($runtime['jit_enabled'] ? "enabled ({$runtime['jit_buffer_mb']} MB)" : 'disabled'),
                "Memory limit: {$runtime['memory_limit']}",
                "Peak memory: {$runtime['peak_memory_mb']} MB",
            ]);

            $io->success('Benchmark complete.');
        }

        return Command::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkAutoloader(SymfonyStyle $io, int $iterations, bool $jsonOutput): array
    {
        // Measure class resolution time (autoloader already primed)
        $classes = [
            \CoquiBot\Coqui\Agent\OrchestratorAgent::class,
            \CoquiBot\Coqui\Storage\SessionStorage::class,
            \CoquiBot\Coqui\Memory\MemoryStore::class,
            \CoquiBot\Coqui\Config\BootManager::class,
            \CoquiBot\Coqui\Tool\SpawnAgentTool::class,
        ];

        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            foreach ($classes as $class) {
                class_exists($class);
            }
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $perOp = $elapsed / ($iterations * count($classes));

        if (!$jsonOutput) {
            $io->text(sprintf('  Autoloader (class_exists × %d classes): <info>%.2f ms</info> total, <info>%.4f ms</info>/op', count($classes), $elapsed, $perOp));
        }

        return [
            'total_ms' => round($elapsed, 2),
            'per_op_ms' => round($perOp, 4),
            'iterations' => $iterations,
            'classes' => count($classes),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkBoot(SymfonyStyle $io, string $workDir, ?string $configPath, bool $jsonOutput): array
    {
        // Measure a single boot cycle (too expensive for many iterations)
        $memBefore = memory_get_usage(true);
        $start = hrtime(true);

        try {
            $configPath ??= $workDir . '/openclaw.json';
            if (!file_exists($configPath)) {
                $configPath = dirname(__DIR__, 2) . '/openclaw.json';
            }

            // BootManager resolves the workspace itself via WorkspaceResolver.
            $boot = new BootManager($workDir);
            $boot->boot(configPath: $configPath);

            $elapsed = (hrtime(true) - $start) / 1_000_000;
            $memAfter = memory_get_usage(true);
            $memDelta = ($memAfter - $memBefore) / 1024 / 1024;

            if (!$jsonOutput) {
                $io->text(sprintf('  Boot (BootManager::boot): <info>%.1f ms</info>, memory delta: <info>%.1f MB</info>', $elapsed, $memDelta));
            }

            return [
                'elapsed_ms' => round($elapsed, 1),
                'memory_delta_mb' => round($memDelta, 1),
                'status' => 'ok',
            ];
        } catch (\Throwable $e) {
            $elapsed = (hrtime(true) - $start) / 1_000_000;

            if (!$jsonOutput) {
                $io->text(sprintf('  Boot: <error>failed</error> after %.1f ms — %s', $elapsed, $e->getMessage()));
            }

            return [
                'elapsed_ms' => round($elapsed, 1),
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkSqlitePragmas(SymfonyStyle $io, int $iterations, bool $jsonOutput): array
    {
        $dbPath = ':memory:';
        $pragmas = [
            'PRAGMA journal_mode=WAL',
            'PRAGMA foreign_keys=ON',
            'PRAGMA synchronous=NORMAL',
            'PRAGMA cache_size=-8000',
            'PRAGMA temp_store=MEMORY',
        ];

        $start = hrtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $db = new \PDO("sqlite:{$dbPath}");
            $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            foreach ($pragmas as $pragma) {
                $db->exec($pragma);
            }
            unset($db);
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $perOp = $elapsed / $iterations;

        if (!$jsonOutput) {
            $io->text(sprintf('  SQLite PRAGMAs (%d pragmas): <info>%.2f ms</info> total, <info>%.4f ms</info>/connection', count($pragmas), $elapsed, $perOp));
        }

        return [
            'total_ms' => round($elapsed, 2),
            'per_connection_ms' => round($perOp, 4),
            'iterations' => $iterations,
            'pragmas' => count($pragmas),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function benchmarkTokenEstimation(SymfonyStyle $io, int $iterations, bool $jsonOutput): array
    {
        // Simulate a realistic conversation
        $conversation = new \CarmeloSantana\PHPAgents\Message\Conversation();
        $conversation->add(new \CarmeloSantana\PHPAgents\Message\SystemMessage(str_repeat('System prompt content. ', 500)));
        for ($j = 0; $j < 20; $j++) {
            $conversation->add(new \CarmeloSantana\PHPAgents\Message\UserMessage('User message number ' . $j . ' with some content.'));
            $conversation->add(new \CarmeloSantana\PHPAgents\Message\AssistantMessage('Assistant response ' . $j . ' with detailed explanation.' . str_repeat(' More text.', 50)));
        }

        $messageCount = $conversation->count();
        $start = hrtime(true);
        $tokens = 0;
        for ($i = 0; $i < $iterations; $i++) {
            $tokens = $conversation->estimateTokens();
        }
        $elapsed = (hrtime(true) - $start) / 1_000_000;
        $perOp = $elapsed / $iterations;

        if (!$jsonOutput) {
            $io->text(sprintf('  Token estimation (%d msgs, ~%d tokens): <info>%.2f ms</info> total, <info>%.4f ms</info>/call', $messageCount, $tokens, $elapsed, $perOp));
        }

        return [
            'total_ms' => round($elapsed, 2),
            'per_call_ms' => round($perOp, 4),
            'iterations' => $iterations,
            'message_count' => $messageCount,
            'estimated_tokens' => $tokens,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectRuntimeInfo(): array
    {
        $opcacheEnabled = false;
        $jitEnabled = false;
        $jitBufferMb = 0;

        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status(false);
            if (is_array($status)) {
                $opcacheEnabled = $status['opcache_enabled'] ?? false;
                $jitEnabled = $status['jit']['enabled'] ?? false;
                $jitBufferMb = isset($status['jit']['buffer_size'])
                    ? round($status['jit']['buffer_size'] / 1024 / 1024)
                    : 0;
            }
        }

        return [
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'opcache_enabled' => $opcacheEnabled,
            'jit_enabled' => $jitEnabled,
            'jit_buffer_mb' => $jitBufferMb,
            'memory_limit' => ini_get('memory_limit') ?: 'unknown',
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 1),
        ];
    }
}
