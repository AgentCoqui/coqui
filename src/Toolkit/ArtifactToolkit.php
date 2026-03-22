<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Agent\PlanTodoGenerator;
use CoquiBot\Coqui\Storage\ArtifactStore;

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
 * - artifact_stage: Transition artifact stage (draft → review → final)
 */
final class ArtifactToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly ArtifactStore $store,
        private readonly string $sessionId,
        private readonly bool $readOnly = false,
        private readonly ?PlanTodoGenerator $planTodoGenerator = null,
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
            $lines[] = sprintf(
                '- **%s** (id: %s) [%s, %s] v%d',
                $a['title'],
                substr($a['id'], 0, 8) . '...',
                $a['type'],
                $a['stage'],
                $a['version'],
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
                new EnumParameter('type', 'Artifact type', ['code', 'document', 'config', 'plan', 'data', 'other'], required: false),
                new StringParameter('language', 'Programming language (for code artifacts, e.g. php, python, javascript)', required: false),
                new StringParameter('filepath', 'Intended file path relative to workspace (e.g. src/MyClass.php)', required: false),
            ],
            callback: function (array $args): ToolResult {
                $title = trim($args['title'] ?? '');
                $content = $args['content'] ?? '';
                $type = $args['type'] ?? 'code';
                $language = isset($args['language']) ? trim($args['language']) : null;
                $filepath = isset($args['filepath']) ? trim($args['filepath']) : null;

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
                );

                if (!$updated) {
                    return ToolResult::error("Artifact not found: {$id}");
                }

                $artifact = $this->store->get($id);

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

                    $artifact = $this->store->get($id);
                    return ToolResult::success(json_encode([
                        'id' => $id,
                        'title' => $artifact['title'] ?? '',
                        'version' => $version,
                        'content' => $versionData['content'],
                        'change_summary' => $versionData['change_summary'],
                        'created_at' => $versionData['created_at'],
                    ], JSON_UNESCAPED_SLASHES) ?: '{}');
                }

                $artifact = $this->store->get($id);
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
            description: 'List artifacts in the current session, optionally filtered by type or stage.',
            parameters: [
                new EnumParameter('type', 'Filter by artifact type', ['code', 'document', 'config', 'plan', 'data', 'other'], required: false),
                new EnumParameter('stage', 'Filter by stage', ['draft', 'review', 'final'], required: false),
            ],
            callback: function (array $args): ToolResult {
                $type = isset($args['type']) ? trim($args['type']) : null;
                $stage = isset($args['stage']) ? trim($args['stage']) : null;

                $artifacts = $this->store->list(
                    sessionId: $this->sessionId,
                    type: $type !== '' ? $type : null,
                    stage: $stage !== '' ? $stage : null,
                );

                if ($artifacts === []) {
                    return ToolResult::success('No artifacts found in this session.');
                }

                $summary = array_map(fn(array $a) => [
                    'id' => $a['id'],
                    'title' => $a['title'],
                    'type' => $a['type'],
                    'stage' => $a['stage'],
                    'version' => $a['version'],
                    'language' => $a['language'],
                    'filepath' => $a['filepath'],
                    'updated_at' => $a['updated_at'],
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
            description: 'Transition an artifact to a new stage: draft → review → final. Use this to indicate readiness.',
            parameters: [
                new StringParameter('id', 'Artifact ID', required: true),
                new EnumParameter('stage', 'New stage', ['draft', 'review', 'final'], required: true),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');
                $stage = trim($args['stage'] ?? '');

                if ($id === '' || $stage === '') {
                    return ToolResult::error('Both id and stage are required.');
                }

                $artifact = $this->store->get($id);
                if ($artifact === null) {
                    return ToolResult::error("Artifact not found: {$id}");
                }

                $updated = $this->store->updateStage($id, $stage);
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
                        $response['todos_note'] = 'Auto-generation failed or extracted no steps. Use todo_add or todo_bulk_add to create todos manually.';
                    }
                }

                return ToolResult::success(json_encode($response, JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    private function deleteTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_delete',
            description: 'Delete an artifact and all its version history. This action is irreversible.',
            parameters: [
                new StringParameter('id', 'Artifact ID to delete', required: true),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');

                if ($id === '') {
                    return ToolResult::error('Artifact ID is required.');
                }

                $artifact = $this->store->get($id);
                if ($artifact === null) {
                    return ToolResult::error("Artifact not found: {$id}");
                }

                $deleted = $this->store->delete($id);
                if (!$deleted) {
                    return ToolResult::error("Failed to delete artifact {$id}");
                }

                return ToolResult::success(json_encode([
                    'id' => $id,
                    'title' => $artifact['title'],
                    'deleted' => true,
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }
}
