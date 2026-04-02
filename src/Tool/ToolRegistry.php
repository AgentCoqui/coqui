<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;

/**
 * In-memory tool index with BM25 keyword search.
 *
 * Holds lightweight metadata (name + description) for every tool registered
 * by OrchestratorAgent. The full tool schemas are never stored here — the
 * agent fetches the full definition from AbstractAgent's allTools() only after
 * the user's request confirms which tools are actually needed.
 *
 * BM25 parameters (k1=1.5, b=0.75) match the Lucene/Elasticsearch defaults,
 * which perform well on short technical strings like tool names and descriptions.
 *
 * No external dependencies — uses only PHP built-ins and SPL.
 */
final class ToolRegistry
{
    private const float K1 = 1.5;
    private const float B = 0.75;

    /** @var array<string, array{name: string, description: string, tokens: int[], package: string}> */
    private array $documents = [];

    /** @var array<string, int> term → document-frequency */
    private array $df = [];

    private int $totalLength = 0;
    private int $docCount = 0;

    /**
     * Register a tool in the index.
     *
     * The document text is: name (boosted ×3) + description.
     * Duplicate registrations silently overwrite the prior entry.
     */
    public function register(ToolInterface $tool, string $packageName = ''): void
    {
        $name = $tool->name();
        $description = $tool->description();

        // Boost name by repeating it — tool name matches should rank high
        $text = $name . ' ' . $name . ' ' . $name . ' ' . $description;
        $terms = $this->tokenise($text);
        $freq = array_count_values($terms);

        // Remove stale document from df counts before re-indexing
        if (isset($this->documents[$name])) {
            $oldFreq = $this->documents[$name]['tokens'];
            $this->totalLength -= array_sum($oldFreq);
            foreach (array_keys($oldFreq) as $term) {
                $this->df[$term]--;
                if ($this->df[$term] <= 0) {
                    unset($this->df[$term]);
                }
            }
            $this->docCount--;
        }

        // Index new document
        foreach (array_keys($freq) as $term) {
            $this->df[$term] = ($this->df[$term] ?? 0) + 1;
        }

        $this->documents[$name] = [
            'name' => $name,
            'description' => $description,
            'tokens' => $freq,
            'package' => $packageName,
        ];

        $this->totalLength += count($terms);
        $this->docCount++;
    }

    /**
     * Search the registry and return up to $topN best-matching tool summaries.
     *
     * @return array<int, array{name: string, description: string, package: string}>
     */
    public function search(string $query, int $topN = 5): array
    {
        if ($this->docCount === 0) {
            return [];
        }

        $queryTerms = $this->tokenise($query);
        if (empty($queryTerms)) {
            return [];
        }

        $avgdl = $this->totalLength / $this->docCount;
        $scores = [];

        foreach ($this->documents as $name => $doc) {
            $docLen = array_sum($doc['tokens']);
            $score = 0.0;

            foreach ($queryTerms as $term) {
                $tf = $doc['tokens'][$term] ?? 0;
                if ($tf === 0) {
                    continue;
                }

                $df = $this->df[$term] ?? 0;
                $idf = log(($this->docCount - $df + 0.5) / ($df + 0.5) + 1);
                $norm = $tf * (self::K1 + 1) / ($tf + self::K1 * (1 - self::B + self::B * $docLen / $avgdl));
                $score += $idf * $norm;
            }

            if ($score > 0.0) {
                $scores[$name] = $score;
            }
        }

        if (empty($scores)) {
            return [];
        }

        arsort($scores);
        $top = array_slice(array_keys($scores), 0, $topN);

        return array_map(fn(string $name): array => [
            'name' => $name,
            'description' => $this->documents[$name]['description'],
            'package' => $this->documents[$name]['package'],
        ], $top);
    }

    /**
     * Return all registered tool names and their one-line descriptions.
     *
     * Used to build a system prompt catalogue so the agent knows what
     * categories of tools are available to search for.
     *
     * @return array<int, array{name: string, description: string, package: string}>
     */
    public function all(): array
    {
        return array_values(array_map(
            fn(array $doc): array => [
                'name' => $doc['name'],
                'description' => $doc['description'],
                'package' => $doc['package'],
            ],
            $this->documents,
        ));
    }

    /**
     * Return the number of registered tools.
     */
    public function count(): int
    {
        return $this->docCount;
    }

    /**
     * Tokenise a string into normalised lowercase alphanumeric tokens.
     *
     * Splits on non-word characters and underscores/hyphens so that tool
     * names like `write_file` and `spawn-agent` are indexed as individual words.
     *
     * @return string[]
     */
    private function tokenise(string $text): array
    {
        $lower = mb_strtolower($text);
        // Split on whitespace, underscores, hyphens, and other non-alphanumeric chars
        $tokens = preg_split('/[\s_\-:\.\/\\\\]+/', $lower, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            $tokens !== false ? $tokens : [],
            fn(string $t): bool => strlen($t) >= 2,
        ));
    }
}
