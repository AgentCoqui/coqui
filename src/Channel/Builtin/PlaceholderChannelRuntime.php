<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Channel\Builtin;

use CoquiBot\Coqui\Contract\ChannelRuntimeInterface;

/**
 * Placeholder runtime used while first-party channel drivers are scaffolded.
 */
final class PlaceholderChannelRuntime implements ChannelRuntimeInterface
{
    private bool $started = false;

    private ?string $lastHeartbeatAt = null;

    /**
     * @param array<string, mixed> $instanceDefinition
     */
    public function __construct(
        private readonly string $driverDisplayName,
        private readonly array $instanceDefinition,
    ) {}

    public function start(): void
    {
        $this->started = true;
        $this->lastHeartbeatAt = gmdate('Y-m-d\TH:i:s\Z');
    }

    public function tick(): void
    {
        if (!$this->started) {
            return;
        }

        $this->lastHeartbeatAt = gmdate('Y-m-d\TH:i:s\Z');
    }

    public function stop(): void
    {
        $this->started = false;
        $this->lastHeartbeatAt = gmdate('Y-m-d\TH:i:s\Z');
    }

    public function healthReport(): array
    {
        $instanceName = (string) ($this->instanceDefinition['name'] ?? 'unknown');

        return [
            'worker_status' => $this->started ? 'placeholder' : 'stopped',
            'ready' => false,
            'summary' => sprintf('%s runtime scaffold registered for %s.', $this->driverDisplayName, $instanceName),
            'last_heartbeat_at' => $this->lastHeartbeatAt,
            'last_receive_at' => null,
            'last_send_at' => null,
            'inbound_backlog' => 0,
            'outbound_backlog' => 0,
            'consecutive_failures' => 0,
            'last_error' => null,
        ];
    }
}