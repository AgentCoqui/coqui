<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\ArrayParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\ObjectParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\TodoStore;
use CoquiBot\Coqui\Support\JsonHelper;

/**
 * Agent-facing toolkit for managing session-scoped todo lists.
 *
 * Todos are structured checklist items that agents create during planning
 * and mark complete during implementation. They can optionally link to
 * artifacts for plan→execution traceability.
 *
 * Access level determines which tools are available:
 * - full: all tools (add, update, complete, list, get, delete)
 * - readonly/readonly-shell: add, update, list, get (plan agent needs create access)
 * - minimal: list, get only
 *
 * Tools:
 * - todo_add: Create one or many todo items (batch via items JSON array)
 * - todo_update: Update one or many todo fields (batch via updates JSON array)
 * - todo_complete: Mark one, many, or all todos as completed
 * - todo_list: List todos with optional filters
 * - todo_get: Get full details of a specific todo
 * - todo_delete: Remove one, many, or scoped todos (completed/all)
 *
 * @phpstan-type BulkTodoUpdate array{
 *     id: non-empty-string,
 *     title?: non-empty-string,
 *     status?: 'pending'|'in_progress'|'completed'|'cancelled',
 *     priority?: 'high'|'medium'|'low',
 *     notes?: string|null
 * }
 */
final class TodoToolkit implements ToolkitInterface
{
    /** @var list<'pending'|'in_progress'|'completed'|'cancelled'> */
    private const array ALLOWED_STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    /** @var list<'high'|'medium'|'low'> */
    private const array ALLOWED_PRIORITIES = ['high', 'medium', 'low'];

    public function __construct(
        private readonly TodoStore $store,
        private readonly string $sessionId,
        private readonly string $currentRole = 'orchestrator',
        private readonly string $accessLevel = 'full',
        private readonly ?ArtifactStore $artifactStore = null,
    ) {}

    public function tools(): array
    {
        // Read tools — available to all access levels
        $tools = [
            $this->listTool(),
            $this->getTool(),
        ];

        // Write tools — available to full + readonly (plan agent needs to create todos)
        if (in_array($this->accessLevel, ['full', 'readonly', 'readonly-shell'], true)) {
            $tools[] = $this->addTool();
            $tools[] = $this->updateTool();
        }

        // Complete/delete tools — only full access (coder implements and completes)
        if ($this->accessLevel === 'full') {
            $tools[] = $this->completeTool();
            $tools[] = $this->deleteTool();
        }

        return $tools;
    }

    public function guidelines(): string
    {
        $stats = $this->store->getStats($this->sessionId);
        $total = $stats['total'];

        if ($total === 0) {
            return <<<'GUIDELINES'
            <TODO-GUIDELINES>
            You can create **todos** to track tasks and progress within this session.
            Use `todo_add` to create checklist items — each todo **must** be linked to an artifact via `artifact_id`.
            Create or find a plan artifact first (`artifact_create` or `artifact_list`), then link todos to it.
            Todos support statuses: pending → in_progress → completed. Use `todo_complete` when finishing a task.
            For multi-step plans, create todos for each step so progress is visible and trackable.
            </TODO-GUIDELINES>
            GUIDELINES;
        }

        $completed = $stats['completed'];
        $inProgress = $stats['in_progress'];
        $pending = $stats['pending'];
        $cancelled = $stats['cancelled'];

        // Build progress bar
        $barWidth = 20;
        $filledWidth = $total > 0 ? (int) round(($completed / $total) * $barWidth) : 0;
        $bar = str_repeat('█', $filledWidth) . str_repeat('░', $barWidth - $filledWidth);

        // List active and pending todos for context
        $activeTodos = $this->store->list($this->sessionId, status: 'in_progress', limit: 5);
        $pendingTodos = $this->store->list($this->sessionId, status: 'pending', limit: 10);

        $lines = [];
        foreach ($activeTodos as $todo) {
            $artifactRef = $this->resolveArtifactRef($todo['artifact_id'] ?? null);
            $lines[] = sprintf('- 🔲 **[in_progress]** %s (id: %s)%s', $todo['title'], $todo['id'], $artifactRef);
        }
        foreach ($pendingTodos as $todo) {
            $artifactRef = $this->resolveArtifactRef($todo['artifact_id'] ?? null);
            $lines[] = sprintf('- ☐ %s (id: %s)%s', $todo['title'], $todo['id'], $artifactRef);
        }
        $listing = $lines !== [] ? "\n\nActive/Pending:\n" . implode("\n", $lines) : '';

        return <<<GUIDELINES
        <TODO-GUIDELINES>
        **Session Progress:** [{$bar}] {$completed}/{$total} completed
        Status: {$pending} pending, {$inProgress} in progress, {$completed} completed, {$cancelled} cancelled
        {$listing}

        Use `todo_complete` after finishing each task. Use `todo_add` to track newly discovered work.
        If the conversation was recently summarized, check `todo_list` and `artifact_list` to re-establish your context before continuing work.
        </TODO-GUIDELINES>
        GUIDELINES;
    }

