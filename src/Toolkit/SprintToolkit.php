<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;

/**
 * Agent-facing toolkit for managing projects and sprints.
 *
 * Projects are session-independent containers that organize long-running work.
 * Sprints divide project work into ordered units with a 4-state lifecycle:
 * planned → in_progress → review → complete (with rejected → in_progress for revisions).
 *
 * Tools:
 * - project_create: Create a new project with a unique slug
 * - project_list: List projects with optional status filter
 * - project_get: Retrieve a project by ID or slug
 * - project_update: Update a project's title, description, or status
 * - sprint_create: Create a sprint within a project
 * - sprint_list: List sprints for a project
 * - sprint_get: Get sprint details with progress
 * - sprint_transition: Move sprint through lifecycle states
 * - sprint_update: Update sprint metadata
 */
final readonly class SprintToolkit implements ToolkitInterface
{
    public function __construct(
        private ProjectStore $projectStore,
        private TodoStore $todoStore,
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
            $this->sprintCreateTool(),
            $this->sprintListTool(),
            $this->sprintGetTool(),
            $this->sprintTransitionTool(),
            $this->sprintUpdateTool(),
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
            <SPRINT-GUIDELINES>
            ## Projects & Sprints

            You can organize long-running work into **projects** and **sprints**.
            Projects persist across sessions. Sprints follow a lifecycle: planned → in_progress → review → complete.

            Use `project_create` to start a new project, then `sprint_create` to add work units.
            Link todos and artifacts to sprints for full traceability.
            </SPRINT-GUIDELINES>
            GUIDELINES;
        }

        $lines = [];
        foreach ($projects as $p) {
            $sprintInfo = sprintf('%d/%d sprints done', (int) $p['sprints_completed'], (int) $p['sprint_count']);
            $marker = $p['id'] === $this->activeProjectId ? ' ●' : '';
            $lines[] = sprintf('- **%s**%s (`%s`) [%s] %s', $p['title'], $marker, $p['slug'], $p['status'], $sprintInfo);
        }
        $listing = implode("\n", $lines);

        // Show active sprints for current session
        $sprintLines = '';
        if ($this->sessionId !== null) {
            $activeSprints = $this->projectStore->getActiveSprintsForSession($this->sessionId);
            if ($activeSprints !== []) {
                $sprintParts = [];
                foreach ($activeSprints as $s) {
                    $progress = $this->projectStore->getSprintProgress($s['id'], $this->todoStore, $this->sessionId);
                    $sprintParts[] = sprintf(
                        '- Sprint #%d: **%s** [%s] %d%% (%d/%d todos) — project: %s',
                        $s['sprint_number'],
                        $s['title'],
                        $s['status'],
                        $progress['percent'],
                        $progress['completed'],
                        $progress['total'],
                        $s['project_slug'],
                    );
                }
                $sprintLines = "\n\n**Active sprints (this session):**\n" . implode("\n", $sprintParts);
            }
        }

        return <<<GUIDELINES
        <SPRINT-GUIDELINES>
        ## Projects & Sprints

        **Active projects ({$activeCount}):**
        {$listing}{$sprintLines}

        Use `sprint_get` for details, `sprint_transition` to advance lifecycle.
        Sprint lifecycle: planned → in_progress → review → complete (rejected → in_progress for revisions).
        </SPRINT-GUIDELINES>
        GUIDELINES;
    }

    // =========================================================================
    // Project Tools
    // =========================================================================

    private function projectCreateTool(): ToolInterface
    {
        return new Tool(
            name: 'project_create',
            description: 'Create a new project to organize long-running work across sessions. Returns the project ID.',
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
                            mkdir($projectDir, 0755, true);
                        }
                    }

                    return ToolResult::success(json_encode([
                        'id' => $id,
                        'title' => $title,
                        'slug' => $slug,
                        'directory' => $directory,
                        'status' => 'active',
                    ], JSON_UNESCAPED_SLASHES) ?: '{}');
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
            description: 'List all projects with sprint progress summary.',
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
                        '- **%s** (slug: `%s`, id: %s) [%s] — %d sprints (%d completed)',
                        $p['title'],
                        $p['slug'],
                        $p['id'],
                        $p['status'],
                        (int) $p['sprint_count'],
                        (int) $p['sprints_completed'],
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
            description: 'Get a project by ID or slug, including its sprint roster.',
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

                $sprints = $this->projectStore->listSprints($project['id']);
                $sprintLines = [];
                foreach ($sprints as $s) {
                    $progress = $this->projectStore->getSprintProgress($s['id'], $this->todoStore, $this->sessionId);
                    $sprintLines[] = sprintf(
                        '  #%d: %s [%s] %d%% (%d/%d todos)%s',
                        $s['sprint_number'],
                        $s['title'],
                        $s['status'],
                        $progress['percent'],
                        $progress['completed'],
                        $progress['total'],
                        $s['review_round'] > 0 ? " (review round {$s['review_round']})" : '',
                    );
                }

                $result = [
                    'id' => $project['id'],
                    'title' => $project['title'],
                    'slug' => $project['slug'],
                    'description' => $project['description'] ?? '',
                    'status' => $project['status'],
                    'created_at' => $project['created_at'],
                    'sprints' => $sprintLines !== [] ? "\n" . implode("\n", $sprintLines) : 'No sprints yet',
                ];

                return ToolResult::success(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
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

                return ToolResult::success(json_encode([
                    'id' => $project['id'],
                    'title' => $project['title'],
                    'slug' => $project['slug'],
                    'directory' => $this->projectStore->getProjectDirectory($project['id']),
                    'status' => 'active_project_set',
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    // =========================================================================
    // Sprint Tools
    // =========================================================================

    private function sprintCreateTool(): ToolInterface
    {
        return new Tool(
            name: 'sprint_create',
            description: 'Create a new sprint within a project. Sprints start in "planned" status.',
            parameters: [
                new StringParameter('project_id', 'Project ID or slug', required: true),
                new StringParameter('title', 'Sprint title describing the work unit', required: true),
                new StringParameter('acceptance_criteria', 'JSON array of acceptance criteria for the reviewer', required: false),
                new NumberParameter('max_review_rounds', 'Maximum review→rejected cycles before requiring user intervention (default: 3, max: 5)', required: false),
            ],
            callback: function (array $args): ToolResult {
                $projectIdOrSlug = trim($args['project_id'] ?? '');
                $title = trim($args['title'] ?? '');

                if ($projectIdOrSlug === '' || $title === '') {
                    return ToolResult::error('Both project_id and title are required.');
                }

                $project = $this->projectStore->getProject($projectIdOrSlug);
                if ($project === null) {
                    return ToolResult::error("Project not found: {$projectIdOrSlug}");
                }

                try {
                    $id = $this->projectStore->createSprint(
                        projectId: $project['id'],
                        title: $title,
                        acceptanceCriteria: isset($args['acceptance_criteria']) ? trim($args['acceptance_criteria']) : null,
                        lastSessionId: $this->sessionId,
                        maxReviewRounds: isset($args['max_review_rounds']) ? (int) $args['max_review_rounds'] : 3,
                    );

                    $sprint = $this->projectStore->getSprint($id);

                    return ToolResult::success(json_encode([
                        'id' => $id,
                        'project' => $project['slug'],
                        'title' => $title,
                        'sprint_number' => $sprint['sprint_number'] ?? 0,
                        'status' => 'planned',
                    ], JSON_UNESCAPED_SLASHES) ?: '{}');
                } catch (\InvalidArgumentException $e) {
                    return ToolResult::error($e->getMessage());
                }
            },
        );
    }

    private function sprintListTool(): ToolInterface
    {
        return new Tool(
            name: 'sprint_list',
            description: 'List sprints for a project with progress details.',
            parameters: [
                new StringParameter('project_id', 'Project ID or slug', required: true),
                new EnumParameter('status', 'Filter by sprint status', ['planned', 'in_progress', 'review', 'rejected', 'complete'], required: false),
            ],
            callback: function (array $args): ToolResult {
                $projectIdOrSlug = trim($args['project_id'] ?? '');

                if ($projectIdOrSlug === '') {
                    return ToolResult::error('Project ID or slug is required.');
                }

                $project = $this->projectStore->getProject($projectIdOrSlug);
                if ($project === null) {
                    return ToolResult::error("Project not found: {$projectIdOrSlug}");
                }

                $status = isset($args['status']) ? trim($args['status']) : null;
                $sprints = $this->projectStore->listSprints($project['id'], $status);

                if ($sprints === []) {
                    return ToolResult::success('No sprints found.');
                }

                $lines = [];
                foreach ($sprints as $s) {
                    $progress = $this->projectStore->getSprintProgress($s['id'], $this->todoStore, $this->sessionId);
                    $reviewInfo = (int) $s['review_round'] > 0
                        ? sprintf(' (review round %d/%d)', $s['review_round'], $s['max_review_rounds'])
                        : '';

                    $lines[] = sprintf(
                        '#%d: **%s** [%s] %d%% (%d/%d todos)%s — id: %s',
                        $s['sprint_number'],
                        $s['title'],
                        $s['status'],
                        $progress['percent'],
                        $progress['completed'],
                        $progress['total'],
                        $reviewInfo,
                        $s['id'],
                    );
                }

                return ToolResult::success(implode("\n", $lines));
            },
        );
    }

    private function sprintGetTool(): ToolInterface
    {
        return new Tool(
            name: 'sprint_get',
            description: 'Get full sprint details including progress, review notes, and acceptance criteria.',
            parameters: [
                new StringParameter('id', 'Sprint ID', required: true),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');

                if ($id === '') {
                    return ToolResult::error('Sprint ID is required.');
                }

                $sprint = $this->projectStore->getSprint($id);
                if ($sprint === null) {
                    return ToolResult::error("Sprint not found: {$id}");
                }

                $progress = $this->projectStore->getSprintProgress($id, $this->todoStore, $this->sessionId);

                $result = [
                    'id' => $sprint['id'],
                    'project_id' => $sprint['project_id'],
                    'title' => $sprint['title'],
                    'sprint_number' => (int) $sprint['sprint_number'],
                    'status' => $sprint['status'],
                    'progress' => sprintf('%d%% (%d/%d)', $progress['percent'], $progress['completed'], $progress['total']),
                    'review_round' => (int) $sprint['review_round'],
                    'max_review_rounds' => (int) $sprint['max_review_rounds'],
                    'acceptance_criteria' => $sprint['acceptance_criteria'] ?? '',
                    'reviewer_notes' => $sprint['reviewer_notes'] ?? '',
                    'contract_artifact_id' => $sprint['contract_artifact_id'] ?? '',
                    'last_session_id' => $sprint['last_session_id'] ?? '',
                    'created_at' => $sprint['created_at'],
                    'completed_at' => $sprint['completed_at'] ?? '',
                ];

                return ToolResult::success(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '{}');
            },
        );
    }

    private function sprintTransitionTool(): ToolInterface
    {
        return new Tool(
            name: 'sprint_transition',
            description: <<<'DESC'
                Transition a sprint to a new lifecycle state.
                Valid transitions: planned→in_progress, in_progress→review, review→complete, review→rejected, rejected→in_progress.
                When rejecting, provide reviewer notes explaining what needs to change.
                DESC,
            parameters: [
                new StringParameter('id', 'Sprint ID', required: true),
                new EnumParameter('status', 'Target status', ['in_progress', 'review', 'complete', 'rejected'], required: true),
                new StringParameter('notes', 'Reviewer notes (required when rejecting, optional otherwise)', required: false),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');
                $status = trim($args['status'] ?? '');

                if ($id === '' || $status === '') {
                    return ToolResult::error('Sprint ID and target status are required.');
                }

                // Update last_session_id on transition
                if ($this->sessionId !== null) {
                    $this->projectStore->updateSprint($id, lastSessionId: $this->sessionId);
                }

                try {
                    $notes = isset($args['notes']) ? trim($args['notes']) : null;
                    $result = $this->projectStore->transitionSprint($id, $status, $notes);

                    if (!$result) {
                        return ToolResult::error("Sprint not found: {$id}");
                    }

                    $sprint = $this->projectStore->getSprint($id);
                    $progress = $this->projectStore->getSprintProgress($id, $this->todoStore, $this->sessionId);

                    return ToolResult::success(json_encode([
                        'id' => $id,
                        'status' => $sprint['status'] ?? $status,
                        'progress' => sprintf('%d%%', $progress['percent']),
                        'review_round' => (int) ($sprint['review_round'] ?? 0),
                        'transitioned' => true,
                    ], JSON_UNESCAPED_SLASHES) ?: '{}');
                } catch (\InvalidArgumentException $e) {
                    return ToolResult::error($e->getMessage());
                }
            },
        );
    }

    private function sprintUpdateTool(): ToolInterface
    {
        return new Tool(
            name: 'sprint_update',
            description: 'Update a sprint\'s metadata (title, acceptance criteria, contract artifact link).',
            parameters: [
                new StringParameter('id', 'Sprint ID', required: true),
                new StringParameter('title', 'New sprint title', required: false),
                new StringParameter('acceptance_criteria', 'Updated acceptance criteria (JSON)', required: false),
                new StringParameter('contract_artifact_id', 'Link to the plan/contract artifact', required: false),
            ],
            callback: function (array $args): ToolResult {
                $id = trim($args['id'] ?? '');

                if ($id === '') {
                    return ToolResult::error('Sprint ID is required.');
                }

                $updated = $this->projectStore->updateSprint(
                    id: $id,
                    title: isset($args['title']) ? trim($args['title']) : null,
                    acceptanceCriteria: isset($args['acceptance_criteria']) ? trim($args['acceptance_criteria']) : null,
                    contractArtifactId: isset($args['contract_artifact_id']) ? trim($args['contract_artifact_id']) : null,
                    lastSessionId: $this->sessionId,
                );

                return $updated
                    ? ToolResult::success("Sprint updated: {$id}")
                    : ToolResult::error("Sprint not found: {$id}");
            },
        );
    }
}
