<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Memory\MemoryEntry;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
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

        **Memory areas:**
        - `preferences` — user preferences, workflow choices, coding style, tool preferences
        - `facts` — key facts about the user, their environment, accounts, or setup
        - `solutions` — approaches that worked, bug fixes, successful configurations
        - `context` — project knowledge, architecture decisions, important codebase details

        **Best practices:**
        - Use descriptive content — "User prefers dark mode and Vim keybindings" not "dark mode"
        - Add relevant tags for discoverability — "editor, preferences, vim"
        - Search before saving to avoid duplicates
        - Update existing memories when information changes rather than creating new ones
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
            ],
            callback: function (array $input): ToolResult {
                $content = trim($input['content'] ?? '');

                if ($content === '') {
                    return ToolResult::error('Content cannot be empty.');
                }

                $entry = new MemoryEntry(
                    content: $content,
                    area: $input['area'] ?? 'facts',
                    metadata: ['tags' => $input['tags'] ?? ''],
                );

                $id = $this->memoryStore->save($entry);

                return ToolResult::success("Memory saved (id: {$id}, area: {$entry->area})");
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
            ],
            callback: function (array $input): ToolResult {
                $id = trim($input['id'] ?? '');
                $content = trim($input['content'] ?? '');

                if ($id === '' || $content === '') {
                    return ToolResult::error('Both id and content are required.');
                }

                $updated = $this->memoryStore->update(
                    id: $id,
                    content: $content,
                    area: isset($input['area']) ? $input['area'] : null,
                    tags: isset($input['tags']) ? $input['tags'] : null,
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
            ],
            callback: function (array $input): ToolResult {
                $limit = (int) ($input['limit'] ?? 20);
                $area = $input['area'] ?? null;
                $tags = $input['tags'] ?? null;

                if ($tags !== null && trim($tags) !== '') {
                    $tagList = array_map('trim', explode(',', $tags));
                    $entries = $this->memoryStore->listByTags($tagList, $limit);
                } elseif ($area !== null) {
                    $entries = $this->memoryStore->list($area, $limit);
                } else {
                    $entries = $this->memoryStore->listAll($limit);
                }

                if (empty($entries)) {
                    $filter = $area !== null ? " in area '{$area}'" : ($tags !== null ? " with tags '{$tags}'" : '');
                    return ToolResult::success("No memories found{$filter}.");
                }

                $formatted = array_map(
                    function (MemoryEntry $e): string {
                        $tags = ($e->metadata['tags'] ?? '') !== '' ? " [tags: {$e->metadata['tags']}]" : '';
                        $date = $e->createdAt?->format('Y-m-d') ?? 'unknown';
                        return "**[{$e->area}]** (id: {$e->id}, {$date}){$tags}\n{$e->content}";
                    },
                    $entries,
                );

                $total = $this->memoryStore->count();
                $header = "Showing " . count($entries) . " of {$total} total memories:";

                return ToolResult::success("{$header}\n\n" . implode("\n\n---\n\n", $formatted));
            },
        );
    }
}