    private function addTool(): ToolInterface
    {
        return new Tool(
            name: 'todo_add',
            description: 'Add one or many todo items linked to a plan artifact. For single items provide title directly. For batch creation (up to 25), provide an items JSON array instead.',
            parameters: [
                new StringParameter('title', 'Short description of the task (max 200 chars) — for single-item mode', required: false),
                new StringParameter('artifact_id', 'Artifact ID this todo belongs to (required — use artifact_list to find IDs)', required: true),
                new EnumParameter('priority', 'Task priority (single-item mode)', ['high', 'medium', 'low'], required: false),
                new StringParameter('parent_id', 'Parent todo ID for creating a subtask', required: false),
                new StringParameter('notes', 'Additional context, requirements, or implementation details', required: false),
                new StringParameter('sprint_id', 'Link to a sprint for project tracking', required: false),
                new ArrayParameter('items', 'Todo items for batch mode. Max 25 items. When provided, title is ignored.', required: false, items: new ObjectParameter('item', 'Todo item', required: true, properties: [
                    new StringParameter('title', 'Short todo title', required: true),
                    new EnumParameter('priority', 'Task priority', ['high', 'medium', 'low'], required: false),
                    new StringParameter('notes', 'Additional context', required: false),
                ])),
            ],
            callback: function (array $args): ToolResult {
                $artifactId = isset($args['artifact_id']) && trim($args['artifact_id']) !== '' ? trim($args['artifact_id']) : null;
                if ($artifactId === null) {
                    return ToolResult::error('artifact_id is required. Every todo must be linked to a plan artifact. Use artifact_list to find available artifact IDs.');
                }

                $sprintId = isset($args['sprint_id']) && trim($args['sprint_id']) !== '' ? trim($args['sprint_id']) : null;

                // Batch mode: items parameter provided
                if (array_key_exists('items', $args) && $args['items'] !== null && (!is_string($args['items']) || trim($args['items']) !== '')) {
                    $items = JsonHelper::decodeJsonList($args['items']);
                    if ($items === null || $items === []) {
                        return ToolResult::error('items must be a non-empty JSON array of [{"title": "...", ...}].');
                    }

                    return $this->executeBulkAdd($items, $artifactId, $sprintId);
                }

                // Single-item mode
                $title = trim($args['title'] ?? '');
                if ($title === '') {
                    return ToolResult::error('Title is required (or provide items for batch mode).');
                }
                if (mb_strlen($title) > 200) {
                    return ToolResult::error('Title must be 200 characters or less.');
                }

                $priority = $args['priority'] ?? 'medium';
                $parentId = isset($args['parent_id']) && trim($args['parent_id']) !== '' ? trim($args['parent_id']) : null;
                $notes = isset($args['notes']) && trim($args['notes']) !== '' ? trim($args['notes']) : null;

                $id = $this->store->create(
                    sessionId: $this->sessionId,
                    title: $title,
                    priority: $priority,
                    artifactId: $artifactId,
                    parentId: $parentId,
                    createdBy: $this->currentRole,
                    notes: $notes,
                    sprintId: $sprintId,
                );

                return ToolResult::json([
                    'id' => $id,
                    'title' => $title,
                    'status' => 'pending',
                    'priority' => $priority,
                    'artifact_id' => $artifactId,
                    'parent_id' => $parentId,
                ]);
            },
        );
    }

