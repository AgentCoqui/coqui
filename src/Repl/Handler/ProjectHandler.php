<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /projects and /sprints slash commands.
 */
final class ProjectHandler
{
    public function __construct(
        private readonly BootManager $boot,
    ) {}

    public function handleProjects(SymfonyStyle $io, string $arg = ''): void
    {
        $projectStore = $this->boot->projectStore();
        if ($projectStore === null) {
            $io->error('Project system not initialized.');
            return;
        }

        $statusFilter = trim($arg) !== '' ? trim($arg) : null;
        $projects = $projectStore->listProjects($statusFilter);
        if ($projects === []) {
            $io->info($statusFilter !== null ? "No projects with status '{$statusFilter}'." : 'No projects yet.');
            return;
        }

        $io->section('Projects');
        $rows = [];
        foreach ($projects as $project) {
            $sprints = $projectStore->listSprints($project['id']);
            $active = count(array_filter($sprints, fn(array $s) => in_array($s['status'], ['in_progress', 'review'], true)));
            $rows[] = [
                $project['title'],
                $project['slug'],
                $project['status'],
                count($sprints) . ($active > 0 ? " ({$active} active)" : ''),
                substr($project['created_at'], 0, 10),
            ];
        }
        $io->table(['Title', 'Slug', 'Status', 'Sprints', 'Created'], $rows);
    }

    public function handleSprints(SymfonyStyle $io, string $arg = ''): void
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
                $stats = $projectStore->getSprintProgress($sprint['id'], $todoStore);
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
