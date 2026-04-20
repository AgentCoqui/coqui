<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Channel\ChannelConfig;
use CoquiBot\Coqui\Channel\ChannelDiscovery;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Contract\ChannelRuntimeInterface;
use CoquiBot\Coqui\Storage\ChannelStore;

/**
 * Reconciles configured channel instances into long-lived runtimes and health state.
 */
final class ChannelManager
{
    /** @var array<string, ChannelRuntimeInterface> */
    private array $runtimes = [];

    /** @var array<string, string> */
    private array $runtimeFingerprints = [];

    private ?string $lastTickAt = null;

    private ?string $lastReconcileAt = null;

    /**
     * @param array<string, mixed> $runtimeContext
     */
    public function __construct(
        private readonly OpenClawConfig $config,
        private readonly ChannelDiscovery $discovery,
        private readonly ChannelStore $store,
        private readonly array $runtimeContext = [],
    ) {}

    public function tick(): void
    {
        $this->lastTickAt = gmdate('Y-m-d\TH:i:s\Z');
        $this->reconcile();

        foreach ($this->runtimes as $name => $runtime) {
            $row = $this->store->getByName($name);
            if ($row === null) {
                $this->stopRuntime($name);
                continue;
            }

            try {
                $runtime->tick();
                $this->store->updateRuntimeState((string) $row['id'], $runtime->healthReport());
            } catch (\Throwable $e) {
                $this->stopRuntime($name);
                $this->store->updateRuntimeState((string) $row['id'], [
                    'worker_status' => 'error',
                    'ready' => false,
                    'summary' => 'Channel runtime tick failed.',
                    'last_heartbeat_at' => gmdate('Y-m-d\TH:i:s\Z'),
                    'last_error' => $e->getMessage(),
                    'inbound_backlog' => 0,
                    'outbound_backlog' => 0,
                    'consecutive_failures' => 1,
                ]);
            }
        }
    }

    public function reconcile(): void
    {
        $this->lastReconcileAt = gmdate('Y-m-d\TH:i:s\Z');
        $channelConfig = ChannelConfig::fromArray($this->config->getChannelConfig());
        $instanceNames = [];

        foreach ($channelConfig->instances() as $definition) {
            $name = (string) $definition['name'];
            $instanceNames[] = $name;

            $driver = $this->discovery->driver((string) $definition['driver']);
            $instanceId = $this->store->upsertConfiguredInstance($definition, $driver?->capabilities());

            if ($driver === null) {
                $this->stopRuntime($name);
                $this->store->updateRuntimeState($instanceId, [
                    'worker_status' => 'driver_missing',
                    'ready' => false,
                    'summary' => 'Configured driver is not registered.',
                    'last_error' => sprintf('Unknown channel driver: %s', (string) $definition['driver']),
                    'inbound_backlog' => 0,
                    'outbound_backlog' => 0,
                    'consecutive_failures' => 0,
                ]);
                continue;
            }

            if (!(bool) $definition['enabled']) {
                $this->stopRuntime($name);
                $this->store->updateRuntimeState($instanceId, [
                    'worker_status' => 'disabled',
                    'ready' => false,
                    'summary' => 'Channel instance is disabled.',
                    'inbound_backlog' => 0,
                    'outbound_backlog' => 0,
                    'consecutive_failures' => 0,
                ]);
                continue;
            }

            $errors = $driver->validateInstanceConfig($definition);
            if ($errors !== []) {
                $this->stopRuntime($name);
                $this->store->updateRuntimeState($instanceId, [
                    'worker_status' => 'invalid_configuration',
                    'ready' => false,
                    'summary' => 'Channel instance configuration is invalid.',
                    'last_error' => implode('; ', $errors),
                    'inbound_backlog' => 0,
                    'outbound_backlog' => 0,
                    'consecutive_failures' => 0,
                ]);
                continue;
            }

            $fingerprint = $this->fingerprint($definition);
            $runtime = $this->runtimes[$name] ?? null;

            if ($runtime === null || ($this->runtimeFingerprints[$name] ?? null) !== $fingerprint) {
                $this->stopRuntime($name);
                $runtime = $driver->createRuntime($definition, $this->runtimeContext + [
                    'driverName' => $driver->driverName(),
                    'driverDisplayName' => $driver->displayName(),
                ]);
                $runtime->start();
                $this->runtimes[$name] = $runtime;
                $this->runtimeFingerprints[$name] = $fingerprint;
            }

            $this->store->updateRuntimeState($instanceId, $runtime->healthReport());
        }

        $this->store->pruneConfigInstances($instanceNames);

        foreach (array_keys($this->runtimes) as $name) {
            if (!in_array($name, $instanceNames, true)) {
                $this->stopRuntime($name);
            }
        }
    }

    public function shutdown(): void
    {
        foreach (array_keys($this->runtimes) as $name) {
            $this->stopRuntime($name);
        }
    }

    public function lastTickAt(): ?string
    {
        return $this->lastTickAt;
    }

    public function lastReconcileAt(): ?string
    {
        return $this->lastReconcileAt;
    }

    /**
     * @return array{total: int, enabled: int, ready: int, errors: int, active_runtimes: int, registered_drivers: int}
     */
    public function stats(): array
    {
        $stats = $this->store->getStats();
        $stats['active_runtimes'] = count($this->runtimes);
        $stats['registered_drivers'] = count($this->discovery->driverNames());

        return $stats;
    }

    private function stopRuntime(string $name): void
    {
        $runtime = $this->runtimes[$name] ?? null;
        if ($runtime !== null) {
            try {
                $runtime->stop();
            } catch (\Throwable) {
                // Best-effort shutdown — runtime failures are reflected on next reconcile.
            }
        }

        unset($this->runtimes[$name], $this->runtimeFingerprints[$name]);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function fingerprint(array $definition): string
    {
        $encoded = json_encode([
            'driver' => $definition['driver'] ?? '',
            'enabled' => $definition['enabled'] ?? true,
            'settings' => $definition['settings'] ?? [],
            'allowed_scopes' => $definition['allowed_scopes'] ?? [],
            'security' => $definition['security'] ?? [],
        ], JSON_UNESCAPED_SLASHES);

        return sha1(is_string($encoded) ? $encoded : $definition['name']);
    }
}