    /**
     * @param list<mixed> $items
     */
    private function executeBulkAdd(array $items, string $artifactId, ?string $sprintId): ToolResult
    {
        if (count($items) > 25) {
            return ToolResult::error('Maximum 25 items per call.');
        }

        foreach ($items as $i => $item) {
            if (!is_array($item) || !isset($item['title']) || trim((string) $item['title']) === '') {
                return ToolResult::error(sprintf('Item %d: title is required.', $i + 1));
            }
            if (mb_strlen(trim((string) $item['title'])) > 200) {
                return ToolResult::error(sprintf('Item %d: title must be 200 characters or less.', $i + 1));
            }
            if (isset($item['priority']) && !in_array($item['priority'], self::ALLOWED_PRIORITIES, true)) {
                return ToolResult::error(sprintf('Item %d: invalid priority "%s".', $i + 1, $item['priority']));
            }
        }

        $normalized = array_map(function (array $item): array {
            $result = [
                'title' => trim((string) $item['title']),
                'priority' => $item['priority'] ?? 'medium',
            ];
            if (isset($item['notes']) && trim((string) $item['notes']) !== '') {
                $result['notes'] = trim((string) $item['notes']);
            }

            return $result;
        }, $items);

        $ids = $this->store->bulkCreate(
            sessionId: $this->sessionId,
            items: $normalized,
            createdBy: $this->currentRole,
            artifactId: $artifactId,
            sprintId: $sprintId,
        );

        return ToolResult::json([
            'created' => count($ids),
            'ids' => $ids,
            'artifact_id' => $artifactId,
        ]);
    }

    private function updateTool(): ToolInterface
    {
        return new Tool(
            name: 'todo_update',
            description: 'Update one or many todo items. For a single item, provide id + fields. For batch updates (up to 25), provide an updates JSON array instead.',
            parameters: [
                new StringParameter('id', 'Todo ID (single-item mode)', required: false),
                new StringParameter('title', 'New title', required: false),
                new EnumParameter('priority', 'New priority', ['high', 'medium', 'low'], required: false),
                new StringParameter('notes', 'Updated notes or context', required: false),
                new EnumParameter('status', 'New status', ['pending', 'in_progress', 'cancelled'], required: false),
                new ArrayParameter('updates', 'Todo updates for batch mode. Max 25.', required: false, items: new ObjectParameter('update', 'Todo update', required: true, properties: [
                    new StringParameter('id', 'Todo ID', required: true),
                    new StringParameter('title', 'Updated title', required: false),
                    new EnumParameter('status', 'Updated status', ['pending', 'in_progress', 'completed', 'cancelled'], required: false),
                    new EnumParameter('priority', 'Updated priority', ['high', 'medium', 'low'], required: false),
                    new StringParameter('notes', 'Updated notes', required: false),
                ])),
            ],
            callback: function (array $args): ToolResult {
                // Batch mode
                if (array_key_exists('updates', $args) && $args['updates'] !== null && (!is_string($args['updates']) || trim($args['updates']) !== '')) {
                    $updates = JsonHelper::decodeJsonList($args['updates']);
                    if ($updates === null || $updates === []) {
                        return ToolResult::error('updates must be a non-empty JSON array of [{"id": "...", ...}].');
                    }

                    return $this->executeBulkUpdate($updates);
                }

                // Single-item mode
                $id = trim($args['id'] ?? '');
                if ($id === '') {
                    return ToolResult::error('Todo ID is required (or provide updates for batch mode).');
                }

                $title = isset($args['title']) && trim($args['title']) !== '' ? trim($args['title']) : null;
                $priority = isset($args['priority']) && trim($args['priority']) !== '' ? trim($args['priority']) : null;
                $notes = isset($args['notes']) ? trim($args['notes']) : null;
                $status = isset($args['status']) && trim($args['status']) !== '' ? trim($args['status']) : null;

                if ($title !== null && mb_strlen($title) > 200) {
                    return ToolResult::error('Title must be 200 characters or less.');
                }

                $updated = $this->store->update(
                    id: $id,
                    title: $title,
                    priority: $priority,
                    notes: $notes !== '' ? $notes : null,
                    status: $status,
                    sessionId: $this->sessionId,
                );

                if (!$updated) {
                    return ToolResult::error("Todo not found: {$id}");
                }

                $todo = $this->store->get($id, sessionId: $this->sessionId);

                return ToolResult::json([
                    'id' => $id,
                    'title' => $todo['title'] ?? '',
                    'status' => $todo['status'] ?? '',
                    'priority' => $todo['priority'] ?? '',
                    'updated' => true,
                ]);
            },
        );
    }

