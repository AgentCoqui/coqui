<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Backstory\BackstoryAssembler;
use CoquiBot\Coqui\Backstory\BackstoryInspectionService;
use CoquiBot\Coqui\Backstory\BackstoryManifest;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\ProfilePreferences;
use CoquiBot\Coqui\Repl\RouteResult;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /backstory slash command for managing backstory generation.
 */
final class BackstoryHandler
{
    private readonly BackstoryAssembler $assembler;
    private readonly BackstoryInspectionService $inspectionService;

    public function __construct(
        private readonly ProfileDiscovery $profileDiscovery,
        private readonly string $workspacePath,
        ?BackstoryAssembler $assembler = null,
        ?BackstoryInspectionService $inspectionService = null,
    ) {
        $this->assembler = $assembler ?? new BackstoryAssembler();
        $this->inspectionService = $inspectionService ?? new BackstoryInspectionService($this->workspacePath, $this->profileDiscovery, $this->assembler);
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
        $backstory = $this->inspectionService->inspect($activeProfile);
        if (($backstory['source_folder_exists'] ?? false) !== true) {
            $io->info(sprintf(
                'No backstory source folder found for profile "%s". Create one at: %s',
                $activeProfile,
                BackstoryManifest::backstoryDir($profilePath),
            ));
            return RouteResult::continue();
        }

        if (($backstory['has_generated_backstory'] ?? false) !== true) {
            $io->warning('Backstory source folder exists but has not been generated yet. Run /backstory generate');
            return RouteResult::continue();
        }

        $io->section(sprintf('Backstory — %s', $activeProfile));

        $io->definitionList(
            ['Source folder' => (string) ($backstory['source_folder'] ?? '')],
            ['Generated file' => (string) ($backstory['generated_backstory_path'] ?? '')],
            ['Generated at' => self::formatNullableTimestamp(is_string($backstory['generated_at'] ?? null) ? $backstory['generated_at'] : null)],
            ['Last modified' => self::formatNullableTimestamp(is_string($backstory['last_modified_at'] ?? null) ? $backstory['last_modified_at'] : null)],
            ['Total files' => (string) ($backstory['total_files'] ?? 0)],
            ['Supported files' => (string) ($backstory['supported_file_count'] ?? 0)],
            ['Unsupported files' => (string) ($backstory['unsupported_file_count'] ?? 0)],
            ['Failed files' => (string) ($backstory['failed_file_count'] ?? 0)],
            ['Estimated tokens' => number_format((int) ($backstory['total_tokens'] ?? 0))],
            ['Total size' => self::formatBytes((int) ($backstory['total_size_bytes'] ?? 0))],
            ['Needs regeneration' => ($backstory['needs_regeneration'] ?? false) ? 'yes' : 'no'],
        );

        if (($backstory['folders'] ?? []) !== []) {
            $rows = [];
            foreach ($backstory['folders'] as $folder) {
                if (!is_array($folder)) {
                    continue;
                }

                $rows[] = [
                    self::formatFolderPath((string) ($folder['path'] ?? '')),
                    number_format((int) ($folder['total_tokens'] ?? 0)),
                    (string) ($folder['file_count'] ?? 0),
                    (string) ($folder['unsupported_file_count'] ?? 0),
                    (string) ($folder['failed_file_count'] ?? 0),
                    self::formatBytes((int) ($folder['total_size_bytes'] ?? 0)),
                    self::formatNullableTimestamp(is_string($folder['last_modified_at'] ?? null) ? $folder['last_modified_at'] : null),
                ];
            }

            $io->table(
                ['Folder', 'Tokens', 'Files', 'Skipped', 'Failed', 'Size', 'Modified'],
                $rows,
            );
        }

        if (($backstory['files'] ?? []) !== []) {
            $rows = [];
            foreach ($backstory['files'] as $file) {
                if (!is_array($file)) {
                    continue;
                }

                $status = ($file['status'] ?? 'unknown') === 'ok'
                    ? '<fg=green>ok</>'
                    : '<fg=red>failed</>';

                $rows[] = [
                    (string) ($file['relative_path'] ?? ''),
                    self::formatBytes((int) ($file['size_bytes'] ?? 0)),
                    number_format((int) ($file['token_estimate'] ?? 0)),
                    $status,
                    self::formatNullableTimestamp(is_string($file['modified_at'] ?? null) ? $file['modified_at'] : null),
                ];
            }

            $io->newLine();
            $io->table(
                ['File', 'Size', 'Tokens', 'Status', 'Modified'],
                $rows,
            );
        }

        if (($backstory['unsupported_files'] ?? []) !== []) {
            $rows = [];
            foreach ($backstory['unsupported_files'] as $file) {
                if (!is_array($file)) {
                    continue;
                }

                $rows[] = [
                    (string) ($file['relative_path'] ?? ''),
                    ($file['extension'] ?? '') !== '' ? '.' . $file['extension'] : '—',
                    (string) ($file['reason'] ?? ''),
                    self::formatNullableTimestamp(is_string($file['modified_at'] ?? null) ? $file['modified_at'] : null),
                ];
            }

            $io->newLine();
            $io->table(
                ['Skipped file', 'Extension', 'Reason', 'Modified'],
                $rows,
            );
        }

        if (($backstory['failed_file_count'] ?? 0) > 0 || ($backstory['unsupported_file_count'] ?? 0) > 0) {
            $messages = [];
            if (($backstory['failed_file_count'] ?? 0) > 0) {
                $messages[] = sprintf('%d failed extraction(s)', (int) $backstory['failed_file_count']);
            }
            if (($backstory['unsupported_file_count'] ?? 0) > 0) {
                $messages[] = sprintf('%d unsupported file(s) skipped', (int) $backstory['unsupported_file_count']);
            }

            $io->warning(implode('; ', $messages) . '. Run /backstory failed for details.');
        }

        if (($backstory['needs_regeneration'] ?? false) === true) {
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

        $result = $this->assembler->generate(
            $profilePath,
            ProfilePreferences::fromProfilePath($profilePath)->getBackstoryLabel(),
        );

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
                    self::formatNullableTimestamp($error['timestamp']),
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
                    self::formatNullableTimestamp($file['modified_at']),
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
        if (!$this->profileDiscovery->profileExists($profileName)) {
            return null;
        }

        return $this->profileDiscovery->getProfilePath($profileName);
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

    public static function formatNullableTimestamp(?string $timestamp): string
    {
        if ($timestamp === null || $timestamp === '') {
            return '—';
        }

        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);
        if ($dt === false) {
            return $timestamp;
        }

        return $dt->format('Y-m-d H:i');
    }

    private static function formatFolderPath(string $path): string
    {
        return $path !== '' ? $path : '.';
    }

    /**
     * Get a brief manifest summary for display in /prompt output.
     *
    * @return array{total_files: int, total_tokens: int, failed_files: int, unsupported_files: int}|null
     */
    public function getManifestSummary(string $profileName): ?array
    {
        if ($this->resolveProfilePath($profileName) === null) {
            return null;
        }

        $backstory = $this->inspectionService->inspect($profileName);
        if (($backstory['has_generated_backstory'] ?? false) !== true || (int) ($backstory['total_files'] ?? 0) === 0) {
            return null;
        }

        return [
            'total_files' => (int) ($backstory['total_files'] ?? 0),
            'total_tokens' => (int) ($backstory['total_tokens'] ?? 0),
            'failed_files' => (int) ($backstory['failed_file_count'] ?? 0),
            'unsupported_files' => (int) ($backstory['unsupported_file_count'] ?? 0),
        ];
    }
}
