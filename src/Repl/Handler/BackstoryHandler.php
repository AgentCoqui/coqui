<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Backstory\BackstoryAssembler;
use CoquiBot\Coqui\Backstory\BackstoryManifest;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Repl\RouteResult;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /backstory slash command for managing backstory generation.
 */
final class BackstoryHandler
{
    private readonly BackstoryAssembler $assembler;

    public function __construct(
        private readonly BootManager $boot,
    ) {
        $this->assembler = new BackstoryAssembler();
    }

    public function handle(SymfonyStyle $io, string $arg, ?string $activeProfile): RouteResult
    {
        if ($activeProfile === null) {
            $io->error('No active profile. Use /profile <name> to activate a profile first.');
            return RouteResult::continue();
        }

        $profilePath = $this->resolveProfilePath($activeProfile);
        if ($profilePath === null) {
            $io->error(sprintf('Profile "%s" not found.', $activeProfile));
            return RouteResult::continue();
        }

        $subcommand = strtolower(trim($arg));

        return match ($subcommand) {
            'generate' => $this->handleGenerate($io, $profilePath, $activeProfile),
            'failed' => $this->handleFailed($io, $profilePath, $activeProfile),
            '' => $this->handleOverview($io, $profilePath, $activeProfile),
            default => $this->handleUnknown($io, $subcommand),
        };
    }

    private function handleOverview(SymfonyStyle $io, string $profilePath, string $activeProfile): RouteResult
    {
        $backstoryDir = BackstoryManifest::backstoryDir($profilePath);
        if (!is_dir($backstoryDir)) {
            $io->info(sprintf(
                'No backstory source folder found for profile "%s". Create one at: %s',
                $activeProfile,
                $backstoryDir,
            ));
            return RouteResult::continue();
        }

        $manifest = $this->assembler->getManifest($profilePath);
        if ($manifest === null || $manifest->generatedAt === '') {
            $io->warning('Backstory source folder exists but has not been generated yet. Run /backstory generate');
            return RouteResult::continue();
        }

        $io->section(sprintf('Backstory — %s', $activeProfile));

        $io->definitionList(
            ['Source folder' => $backstoryDir],
            ['Generated at' => $manifest->generatedAt],
            ['Total files' => (string) $manifest->totalFiles],
            ['Supported files' => (string) $manifest->supportedFilesCount()],
            ['Unsupported files' => (string) $manifest->unsupportedFileCount()],
            ['Failed files' => (string) $manifest->failedFiles],
            ['Estimated tokens' => number_format($manifest->totalTokens)],
        );

        if ($manifest->files !== []) {
            $rows = [];
            foreach ($manifest->files as $file) {
                $status = $file['status'] === 'ok'
                    ? '<fg=green>ok</>'
                    : '<fg=red>failed</>';

                $rows[] = [
                    $file['relative_path'],
                    self::formatBytes($file['size_bytes']),
                    number_format($file['token_estimate']),
                    $status,
                    self::formatTimestamp($file['modified_at']),
                ];
            }

            $io->table(
                ['File', 'Size', 'Tokens', 'Status', 'Modified'],
                $rows,
            );
        }

        if ($manifest->unsupportedFiles !== []) {
            $rows = [];
            foreach ($manifest->unsupportedFiles as $file) {
                $rows[] = [
                    $file['relative_path'],
                    $file['extension'] !== '' ? '.' . $file['extension'] : '—',
                    $file['reason'],
                    self::formatTimestamp($file['modified_at']),
                ];
            }

            $io->newLine();
            $io->table(
                ['Skipped file', 'Extension', 'Reason', 'Modified'],
                $rows,
            );
        }

        if ($manifest->failedFiles > 0 || $manifest->unsupportedFileCount() > 0) {
            $messages = [];
            if ($manifest->failedFiles > 0) {
                $messages[] = sprintf('%d failed extraction(s)', $manifest->failedFiles);
            }
            if ($manifest->unsupportedFileCount() > 0) {
                $messages[] = sprintf('%d unsupported file(s) skipped', $manifest->unsupportedFileCount());
            }

            $io->warning(implode('; ', $messages) . '. Run /backstory failed for details.');
        }

        $needsRegen = $this->assembler->needsRegeneration($profilePath);
        if ($needsRegen) {
            $io->warning('Source files have changed since last generation. Run /backstory generate to update.');
        }

        return RouteResult::continue();
    }