    /**
     * @param list<mixed> $updates
     */
    private function executeBulkUpdate(array $updates): ToolResult
    {
        if (count($updates) > 25) {
            return ToolResult::error('Maximum 25 items per call.');
        }

        /** @var list<BulkTodoUpdate> $typedUpdates */
        $typedUpdates = [];
        foreach ($updates as $i => $update) {
            if (!is_array($update)) {
                return ToolResult::error(sprintf('Item %d: each update must be an object.', $i + 1));
            }

            $id = trim((string) ($update['id'] ?? ''));
            if ($id === '') {
                return ToolResult::error(sprintf('Item %d: id is required.', $i + 1));
            }

            /** @var BulkTodoUpdate $typed */
            $typed = ['id' => $id];
            $fieldCount = 0;

            if (array_key_exists('title', $update)) {
                $title = trim((string) $update['title']);
                if ($title === '') {
                    return ToolResult::error(sprintf('Item %d: title cannot be empty.', $i + 1));
                }
                if (mb_strlen($title) > 200) {
                    return ToolResult::error(sprintf('Item %d: title must be 200 characters or less.', $i + 1));
                }

                /** @var non-empty-string $title */
                $typed['title'] = $title;
                $fieldCount++;
            }

            if (array_key_exists('status', $update)) {
                $status = strtolower(trim((string) $update['status']));
                if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                    return ToolResult::error(sprintf('Item %d: invalid status "%s".', $i + 1, (string) $update['status']));
                }

                /** @var 'pending'|'in_progress'|'completed'|'cancelled' $status */
                $typed['status'] = $status;
                $fieldCount++;
            }

            if (array_key_exists('priority', $update)) {
                $priority = strtolower(trim((string) $update['priority']));
                if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
                    return ToolResult::error(sprintf('Item %d: invalid priority "%s".', $i + 1, (string) $update['priority']));
                }

                /** @var 'high'|'medium'|'low' $priority */
                $typed['priority'] = $priority;
                $fieldCount++;
            }

            if (array_key_exists('notes', $update)) {
                $typed['notes'] = $update['notes'] !== null ? (string) $update['notes'] : null;
                $fieldCount++;
            }

            if ($fieldCount === 0) {
                return ToolResult::error(sprintf('Item %d: provide at least one mutable field.', $i + 1));
            }

