<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Channel\ChannelConfigurationEditor;
use CoquiBot\Coqui\Channel\ChannelDiscovery;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Storage\ChannelStore;
use CoquiBot\Coqui\Storage\RuntimeStateStore;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /channels and its operator subcommands.
 */
final readonly class ChannelHandler
{
    public function __construct(
        private ChannelStore $channelStore,
        private ChannelConfigurationEditor $configEditor,
        private ChannelDiscovery $channelDiscovery,
        private ProfileDiscovery $profileDiscovery,
        private RuntimeStateStore $runtimeStateStore,
    ) {}

    public function handle(SymfonyStyle $io, string $arg): void
    {
        $trimmedArg = trim($arg);
        $parts = $trimmedArg !== '' ? preg_split('/\s+/', $trimmedArg) : [];
        $parts = is_array($parts) ? array_values(array_filter($parts, static fn(string $part): bool => $part !== '')) : [];
        $action = strtolower($parts[0] ?? '');

        match ($action) {
            'status', 'show' => $this->handleStatus($io, $parts[1] ?? ''),
            'drivers' => $this->handleDrivers($io),
            'health' => $this->handleHealth($io, $parts[1] ?? ''),
            'enable' => $this->handleEnable($io, $parts[1] ?? ''),
            'disable' => $this->handleDisable($io, $parts[1] ?? ''),
            'delete', 'remove' => $this->handleDelete($io, $parts[1] ?? ''),
            'add' => $this->handleAdd($io, $parts),
            'set' => $this->handleSet($io, $parts, $trimmedArg),
            'links' => $this->handleLinks($io, $parts[1] ?? ''),
            'link' => $this->handleLink($io, $parts),
            'unlink' => $this->handleUnlink($io, $parts),
            'deliveries' => $this->handleDeliveries($io, $parts[1] ?? ''),
            default => $this->handleList($io),
        };
    }

    private function handleList(SymfonyStyle $io): void
    {
        $channels = $this->channelStore->listInstances();
        if ($channels === []) {
            $io->info('No configured channels. Use /channels add <driver> <name> to create one.');
            return;
        }

        $stats = $this->channelStore->getStats();
        $io->section(sprintf('Channels (%d enabled / %d total)', $stats['enabled'], $stats['total']));

        $restart = $this->runtimeStateStore->apiRestartState();
        if ($restart['required']) {
            $io->warning('API restart pending: channel changes have been saved and the running API should be restarted to fully apply them.');
        }

        $rows = [];
        foreach ($channels as $channel) {
            $rows[] = [
                ((bool) ($channel['enabled'] ?? false)) ? '<fg=green>✓</>' : '<fg=red>✗</>',
                substr((string) $channel['id'], 0, 8) . '...',
                (string) $channel['name'],
                (string) $channel['driver'],
                ((bool) ($channel['ready'] ?? false)) ? 'ready' : 'not-ready',
                (string) ($channel['worker_status'] ?? 'missing'),
                (string) ($channel['last_heartbeat_at'] ?? '-'),
            ];
        }

        $io->table(['', 'ID', 'Name', 'Driver', 'Health', 'Worker', 'Heartbeat'], $rows);
    }

    private function handleStatus(SymfonyStyle $io, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /channels status <name|id>');
            return;
        }

        $channel = $this->channelStore->getByIdOrName($target);
        if ($channel === null) {
            $io->error(sprintf('No channel found matching "%s".', $target));
            return;
        }

        $io->section(sprintf('Channel: %s', (string) $channel['name']));
        $io->definitionList(
            ['ID' => (string) $channel['id']],
            ['Driver' => (string) $channel['driver']],
            ['Display' => (string) ($channel['display_name'] ?? $channel['name'])],
            ['Enabled' => ((bool) ($channel['enabled'] ?? false)) ? 'yes' : 'no'],
            ['Default profile' => (string) ($channel['default_profile'] ?? '-')],
            ['Ready' => ((bool) ($channel['ready'] ?? false)) ? 'yes' : 'no'],
            ['Worker status' => (string) ($channel['worker_status'] ?? 'missing')],
            ['Last heartbeat' => (string) ($channel['last_heartbeat_at'] ?? '-')],
            ['Last receive' => (string) ($channel['last_receive_at'] ?? '-')],
            ['Last send' => (string) ($channel['last_send_at'] ?? '-')],
            ['Inbound backlog' => (string) ($channel['inbound_backlog'] ?? 0)],
            ['Outbound backlog' => (string) ($channel['outbound_backlog'] ?? 0)],
            ['Consecutive failures' => (string) ($channel['consecutive_failures'] ?? 0)],
        );

        $settings = is_array($channel['settings'] ?? null) ? array_keys($channel['settings']) : [];
        $allowedScopes = is_array($channel['allowed_scopes'] ?? null) ? $channel['allowed_scopes'] : [];
        $security = is_array($channel['security'] ?? null) ? array_keys($channel['security']) : [];

        $io->text(sprintf('<fg=cyan>Settings keys:</> %s', $settings !== [] ? implode(', ', $settings) : '-'));
        $io->text(sprintf('<fg=cyan>Allowed scopes:</> %s', $allowedScopes !== [] ? implode(', ', $allowedScopes) : '-'));
        $io->text(sprintf('<fg=cyan>Security keys:</> %s', $security !== [] ? implode(', ', $security) : '-'));

        if (($channel['last_error'] ?? null) !== null && trim((string) $channel['last_error']) !== '') {
            $io->warning((string) $channel['last_error']);
        }
    }

    private function handleDrivers(SymfonyStyle $io): void
    {
        $rows = [];

        foreach ($this->channelDiscovery->drivers() as $name => $driver) {
            $caps = array_keys(array_filter($driver->capabilities(), static fn(bool $enabled): bool => $enabled));
            $rows[] = [
                $name,
                $driver->displayName(),
                $caps !== [] ? implode(', ', $caps) : '-',
            ];
        }

        $io->table(['Driver', 'Display', 'Capabilities'], $rows);
    }

    private function handleHealth(SymfonyStyle $io, string $target): void
    {
        if ($target === '') {
            $this->handleList($io);
            return;
        }

        $channel = $this->channelStore->getByIdOrName($target);
        if ($channel === null) {
            $io->error(sprintf('No channel found matching "%s".', $target));
            return;
        }

        $status = ((bool) ($channel['ready'] ?? false)) ? '<fg=green>healthy</>' : '<fg=red>unhealthy</>';
        $io->writeln(sprintf('%s: %s (%s)', $channel['name'], $status, (string) ($channel['worker_status'] ?? 'missing')));
        if (($channel['summary'] ?? null) !== null && trim((string) $channel['summary']) !== '') {
            $io->text((string) $channel['summary']);
        }
    }

    private function handleEnable(SymfonyStyle $io, string $target): void
    {
        $this->toggle($io, $target, true);
    }

    private function handleDisable(SymfonyStyle $io, string $target): void
    {
        $this->toggle($io, $target, false);
    }

    private function handleDelete(SymfonyStyle $io, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /channels delete <name|id>');
            return;
        }

        $channel = $this->channelStore->getByIdOrName($target);
        if ($channel === null) {
            $io->error(sprintf('No channel found matching "%s".', $target));
            return;
        }

        if (!$this->configEditor->delete((string) $channel['name'])) {
            $io->error('Failed to delete channel configuration.');
            return;
        }

        $this->runtimeStateStore->markApiRestartRequired(
            'Channel configuration changed. Restart the API server to ensure channel runtimes reload cleanly.',
            'repl.channels.delete',
            ['channel_name' => (string) $channel['name'], 'operation' => 'delete'],
        );

        $io->success(sprintf('Deleted channel "%s". Restart the API server to apply the removal if it is already running.', $channel['name']));
    }

    /**
     * @param list<string> $parts
     */
    private function handleAdd(SymfonyStyle $io, array $parts): void
    {
        $driver = trim((string) ($parts[1] ?? ''));
        $name = trim((string) ($parts[2] ?? ''));
        if ($driver === '' || $name === '') {
            $io->error('Usage: /channels add <driver> <name> [signal-account]');
            return;
        }

        $account = trim((string) ($parts[3] ?? ''));
        $payload = [
            'driver' => $driver,
            'displayName' => $name,
            'enabled' => true,
        ];

        if ($driver === 'signal' && $account !== '') {
            $payload['settings'] = ['account' => $account];
        }

        $errors = $this->configEditor->create($name, $payload);

        if ($errors !== []) {
            $io->error($errors);
            return;
        }

        $this->runtimeStateStore->markApiRestartRequired(
            'Channel configuration changed. Restart the API server to ensure channel runtimes reload cleanly.',
            'repl.channels.create',
            ['channel_name' => $name, 'operation' => 'create'],
        );

        $io->success(sprintf('Saved channel "%s" with driver "%s". Restart the API server to apply it if needed.', $name, $driver));
    }

    /**
     * @param list<string> $parts
     */
    private function handleSet(SymfonyStyle $io, array $parts, string $fullArg): void
    {
        $name = trim((string) ($parts[1] ?? ''));
        $field = trim((string) ($parts[2] ?? ''));
        if ($name === '' || $field === '') {
            $io->error('Usage: /channels set <name|id> <field> <value>');
            return;
        }

        $channel = $this->channelStore->getByIdOrName($name);
        if ($channel === null) {
            $io->error(sprintf('No channel found matching "%s".', $name));
            return;
        }

        $prefix = sprintf('set %s %s ', $parts[1], $parts[2]);
        $rawValue = str_starts_with($fullArg, $prefix) ? substr($fullArg, strlen($prefix)) : trim(implode(' ', array_slice($parts, 3)));
        if ($rawValue === '') {
            $io->error('A value is required.');
            return;
        }

        $patch = match ($field) {
            'driver' => ['driver' => $rawValue],
            'displayName', 'display_name' => ['displayName' => $rawValue],
            'defaultProfile', 'default_profile' => ['defaultProfile' => in_array(strtolower($rawValue), ['none', 'null'], true) ? null : $rawValue],
            'enabled' => ['enabled' => $this->parseBoolean($rawValue)],
            'settings' => ['settings' => $this->decodeJsonArgument($rawValue)],
            'allowedScopes', 'allowed_scopes' => ['allowedScopes' => $this->decodeJsonArgument($rawValue)],
            'security' => ['security' => $this->decodeJsonArgument($rawValue)],
            default => null,
        };

        if ($patch === null) {
            $io->error('Supported fields: driver, displayName, defaultProfile, enabled, settings, allowedScopes, security');
            return;
        }

        if (array_key_exists('enabled', $patch) && !is_bool($patch['enabled'])) {
            $io->error('enabled must be one of: true, false, on, off, yes, no, 1, 0');
            return;
        }

        foreach (['settings', 'allowedScopes', 'security'] as $structuredField) {
            if (array_key_exists($structuredField, $patch) && !is_array($patch[$structuredField])) {
                $io->error('JSON value required for structured fields.');
                return;
            }
        }

        $errors = $this->configEditor->update((string) $channel['name'], $patch);
        if ($errors !== []) {
            $io->error($errors);
            return;
        }

        $this->runtimeStateStore->markApiRestartRequired(
            'Channel configuration changed. Restart the API server to ensure channel runtimes reload cleanly.',
            'repl.channels.update',
            ['channel_name' => (string) $channel['name'], 'operation' => 'update'],
        );

        $io->success(sprintf('Updated channel "%s". Restart the API server to apply config changes if needed.', $channel['name']));
    }

    private function handleLinks(SymfonyStyle $io, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /channels links <name|id>');
            return;
        }

        $channel = $this->channelStore->getByIdOrName($target);
        if ($channel === null) {
            $io->error(sprintf('No channel found matching "%s".', $target));
            return;
        }

        $links = $this->channelStore->listLinks((string) $channel['id'], 50);
        if ($links === []) {
            $io->info(sprintf('No identity links for channel "%s".', $channel['name']));
            return;
        }

        $rows = [];
        foreach ($links as $link) {
            $rows[] = [
                substr((string) $link['id'], 0, 8) . '...',
                (string) $link['remote_user_key'],
                (string) ($link['remote_scope_key'] ?? '-'),
                (string) $link['profile'],
                (string) ($link['trust_level'] ?? 'linked'),
            ];
        }

        $io->table(['ID', 'Remote User', 'Scope', 'Profile', 'Trust'], $rows);
    }

    /**
     * @param list<string> $parts
     */
    private function handleLink(SymfonyStyle $io, array $parts): void
    {
        $target = trim((string) ($parts[1] ?? ''));
        $remoteUserKey = trim((string) ($parts[2] ?? ''));
        $profile = trim((string) ($parts[3] ?? ''));
        if ($target === '' || $remoteUserKey === '' || $profile === '') {
            $io->error('Usage: /channels link <name|id> <remote-user-key> <profile>');
            return;
        }

        $channel = $this->channelStore->getByIdOrName($target);
        if ($channel === null) {
            $io->error(sprintf('No channel found matching "%s".', $target));
            return;
        }

        if (!$this->profileDiscovery->profileExists($profile)) {
            $io->error(sprintf('Unknown profile "%s".', $profile));
            return;
        }

        $linkId = $this->channelStore->createLink((string) $channel['id'], $remoteUserKey, $profile);
        $io->success(sprintf('Created link %s for channel "%s".', $linkId, $channel['name']));
    }

    /**
     * @param list<string> $parts
     */
    private function handleUnlink(SymfonyStyle $io, array $parts): void
    {
        $target = trim((string) ($parts[1] ?? ''));
        $linkId = trim((string) ($parts[2] ?? ''));
        if ($target === '' || $linkId === '') {
            $io->error('Usage: /channels unlink <name|id> <link-id>');
            return;
        }

        $channel = $this->channelStore->getByIdOrName($target);
        if ($channel === null) {
            $io->error(sprintf('No channel found matching "%s".', $target));
            return;
        }

        if (!$this->channelStore->deleteLink((string) $channel['id'], $linkId)) {
            $io->error('Link not found.');
            return;
        }

        $io->success('Channel link removed.');
    }

    private function handleDeliveries(SymfonyStyle $io, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /channels deliveries <name|id>');
            return;
        }

        $channel = $this->channelStore->getByIdOrName($target);
        if ($channel === null) {
            $io->error(sprintf('No channel found matching "%s".', $target));
            return;
        }

        $deliveries = $this->channelStore->listDeliveries((string) $channel['id'], 25);
        if ($deliveries === []) {
            $io->info(sprintf('No deliveries recorded for channel "%s" yet.', $channel['name']));
            return;
        }

        $rows = [];
        foreach ($deliveries as $delivery) {
            $rows[] = [
                substr((string) $delivery['id'], 0, 8) . '...',
                (string) ($delivery['direction'] ?? '-'),
                (string) ($delivery['status'] ?? '-'),
                (string) ($delivery['queued_at'] ?? '-'),
                (string) ($delivery['completed_at'] ?? '-'),
            ];
        }

        $io->table(['ID', 'Direction', 'Status', 'Queued', 'Completed'], $rows);
    }

    private function toggle(SymfonyStyle $io, string $target, bool $enabled): void
    {
        if ($target === '') {
            $io->error(sprintf('Usage: /channels %s <name|id>', $enabled ? 'enable' : 'disable'));
            return;
        }

        $channel = $this->channelStore->getByIdOrName($target);
        if ($channel === null) {
            $io->error(sprintf('No channel found matching "%s".', $target));
            return;
        }

        $errors = $this->configEditor->setEnabled((string) $channel['name'], $enabled);
        if ($errors !== []) {
            $io->error($errors);
            return;
        }

        $this->runtimeStateStore->markApiRestartRequired(
            'Channel configuration changed. Restart the API server to ensure channel runtimes reload cleanly.',
            $enabled ? 'repl.channels.enable' : 'repl.channels.disable',
            ['channel_name' => (string) $channel['name'], 'operation' => $enabled ? 'enable' : 'disable'],
        );

        $io->success(sprintf('%s channel "%s". Restart the API server to apply the change if needed.', $enabled ? 'Enabled' : 'Disabled', $channel['name']));
    }

    private function parseBoolean(string $value): bool|null
    {
        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>|list<mixed>|null
     */
    private function decodeJsonArgument(string $value): array|null
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}