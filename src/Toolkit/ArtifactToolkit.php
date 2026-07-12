<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Storage\ArtifactStore;

/**
 * Agent-facing toolkit for managing structured artifacts.
 *
 * Artifacts are plain files on disk under `artifacts/<type>/…`; the DB is a
 * lightweight index. The file is the source of truth and history comes from
 * the user's own VCS. The toolkit provides simple CRUD — create, update
 * (full rewrite), get, list, delete — with no stage lifecycle or bulk ops.
 *
 * Tools:
 * - artifact_create: Create a new artifact (writes a file, returns id + path)
 * - artifact_update: Full-rewrite an artifact's content (bumps version)
 * - artifact_get: Retrieve an artifact by ID (content read from its file)
 * - artifact_list: List session/project artifacts with optional filters
 * - artifact_delete: Delete an artifact and its file (withheld from read-only roles)
 */
final class ArtifactToolkit implements ToolkitInterface
{
    /** Recent-artifacts index cap. */
    private const int INDEX_LIMIT = 10;

    public function __construct(
        private readonly ArtifactStore $store,
        private readonly string $sessionId,
        private readonly bool $readOnly = false,
        private readonly ?string $defaultProjectId = null,
        private readonly ?string $createdBy = null,
    ) {}

    public function tools(): array
    {
        $tools = [
            $this->createTool(),
            $this->updateTool(),
            $this->getTool(),
            $this->listTool(),
        ];

        if (!$this->readOnly) {
            $tools[] = $this->deleteTool();
        }

        return $tools;
    }

    public function guidelines(): string
    {
        return $this->recentArtifactsIndex();
    }

    /**
     * Pinned recent-artifacts index: when-to-use guidance plus a capped list of
     * pointers (title, id, type, path, provenance). Content is pointers only,
     * never bodies. Scoped session→project; never filtered by creator.
     */
    public function recentArtifactsIndex(): string
    {
        $artifacts = $this->store->listRecent(
            $this->sessionId,
            projectId: $this->defaultProjectId,
            limit: self::INDEX_LIMIT,
        );

        $when = <<<'WHEN'
        Create an artifact when the output is (1) **substantial** — more than ~15 lines
        or a complete file/document; (2) **durable** — the user would keep, re-open,
        share, or iterate on it; (3) **self-contained** — it stands on its own without
        the surrounding chat. Do NOT create one for one-off answers, short snippets,
        explanations, or commentary about an existing artifact. If unsure, prefer a file
        the user can open on disk over an ephemeral message. Artifacts are plain files
        under `artifacts/<path>` — inspectable, greppable, and versioned by the user's
        own git; reference one by path instead of re-pasting it to save context budget.
        To change one, `artifact_update` its id (full rewrite, reuses the same file);
        only `artifact_create` for a genuinely new deliverable.
        WHEN;

        if ($artifacts === []) {
            return "<ARTIFACTS>\n{$when}\n</ARTIFACTS>";
        }

        $lines = [];
        foreach ($artifacts as $a) {
            $by = ((string) ($a['created_by'] ?? '')) !== '' ? " — by {$a['created_by']}" : '';
            $lines[] = sprintf(
                '- **%s** (%s) [%s] %s%s',
                $a['title'],
                $a['id'],
                $a['type'],
                (string) ($a['path'] ?? ''),
                $by,
            );
        }
        $listing = implode("\n", $lines);

        return "<ARTIFACTS>\n{$when}\n\nRecent artifacts in scope (read/grep by path):\n{$listing}\n</ARTIFACTS>";
    }