            $typedUpdates[] = $typed;
        }

        $count = $this->store->bulkUpdate($typedUpdates, sessionId: $this->sessionId);
        $stats = $this->store->getStats($this->sessionId);

        return ToolResult::json([
            'updated' => $count,
            'total_requested' => count($updates),
            'progress' => "{$stats['completed']}/{$stats['total']} completed",
        ]);
    }

    private function completeTool(): ToolInterface
    {
        return new Tool(
            name: 'todo_complete',
            description: 'Mark one, many, or all todos as completed. Provide id for single, ids JSON array for batch (max 25), or all=true for every pending/in-progress todo in the session.',
            parameters: [
                new StringParameter('id', 'Todo ID to mark as completed (single-item mode)', required: false),
                new ArrayParameter('ids', 'Todo IDs for batch mode. Max 25.', required: false, items: new StringParameter('id', 'Todo ID', required: true)),
                new BoolParameter('all', 'If true, complete all pending/in-progress todos in the session.', required: false),
                new StringParameter('notes', 'Completion notes (what was done, any follow-ups)', required: false),
            ],
            callback: function (array $args): ToolResult {
                $notes = isset($args['notes']) && trim($args['notes']) !== '' ? trim($args['notes']) : null;
                $all = (bool) ($args['all'] ?? false);

                // All mode
                if ($all) {
                    $completed = $this->store->completeAllBySession(
                        sessionId: $this->sessionId,
                        completedBy: $this->currentRole,
                    );
                    $stats = $this->store->getStats($this->sessionId);

                    return ToolResult::json([
                        'completed' => $completed,
                        'progress' => "{$stats['completed']}/{$stats['total']} completed",
                    ]);
                }

                // Batch mode
                if (array_key_exists('ids', $args) && $args['ids'] !== null && (!is_string($args['ids']) || trim($args['ids']) !== '')) {
                    $ids = JsonHelper::decodeJsonList($args['ids']);
                    if ($ids === null || $ids === []) {
                        return ToolResult::error('ids must be a non-empty JSON array of todo IDs.');
                    }
                    if (count($ids) > 25) {
                        return ToolResult::error('Maximum 25 items per call.');
                    }

                    $completed = 0;
                    $failed = [];

                    foreach ($ids as $id) {
                        $id = trim((string) $id);
                        if ($id === '') {
                            continue;
                        }

                        $result = $this->store->complete(
                            id: $id,
                            completedBy: $this->currentRole,
                            notes: $notes,
                            sessionId: $this->sessionId,
                        );

                        if ($result) {
                            $completed++;
                        } else {
                            $failed[] = $id;
                        }
                    }

                    $stats = $this->store->getStats($this->sessionId);

                    $response = [
                        'completed' => $completed,
                        'total_requested' => count($ids),
                        'progress' => "{$stats['completed']}/{$stats['total']} completed",
                    ];
                    if ($failed !== []) {
                        $response['failed_ids'] = $failed;
                    }

                    return ToolResult::json($response);
                }

                // Single-item mode
                $id = trim($args['id'] ?? '');
                if ($id === '') {
                    return ToolResult::error('Provide id (single), ids (batch), or all=true.');
                }

                $completed = $this->store->complete(
                    id: $id,
                    completedBy: $this->currentRole,
                    notes: $notes,
                    sessionId: $this->sessionId,
                );

                if (!$completed) {
                    return ToolResult::error("Todo not found: {$id}");
                }

                $todo = $this->store->get($id, sessionId: $this->sessionId);
                $stats = $this->store->getStats($this->sessionId);

                return ToolResult::json([
                    'id' => $id,
                    'title' => $todo['title'] ?? '',
                    'status' => 'completed',
                    'completed_by' => $this->currentRole,
                    'progress' => "{$stats['completed']}/{$stats['total']} completed",
                ]);
            },
        );
    }

    private function listTool(): ToolInterface
    {
        return new Tool(
            name: 'todo_list',
            description: 'List todos in the current session. Returns a formatted checklist with status indicators. Filter by artifact, status, or priority.',
            parameters: [
                new StringParameter('artifact_id', 'Filter by artifact ID', required: false),
                new EnumParameter('status', 'Filter by status', ['pending', 'in_progress', 'completed', 'cancelled'], required: false),
                new EnumParameter('priority', 'Filter by priority', ['high', 'medium', 'low'], required: false),
                new BoolParameter('include_completed', 'Include completed todos (default: true)', required: false),
            ],
            callback: function (array $args): ToolResult {
                $artifactId = isset($args['artifact_id']) && trim($args['artifact_id']) !== '' ? trim($args['artifact_id']) : null;
                $status = isset($args['status']) && trim($args['status']) !== '' ? trim($args['status']) : null;
                $priority = isset($args['priority']) && trim($args['priority']) !== '' ? trim($args['priority']) : null;
                $includeCompleted = $args['include_completed'] ?? true;

                $todos = $this->store->list(
                    sessionId: $this->sessionId,
                    artifactId: $artifactId,
                    status: $status,
                    priority: $priority,
                    includeCompleted: (bool) $includeCompleted,
                );

                if ($todos === []) {
                    return ToolResult::success('No todos found.');
                }

                // Build checklist format with subtasks
                $lines = [];
                foreach ($todos as $todo) {
                    $icon = match ($todo['status']) {
                        'completed' => '✅',
                        'in_progress' => '🔲',
                        'cancelled' => '❌',
                        default => '☐',
                    };
                    $priorityTag = $todo['priority'] === 'high' ? ' ⚡' : ($todo['priority'] === 'low' ? ' ↓' : '');
                    $lines[] = sprintf(
                        '%s %s%s (id: %s)',
                        $icon,
                        $todo['title'],
                        $priorityTag,
                        $todo['id'],
                    );

                    // Include subtasks
                    $subtasks = $this->store->getSubtasks($todo['id'], sessionId: $this->sessionId);
                    foreach ($subtasks as $sub) {
                        $subIcon = match ($sub['status']) {
                            'completed' => '✅',
                            'in_progress' => '🔲',
                            'cancelled' => '❌',
                            default => '☐',
                        };
                        $lines[] = sprintf(
                            '  %s %s (id: %s)',
                            $subIcon,
                            $sub['title'],
                            $sub['id'],
                        );
                    }
                }

                $stats = $this->store->getStats($this->sessionId, $artifactId);
                $checklist = implode("\n", $lines);

                return ToolResult::json([
                    'checklist' => $checklist,
                    'stats' => $stats,
                ]);
            },
        );
    }

    private function getTool(): ToolInterface
    {
        return new Tool(
            name: 'todo_get',
            description: 'Get full details of a specific todo item, including notes, timestamps, and subtasks.',
            parameters: [
                new StringParameter('id', 'Todo ID', required: true),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');
                if ($id === '') {
                    return ToolResult::error('Todo ID is required.');
                }

                $todo = $this->store->get($id, sessionId: $this->sessionId);
                if ($todo === null) {
                    return ToolResult::error("Todo not found: {$id}");
                }

                // Include subtasks if any
                $subtasks = $this->store->getSubtasks($id, sessionId: $this->sessionId);
                if ($subtasks !== []) {
                    $todo['subtasks'] = $subtasks;
                }

                return ToolResult::json($todo);
            },
        );
    }

    private function deleteTool(): ToolInterface
    {
        return new Tool(
            name: 'todo_delete',
            description: 'Delete one or many todos, or clear by scope. Provide id for single, ids JSON array for batch (max 25), or scope to delete completed/all todos in the session. Prefer cancelling over deleting.',
            parameters: [
                new StringParameter('id', 'Todo ID to delete (single-item mode)', required: false),
                new ArrayParameter('ids', 'Todo IDs for batch mode. Max 25.', required: false, items: new StringParameter('id', 'Todo ID', required: true)),
                new EnumParameter('scope', 'Delete by scope: "completed" removes completed/cancelled, "all" wipes entire session list', ['completed', 'all'], required: false),
            ],
            callback: function (array $args): ToolResult {
                // Scope mode (clear)
                $scope = isset($args['scope']) && trim((string) $args['scope']) !== '' ? trim((string) $args['scope']) : null;
                if ($scope !== null) {
                    $deleted = $scope === 'all'
                        ? $this->store->deleteBySession($this->sessionId)
                        : $this->store->deleteCompletedBySession($this->sessionId);

                    $stats = $this->store->getStats($this->sessionId);

                    return ToolResult::json([
                        'scope' => $scope,
                        'deleted' => $deleted,
                        'remaining' => $stats['total'],
                    ]);
                }

                // Batch mode
                if (array_key_exists('ids', $args) && $args['ids'] !== null && (!is_string($args['ids']) || trim($args['ids']) !== '')) {
                    $ids = JsonHelper::decodeJsonList($args['ids']);
                    if ($ids === null || $ids === []) {
                        return ToolResult::error('ids must be a non-empty JSON array of todo IDs.');
                    }
                    if (count($ids) > 25) {
                        return ToolResult::error('Maximum 25 items per call.');
                    }

                    $deleted = 0;
                    $failed = [];

                    foreach ($ids as $id) {
                        $id = trim((string) $id);
                        if ($id === '') {
                            continue;
                        }

                        $result = $this->store->delete($id, sessionId: $this->sessionId);

                        if ($result) {
                            $deleted++;
                        } else {
                            $failed[] = $id;
                        }
                    }

                    $response = [
                        'deleted' => $deleted,
                        'total_requested' => count($ids),
                    ];
                    if ($failed !== []) {
                        $response['failed_ids'] = $failed;
                    }

                    return ToolResult::json($response);
                }

                // Single-item mode
                $id = trim($args['id'] ?? '');
                if ($id === '') {
                    return ToolResult::error('Provide id (single), ids (batch), or scope (completed/all).');
                }

                $todo = $this->store->get($id, sessionId: $this->sessionId);
                if ($todo === null) {
                    return ToolResult::error("Todo not found: {$id}");
                }

                $deleted = $this->store->delete($id, sessionId: $this->sessionId);
                if (!$deleted) {
                    return ToolResult::error("Failed to delete todo {$id}");
                }

                return ToolResult::json([
                    'id' => $id,
                    'title' => $todo['title'],
                    'deleted' => true,
                ]);
            },
        );
    }

    /**
     * Resolve artifact title for cross-reference display in guidelines.
     */
    private function resolveArtifactRef(?string $artifactId): string
    {
        if ($artifactId === null || $artifactId === '' || $this->artifactStore === null) {
            return '';
        }

        try {
            $artifact = $this->artifactStore->get($artifactId, sessionId: $this->sessionId);
            if ($artifact !== null) {
                $title = $artifact['title'] ?? 'Untitled';
                return " → artifact: {$title} (id: {$artifactId})";
            }
        } catch (\Throwable) {
            // Non-critical
        }

        return '';
    }
}
