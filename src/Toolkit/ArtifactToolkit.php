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
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Agent\PlanTodoGenerator;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\TodoStore;
use CoquiBot\Coqui\Support\JsonHelper;

/**
 * Agent-facing toolkit for managing structured artifacts.
 *
 * Artifacts are versioned, staged outputs (code, documents, configs)
 * that persist across turns within a session. The toolkit provides
 * CRUD operations plus version history and stage transitions.
 *
 * Tools:
 * - artifact_create: Create a new artifact
 * - artifact_update: Update content (auto-versions)
 * - artifact_get: Retrieve an artifact by ID
 * - artifact_list: List session artifacts with optional filters
 * - artifact_stage: Transition one or many artifacts to a new stage
 * - artifact_delete: Delete one or many artifacts (irreversible)
 */
final class ArtifactToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly ArtifactStore $store,
        private readonly string $sessionId,
        private readonly bool $readOnly = false,
        private readonly ?PlanTodoGenerator $planTodoGenerator = null,
        private readonly ?TodoStore $todoStore = null,
        private readonly ?string $defaultProjectId = null,
        private readonly ?string $defaultSprintId = null,
    ) {}

    public function tools(): array
    {
        $tools = [
            $this->createTool(),
            $this->updateTool(),
            $this->getTool(),
            $this->listTool(),
            $this->stageTool(),
        ];

        if (!$this->readOnly) {
            $tools[] = $this->deleteTool();
        }

        return $tools;
    }

    public function guidelines(): string
    {
        $artifacts = $this->store->list($this->sessionId, limit: 10);
        $count = count($artifacts);

        if ($count === 0) {
            return <<<'GUIDELINES'
            <ARTIFACT-GUIDELINES>
            You can create **artifacts** to track structured outputs (code files, documents, configs).
            Artifacts are versioned automatically on each update and support staging: draft → review → final.
            Use `artifact_create` when producing significant code or content that the user may want to iterate on.
            </ARTIFACT-GUIDELINES>
            GUIDELINES;
        }

        $lines = [];
        foreach ($artifacts as $a) {
            $todoRef = $this->resolveTodoCount($a['id'], $a['type'] ?? '');
            $lines[] = sprintf(
                '- **%s** (id: %s) [%s, %s] v%d%s',
                $a['title'],
                $a['id'],
                $a['type'],
                $a['stage'],
                $a['version'],
                $todoRef,
            );
        }
        $listing = implode("\n", $lines);

        return <<<GUIDELINES
        <ARTIFACT-GUIDELINES>
        This session has **{$count} artifact(s)**. Use `artifact_get` to retrieve content, `artifact_update` to revise.
        Artifacts are versioned automatically — each update creates a snapshot. Stages: draft → review → final.

        Current artifacts:
        {$listing}
        </ARTIFACT-GUIDELINES>
        GUIDELINES;
    }

    private function createTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_create',
            description: 'Create a new versioned artifact (code, document, config). Returns the artifact ID for future reference.',
            parameters: [
                new StringParameter('title', 'Short descriptive title for the artifact', required: true),
                new StringParameter('content', 'The full content of the artifact', required: true),
                new EnumParameter('type', 'Artifact type', ['code', 'document', 'config', 'plan', 'data', 'loop_output', 'sketch', 'hypothesis', 'other'], required: false),
                new StringParameter('language', 'Programming language (for code artifacts, e.g. php, python, javascript)', required: false),
                new StringParameter('filepath', 'Intended file path relative to workspace (e.g. src/MyClass.php)', required: false),
                new StringParameter('project_id', 'Link artifact to a project (makes it persistent across sessions)', required: false),
                new StringParameter('sprint_id', 'Link artifact to a sprint', required: false),
            ],
            callback: function (array $args): ToolResult {
                $title = trim($args['title'] ?? '');
                $content = $args['content'] ?? '';
                $type = $args['type'] ?? 'code';
                $language = isset($args['language']) ? trim($args['language']) : null;
                $filepath = isset($args['filepath']) ? trim($args['filepath']) : null;
                $projectId = isset($args['project_id']) && trim($args['project_id']) !== '' ? trim($args['project_id']) : $this->defaultProjectId;
                $sprintId = isset($args['sprint_id']) && trim($args['sprint_id']) !== '' ? trim($args['sprint_id']) : $this->defaultSprintId;

                if ($title === '') {
                    return ToolResult::error('Title is required.');
                }

                $id = $this->store->create(
                    sessionId: $this->sessionId,
                    title: $title,
                    content: $content,
                    type: $type,
                    language: $language,
                    filepath: $filepath,
                    projectId: $projectId,
                    sprintId: $sprintId,
                );

                return ToolResult::success(json_encode([
                    'id' => $id,
                    'title' => $title,
                    'type' => $type,
                    'stage' => 'draft',
                    'version' => 1,
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    private function updateTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_update',
            description: 'Update an artifact\'s content. Automatically creates a new version snapshot.',
            parameters: [
                new StringParameter('id', 'Artifact ID (from artifact_create or artifact_list)', required: true),
                new StringParameter('content', 'The updated full content', required: true),
                new StringParameter('change_summary', 'Brief description of what changed', required: false),
                new StringParameter('title', 'New title (if renaming)', required: false),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');
                $content = $args['content'] ?? '';

                if ($id === '') {
                    return ToolResult::error('Artifact ID is required.');
                }

                $updated = $this->store->update(
                    id: $id,
                    content: $content,
                    changeSummary: isset($args['change_summary']) ? trim($args['change_summary']) : null,
                    title: isset($args['title']) ? trim($args['title']) : null,
                    sessionId: $this->sessionId,
                );

                if (!$updated) {
                    return ToolResult::error("Artifact not found: {$id}");
                }

                $artifact = $this->store->get($id, sessionId: $this->sessionId);

                return ToolResult::success(json_encode([
                    'id' => $id,
                    'title' => $artifact['title'] ?? '',
                    'version' => $artifact['version'] ?? 0,
                    'stage' => $artifact['stage'] ?? '',
                    'updated' => true,
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    private function getTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_get',
            description: 'Retrieve an artifact by ID, including its content and metadata. Optionally retrieve a specific version.',
            parameters: [
                new StringParameter('id', 'Artifact ID', required: true),
                new NumberParameter('version', 'Specific version number to retrieve (omit for latest)', required: false),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');

                if ($id === '') {
                    return ToolResult::error('Artifact ID is required.');
                }

                // If a specific version is requested, fetch from version history
                if (isset($args['version'])) {
                    $version = (int) $args['version'];
                    $versionData = $this->store->getVersion($id, $version);
                    if ($versionData === null) {
                        return ToolResult::error("Version {$version} not found for artifact {$id}");
                    }

                    $artifact = $this->store->get($id, sessionId: $this->sessionId);
                    return ToolResult::success(json_encode([
                        'id' => $id,
                        'title' => $artifact['title'] ?? '',
                        'version' => $version,
                        'content' => $versionData['content'],
                        'change_summary' => $versionData['change_summary'],
                        'created_at' => $versionData['created_at'],
                    ], JSON_UNESCAPED_SLASHES) ?: '{}');
                }

                $artifact = $this->store->get($id, sessionId: $this->sessionId);
                if ($artifact === null) {
                    return ToolResult::error("Artifact not found: {$id}");
                }

                return ToolResult::success(json_encode($artifact, JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    private function listTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_list',
            description: 'List artifacts in the current session, optionally filtered by type, stage, project, sprint, or creation time.',
            parameters: [
                new EnumParameter('type', 'Filter by artifact type', ['code', 'document', 'config', 'plan', 'data', 'loop_output', 'sketch', 'hypothesis', 'other'], required: false),
                new EnumParameter('stage', 'Filter by stage', ['draft', 'review', 'final'], required: false),
                new StringParameter('project_id', 'Filter by project ID — useful in loop/sprint contexts to see only relevant artifacts', required: false),
                new StringParameter('sprint_id', 'Filter by sprint ID', required: false),
                new StringParameter('created_after', 'Only return artifacts created after this ISO 8601 timestamp (e.g. 2026-04-03T12:00:00Z)', required: false),
            ],
            callback: function (array $args): ToolResult {
                $type = isset($args['type']) ? trim($args['type']) : null;
                $stage = isset($args['stage']) ? trim($args['stage']) : null;
                $projectId = isset($args['project_id']) && trim($args['project_id']) !== '' ? trim($args['project_id']) : null;
                $sprintId = isset($args['sprint_id']) && trim($args['sprint_id']) !== '' ? trim($args['sprint_id']) : null;
                $createdAfter = isset($args['created_after']) && trim($args['created_after']) !== '' ? trim($args['created_after']) : null;

                $artifacts = $this->store->list(
                    sessionId: $this->sessionId,
                    type: $type !== '' ? $type : null,
                    stage: $stage !== '' ? $stage : null,
                    projectId: $projectId,
                    sprintId: $sprintId,
                    createdAfter: $createdAfter,
                );

                if ($artifacts === []) {
                    return ToolResult::success('No artifacts found matching the given filters.');
                }

                $summary = array_map(fn(array $a) => [
                    'id' => $a['id'],
                    'title' => $a['title'],
                    'type' => $a['type'],
                    'stage' => $a['stage'],
                    'version' => $a['version'],
                    'language' => $a['language'],
                    'filepath' => $a['filepath'],
                    'storage_mode' => $a['storage_mode'] ?? 'database',
                    'canonical_path' => $a['canonical_path'] ?? null,
                    'project_id' => $a['project_id'] ?? null,
                    'sprint_id' => $a['sprint_id'] ?? null,
                    'updated_at' => $a['updated_at'],
                    'created_at' => $a['created_at'],
                ], $artifacts);

                return ToolResult::success(json_encode([
                    'count' => count($summary),
                    'artifacts' => $summary,
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    private function stageTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_stage',
            description: 'Transition one or many artifacts to a new stage: draft → review → final. Provide id for single mode, or ids/all/filters for bulk mode.',
            parameters: [
                new StringParameter('id', 'Artifact ID for single-artifact mode', required: false),
                new ArrayParameter('ids', 'Artifact IDs for bulk mode. Max 200.', required: false, items: new StringParameter('id', 'Artifact ID', required: true)),
                new EnumParameter('stage', 'Target stage', ['draft', 'review', 'final'], required: true),
                new EnumParameter('current_stage', 'Filter by current stage (bulk mode)', ['draft', 'review', 'final'], required: false),
                new EnumParameter('type', 'Filter by artifact type (bulk mode)', ['code', 'document', 'config', 'plan', 'data', 'loop_output', 'sketch', 'hypothesis', 'other'], required: false),
                new StringParameter('project_id', 'Filter by project ID (bulk mode)', required: false),
                new StringParameter('sprint_id', 'Filter by sprint ID (bulk mode)', required: false),
                new StringParameter('created_after', 'ISO 8601 creation-time filter (bulk mode)', required: false),
                new BoolParameter('all', 'If true, target all artifacts in the current session.', required: false),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');
                $stage = trim($args['stage'] ?? '');

                if ($stage === '') {
                    return ToolResult::error('stage is required.');
                }

                // Single-artifact mode
                if ($id !== '') {
                    $artifact = $this->store->get($id, sessionId: $this->sessionId);
                    if ($artifact === null) {
                        return ToolResult::error("Artifact not found: {$id}");
                    }

                    $updated = $this->store->updateStage($id, $stage, sessionId: $this->sessionId);
                    if (!$updated) {
                        return ToolResult::error("Failed to update stage for artifact {$id}");
                    }

                    $response = [
                        'id' => $id,
                        'title' => $artifact['title'],
                        'previous_stage' => $artifact['stage'],
                        'new_stage' => $stage,
                    ];

                    // Auto-generate todos from finalized plan artifacts
                    if ($stage === 'final' && $artifact['type'] === 'plan' && $this->planTodoGenerator !== null) {
                        $todoIds = $this->planTodoGenerator->generate(
                            artifactId: $id,
                            sessionId: $this->sessionId,
                            planContent: $artifact['content'] ?? '',
                        );
                        $response['todos_generated'] = count($todoIds);
                        if ($todoIds === []) {
                            $response['todos_note'] = 'Auto-generation failed or extracted no steps. Use todo_add to create todos manually.';
                        }
                    }

                    return ToolResult::success(json_encode($response, JSON_UNESCAPED_SLASHES) ?: '{}');
                }

                // Bulk mode
                [$matchedIds, $failedIds, $error] = $this->resolveArtifactTargets($args, stageFilterKey: 'current_stage');

                if ($error !== null) {
                    return ToolResult::error($error);
                }

                if ($matchedIds === []) {
                    return ToolResult::success(json_encode([
                        'updated' => 0,
                        'target_stage' => $stage,
                        'failed_ids' => $failedIds,
                    ], JSON_UNESCAPED_SLASHES) ?: '{}');
                }

                $updated = $this->store->bulkUpdateStage($matchedIds, $stage, $this->sessionId);

                return ToolResult::success(json_encode([
                    'updated' => $updated,
                    'target_stage' => $stage,
                    'failed_ids' => $failedIds,
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    private function deleteTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_delete',
            description: 'Delete one or many artifacts and their version history. Provide id for single mode, or ids/all/filters for bulk mode. Irreversible.',
            parameters: [
                new StringParameter('id', 'Artifact ID for single-artifact mode', required: false),
                new ArrayParameter('ids', 'Artifact IDs for bulk mode. Max 200.', required: false, items: new StringParameter('id', 'Artifact ID', required: true)),
                new EnumParameter('type', 'Filter by artifact type (bulk mode)', ['code', 'document', 'config', 'plan', 'data', 'loop_output', 'sketch', 'hypothesis', 'other'], required: false),
                new EnumParameter('stage', 'Filter by stage (bulk mode)', ['draft', 'review', 'final'], required: false),
                new StringParameter('project_id', 'Filter by project ID (bulk mode)', required: false),
                new StringParameter('sprint_id', 'Filter by sprint ID (bulk mode)', required: false),
                new StringParameter('created_after', 'ISO 8601 creation-time filter (bulk mode)', required: false),
                new BoolParameter('all', 'If true, target all artifacts in the current session.', required: false),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');

                // Single-artifact mode
                if ($id !== '') {
                    $artifact = $this->store->get($id, sessionId: $this->sessionId);
                    if ($artifact === null) {
                        return ToolResult::error("Artifact not found: {$id}");
                    }

                    $deleted = $this->store->delete($id, sessionId: $this->sessionId);
                    if (!$deleted) {
                        return ToolResult::error("Failed to delete artifact {$id}");
                    }

                    return ToolResult::success(json_encode([
                        'id' => $id,
                        'title' => $artifact['title'],
                        'deleted' => true,
                    ], JSON_UNESCAPED_SLASHES) ?: '{}');
                }

                // Bulk mode
                [$matchedIds, $failedIds, $error] = $this->resolveArtifactTargets($args);

                if ($error !== null) {
                    return ToolResult::error($error);
                }

                $deleted = $this->store->bulkDelete($matchedIds, $this->sessionId);

                return ToolResult::success(json_encode([
                    'deleted' => $deleted,
                    'failed_ids' => $failedIds,
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    /**
     * @param array<string, mixed> $args
     * @return array{0: list<string>, 1: list<string>, 2: ?string}
     */
    private function resolveArtifactTargets(array $args, string $stageFilterKey = 'stage'): array
    {
        $hasIds = array_key_exists('ids', $args);
        $type = isset($args['type']) && trim((string) $args['type']) !== '' ? trim((string) $args['type']) : null;
        $stage = isset($args[$stageFilterKey]) && trim((string) $args[$stageFilterKey]) !== '' ? trim((string) $args[$stageFilterKey]) : null;
        $projectId = isset($args['project_id']) && trim((string) $args['project_id']) !== '' ? trim((string) $args['project_id']) : null;
        $sprintId = isset($args['sprint_id']) && trim((string) $args['sprint_id']) !== '' ? trim((string) $args['sprint_id']) : null;
        $createdAfter = isset($args['created_after']) && trim((string) $args['created_after']) !== '' ? trim((string) $args['created_after']) : null;
        $all = (bool) ($args['all'] ?? false);

        if ($hasIds) {
            $decoded = JsonHelper::decodeJsonList($args['ids']);
            if ($decoded === null || $decoded === []) {
                return [[], [], 'ids must be a non-empty JSON array of artifact IDs.'];
            }
            if (count($decoded) > 200) {
                return [[], [], 'Maximum 200 artifact IDs per bulk operation.'];
            }

            $matched = [];
            $failed = [];
            foreach ($decoded as $id) {
                $artifactId = trim((string) $id);
                if ($artifactId === '') {
                    continue;
                }

                $artifact = $this->store->get($artifactId, sessionId: $this->sessionId);
                if ($artifact === null) {
                    $failed[] = $artifactId;
                    continue;
                }

                $matched[] = $artifactId;
            }

            return [array_values(array_unique($matched)), $failed, null];
        }

        if (!$all && $type === null && $stage === null && $projectId === null && $sprintId === null && $createdAfter === null) {
            return [[], [], 'Specify ids, all=true, or at least one filter to select artifacts.'];
        }

        $artifacts = $this->store->list(
            sessionId: $this->sessionId,
            type: $type,
            stage: $stage,
            limit: 5000,
            projectId: $projectId,
            sprintId: $sprintId,
            createdAfter: $createdAfter,
        );

        /** @var list<string> $matched */
        $matched = array_map(
            static fn(array $artifact): string => (string) $artifact['id'],
            $artifacts,
        );

        return [$matched, [], null];
    }

    /**
     * Resolve linked todo count for plan artifacts in guidelines.
     */
    private function resolveTodoCount(string $artifactId, string $type): string
    {
        if ($type !== 'plan' || $this->todoStore === null) {
            return '';
        }

        try {
            $stats = $this->todoStore->getStats($this->sessionId, $artifactId);
            $total = $stats['total'];
            if ($total > 0) {
                $completed = $stats['completed'];
                return " — todos: {$completed}/{$total}";
            }
        } catch (\Throwable) {
            // Non-critical
        }

        return '';
    }
}