    private function handleGenerate(SymfonyStyle $io, string $profilePath, string $activeProfile): RouteResult
    {
        $backstoryDir = BackstoryManifest::backstoryDir($profilePath);
        if (!is_dir($backstoryDir)) {
            $io->error(sprintf(
                'No backstory source folder found. Create one at: %s',
                $backstoryDir,
            ));
            return RouteResult::continue();
        }

        $io->text(sprintf('Generating backstory for profile "%s"...', $activeProfile));

        $result = $this->assembler->generate($profilePath);

        if ($result->totalFiles === 0) {
            $io->info('No source files found in backstory source folder.');
            return RouteResult::continue();
        }

        $io->newLine();
        $io->definitionList(
            ['Files discovered' => (string) $result->totalFiles],
            ['Supported files' => (string) ($result->totalFiles - $result->unsupportedFiles)],
            ['Unsupported files' => (string) $result->unsupportedFiles],
            ['Failed extractions' => (string) $result->failedFiles],
            ['Estimated tokens' => number_format($result->totalTokens)],
            ['Generation time' => sprintf('%.1f ms', $result->generationTimeMs)],
        );

        if ($result->failedFiles > 0 || $result->unsupportedFiles > 0) {
            $messages = [];
            if ($result->failedFiles > 0) {
                $messages[] = sprintf('%d failed extraction(s)', $result->failedFiles);
            }
            if ($result->unsupportedFiles > 0) {
                $messages[] = sprintf('%d unsupported file(s) skipped', $result->unsupportedFiles);
            }

            $io->warning(implode('; ', $messages) . '. Run /backstory failed for details.');
        } else {
            $io->success('Backstory generated successfully.');
        }

        return RouteResult::continue();
    }

    private function handleFailed(SymfonyStyle $io, string $profilePath, string $activeProfile): RouteResult
    {
        $manifest = $this->assembler->getManifest($profilePath);
        if ($manifest === null || $manifest->generatedAt === '') {
            $io->info('No backstory has been generated yet. Run /backstory generate first.');
            return RouteResult::continue();
        }

        if ($manifest->errors === [] && $manifest->unsupportedFiles === []) {
            $io->success('No failed or unsupported files in the last generation.');
            return RouteResult::continue();
        }

        $io->section(sprintf('Backstory Issues — %s', $activeProfile));

        if ($manifest->errors !== []) {
            $rows = [];
            foreach ($manifest->errors as $error) {
                $rows[] = [
                    $error['relative_path'],
                    $error['error'],
                    self::formatTimestamp($error['timestamp']),
                ];
            }

            $io->table(
                ['Failed file', 'Error', 'Timestamp'],
                $rows,
            );
        }

        if ($manifest->unsupportedFiles !== []) {
            $rows = [];
            foreach ($manifest->unsupportedFiles as $file) {
                $rows[] = [
                    $file['relative_path'],
                    $file['extension'] !== '' ? '.' . $file['extension'] : '—',
                    $file['reason'],
                    self::formatTimestamp($file['modified_at']),
                ];
            }

            if ($manifest->errors !== []) {
                $io->newLine();
            }

            $io->table(
                ['Skipped file', 'Extension', 'Reason', 'Modified'],
                $rows,
            );
        }

        return RouteResult::continue();
    }

    private function handleUnknown(SymfonyStyle $io, string $subcommand): RouteResult
    {
        $io->error(sprintf('Unknown backstory subcommand: "%s"', $subcommand));
        $io->text([
            'Usage:',
            '  /backstory            Show backstory manifest and status',
            '  /backstory generate   Generate backstory.md from source files',
            '  /backstory failed     Show failed and unsupported files from the last generation',
        ]);
        return RouteResult::continue();
    }

    private function resolveProfilePath(string $profileName): ?string
    {
        $profileDiscovery = $this->boot->profileDiscovery();
        if (!$profileDiscovery->profileExists($profileName)) {
            return null;
        }

        return $profileDiscovery->getProfilePath($profileName);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }

    private static function formatTimestamp(string $timestamp): string
    {
        if ($timestamp === '') {
            return '—';
        }

        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);
        if ($dt === false) {
            return $timestamp;
        }

        return $dt->format('Y-m-d H:i');
    }

    /**
     * Get a brief manifest summary for display in /prompt output.
     *
    * @return array{total_files: int, total_tokens: int, failed_files: int, unsupported_files: int}|null
     */
    public function getManifestSummary(string $profileName): ?array
    {
        $profilePath = $this->resolveProfilePath($profileName);
        if ($profilePath === null) {
            return null;
        }

        $manifest = $this->assembler->getManifest($profilePath);
        if ($manifest === null || $manifest->totalFiles === 0) {
            return null;
        }

        return [
            'total_files' => $manifest->totalFiles,
            'total_tokens' => $manifest->totalTokens,
            'failed_files' => $manifest->failedFiles,
            'unsupported_files' => $manifest->unsupportedFileCount(),
        ];
    }
}
