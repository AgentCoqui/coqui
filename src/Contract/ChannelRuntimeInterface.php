<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Long-lived runtime for one configured channel instance.
 */
interface ChannelRuntimeInterface
{
    public function start(): void;

    public function tick(): void;

    public function stop(): void;

    /**
     * @return array{
     *     worker_status: string,
     *     ready: bool,
     *     summary: string,
     *     last_heartbeat_at: ?string,
     *     last_receive_at: ?string,
     *     last_send_at: ?string,
     *     inbound_backlog: int,
     *     outbound_backlog: int,
     *     consecutive_failures: int,
     *     last_error: ?string
     * }
     */
    public function healthReport(): array;
}