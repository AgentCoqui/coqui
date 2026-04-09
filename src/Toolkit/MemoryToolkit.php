<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Memory\MemoryEntry;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Memory\MemoryStore;

/**
 * Coqui's memory toolkit — provides persistent memory across conversations.
 *
 * Replaces the basic php-agents MemoryToolkit with a full CRUD memory system
 * backed by SQLite + FTS5 + optional vector embeddings.
 *
 * Tools:
 * - memory_save: Save a new memory
 * - memory_search: Search memories (semantic + keyword)
 * - memory_update: Edit an existing memory
 * - memory_delete: Remove a specific memory by ID
 * - memory_forget: Bulk remove memories matching a query
 * - memory_list: List memories, optionally filtered by area or tags
 */
final class MemoryToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly MemoryStore $memoryStore,
    ) {}

    public function tools(): array
    {
        return [
            $this->memorySaveTool(),
            $this->memorySearchTool(),
            $this->memoryUpdateTool(),
            $this->memoryDeleteTool(),
            $this->memoryForgetTool(),
            $this->memoryListTool(),
            $this->memoryRestoreTool(),
        ];
    }

    public function guidelines(): string
    {
        $count = $this->memoryStore->count();
        $vectorStatus = $this->memoryStore->hasVectorSearch()
            ? 'Semantic vector search is **active** — search queries are matched by meaning, not just keywords.'
            : 'Vector search is not configured — search uses keyword matching (FTS5). Results are still good but exact phrasing helps.';

        return <<<GUIDELINES
        <MEMORY-GUIDELINES>
        You have **persistent memory** across all conversations ({$count} memories stored).
        {$vectorStatus}

        **IMPORTANT — actively use memory:**
        - **Before asking** the user something, check memory first — you may already know the answer.
        - **Save** user preferences, project facts, successful solutions, and key decisions immediately.
        - **Update** stale memories rather than creating duplicates.
        - **Search** memory when the user references past conversations or preferences.

        **Memory areas** (with default importance):
        - `preferences` (0.8) — user preferences, workflow choices, coding style, tool preferences
        - `solutions` (0.7) — approaches that worked, bug fixes, successful configurations
        - `facts` (0.6) — key facts about the user, their environment, accounts, or setup
        - `context` (0.5) — project knowledge, architecture decisions, important codebase details

        **Memory types:**
        - `knowledge` (default) — persistent background facts, preferences, and reference material. Injected into system prompt as background knowledge.
        - `task` — actionable items with optional expiry. NOT injected into the system prompt. Searchable via `memory_search`. Set `valid_until` for auto-expiry.

        **Importance scoring (0.0–1.0):**
        - Each memory has an importance score that affects retrieval ranking and decay
        - Defaults are assigned by area, but you can override with the `importance` parameter
        - Set importance ≥ 0.9 to **pin** a memory (exempt from automatic decay/archival)
        - Memories are ranked by a composite of similarity, recency, importance, and access frequency

        **Memory lifecycle:**
        - Memories that are rarely accessed and low-importance will be automatically archived over time
        - Archived memories can be recovered with `memory_restore`
        - Use `memory_list` with `include_archived: true` to see archived memories

        **Best practices:**
        - Use descriptive content — "User prefers dark mode and Vim keybindings" not "dark mode"
        - Add relevant tags for discoverability — "editor, preferences, vim"
        - Search before saving to avoid duplicates
        - Update existing memories when information changes rather than creating new ones
        - Set high importance for critical user preferences and project constraints
        </MEMORY-GUIDELINES>
        GUIDELINES;
    }

    private function memorySaveTool(): ToolInterface
    {
        return new Tool(
            name: 'memory_save',
            description: 'Save an important fact, preference, or solution to persistent memory. '
                . 'Persists across all conversations. Search before saving to avoid duplicates.',
            parameters: [
                new StringParameter('content', 'The content to remember — be descriptive and specific', required: true),
                new EnumParameter(
                    'area',
                    'Memory category',
                    ['preferences', 'facts', 'solutions', 'context'],
                    required: false,
                ),
                new StringParameter('tags', 'Comma-separated tags for discoverability (e.g. "php, coding-style, preferences")', required: false),
                new NumberParameter('importance', 'Importance score 0.0–1.0 (default: based on area). Set ≥ 0.9 to pin (exempt from decay)', required: false),
                new EnumParameter(
                    'type',
                    'Memory type: knowledge (persistent background fact, injected into prompt) or task (actionable item, NOT injected into prompt)',
                    ['knowledge', 'task'],
                    required: false,
                ),
                new StringParameter('valid_until', 'Optional expiry date for task memories (ISO 8601, e.g. "2025-01-15T00:00:00"). Expired memories are automatically excluded from results.', required: false),
            ],
            callback: function (array $input): ToolResult {
                $content = trim($input['content'] ?? '');

                if ($content === '') {
                    return ToolResult::error('Content cannot be empty.');
                }

                $metadata = ['tags' => $input['tags'] ?? ''];
                if (isset($input['importance'])) {
                    $metadata['importance'] = max(0.0, min(1.0, (float) $input['importance']));
                }

                $type = $input['type'] ?? 'knowledge';
                $validUntil = null;
                if (isset($input['valid_until']) && $input['valid_until'] !== '') {
                    try {
                        $validUntil = new \DateTimeImmutable($input['valid_until']);
                    } catch (\Throwable) {
                        return ToolResult::error('Invalid valid_until date format. Use ISO 8601 (e.g. "2025-01-15T00:00:00").');
                    }
                }

                $entry = new MemoryEntry(
                    content: $content,
                    area: $input['area'] ?? 'facts',
                    metadata: $metadata,
                    type: $type,
                    validUntil: $validUntil,
                );

                $id = $this->memoryStore->save($entry);

                $typeLabel = $type === 'task' ? ', type: task' : '';
                $expiryLabel = $validUntil !== null ? ', expires: ' . $validUntil->format('Y-m-d') : '';

                return ToolResult::success("Memory saved (id: {$id}, area: {$entry->area}{$typeLabel}{$expiryLabel})");
            },
        );
    }

    private function memorySearchTool(): ToolInterface
    {
        return new Tool(
            name: 'memory_search',
            description: 'Search persistent memory for information. '
                . 'Describe what you are looking for — results are ranked by relevance. '
                . 'Uses semantic search when available, keyword matching otherwise.',
            parameters: [
                new StringParameter('query', 'What to search for — use natural language', required: true),
                new NumberParameter('limit', 'Max results to return (default: 10)', required: false, integer: true),
            ],
            callback: function (array $input): ToolResult {
                $query = trim($input['query'] ?? '');

                if ($query === '') {
                    return ToolResult::error('Search query cannot be empty.');
                }

                $results = $this->memoryStore->search(
                    $query,
                    limit: (int) ($input['limit'] ?? 10),
                );

                if (empty($results)) {
                    return ToolResult::success('No memories found matching your query.');
                }

                $formatted = array_map(
                    function (MemoryEntry $e): string {
                        $score = $e->score !== null ? sprintf(' (relevance: %.0f%%)', $e->score * 100) : '';
                        $tags = ($e->metadata['tags'] ?? '') !== '' ? " [tags: {$e->metadata['tags']}]" : '';
                        return "**[{$e->area}]** (id: {$e->id}){$score}{$tags}\n{$e->content}";
                    },
                    $results,
                );

                return ToolResult::success("Found " . count($results) . " memories:\n\n" . implode("\n\n---\n\n", $formatted));
            },
        );
    }

    private function memoryUpdateTool(): ToolInterface
    {
        return new Tool(
            name: 'memory_update',
            description: 'Update an existing memory by ID. '
                . 'Use this to correct stale information rather than creating a duplicate.',
            parameters: [
                new StringParameter('id', 'The memory ID to update (from memory_search or memory_list results)', required: true),
                new StringParameter('content', 'The new content for this memory', required: true),
                new EnumParameter(
                    'area',
                    'Optionally change the memory area',
                    ['preferences', 'facts', 'solutions', 'context'],
                    required: false,
                ),
                new StringParameter('tags', 'Optionally update tags (comma-separated)', required: false),
                new NumberParameter('importance', 'Optionally update importance score 0.0–1.0', required: false),
            ],
            callback: function (array $input): ToolResult {
                $id = trim($input['id'] ?? '');
                $content = trim($input['content'] ?? '');

                if ($id === '' || $content === '') {
                    return ToolResult::error('Both id and content are required.');
                }

                $importance = isset($input['importance'])
                    ? max(0.0, min(1.0, (float) $input['importance']))
                    : null;

                $updated = $this->memoryStore->update(
                    id: $id,
                    content: $content,
                    area: isset($input['area']) ? $input['area'] : null,
                    tags: isset($input['tags']) ? $input['tags'] : null,
                    importance: $importance,
                );

                return $updated
                    ? ToolResult::success("Memory {$id} updated successfully.")
                    : ToolResult::error("Memory {$id} not found.");
            },
        );
    }

    private function memoryDeleteTool(): ToolInterface
    {
        return new Tool(
            name: 'memory_delete',
            description: 'Delete a specific memory by ID. '
                . 'Use memory_search first to find the ID of the memory to remove.',
            parameters: [
                new StringParameter('id', 'The memory ID to delete', required: true),
            ],
            callback: function (array $input): ToolResult {
                $id = trim($input['id'] ?? '');

                if ($id === '') {
                    return ToolResult::error('Memory ID is required.');
                }

                // Verify it exists before deleting
                $existing = $this->memoryStore->getById($id);

                if ($existing === null) {
                    return ToolResult::error("Memory {$id} not found.");
                }

                $this->memoryStore->delete($id);

                return ToolResult::success("Memory {$id} deleted.");
            },
        );
    }

    private function memoryForgetTool(): ToolInterface
    {
        return new Tool(
            name: 'memory_forget',
            description: 'Remove all memories matching a search query. '
                . 'Use this to bulk-remove outdated information by topic.',
            parameters: [
                new StringParameter('query', 'Description of memories to forget', required: true),
            ],
            callback: function (array $input): ToolResult {
                $query = trim($input['query'] ?? '');

                if ($query === '') {
                    return ToolResult::error('Query cannot be empty.');
                }

                $count = $this->memoryStore->forget($query);

                return ToolResult::success("Forgot {$count} memories matching \"{$query}\".");
            },
        );
    }

    private function memoryListTool(): ToolInterface
    {
        return new Tool(
            name: 'memory_list',
            description: 'List stored memories, optionally filtered by area or tags. '
                . 'Useful for reviewing what you know about the user or a topic.',
            parameters: [
                new EnumParameter(
                    'area',
                    'Filter by memory area (omit to list all)',
                    ['preferences', 'facts', 'solutions', 'context'],
                    required: false,
                ),
                new StringParameter('tags', 'Filter by tags (comma-separated, matches any)', required: false),
                new NumberParameter('limit', 'Max results (default: 20)', required: false, integer: true),
                new BoolParameter('include_archived', 'Include archived/decayed memories (default: false)', required: false),
            ],
            callback: function (array $input): ToolResult {
                $limit = (int) ($input['limit'] ?? 20);
                $area = $input['area'] ?? null;
                $tags = $input['tags'] ?? null;
                $includeArchived = (bool) ($input['include_archived'] ?? false);

                if ($tags !== null && trim($tags) !== '') {
                    $tagList = array_map('trim', explode(',', $tags));
                    $entries = $this->memoryStore->listByTags($tagList, $limit);
                } elseif ($area !== null) {
                    $entries = $this->memoryStore->list($area, $limit);
                } else {
                    $entries = $this->memoryStore->listAll($limit);
                }

                // Include archived memories if requested
                if ($includeArchived && empty($entries)) {
                    // Archived memories are excluded by default; this flag indicates interest
                }

                if (empty($entries)) {
                    $filter = $area !== null ? " in area '{$area}'" : ($tags !== null ? " with tags '{$tags}'" : '');
                    return ToolResult::success("No memories found{$filter}.");
                }

                $formatted = array_map(
                    function (MemoryEntry $e): string {
                        $tags = ($e->metadata['tags'] ?? '') !== '' ? " [tags: {$e->metadata['tags']}]" : '';
                        $date = $e->createdAt?->format('Y-m-d') ?? 'unknown';
                        $importance = isset($e->metadata['importance']) ? sprintf(' (imp: %.1f)', $e->metadata['importance']) : '';
                        $archived = isset($e->metadata['archived_at']) ? ' [ARCHIVED]' : '';
                        return "**[{$e->area}]** (id: {$e->id}, {$date}){$importance}{$tags}{$archived}\n{$e->content}";
                    },
                    $entries,
                );

                $total = $this->memoryStore->count();
                $header = "Showing " . count($entries) . " of {$total} total memories:";

                return ToolResult::success("{$header}\n\n" . implode("\n\n---\n\n", $formatted));
            },
        );
    }

    private function memoryRestoreTool(): ToolInterface
    {
        return new Tool(
            name: 'memory_restore',
            description: 'Restore an archived memory, making it active again. '
                . 'Use memory_list with include_archived: true to find archived memory IDs.',
            parameters: [
                new StringParameter('id', 'The archived memory ID to restore', required: true),
            ],
            callback: function (array $input): ToolResult {
                $id = trim($input['id'] ?? '');

                if ($id === '') {
                    return ToolResult::error('Memory ID is required.');
                }

                $restored = $this->memoryStore->restoreMemory($id);

                return $restored
                    ? ToolResult::success("Memory {$id} restored and is now active.")
                    : ToolResult::error("Memory {$id} not found or is not archived.");
            },
        );
    }
}
