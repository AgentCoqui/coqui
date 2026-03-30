<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /projects and /sprints slash commands.
 */
final class ProjectHandler
{
    public function __construct(
        private readonly BootManager $boot,
        private readonly SessionStorage $storage,
    ) {}

    /**
     * Handle /projects command.
     *
     * - No argument: list all projects (with active project marker)
     * - Status argument (active|completed|archived): filter list
     * - Slug/ID argument: set as active project
     * - "clear": unset active project
     *
     * @return array{0: ?string, 1: ?string} [projectId, projectSlug] or [null, null]
     */
    public function handleProjects(SymfonyStyle $io, string $arg, string $sessionId, ?string $activeProjectId = null): array
    {
        $projectStore = $this->boot->projectStore();
        if ($projectStore === null) {
            $io->error('Project system not initialized.');
            return [null, null];
        }

        $arg = trim($arg);

        // /projects clear — unset active project
        if ($arg === 'clear') {
            $this->storage->setActiveProject($sessionId, null);
            $io->success('Cleared active project.');
            return ['__clear__', null];
        }

        // /projects (no arg or status filter) — list
        if ($arg === '' || in_array($arg, ['active', 'completed', 'archived'], true)) {
            $statusFilter = $arg !== '' ? $arg : null;
            $projects = $projectStore->listProjects($statusFilter);
            if ($projects === []) {
                $io->info($statusFilter !== null ? "No projects with status '{$statusFilter}'." : 'No projects yet.');
                return [null, null];
            }

            $io->section('Projects');
            $rows = [];
            foreach ($projects as $project) {
                $sprints = $projectStore->listSprints($project['id']);
                $active = count(array_filter($sprints, fn(array $s) => in_array($s['status'], ['in_progress', 'review'], true)));
                $marker = $project['id'] === $activeProjectId ? ' ●' : '';
                $rows[] = [
                    $project['title'] . $marker,
                    $project['slug'],
                    $project['status'],
                    count($sprints) . ($active > 0 ? " ({$active} active)" : ''),
                    substr($project['created_at'], 0, 10),
                ];
            }
            $io->table(['Title', 'Slug', 'Status', 'Sprints', 'Created'], $rows);
            return [null, null];
        }

        // /projects <slug|id> — switch to project
        $project = $projectStore->getProject($arg);
        if ($project === null) {
            $io->error(sprintf(
                'Project "%s" not found. Use /projects to list available projects.',
                $arg,
            ));
            return [null, null];
        }

        $this->storage->setActiveProject($sessionId, $project['id']);
        $io->success(sprintf('Active project: %s (%s)', $project['title'], $project['slug']));

        return [$project['id'], $project['slug']];
    }

    public function handleSprints(SymfonyStyle $io, string $arg = '', ?string $sessionId = null): void
    {
        $projectStore = $this->boot->projectStore();
        if ($projectStore === null) {
            $io->error('Project system not initialized.');
            return;
        }
        $todoStore = $this->boot->todoStore();

        $slug = trim($arg);
        if ($slug !== '') {
            $project = $projectStore->getProject($slug);
            if ($project === null) {
                $io->error("Project not found: {$slug}");
                return;
            }
            $sprints = $projectStore->listSprints($project['id']);
        } else {
            $projects = $projectStore->listProjects('active');
            $sprints = [];
            foreach ($projects as $project) {
                foreach ($projectStore->listSprints($project['id']) as $sprint) {
                    $sprint['_project_title'] = $project['title'];
                    $sprints[] = $sprint;
                }
            }
        }

        if ($sprints === []) {
            $io->info('No sprints found.');
            return;
        }

        $io->section('Sprints');
        $rows = [];
        foreach ($sprints as $sprint) {
            $progress = '';
            if ($todoStore !== null) {
                $stats = $projectStore->getSprintProgress($sprint['id'], $todoStore, $sessionId);
                $progress = "{$stats['percent']}% ({$stats['completed']}/{$stats['total']})";  
            }
            $projectLabel = $sprint['_project_title'] ?? '';
            $rows[] = [
                "#{$sprint['sprint_number']}",
                $sprint['title'],
                $sprint['status'],
                $progress,
                "{$sprint['review_round']}/{$sprint['max_review_rounds']}",
                $projectLabel,
            ];
        }
        $io->table(['#', 'Title', 'Status', 'Progress', 'Review', 'Project'], $rows);
    }
}
