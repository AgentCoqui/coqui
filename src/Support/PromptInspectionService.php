<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

use CoquiBot\Coqui\Agent\AgentRunner;

/**
 * Builds a source-aware prompt inspection payload for REPL and API consumers.
 */
final readonly class PromptInspectionService
{
    public function __construct(
        private AgentRunner $agentRunner,
        private string $workspacePath,
        private string $projectRoot,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function inspect(?string $role = null, ?string $profile = null): array
    {
        $preview = $this->agentRunner->buildPromptPreview($role, $profile);
        $budget = $preview['budget_snapshot'];
        $promptSections = is_array($budget['prompt_sections'] ?? null) ? $budget['prompt_sections'] : [];

        return [
            'profile' => $profile,
            'prompt' => $preview['prompt'],
            'tool_count' => $preview['tool_count'],
            'toolkit_count' => $preview['toolkit_count'],
            'prompt_tokens' => $preview['prompt_tokens'],
            'tool_tokens' => $preview['tool_tokens'],
            'total_tokens' => $preview['total_tokens'],
            'toolkit_breakdown' => $preview['toolkit_breakdown'],
            'budget' => $budget,
            'prompt_sources' => $this->buildPromptSources($promptSections),
            'profile_policy' => $preview['profile_policy'] ?? null,
        ];
    }

    /**
     * @param array<int, mixed> $promptSections
     * @return array<string, mixed>
     */
    private function buildPromptSources(array $promptSections): array
    {
        $files = [];
        $synthetic = [];
        $fileBackedTokens = 0;
        $syntheticTokens = 0;
        $latestModifiedAt = null;

        foreach ($promptSections as $section) {
            if (!is_array($section)) {
                continue;
            }

            $tokens = (int) ($section['tokens'] ?? 0);
            $source = is_string($section['source'] ?? null) ? $section['source'] : null;
            $sectionSummary = [
                'id' => (string) ($section['id'] ?? ''),
                'title' => (string) ($section['title'] ?? ''),
                'group' => (string) ($section['group'] ?? ''),
                'tokens' => $tokens,
            ];

            if ($source !== null && $source !== '' && is_file($source)) {
                $descriptor = $this->describePath($source);
                $fileKey = $descriptor['scope'] . ':' . $descriptor['path'];
                $modifiedTs = filemtime($source);
                $sizeBytes = filesize($source);

                if (!isset($files[$fileKey])) {
                    $files[$fileKey] = [
                        'scope' => $descriptor['scope'],
                        'path' => $descriptor['path'],
                        'tokens' => 0,
                        'size_bytes' => $sizeBytes !== false ? (int) $sizeBytes : 0,
                        'last_modified_at' => $modifiedTs !== false ? date('c', $modifiedTs) : null,
                        '_mtime_ts' => $modifiedTs !== false ? (int) $modifiedTs : null,
                        'section_count' => 0,
                        'sections' => [],
                    ];
                }

                $files[$fileKey]['tokens'] += $tokens;
                $files[$fileKey]['section_count']++;
                $files[$fileKey]['sections'][] = $sectionSummary;
                $fileBackedTokens += $tokens;

                if ($modifiedTs !== false && ($latestModifiedAt === null || $modifiedTs > $latestModifiedAt)) {
                    $latestModifiedAt = (int) $modifiedTs;
                }

                continue;
            }

            $syntheticKey = $source !== null && $source !== ''
                ? 'symbol:' . $source
                : 'generated:' . (string) ($section['id'] ?? 'unknown');

            if (!isset($synthetic[$syntheticKey])) {
                $synthetic[$syntheticKey] = [
                    'source_type' => $source !== null && $source !== '' ? 'symbol' : 'generated',
                    'source' => $source,
                    'label' => $this->labelSyntheticSource($source, (string) ($section['title'] ?? 'Generated Section')),
                    'tokens' => 0,
                    'section_count' => 0,
                    'sections' => [],
                ];
            }

            $synthetic[$syntheticKey]['tokens'] += $tokens;
            $synthetic[$syntheticKey]['section_count']++;
            $synthetic[$syntheticKey]['sections'][] = $sectionSummary;
            $syntheticTokens += $tokens;
        }

        foreach ($files as &$entry) {
            unset($entry['_mtime_ts']);
            usort(
                $entry['sections'],
                static fn(array $left, array $right): int => $right['tokens'] <=> $left['tokens'],
            );
        }
        unset($entry);

        foreach ($synthetic as &$entry) {
            usort(
                $entry['sections'],
                static fn(array $left, array $right): int => $right['tokens'] <=> $left['tokens'],
            );
        }
        unset($entry);

        $fileList = array_values($files);
        usort($fileList, static function (array $left, array $right): int {
            $tokenComparison = $right['tokens'] <=> $left['tokens'];
            if ($tokenComparison !== 0) {
                return $tokenComparison;
            }

            return [$left['scope'], $left['path']] <=> [$right['scope'], $right['path']];
        });

        $folderList = $this->buildFolderBreakdown($fileList);
        $syntheticList = array_values($synthetic);
        usort($syntheticList, static function (array $left, array $right): int {
            $tokenComparison = $right['tokens'] <=> $left['tokens'];
            if ($tokenComparison !== 0) {
                return $tokenComparison;
            }

            return $left['label'] <=> $right['label'];
        });

        return [
            'files' => $fileList,
            'folders' => $folderList,
            'synthetic' => $syntheticList,
            'file_backed_tokens' => $fileBackedTokens,
            'synthetic_tokens' => $syntheticTokens,
            'last_modified_at' => $latestModifiedAt !== null ? date('c', $latestModifiedAt) : null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $fileList
     * @return array<int, array<string, mixed>>
     */
    private function buildFolderBreakdown(array $fileList): array
    {
        $folders = [];

        foreach ($fileList as $file) {
            $folderPath = dirname((string) $file['path']);
            if ($folderPath === '.') {
                $folderPath = '';
            }

            $folderKey = (string) $file['scope'] . ':' . $folderPath;
            $modifiedTs = isset($file['last_modified_at']) && is_string($file['last_modified_at'])
                ? strtotime($file['last_modified_at'])
                : false;

            if (!isset($folders[$folderKey])) {
                $folders[$folderKey] = [
                    'scope' => $file['scope'],
                    'path' => $folderPath,
                    'tokens' => 0,
                    'file_count' => 0,
                    'size_bytes' => 0,
                    'last_modified_at' => $modifiedTs !== false ? date('c', (int) $modifiedTs) : null,
                    '_mtime_ts' => $modifiedTs !== false ? (int) $modifiedTs : null,
                ];
            }

            $folders[$folderKey]['tokens'] += (int) $file['tokens'];
            $folders[$folderKey]['file_count']++;
            $folders[$folderKey]['size_bytes'] += (int) $file['size_bytes'];

            if (
                $modifiedTs !== false
                && ($folders[$folderKey]['_mtime_ts'] === null || $modifiedTs > $folders[$folderKey]['_mtime_ts'])
            ) {
                $folders[$folderKey]['_mtime_ts'] = (int) $modifiedTs;
                $folders[$folderKey]['last_modified_at'] = date('c', (int) $modifiedTs);
            }
        }

        foreach ($folders as &$entry) {
            unset($entry['_mtime_ts']);
        }
        unset($entry);

        $folderList = array_values($folders);
        usort($folderList, static function (array $left, array $right): int {
            $tokenComparison = $right['tokens'] <=> $left['tokens'];
            if ($tokenComparison !== 0) {
                return $tokenComparison;
            }

            return [$left['scope'], $left['path']] <=> [$right['scope'], $right['path']];
        });

        return $folderList;
    }

    /**
     * @return array{scope: string, path: string}
     */
    private function describePath(string $path): array
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $workspacePath = rtrim(str_replace('\\', '/', $this->workspacePath), '/');
        $projectRoot = rtrim(str_replace('\\', '/', $this->projectRoot), '/');

        if ($workspacePath !== '' && str_starts_with($normalizedPath, $workspacePath . '/')) {
            return [
                'scope' => 'workspace',
                'path' => substr($normalizedPath, strlen($workspacePath) + 1),
            ];
        }

        if ($projectRoot !== '' && str_starts_with($normalizedPath, $projectRoot . '/')) {
            return [
                'scope' => 'project',
                'path' => substr($normalizedPath, strlen($projectRoot) + 1),
            ];
        }

        return [
            'scope' => 'absolute',
            'path' => $normalizedPath,
        ];
    }

    private function labelSyntheticSource(?string $source, string $fallbackTitle): string
    {
        if ($source === null || $source === '') {
            return $fallbackTitle;
        }

        if (str_contains($source, '\\')) {
            return basename(str_replace('\\', '/', $source));
        }

        return $source;
    }
}