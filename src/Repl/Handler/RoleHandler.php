<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\PersonaPreferences;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleUpdateTracker;
use CoquiBot\Coqui\Contract\RoleProperties;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /role, /roles, and all subcommands (edit, ignore, unignore, update).
 */
final class RoleHandler
{
    public function __construct(
        private readonly BootManager $boot,
        private readonly SessionStorage $storage,
    ) {}

    /**
     * Handle /role [name|edit|reset].
     *
     * Returns the new active role name if changed, or null if unchanged.
     */
    public function handleRole(SymfonyStyle $io, string $arg, string $activeRole, string $sessionId, ?string $activeProfile = null): ?string
    {
        $roleDiscovery = $this->boot->roleDiscovery();
        $preferences = $this->loadProfilePreferences($activeProfile);

        if ($arg === '') {
            $io->writeln(sprintf('<info>Active role:</info> %s', $activeRole));
            $availableRoles = array_values($this->boot->roleResolver()->selectableRoles());
            $available = $preferences?->filterAllowedRoles($availableRoles)
                ?? $availableRoles;
            if ($available !== []) {
                foreach ($this->groupByCategory($roleDiscovery, $available) as $category => $names) {
                    $io->writeln(sprintf('<fg=gray>%s:</> %s', $category, implode(', ', $names)));
                }
            }
            return null;
        }

        if (str_starts_with($arg, 'edit')) {
            $this->handleEdit($io, trim(substr($arg, 4)));
            return null;
        }

        $roleName = strtolower(trim($arg));

        if ($roleName === 'reset' || $roleName === SystemRole::Orchestrator->value) {
            $modelString = $this->boot->roleResolver()->resolve(SystemRole::Orchestrator->value);
            $this->storage->updateSessionRole($sessionId, SystemRole::Orchestrator->value, $modelString);
            $io->success('Switched back to orchestrator.');
            return SystemRole::Orchestrator->value;
        }

        if (!$roleDiscovery->roleExists($roleName)) {
            $io->error(sprintf('Role "%s" not found. Available: %s', $roleName, implode(', ', $this->boot->roleResolver()->selectableRoles())));
            return null;
        }

        if ($preferences !== null && !$preferences->isRoleAllowed($roleName)) {
            $io->error(sprintf('Profile "%s" does not allow role "%s".', $activeProfile, $roleName));
            return null;
        }

        $role = $roleDiscovery->getRole($roleName);
        if ($role->isTemplate) {
            $io->error(sprintf('Role "%s" is a template role and cannot be used directly. Use /role edit %s to customize it.', $roleName, $roleName));
            return null;
        }

        $modelString = $this->boot->roleResolver()->resolve($roleName);
        $this->storage->updateSessionRole($sessionId, $roleName, $modelString);

        $io->success(sprintf(
            'Switched to %s (%s access, model: %s)',
            $role->displayName,
            $role->accessLevel,
            $modelString,
        ));

        return $roleName;
    }

    /**
     * Group role names by their declared category, sorted for stable display.
     *
     * @param list<string> $roleNames
     * @return array<string, list<string>>
     */
    private function groupByCategory(RoleDiscovery $roleDiscovery, array $roleNames): array
    {
        $grouped = [];
        foreach ($roleNames as $name) {
            try {
                $category = $roleDiscovery->getRole($name)->category;
            } catch (\Throwable) {
                $category = 'general';
            }
            $grouped[$category][] = $name;
        }

        ksort($grouped);

        return $grouped;
    }