    private function createTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_create',
            description: 'Create a new artifact — a plain file written under artifacts/<type>/. Use for substantial, durable, self-contained deliverables (not one-off answers or snippets). Returns the artifact id and its file path.',
            parameters: [
                new StringParameter('title', 'Short descriptive title for the artifact', required: true),
                new StringParameter('content', 'The full content of the artifact', required: true),
                new EnumParameter('type', 'Artifact type (default document)', ['plan', 'document', 'code', 'config'], required: false),
                new StringParameter('language', 'Language hint for code/config artifacts (e.g. php, python, json) — sets the file extension', required: false),
            ],
            callback: function (array $args): ToolResult {
                $title = trim($args['title'] ?? '');
                $content = $args['content'] ?? '';
                $type = isset($args['type']) && trim((string) $args['type']) !== '' ? trim((string) $args['type']) : 'document';
                $language = isset($args['language']) && trim((string) $args['language']) !== '' ? trim((string) $args['language']) : null;

                if ($title === '') {
                    return ToolResult::error('Title is required.');
                }

                $id = $this->store->create(
                    sessionId: $this->sessionId,
                    title: $title,
                    content: $content,
                    type: $type,
                    language: $language,
                    projectId: $this->defaultProjectId,
                    createdBy: $this->createdBy,
                );

                $artifact = $this->store->get($id, sessionId: $this->sessionId);

                return ToolResult::json([
                    'id' => $id,
                    'title' => $title,
                    'type' => $type,
                    'version' => 1,
                    'path' => $artifact['path'] ?? null,
                ]);
            },
        );
    }

    private function updateTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_update',
            description: 'Full-rewrite an artifact\'s content (reuses the same file and path; bumps the version counter). Use this to change an existing artifact rather than creating a new one.',
            parameters: [
                new StringParameter('id', 'Artifact ID (from artifact_create or artifact_list)', required: true),
                new StringParameter('content', 'The updated full content (complete rewrite)', required: true),
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
                    title: isset($args['title']) && trim((string) $args['title']) !== '' ? trim((string) $args['title']) : null,
                    sessionId: $this->sessionId,
                );

                if (!$updated) {
                    return ToolResult::error("Artifact not found: {$id}");
                }

                $artifact = $this->store->get($id, sessionId: $this->sessionId);

                return ToolResult::json([
                    'id' => $id,
                    'title' => $artifact['title'] ?? '',
                    'version' => $artifact['version'] ?? 0,
                    'path' => $artifact['path'] ?? null,
                    'updated' => true,
                ]);
            },
        );
    }

    private function getTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_get',
            description: 'Retrieve an artifact by ID, including its current content (read from its file) and metadata.',
            parameters: [
                new StringParameter('id', 'Artifact ID', required: true),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');

                if ($id === '') {
                    return ToolResult::error('Artifact ID is required.');
                }

                $artifact = $this->store->get($id, sessionId: $this->sessionId);
                if ($artifact === null) {
                    return ToolResult::error("Artifact not found: {$id}");
                }

                return ToolResult::json($artifact);
            },
        );
    }

    private function listTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_list',
            description: 'List artifacts in the current session, optionally filtered by type, project, or creation time.',
            parameters: [
                new EnumParameter('type', 'Filter by artifact type', ['plan', 'document', 'code', 'config', 'loop_output'], required: false),
                new StringParameter('project_id', 'Filter by project ID — useful in loop contexts to see only relevant artifacts', required: false),
                new StringParameter('created_after', 'Only return artifacts created after this ISO 8601 timestamp (e.g. 2026-04-03T12:00:00Z)', required: false),
            ],
            callback: function (array $args): ToolResult {
                $type = isset($args['type']) && trim((string) $args['type']) !== '' ? trim((string) $args['type']) : null;
                $projectId = isset($args['project_id']) && trim((string) $args['project_id']) !== '' ? trim((string) $args['project_id']) : null;
                $createdAfter = isset($args['created_after']) && trim((string) $args['created_after']) !== '' ? trim((string) $args['created_after']) : null;

                $artifacts = $this->store->list(
                    sessionId: $this->sessionId,
                    type: $type,
                    projectId: $projectId,
                    createdAfter: $createdAfter,
                );

                if ($artifacts === []) {
                    return ToolResult::success('No artifacts found matching the given filters.');
                }

                $summary = array_map(static fn(array $a): array => [
                    'id' => $a['id'],
                    'title' => $a['title'],
                    'type' => $a['type'],
                    'version' => $a['version'],
                    'path' => $a['path'] ?? null,
                    'created_by' => $a['created_by'] ?? null,
                    'project_id' => $a['project_id'] ?? null,
                    'updated_at' => $a['updated_at'],
                    'created_at' => $a['created_at'],
                ], $artifacts);

                return ToolResult::json([
                    'count' => count($summary),
                    'artifacts' => $summary,
                ]);
            },
        );
    }

    private function deleteTool(): ToolInterface
    {
        return new Tool(
            name: 'artifact_delete',
            description: 'Delete an artifact and its file. Irreversible.',
            parameters: [
                new StringParameter('id', 'Artifact ID', required: true),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');

                if ($id === '') {
                    return ToolResult::error('Artifact ID is required.');
                }

                $artifact = $this->store->get($id, sessionId: $this->sessionId);
                if ($artifact === null) {
                    return ToolResult::error("Artifact not found: {$id}");
                }

                $deleted = $this->store->delete($id, sessionId: $this->sessionId);
                if (!$deleted) {
                    return ToolResult::error("Failed to delete artifact {$id}");
                }

                return ToolResult::json([
                    'id' => $id,
                    'title' => $artifact['title'],
                    'deleted' => true,
                ]);
            },
        );
    }
}
