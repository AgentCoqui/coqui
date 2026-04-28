<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\CoquiSpace;

use CarmeloSantana\PathHelper\PathHelper;

/**
 * Small workspace-backed cache for `/space install` tab completion.
 *
 * Keeps recent marketplace search results on disk so repeated completion
 * attempts do not hit the network for the same prefix on every tab press.
 */
final class SpaceInstallCompletionCache
{
    private const int TTL_SECONDS = 21600;

    private const int MAX_ENTRIES = 100;

    private const int MAX_SUGGESTIONS = 20;

    private string $cacheFile;

    public function __construct(
        string $workspacePath,
        private readonly SpaceClient $client,
    ) {
        $cacheDir = PathHelper::trimTrailingSlash($workspacePath) . '/.coqui-cache';
        $this->cacheFile = $cacheDir . '/space-install-completions.json';
    }

    /**
     * @return list<string>
     */
    public function suggestTargets(string $prefix): array
    {
        $query = trim($prefix);
        if ($query === '') {
            return [];
        }

        [$queries, $changed] = $this->loadQueries();
        $matches = $this->cachedMatches($queries, $query);
        $normalizedQuery = strtolower($query);

        if (mb_strlen($query) < 2) {
            if ($changed) {
                $this->saveQueries($queries);
            }

            return $matches;
        }

        $entry = $queries[$normalizedQuery] ?? null;
        if ($entry === null) {
            try {
                $targets = $this->fetchTargets($query);
                $queries[$normalizedQuery] = [
                    'fetched_at' => time(),
                    'targets' => $targets,
                ];
                $queries = $this->pruneQueries($queries);
                $this->saveQueries($queries);

                return $this->mergeMatches($matches, $targets, $query);
            } catch (\Throwable) {
                if ($changed) {
                    $this->saveQueries($queries);
                }

                return $matches;
            }
        }

        if ($changed) {
            $this->saveQueries($queries);
        }

        return $matches;
    }

    /**
     * @return array{0: array<string, array{fetched_at: int, targets: list<string>}>, 1: bool}
     */
    private function loadQueries(): array
    {
        if (!file_exists($this->cacheFile)) {
            return [[], false];
        }

        $raw = file_get_contents($this->cacheFile);
        if ($raw === false) {
            return [[], false];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [[], false];
        }

        $entries = $decoded['queries'] ?? [];
        if (!is_array($entries)) {
            return [[], false];
        }

        $queries = [];
        foreach ($entries as $query => $entry) {
            if (!is_string($query) || !is_array($entry)) {
                continue;
            }

            $fetchedAt = $entry['fetched_at'] ?? null;
            $targets = $entry['targets'] ?? null;

            if (!is_int($fetchedAt) || !is_array($targets)) {
                continue;
            }

            $normalizedTargets = [];
            foreach ($targets as $target) {
                if (is_string($target) && $target !== '') {
                    $normalizedTargets[] = $target;
                }
            }

            $queries[strtolower($query)] = [
                'fetched_at' => $fetchedAt,
                'targets' => array_values(array_unique($normalizedTargets)),
            ];
        }

        $pruned = $this->pruneQueries($queries);

        return [$pruned, $pruned !== $queries];
    }

    /**
     * @param array<string, array{fetched_at: int, targets: list<string>}> $queries
     * @return array<string, array{fetched_at: int, targets: list<string>}>
     */
    private function pruneQueries(array $queries): array
    {
        $cutoff = time() - self::TTL_SECONDS;
        $fresh = array_filter(
            $queries,
            static fn(array $entry): bool => $entry['fetched_at'] >= $cutoff,
        );

        uasort(
            $fresh,
            static fn(array $left, array $right): int => $right['fetched_at'] <=> $left['fetched_at'],
        );

        return array_slice($fresh, 0, self::MAX_ENTRIES, true);
    }

    /**
     * @param array<string, array{fetched_at: int, targets: list<string>}> $queries
     * @return list<string>
     */
    private function cachedMatches(array $queries, string $prefix): array
    {
        $matches = [];
        $seen = [];
        $normalizedPrefix = strtolower($prefix);

        foreach ($queries as $entry) {
            foreach ($entry['targets'] as $target) {
                $normalizedTarget = strtolower($target);
                if (!str_starts_with($normalizedTarget, $normalizedPrefix) || isset($seen[$normalizedTarget])) {
                    continue;
                }

                $seen[$normalizedTarget] = true;
                $matches[] = $target;

                if (count($matches) >= self::MAX_SUGGESTIONS) {
                    return $matches;
                }
            }
        }

        return $matches;
    }

    /**
     * @param list<string> $existing
     * @param list<string> $targets
     * @return list<string>
     */
    private function mergeMatches(array $existing, array $targets, string $prefix): array
    {
        $matches = $existing;
        $seen = array_fill_keys(array_map('strtolower', $existing), true);
        $normalizedPrefix = strtolower($prefix);

        foreach ($targets as $target) {
            $normalizedTarget = strtolower($target);
            if (!str_starts_with($normalizedTarget, $normalizedPrefix) || isset($seen[$normalizedTarget])) {
                continue;
            }

            $seen[$normalizedTarget] = true;
            $matches[] = $target;

            if (count($matches) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $matches;
    }

    /**
     * @return list<string>
     */
    private function fetchTargets(string $query): array
    {
        $data = $this->client->searchAll($query, limit: 20);
        $targets = [];
        $seen = [];

        foreach ((array) ($data['skills']['results'] ?? []) as $skill) {
            if (!is_array($skill)) {
                continue;
            }

            $owner = SpaceRegistry::extractOwner($skill);
            $name = (string) ($skill['name'] ?? '');

            if ($owner === '' || $name === '') {
                continue;
            }

            $identifier = $owner . '/' . $name;
            $normalized = strtolower($identifier);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $targets[] = $identifier;
        }

        foreach ((array) ($data['toolkits']['results'] ?? []) as $toolkit) {
            if (!is_array($toolkit)) {
                continue;
            }

            $package = (string) ($toolkit['name'] ?? '');
            if ($package === '') {
                continue;
            }

            $normalized = strtolower($package);
            if (isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $targets[] = $package;
        }

        return $targets;
    }

    /**
     * @param array<string, array{fetched_at: int, targets: list<string>}> $queries
     */
    private function saveQueries(array $queries): void
    {
        $directory = dirname($this->cacheFile);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            return;
        }

        $payload = json_encode(['queries' => $queries], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }

        file_put_contents($this->cacheFile, $payload . "\n");
    }
}