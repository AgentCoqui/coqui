<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\CoquiSpace\Installer\SkillInstaller;
use CoquiBot\Coqui\CoquiSpace\Installer\ToolkitInstaller;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /space slash command and all subcommands (search, install, remove, etc.).
 */
final class SpaceHandler
{
    public function __construct(
        private readonly BootManager $boot,
    ) {}

    public function handle(SymfonyStyle $io, string $arg): void
    {
        $spaceToolkit = $this->boot->spaceToolkit();

        if ($spaceToolkit === null) {
            $io->error('Coqui Space is not initialized. Check boot configuration.');
            return;
        }

        $client = $spaceToolkit->client();
        $skillInstaller = $spaceToolkit->skillInstaller();
        $toolkitInstaller = $spaceToolkit->toolkitInstaller();

        $parts = explode(' ', trim($arg), 2);
        $action = strtolower($parts[0]);
        $target = $parts[1] ?? '';

        if ($action === '' || $action === 'status') {
            $this->showStatus($io, $client, $skillInstaller, $toolkitInstaller);
            return;
        }

        if ($action === 'search') {
            $this->search($io, $client, $target);
            return;
        }

        if ($action === 'install') {
            $this->install($io, $skillInstaller, $toolkitInstaller, $target);
            return;
        }

        if ($action === 'remove') {
            $this->remove($io, $skillInstaller, $toolkitInstaller, $target);
            return;
        }

        if ($action === 'skills') {
            $this->listSkills($io, $skillInstaller);
            return;
        }

        if ($action === 'toolkits') {
            $this->listToolkits($io, $toolkitInstaller);
            return;
        }

        if ($action === 'installed') {
            $this->listInstalled($io, $skillInstaller, $toolkitInstaller);
            return;
        }

        if ($action === 'update') {
            $this->update($io, $skillInstaller, $toolkitInstaller, $target);
            return;
        }

        $io->error("Unknown /space subcommand: {$action}. Use: search, install, remove, installed, skills, toolkits, update");
    }

    private function showStatus(SymfonyStyle $io, SpaceClient $client, SkillInstaller $skillInstaller, ToolkitInstaller $toolkitInstaller): void
    {
        try {
            $health = $client->healthCheck();
            $status = ($health['status'] ?? 'unknown') === 'ok' ? '<fg=green>connected</>' : '<fg=red>unreachable</>';
        } catch (\Throwable) {
            $status = '<fg=red>unreachable</>';
        }

        $authenticated = $client->isAuthenticated() ? '<fg=green>yes</>' : '<fg=yellow>no (set COQUI_SPACE_API_TOKEN)</>';

        $installedSkills = $skillInstaller->list();
        $installedToolkits = $toolkitInstaller->list();

        $io->text([
            '<fg=cyan>Coqui Space</>',
            "  API: {$status}",
            "  Authenticated: {$authenticated}",
            '  Installed skills: ' . count($installedSkills),
            '  Installed toolkits: ' . count($installedToolkits),
            '',
            '<fg=gray>Commands: /space search|install|remove|installed|update</>',
        ]);
    }

    private function search(SymfonyStyle $io, SpaceClient $client, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /space search <query>');
            return;
        }

