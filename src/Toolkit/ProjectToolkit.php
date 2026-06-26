<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\FileSystemOperations;

/**
 * Agent-facing toolkit for lightweight projects.
 *
 * A project is a named, session-independent working scope backed by a
 * workspace directory (projects/<slug>-<id>/). Activating a project keeps
 * the agent oriented around one body of work and one directory across
 * sessions; it is intentionally minimal — no sprints, no task tracking.
 *
 * Tools:
 * - project_create: Create a new project with a unique slug
 * - project_list: List projects with optional status filter
 * - project_get: Retrieve a project by ID or slug
 * - project_update: Update a project's title, description, or status
 * - project_delete: Delete a project (or all projects)
 * - project_switch: Set or clear the active project for this session
 */
final readonly class ProjectToolkit implements ToolkitInterface
{
    public function __construct(
        private ProjectStore $projectStore,
        private ?string $sessionId = null,
        private ?string $workspacePath = null,
        private ?string $activeProjectId = null,
        private ?SessionStorage $storage = null,
    ) {}

    public function tools(): array
    {
        $tools = [
            $this->projectCreateTool(),
            $this->projectListTool(),
            $this->projectGetTool(),
            $this->projectUpdateTool(),
            $this->projectDeleteTool(),
        ];

        // project_switch requires session storage to persist the active project
        if ($this->storage !== null && $this->sessionId !== null) {
            $tools[] = $this->projectSwitchTool();
        }

        return $tools;
    }

    public function guidelines(): string
    {
        $projects = $this->projectStore->listProjects('active', 5);
        $activeCount = count($projects);

        if ($activeCount === 0) {
            return <<<'GUIDELINES'
            <PROJECT-GUIDELINES>
            ## Projects

            A **project** is a named working scope backed by its own workspace directory.
            Activating a project keeps your work organized in one place across sessions.

            Use `project_create` to start a new project, then `project_switch` to make it active.
            </PROJECT-GUIDELINES>
            GUIDELINES;
        }

        $lines = [];
        foreach ($projects as $p) {
            $marker = $p['id'] === $this->activeProjectId ? ' ●' : '';
            $lines[] = sprintf('- **%s**%s (`%s`) [%s]', $p['title'], $marker, $p['slug'], $p['status']);
        }
        $listing = implode("\n", $lines);

        return <<<GUIDELINES
        <PROJECT-GUIDELINES>
        ## Projects

        **Active projects ({$activeCount}):**
        {$listing}

        Use `project_switch` to set the active project. The active project (●) scopes your
        work to its directory and appears in the system prompt.
        </PROJECT-GUIDELINES>
        GUIDELINES;
    }

    private function projectCreateTool(): ToolInterface
    {
        return new Tool(
            name: 'project_create',
            description: 'Create a new project to organize long-running work across sessions. Returns the project ID and workspace directory.',
            parameters: [
                new StringParameter('title', 'Project title (e.g. "E-commerce Platform")', required: true),
                new StringParameter('slug', 'URL-friendly identifier, lowercase with hyphens (e.g. "ecommerce-platform")', required: true),
                new StringParameter('description', 'Brief project description', required: false),
            ],
            callback: function (array $args): ToolResult {
                $title = trim($args['title'] ?? '');
                $slug = trim($args['slug'] ?? '');

                if ($title === '' || $slug === '') {
                    return ToolResult::error('Both title and slug are required.');
                }

                try {
                    $id = $this->projectStore->createProject(
                        title: $title,
                        slug: $slug,
                        description: isset($args['description']) ? trim($args['description']) : null,
                    );

                    // Auto-create the project directory under workspace/projects/
                    $directory = $this->projectStore->getProjectDirectory($id);
                    if ($this->workspacePath !== null) {
                        $projectDir = $this->workspacePath . '/projects/' . $directory;
                        if (!is_dir($projectDir)) {
                            mkdir($projectDir, CoquiDefaults::DIRECTORY_MODE, true);
                        }
                    }

                    return ToolResult::json([
                        'id' => $id,
                        'title' => $title,
                        'slug' => $slug,
                        'directory' => $directory,
                        'status' => 'active',
                    ]);
                } catch (\InvalidArgumentException $e) {
                    return ToolResult::error($e->getMessage());
                }
            },
        );
    }

    private function projectListTool(): ToolInterface
    {
        return new Tool(
            name: 'project_list',
            description: 'List all projects.',
            parameters: [
                new EnumParameter('status', 'Filter by project status', ['active', 'completed', 'archived'], required: false),
            ],
            callback: function (array $args): ToolResult {
                $status = isset($args['status']) ? trim($args['status']) : null;
                $projects = $this->projectStore->listProjects($status);

                if ($projects === []) {
                    return ToolResult::success('No projects found.');
                }

                $lines = [];
                foreach ($projects as $p) {
                    $lines[] = sprintf(
                        '- **%s** (slug: `%s`, id: %s) [%s]',
                        $p['title'],
                        $p['slug'],
                        $p['id'],
                        $p['status'],
                    );
                }

                return ToolResult::success(implode("\n", $lines));
            },
        );
    }

    private function projectGetTool(): ToolInterface
    {
        return new Tool(
            name: 'project_get',
            description: 'Get a project by ID or slug, including its workspace directory.',
            parameters: [
                new StringParameter('id', 'Project ID or slug', required: true),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');

                if ($id === '') {
                    return ToolResult::error('Project ID or slug is required.');
                }

                $project = $this->projectStore->getProject($id);
                if ($project === null) {
                    return ToolResult::error("Project not found: {$id}");
                }

                return ToolResult::json([
                    'id' => $project['id'],
                    'title' => $project['title'],
                    'slug' => $project['slug'],
                    'description' => $project['description'] ?? '',
                    'status' => $project['status'],
                    'directory' => $this->projectStore->getProjectDirectory($project['id']),
                    'created_at' => $project['created_at'],
                ]);
            },
        );
    }

    private function projectUpdateTool(): ToolInterface
    {
        return new Tool(
            name: 'project_update',
            description: 'Update a project\'s title, description, or status.',
            parameters: [
                new StringParameter('id', 'Project ID or slug', required: true),
                new StringParameter('title', 'New project title', required: false),
                new StringParameter('description', 'New description', required: false),
                new EnumParameter('status', 'New project status', ['active', 'completed', 'archived'], required: false),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');

                if ($id === '') {
                    return ToolResult::error('Project ID or slug is required.');
                }

                $project = $this->projectStore->getProject($id);
                if ($project === null) {
                    return ToolResult::error("Project not found: {$id}");
                }

                try {
                    $updated = $this->projectStore->updateProject(
                        id: $project['id'],
                        title: isset($args['title']) ? trim($args['title']) : null,
                        description: isset($args['description']) ? trim($args['description']) : null,
                        status: isset($args['status']) ? trim($args['status']) : null,
                    );

                    return $updated
                        ? ToolResult::success("Project updated: {$project['title']}")
                        : ToolResult::error('Failed to update project.');
                } catch (\InvalidArgumentException $e) {
                    return ToolResult::error($e->getMessage());
                }
            },
        );
    }

    private function projectSwitchTool(): ToolInterface
    {
        return new Tool(
            name: 'project_switch',
            description: 'Set or clear the active project for this session. When a project is active, all work is scoped to it and the project context appears in the system prompt.',
            parameters: [
                new StringParameter('slug', 'Project slug or ID to activate. Pass "clear" to deactivate the current project.', required: true),
            ],
            callback: function (array $args): ToolResult {
                $slug = trim($args['slug'] ?? '');
                if ($slug === '') {
                    return ToolResult::error('Project slug or ID is required.');
                }

                // Guaranteed non-null by tools() guard
                assert($this->storage !== null && $this->sessionId !== null);

                if ($slug === 'clear') {
                    $this->storage->setActiveProject($this->sessionId, null);
                    return ToolResult::success('Active project cleared.');
                }

                $project = $this->projectStore->getProject($slug);
                if ($project === null) {
                    return ToolResult::error(sprintf('Project "%s" not found.', $slug));
                }

                $this->storage->setActiveProject($this->sessionId, $project['id']);

                return ToolResult::json([
                    'id' => $project['id'],
                    'title' => $project['title'],
                    'slug' => $project['slug'],
                    'directory' => $this->projectStore->getProjectDirectory($project['id']),
                    'status' => 'active_project_set',
                ]);
            },
        );
    }

    private function projectDeleteTool(): ToolInterface
    {
        return new Tool(
            name: 'project_delete',
            description: 'Delete a project or all projects. Clears active-project session references. Optionally delete project workspace directories too.',
            parameters: [
                new StringParameter('id', 'Project ID, slug, or "all"', required: true),
                new BoolParameter('delete_directory', 'If true, also delete workspace/projects/<directory> for each project.', required: false),
            ],
            callback: function (array $args): ToolResult {
                $id = trim((string) ($args['id'] ?? ''));
                $deleteDirectory = (bool) ($args['delete_directory'] ?? false);

                if ($id === '') {
                    return ToolResult::error('Project ID, slug, or "all" is required.');
                }

                if ($deleteDirectory && $this->workspacePath === null) {
                    return ToolResult::error('Project directory deletion requires a workspace path.');
                }

                if (strtolower($id) === 'all') {
                    $projects = $this->projectStore->listProjects(limit: 1000);
                    if ($projects === []) {
                        return ToolResult::json([
                            'deleted' => 0,
                            'cleared_active_sessions' => 0,
                            'directories_deleted' => 0,
                        ]);
                    }

                    $directoriesDeleted = 0;
                    $warnings = [];
                    if ($deleteDirectory) {
                        foreach ($projects as $project) {
                            try {
                                $this->deleteProjectDirectory((string) $project['id']);
                                $directoriesDeleted++;
                            } catch (\Throwable $e) {
                                $warnings[] = sprintf('Failed to delete project directory for %s: %s', $project['slug'], $e->getMessage());
                            }
                        }
                    }

                    $deleted = $this->projectStore->deleteAllProjects();
                    $cleared = $this->storage?->clearAllActiveProjects() ?? 0;

                    return ToolResult::json([
                        'deleted' => $deleted,
                        'cleared_active_sessions' => $cleared,
                        'directories_deleted' => $directoriesDeleted,
                        'warnings' => $warnings !== [] ? $warnings : null,
                    ]);
                }

                $project = $this->projectStore->getProject($id);
                if ($project === null) {
                    return ToolResult::error(sprintf('Project "%s" not found.', $id));
                }

                $directoryDeleted = false;
                $warning = null;
                if ($deleteDirectory) {
                    try {
                        $this->deleteProjectDirectory((string) $project['id']);
                        $directoryDeleted = true;
                    } catch (\Throwable $e) {
                        $warning = $e->getMessage();
                    }
                }

                $deleted = $this->projectStore->deleteProject((string) $project['id']);
                if (!$deleted) {
                    return ToolResult::error('Failed to delete project.');
                }

                $cleared = $this->storage?->clearActiveProjectReferences((string) $project['id']) ?? 0;

                return ToolResult::json([
                    'id' => $project['id'],
                    'slug' => $project['slug'],
                    'deleted' => true,
                    'cleared_active_sessions' => $cleared,
                    'directory_deleted' => $directoryDeleted,
                    'warning' => $warning,
                ]);
            },
        );
    }

    private function deleteProjectDirectory(string $projectId): void
    {
        if ($this->workspacePath === null) {
            return;
        }

        $operations = new FileSystemOperations($this->workspacePath);
        $operations->deleteDirectory('projects/' . $this->projectStore->getProjectDirectory($projectId));
    }
}