    private function truncate(string $text, int $max): string
    {
        $text = trim(str_replace(["\n", "\r"], ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1) . '…';
    }

    private function loadProfilePreferences(?string $activeProfile): ?PersonaPreferences
    {
        if ($activeProfile === null || !$this->boot->profileDiscovery()->profileExists($activeProfile)) {
            return null;
        }

        return PersonaPreferences::fromProfilePath($this->boot->profileDiscovery()->getProfilePath($activeProfile));
    }

    public function handleRoles(SymfonyStyle $io, string $arg, string $activeRole): void
    {
        $roleDiscovery = $this->boot->roleDiscovery();
        $tracker = $this->boot->roleUpdateTracker();

        $parts = explode(' ', trim($arg), 2);
        $action = strtolower($parts[0]);
        $target = $parts[1] ?? '';

        if ($action === '' || $action === 'list') {
            $this->showTable($io, $roleDiscovery, $tracker, $activeRole);
            return;
        }

        if ($action === 'update') {
            $this->handleUpdate($io, $roleDiscovery, $tracker, $target);
            return;
        }

        if ($action === 'ignore' || $action === 'unignore') {
            $this->handleIgnore($io, $roleDiscovery, $action === 'ignore', trim($target));
            return;
        }

        $io->error("Unknown subcommand: {$action}. Use /roles, /roles update, /roles ignore, or /roles unignore.");
    }

    private function showTable(
        SymfonyStyle $io,
        RoleDiscovery $roleDiscovery,
        RoleUpdateTracker $tracker,
        string $activeRole,
    ): void {
        $allRoles = $roleDiscovery->availableRoles();
        $pendingUpdates = $tracker->checkForUpdates($roleDiscovery);
        $pendingMap = [];
        foreach ($pendingUpdates as $update) {
            $pendingMap[$update->roleName] = $update;
        }

        /** @var array<string, array{props: RoleProperties, update: string}> $collected */
        $collected = [];
        foreach ($allRoles as $roleName) {
            try {
                $props = $roleDiscovery->getRole($roleName);
            } catch (\Throwable) {
                continue;
            }

            $updateStatus = '-';
            if (isset($pendingMap[$roleName])) {
                $update = $pendingMap[$roleName];
                if ($update->ignoreUpdates) {
                    $updateStatus = '<fg=gray>ignored</>';
                } elseif ($update->isUserModified) {
                    $updateStatus = '<fg=yellow>update available (modified)</>';
                } else {
                    $updateStatus = '<fg=cyan>update available</>';
                }
            } elseif ($props->ignoreUpdates) {
                $updateStatus = '<fg=gray>ignored</>';
            }

            $collected[$roleName] = ['props' => $props, 'update' => $updateStatus];
        }

        // Group rows by category so related roles sit together.
        uasort($collected, static function (array $a, array $b): int {
            return [$a['props']->category, $a['props']->name] <=> [$b['props']->category, $b['props']->name];
        });

        $rows = [];
        foreach ($collected as $roleName => $entry) {
            $props = $entry['props'];
            $rows[] = [
                $roleName === $activeRole ? "<fg=green>{$roleName}</>" : $roleName,
                $props->category,
                $props->accessLevel,
                $this->truncate($props->description, 50),
                $entry['update'],
            ];
        }

        if ($rows === []) {
            $io->text('No roles discovered.');
        } else {
            $io->table(['Name', 'Category', 'Access', 'Description', 'Updates'], $rows);
            $io->text('<fg=gray>Use /role <name> to switch, /role edit <name> to edit, /roles update to apply updates.</>');
        }
    }

    private function handleUpdate(
        SymfonyStyle $io,
        RoleDiscovery $roleDiscovery,
        RoleUpdateTracker $tracker,
        string $target,
    ): void {
        $target = trim($target);

        if ($target !== '') {
            if (!$roleDiscovery->roleExists($target)) {
                $io->error(sprintf('Role "%s" not found.', $target));
                return;
            }

            $updates = $tracker->checkForUpdates($roleDiscovery);
            $found = null;
            foreach ($updates as $update) {
                if ($update->roleName === $target) {
                    $found = $update;
                    break;
                }
            }

            if ($found === null) {
                $io->info(sprintf('No pending update for role "%s".', $target));
                return;
            }

            if ($found->isUserModified) {
                $io->warning(sprintf(
                    'Role "%s" has local modifications. Updating will overwrite your changes (a backup will be created).',
                    $target,
                ));
                if (!$io->confirm('Continue?', false)) {
                    $io->text('Update cancelled.');
                    return;
                }
            }

            if ($tracker->applyUpdate($target, $roleDiscovery)) {
                $io->success(sprintf('Role "%s" updated to latest built-in version.', $target));
            } else {
                $io->error(sprintf('Failed to update role "%s".', $target));
            }
            return;
        }

        $updates = $tracker->checkForUpdates($roleDiscovery);
        if ($updates === []) {
            $io->info('All roles are up to date.');
            return;
        }

        $applied = 0;
        $skipped = 0;
        foreach ($updates as $update) {
            if ($update->ignoreUpdates) {
                continue;
            }

            if ($update->isUserModified) {
                $io->text(sprintf(
                    '  <fg=yellow>⚠</> <fg=white>%s</> — has local modifications, use <fg=cyan>/roles update %s</> to update individually',
                    $update->roleName,
                    $update->roleName,
                ));
                $skipped++;
                continue;
            }

            if ($tracker->applyUpdate($update->roleName, $roleDiscovery)) {
                $io->text(sprintf('  <fg=green>✓</> <fg=white>%s</> updated', $update->roleName));
                $applied++;
            }
        }

        if ($applied > 0 || $skipped > 0) {
            $io->newLine();
            $msgParts = [];
            if ($applied > 0) {
                $msgParts[] = "{$applied} updated";
            }
            if ($skipped > 0) {
                $msgParts[] = "{$skipped} skipped (locally modified)";
            }
            $io->text('<fg=gray>' . implode(', ', $msgParts) . '</>');
        }
    }

    private function handleIgnore(SymfonyStyle $io, RoleDiscovery $roleDiscovery, bool $ignore, string $roleName): void
    {
        if ($roleName === '') {
            $io->error(sprintf('Usage: /roles %s <role-name>', $ignore ? 'ignore' : 'unignore'));
            return;
        }

        if (!$roleDiscovery->roleExists($roleName)) {
            $io->error(sprintf('Role "%s" not found.', $roleName));
            return;
        }

        $props = $roleDiscovery->getRole($roleName);
        if ($props->ignoreUpdates === $ignore) {
            $io->info(sprintf('Role "%s" is already %s.', $roleName, $ignore ? 'ignored' : 'not ignored'));
            return;
        }

        $instructions = $roleDiscovery->readInstructions($roleName);
        $newProps = new RoleProperties(
            name: $props->name,
            displayName: $props->displayName,
            description: $props->description,
            version: $props->version,
            accessLevel: $props->accessLevel,
            category: $props->category,
            isBuiltin: $props->isBuiltin,
            editable: $props->editable,
            isTemplate: $props->isTemplate,
            ignoreUpdates: $ignore,
            model: $props->model,
            titleModel: $props->titleModel,
            toolkits: $props->toolkits,
            maxIterations: $props->maxIterations,
            path: $props->path,
        );

        $roleDiscovery->updateRole($roleName, $newProps, $instructions);
        $io->success(sprintf('Role "%s" will %s future built-in updates.', $roleName, $ignore ? 'ignore' : 'receive'));
    }

    private function handleEdit(SymfonyStyle $io, string $name): void
    {
        $name = strtolower(trim($name));
        $roleDiscovery = $this->boot->roleDiscovery();

        if ($name === '') {
            $io->error('Usage: /role edit <role-name>');
            return;
        }

        if (!$roleDiscovery->roleExists($name)) {
            $io->error(sprintf('Role "%s" not found. Available: %s', $name, implode(', ', $roleDiscovery->availableRoles())));
            return;
        }

        $props = $roleDiscovery->getRole($name);
        $editor = getenv('EDITOR') ?: getenv('VISUAL') ?: (PHP_OS_FAMILY === 'Windows' ? 'notepad' : 'vi');
        $path = $props->path;

        $io->text(sprintf('Opening <fg=cyan>%s</> in %s...', $name, basename($editor)));

        $exitCode = 0;
        passthru(sprintf('%s %s', $editor, escapeshellarg($path)), $exitCode);

        if ($exitCode === 0) {
            $roleDiscovery->invalidateCache();
            $io->success(sprintf('Role "%s" reloaded.', $name));
        } else {
            $io->warning('Editor exited with non-zero status.');
        }
    }
}