        try {
            $results = $client->searchAll($target);
            $rows = [];

            foreach ((array) ($results['skills']['results'] ?? []) as $skill) {
                if (!is_array($skill)) {
                    continue;
                }
                $owner = (string) ($skill['owner'] ?? '');
                $name = (string) ($skill['urlName'] ?? $skill['name'] ?? '');
                $desc = mb_substr((string) ($skill['description'] ?? $skill['shortDescription'] ?? ''), 0, 60);
                $rows[] = ['skill', "{$owner}/{$name}", $desc];
            }

            foreach ((array) ($results['toolkits']['results'] ?? []) as $toolkit) {
                if (!is_array($toolkit)) {
                    continue;
                }
                $pkg = (string) ($toolkit['name'] ?? '');
                $desc = mb_substr((string) ($toolkit['description'] ?? $toolkit['shortDescription'] ?? ''), 0, 60);
                $rows[] = ['toolkit', $pkg, $desc];
            }

            if (empty($rows)) {
                $io->text("No results found for \"{$target}\".");
            } else {
                $io->table(['Type', 'Identifier', 'Description'], $rows);
            }
        } catch (\Throwable $e) {
            $io->error('Search failed: ' . $e->getMessage());
        }
    }

    private function install(SymfonyStyle $io, SkillInstaller $skillInstaller, ToolkitInstaller $toolkitInstaller, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /space install <owner/name>');
            return;
        }

        if (!str_contains($target, '/')) {
            $io->error('Invalid identifier. Use owner/name for skills or vendor/package for toolkits.');
            return;
        }

        try {
            $parts = explode('/', $target, 2);
            $firstPart = $parts[0];
            $secondPart = $parts[1];

            if (str_starts_with($firstPart, 'coquibot') || str_contains($secondPart, 'toolkit')) {
                $result = $toolkitInstaller->install($target);
                $io->success($result['message']);
            } else {
                $result = $skillInstaller->install($firstPart, $secondPart);
                $io->success($result['message']);
            }
        } catch (\Throwable $e) {
            $io->error('Install failed: ' . $e->getMessage());
        }
    }

    private function remove(SymfonyStyle $io, SkillInstaller $skillInstaller, ToolkitInstaller $toolkitInstaller, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /space remove <identifier>');
            return;
        }

        try {
            if (str_contains($target, '/')) {
                $msg = $toolkitInstaller->remove($target);
                $io->success($msg);
            } else {
                $msg = $skillInstaller->remove($target, purge: true);
                $io->success($msg);
            }
        } catch (\Throwable $e) {
            $io->error('Remove failed: ' . $e->getMessage());
        }
    }

    private function listSkills(SymfonyStyle $io, SkillInstaller $skillInstaller): void
    {
        $skills = $skillInstaller->list();
        if (empty($skills)) {
            $io->text('No skills installed from Coqui Space.');
            return;
        }
        $rows = [];
        foreach ($skills as $s) {
            $rows[] = [$s['name'], $s['version'], $s['status'], $s['source']];
        }
        $io->table(['Name', 'Version', 'Status', 'Source'], $rows);
    }

    private function listToolkits(SymfonyStyle $io, ToolkitInstaller $toolkitInstaller): void
    {
        $toolkits = $toolkitInstaller->list();
        if (empty($toolkits)) {
            $io->text('No toolkits installed from Coqui Space.');
            return;
        }
        $rows = [];
        foreach ($toolkits as $t) {
            $rows[] = [$t['package'], $t['constraint'], $t['status']];
        }
        $io->table(['Package', 'Constraint', 'Status'], $rows);
    }

    private function listInstalled(SymfonyStyle $io, SkillInstaller $skillInstaller, ToolkitInstaller $toolkitInstaller): void
    {
        $skills = $skillInstaller->list();
        $toolkits = $toolkitInstaller->list();

        if (empty($skills) && empty($toolkits)) {
            $io->text('No skills or toolkits installed from Coqui Space.');
            return;
        }

        if (!empty($skills)) {
            $io->section('Skills');
            $rows = [];
            foreach ($skills as $s) {
                $rows[] = [$s['name'], $s['version'], $s['status'], $s['source']];
            }
            $io->table(['Name', 'Version', 'Status', 'Source'], $rows);
        }

        if (!empty($toolkits)) {
            $io->section('Toolkits');
            $rows = [];
            foreach ($toolkits as $t) {
                $rows[] = [$t['package'], $t['constraint'], $t['status']];
            }
            $io->table(['Package', 'Constraint', 'Status'], $rows);
        }
    }

    private function update(SymfonyStyle $io, SkillInstaller $skillInstaller, ToolkitInstaller $toolkitInstaller, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /space update <identifier>');
            return;
        }

        try {
            if (str_contains($target, '/')) {
                $result = $toolkitInstaller->update($target);
                $io->success($result['message']);
            } else {
                $result = $skillInstaller->update($target);
                $io->success($result['message']);
            }
        } catch (\Throwable $e) {
            $io->error('Update failed: ' . $e->getMessage());
        }
    }
}
