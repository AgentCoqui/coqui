<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Channel\Builtin;

use CoquiBot\Coqui\Contract\ChannelRuntimeInterface;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Storage\ChannelStore;
use CoquiBot\Coqui\Support\Clock;
use React\ChildProcess\Process as ReactProcess;
use React\Stream\WritableStreamInterface;

/**
 * Long-lived Signal runtime backed by signal-cli JSON-RPC notifications.
 */
final class SignalCliChannelRuntime implements ChannelRuntimeInterface
{
    private const DEFAULT_BINARY = 'signal-cli';
    private const DEFAULT_RECEIVE_MODE = 'on-start';

    private ?ReactProcess $process = null;

    private bool $started = false;

    private bool $ready = false;

    private bool $stopRequested = false;

    private int $consecutiveFailures = 0;

    private int $inboundBacklog = 0;

    private int $outboundBacklog = 0;

    private ?string $lastHeartbeatAt = null;

    private ?string $lastReceiveAt = null;

    private ?string $lastSendAt = null;

    private ?string $lastError = null;

    private string $summary = 'Signal runtime not started.';

    private string $stdoutBuffer = '';

    private string $stderrBuffer = '';

    /** @var array<string, array{deliveryId: string}> */
    private array $pendingSendRequests = [];

    /** @var array<string, true> */
    private array $pendingDeliveryIds = [];

    /**
     * @param array<string, mixed> $instanceDefinition
     */
    public function __construct(
        private readonly array $instanceDefinition,
        private readonly ChannelStore $channelStore,
        private readonly string $channelInstanceId,
        private readonly string $workspacePath,
    ) {}

    public function start(): void
    {
        $this->started = true;
        $this->stopRequested = false;
        $this->lastHeartbeatAt = Clock::nowUtc();

        if (!$this->runPreflight()) {
            return;
        }

        $this->spawnProcess();
    }

    public function tick(): void
    {
        $this->lastHeartbeatAt = Clock::nowUtc();
        $this->inboundBacklog = $this->channelStore->countInboundBacklog($this->channelInstanceId);
        $this->outboundBacklog = $this->channelStore->countQueuedDeliveries($this->channelInstanceId);

        if (!$this->started || $this->stopRequested) {
            return;
        }

        if ($this->process === null) {
            if ($this->runPreflight()) {
                $this->spawnProcess();
            }
        }

        if ($this->ready) {
            $this->sendQueuedDeliveries();
        }
    }

    public function stop(): void
    {
        $this->stopRequested = true;
        $this->started = false;
        $this->ready = false;
        $this->summary = 'Signal runtime stopped.';
        $this->lastHeartbeatAt = Clock::nowUtc();

        if ($this->process !== null) {
            $this->process->terminate();
            $this->process = null;
        }

        $this->pendingSendRequests = [];
        $this->pendingDeliveryIds = [];
    }

    public function healthReport(): array
    {
        return [
            'worker_status' => $this->workerStatus(),
            'ready' => $this->ready,
            'summary' => $this->summary,
            'last_heartbeat_at' => $this->lastHeartbeatAt,
            'last_receive_at' => $this->lastReceiveAt,
            'last_send_at' => $this->lastSendAt,
            'inbound_backlog' => $this->inboundBacklog,
            'outbound_backlog' => $this->outboundBacklog,
            'consecutive_failures' => $this->consecutiveFailures,
            'last_error' => $this->lastError,
        ];
    }

    private function runPreflight(): bool
    {
        if ($this->account() === '') {
            $this->ready = false;
            $this->consecutiveFailures++;
            $this->lastError = 'Signal settings.account is required.';
            $this->summary = sprintf('Signal runtime configuration is incomplete for %s.', $this->instanceName());
            return false;
        }

        [$exitCode, $stdout, $stderr] = $this->runCommand([$this->binaryPath(), '--version']);

        if ($exitCode !== 0) {
            $this->ready = false;
            $this->consecutiveFailures++;
            $this->lastError = $this->truncate(trim($stderr !== '' ? $stderr : $stdout));
            $this->summary = sprintf('signal-cli preflight failed for %s.', $this->instanceName());
            return false;
        }

        $this->lastError = null;
        $this->summary = sprintf('Signal runtime ready for %s (%s).', $this->instanceName(), trim($stdout) !== '' ? trim($stdout) : 'signal-cli');
        return true;
    }

    private function sendQueuedDeliveries(): void
    {
        foreach ($this->channelStore->listQueuedDeliveries($this->channelInstanceId, 10) as $delivery) {
            $this->sendQueuedDelivery($delivery);
        }

        $this->outboundBacklog = $this->channelStore->countQueuedDeliveries($this->channelInstanceId);
    }

    /**
     * @param array<string, mixed> $delivery
     */
    private function sendQueuedDelivery(array $delivery): void
    {
        $payload = is_array($delivery['payload'] ?? null) ? $delivery['payload'] : [];
        $message = trim((string) ($payload['message'] ?? ''));
        $groupId = $this->firstNonEmptyString([$payload['group_id'] ?? null]);
        $recipient = $this->firstNonEmptyString([$payload['recipient'] ?? null]);
        $deliveryId = (string) $delivery['id'];

        if ($message === '' || ($groupId === null && $recipient === null)) {
            $attemptCount = $this->channelStore->recordDeliveryAttempt(
                deliveryId: $deliveryId,
                resultStatus: 'failed',
                providerResponseBody: 'Delivery payload is missing message text or destination.',
            );
            $this->channelStore->markDeliveryFailed($deliveryId, $attemptCount, 'Delivery payload is missing message text or destination.');
            $this->lastError = 'Invalid queued Signal delivery payload.';
            $this->consecutiveFailures++;
            return;
        }

        if (isset($this->pendingDeliveryIds[$deliveryId])) {
            return;
        }

        if ($this->process === null || $this->process->stdin === null || !$this->process->stdin instanceof WritableStreamInterface) {
            $attemptCount = $this->channelStore->recordDeliveryAttempt(
                deliveryId: $deliveryId,
                resultStatus: 'failed',
                providerResponseBody: 'Signal JSON-RPC process is not available for outbound delivery.',
            );
            $this->channelStore->markDeliveryFailed($deliveryId, $attemptCount, 'Signal JSON-RPC process is not available for outbound delivery.');
            $this->lastError = 'Signal JSON-RPC process is not available for outbound delivery.';
            $this->consecutiveFailures++;
            return;
        }

        $requestId = 'send-' . $deliveryId;
        $request = $this->buildSendRequest($requestId, $message, $recipient, $groupId);
        $written = $this->process->stdin->write($request . "\n");

        if ($written === false) {
            $attemptCount = $this->channelStore->recordDeliveryAttempt(
                deliveryId: $deliveryId,
                resultStatus: 'failed',
                providerResponseBody: 'Failed to write outbound request to Signal JSON-RPC process.',
            );
            $this->channelStore->markDeliveryFailed($deliveryId, $attemptCount, 'Failed to write outbound request to Signal JSON-RPC process.');
            $this->lastError = 'Failed to write outbound request to Signal JSON-RPC process.';
            $this->consecutiveFailures++;
            return;
        }

        $this->pendingSendRequests[$requestId] = ['deliveryId' => $deliveryId];
        $this->pendingDeliveryIds[$deliveryId] = true;
    }

    private function buildSendRequest(string $requestId, string $message, ?string $recipient, ?string $groupId): string
    {
        $params = ['message' => $message];

        if ($groupId !== null) {
            $params['groupId'] = $groupId;
        } elseif ($recipient !== null) {
            $params['recipient'] = $recipient;
        }

        return json_encode([
            'jsonrpc' => '2.0',
            'method' => 'send',
            'params' => $params,
            'id' => $requestId,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractProviderMessageIdFromResponse(array $response): ?string
    {
        if (is_array($response['result'] ?? null) && isset($response['result']['timestamp'])) {
            return (string) $response['result']['timestamp'];
        }

        if (isset($response['timestamp']) && (is_string($response['timestamp']) || is_int($response['timestamp']))) {
            return (string) $response['timestamp'];
        }

        return null;
    }

    private function spawnProcess(): void
    {
        $command = $this->buildJsonRpcCommand();
        $this->stdoutBuffer = '';
        $this->stderrBuffer = '';

        try {
            $process = new ReactProcess($command, $this->workspacePath);
            $process->start();
        } catch (\Throwable $e) {
            $this->process = null;
            $this->ready = false;
            $this->consecutiveFailures++;
            $this->lastError = $this->truncate($e->getMessage());
            $this->summary = sprintf('Failed to start signal-cli runtime for %s.', $this->instanceName());
            return;
        }

        $this->process = $process;
        $this->ready = true;
        $this->summary = sprintf('Signal JSON-RPC runtime active for %s.', $this->instanceName());

        $process->stdout?->on('data', function (string $chunk): void {
            $this->lastHeartbeatAt = Clock::nowUtc();
            $this->handleStdoutChunk($chunk);
        });

        $process->stderr?->on('data', function (string $chunk): void {
            $this->lastHeartbeatAt = Clock::nowUtc();
            $this->stderrBuffer .= $chunk;
            $trimmed = trim($chunk);
            if ($trimmed !== '') {
                $this->lastError = $this->truncate($trimmed);
            }
        });

        $process->on('exit', function (?int $exitCode, ?int $termSignal = null): void {
            $this->process = null;
            $this->ready = false;
            $this->lastHeartbeatAt = Clock::nowUtc();

            if ($this->stopRequested) {
                $this->summary = 'Signal runtime stopped.';
                return;
            }

            $this->consecutiveFailures++;
            $error = trim($this->stderrBuffer);
            if ($error !== '') {
                $this->lastError = $this->truncate($error);
            }

            $reason = $termSignal !== null
                ? sprintf('signal %d', $termSignal)
                : sprintf('exit %d', (int) ($exitCode ?? 0));
            $this->summary = sprintf('Signal runtime for %s exited (%s).', $this->instanceName(), $reason);
        });
    }

    private function handleStdoutChunk(string $chunk): void
    {
        $this->stdoutBuffer .= $chunk;

        while (($newlinePos = strpos($this->stdoutBuffer, "\n")) !== false) {
            $line = trim(substr($this->stdoutBuffer, 0, $newlinePos));
            $this->stdoutBuffer = (string) substr($this->stdoutBuffer, $newlinePos + 1);

            if ($line === '') {
                continue;
            }

            $this->handleJsonLine($line);
        }
    }

    private function handleJsonLine(string $line): void
    {
        try {
            $payload = json_decode($line, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->lastError = $this->truncate(sprintf('signal-cli emitted non-JSON output: %s', $line));
            return;
        }

        if (!is_array($payload)) {
            return;
        }

        if (array_key_exists('id', $payload) && (array_key_exists('result', $payload) || array_key_exists('error', $payload))) {
            $this->handleJsonRpcResponse($payload);
            return;
        }

        if (($payload['method'] ?? null) === 'receive' && is_array($payload['params']['envelope'] ?? null)) {
            $this->persistEnvelope($payload['params']['envelope']);
            return;
        }

        if ($this->looksLikeSignalEnvelope($payload)) {
            $this->persistEnvelope($payload);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleJsonRpcResponse(array $payload): void
    {
        $requestId = isset($payload['id']) && (is_string($payload['id']) || is_int($payload['id']))
            ? (string) $payload['id']
            : null;

        if ($requestId === null || !isset($this->pendingSendRequests[$requestId])) {
            return;
        }

        $deliveryId = $this->pendingSendRequests[$requestId]['deliveryId'];
        unset($this->pendingSendRequests[$requestId], $this->pendingDeliveryIds[$deliveryId]);

        if (is_array($payload['error'] ?? null)) {
            $errorMessage = trim((string) ($payload['error']['message'] ?? 'signal-cli send failed.'));
            $attemptCount = $this->channelStore->recordDeliveryAttempt(
                deliveryId: $deliveryId,
                resultStatus: 'failed',
                providerResponseBody: $this->truncate(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: $errorMessage),
            );
            $this->channelStore->markDeliveryFailed($deliveryId, $attemptCount, $errorMessage !== '' ? $errorMessage : 'signal-cli send failed.');
            $this->lastError = $errorMessage !== '' ? $errorMessage : 'signal-cli send failed.';
            $this->consecutiveFailures++;
            return;
        }

        $providerMessageId = $this->extractProviderMessageIdFromResponse($payload);
        $attemptCount = $this->channelStore->recordDeliveryAttempt(
            deliveryId: $deliveryId,
            resultStatus: 'sent',
            providerResponseBody: $this->truncate(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: ''),
        );
        $this->channelStore->markDeliverySent($deliveryId, $attemptCount, $providerMessageId);
        $this->lastSendAt = Clock::nowUtc();
        $this->lastError = null;
        $this->consecutiveFailures = 0;
        $this->summary = sprintf(
            'Signal runtime active for %s. %d inbound event(s) pending, %d outbound delivery(s) queued.',
            $this->instanceName(),
            $this->inboundBacklog,
            $this->channelStore->countQueuedDeliveries($this->channelInstanceId),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function looksLikeSignalEnvelope(array $payload): bool
    {
        if (isset($payload['envelope']) && is_array($payload['envelope'])) {
            return false;
        }

        return isset($payload['source'])
            || isset($payload['sourceNumber'])
            || isset($payload['dataMessage'])
            || isset($payload['receiptMessage']);
    }

    /**
     * @param array<string, mixed> $envelope
     */
    private function persistEnvelope(array $envelope): void
    {
        if (!$this->shouldPersistEnvelope($envelope)) {
            return;
        }

        $normalized = $this->normalizeEnvelope($envelope);
        $conversationId = $this->channelStore->upsertConversation(
            channelInstanceId: $this->channelInstanceId,
            remoteConversationKey: $normalized['conversation_key'],
            remoteThreadKey: $normalized['thread_key'],
            profile: $normalized['profile'],
            lastMessageAt: $normalized['received_at'],
            metadata: $normalized['conversation_metadata'],
        );

        $eventId = $this->channelStore->createInboundEvent(
            channelInstanceId: $this->channelInstanceId,
            conversationId: $conversationId,
            providerEventId: $normalized['provider_event_id'],
            dedupeKey: $normalized['dedupe_key'],
            eventType: $normalized['event_type'],
            remoteUserKey: $normalized['remote_user_key'],
            payload: $envelope,
            normalized: $normalized['normalized_payload'],
            receivedAt: $normalized['received_at'],
        );

        if ($eventId === null) {
            return;
        }

        $this->channelStore->upsertConversation(
            channelInstanceId: $this->channelInstanceId,
            remoteConversationKey: $normalized['conversation_key'],
            remoteThreadKey: $normalized['thread_key'],
            profile: $normalized['profile'],
            lastInboundEventId: $eventId,
            lastMessageAt: $normalized['received_at'],
            metadata: $normalized['conversation_metadata'],
        );

        $this->lastReceiveAt = $normalized['received_at'];
        $this->lastError = null;
        $this->consecutiveFailures = 0;
        $this->inboundBacklog = $this->channelStore->countInboundBacklog($this->channelInstanceId);
        $this->outboundBacklog = $this->channelStore->countQueuedDeliveries($this->channelInstanceId);
        $this->summary = sprintf(
            'Signal runtime active for %s. %d inbound event(s) pending, %d outbound delivery(s) queued.',
            $this->instanceName(),
            $this->inboundBacklog,
            $this->outboundBacklog,
        );
    }

    /**
     * Ignore transport noise such as typing indicators, receipts, and empty envelopes.
     * Only actionable user content should enter the inbound task pipeline.
     *
     * @param array<string, mixed> $envelope
     */
    private function shouldPersistEnvelope(array $envelope): bool
    {
        $dataMessage = is_array($envelope['dataMessage'] ?? null) ? $envelope['dataMessage'] : [];

        if ($dataMessage === []) {
            return false;
        }

        $messageText = $this->firstNonEmptyString([
            $dataMessage['message'] ?? null,
        ]);
        $attachments = is_array($dataMessage['attachments'] ?? null) ? $dataMessage['attachments'] : [];

        return $messageText !== null || $attachments !== [];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array{
     *   provider_event_id: ?string,
     *   dedupe_key: string,
     *   event_type: string,
     *   remote_user_key: ?string,
     *   conversation_key: string,
     *   thread_key: ?string,
     *   profile: ?string,
     *   received_at: string,
     *   normalized_payload: array<string, mixed>,
     *   conversation_metadata: array<string, mixed>
     * }
     */
    private function normalizeEnvelope(array $envelope): array
    {
        $dataMessage = is_array($envelope['dataMessage'] ?? null) ? $envelope['dataMessage'] : [];
        $timestampMs = $this->coerceInt($dataMessage['timestamp'] ?? $envelope['timestamp'] ?? null) ?? 0;
        $receivedAt = $timestampMs > 0
            ? gmdate('Y-m-d\TH:i:s\Z', (int) floor($timestampMs / 1000))
            : Clock::nowUtc();
        $remoteUserKey = $this->firstNonEmptyString([
            $envelope['sourceNumber'] ?? null,
            $envelope['source'] ?? null,
            $envelope['sourceUuid'] ?? null,
        ]);
        $groupId = $this->extractGroupId($dataMessage, $envelope);
        $conversationKey = $groupId !== null
            ? 'signal-group:' . $groupId
            : 'signal-dm:' . ($remoteUserKey ?? 'unknown');
        $providerEventId = $timestampMs > 0 ? (string) $timestampMs : null;
        $dedupeKey = implode(':', array_filter([
            $groupId ?? null,
            $remoteUserKey,
            $providerEventId,
            isset($envelope['sourceDevice']) ? (string) $envelope['sourceDevice'] : null,
        ], static fn(?string $value): bool => $value !== null && $value !== ''));

        if ($dedupeKey === '') {
            $dedupeKey = sha1(json_encode($envelope, JSON_UNESCAPED_SLASHES) ?: uniqid('signal', true));
        }

        $messageText = $this->firstNonEmptyString([
            $dataMessage['message'] ?? null,
        ]);
        $attachments = is_array($dataMessage['attachments'] ?? null) ? $dataMessage['attachments'] : [];
        $profile = is_string($this->instanceDefinition['default_profile'] ?? null)
            ? (string) $this->instanceDefinition['default_profile']
            : null;

        return [
            'provider_event_id' => $providerEventId,
            'dedupe_key' => $dedupeKey,
            'event_type' => $dataMessage !== [] ? 'data_message' : 'signal_envelope',
            'remote_user_key' => $remoteUserKey,
            'conversation_key' => $conversationKey,
            'thread_key' => null,
            'profile' => $profile,
            'received_at' => $receivedAt,
            'normalized_payload' => [
                'source' => $remoteUserKey,
                'source_uuid' => $envelope['sourceUuid'] ?? null,
                'source_name' => $envelope['sourceName'] ?? null,
                'source_device' => $envelope['sourceDevice'] ?? null,
                'timestamp_ms' => $timestampMs > 0 ? $timestampMs : null,
                'message' => $messageText,
                'attachment_count' => count($attachments),
                'conversation_key' => $conversationKey,
                'group_id' => $groupId,
                'is_group' => $groupId !== null,
            ],
            'conversation_metadata' => [
                'source_name' => $envelope['sourceName'] ?? null,
                'group_id' => $groupId,
                'driver' => 'signal',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $dataMessage
     * @param array<string, mixed> $envelope
     */
    private function extractGroupId(array $dataMessage, array $envelope): ?string
    {
        $groupInfo = is_array($dataMessage['groupInfo'] ?? null) ? $dataMessage['groupInfo'] : [];

        return $this->firstNonEmptyString([
            $groupInfo['groupId'] ?? null,
            $dataMessage['groupId'] ?? null,
            $envelope['groupId'] ?? null,
        ]);
    }

    private function buildJsonRpcCommand(): string
    {
        $args = [
            $this->binaryPath(),
            '-a',
            $this->account(),
            'jsonRpc',
            '--receive-mode=' . $this->receiveMode(),
        ];

        if ($this->ignoreAttachments()) {
            $args[] = '--ignore-attachments';
        }

        if ($this->sendReadReceipts()) {
            $args[] = '--send-read-receipts';
        }

        return implode(' ', array_map(static fn(string $arg): string => escapeshellarg($arg), $args));
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCommand(array $command, ?string $stdin = null): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($this->buildIsolatedCommand($command), $descriptors, $pipes, $this->workspacePath);
        if (!is_resource($process)) {
            return [1, '', 'Failed to start process'];
        }

        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, (string) $stdout, (string) $stderr];
    }

    /**
     * Wrap child commands so they do not inherit the API listener socket.
     *
     * ReactPHP's listener remains open across proc_open() children on this runtime,
     * which lets signal-cli send processes inherit port 3300 and stall health/API
     * requests. Close all non-stdio descriptors before exec'ing the real command.
     *
     * @param list<string> $command
     * @return list<string>
     */
    private function buildIsolatedCommand(array $command): array
    {
        $script = <<<'SH'
for fd_path in /dev/fd/*; do
    [ -e "$fd_path" ] || continue
    fd=${fd_path##*/}
    case "$fd" in
        ''|*[!0-9]*|0|1|2)
            continue
            ;;
    esac
    eval "exec ${fd}>&- ${fd}<&-" 2>/dev/null || true
done
exec "$@"
SH;

        return [
            '/bin/sh',
            '-c',
            $script,
            'signal-cli-wrapper',
            ...$command,
        ];
    }

    private function workerStatus(): string
    {
        if ($this->process !== null && $this->ready) {
            return 'running';
        }

        if ($this->stopRequested || !$this->started) {
            return 'stopped';
        }

        if ($this->lastError !== null && $this->lastError !== '') {
            return 'error';
        }

        return 'starting';
    }

    private function binaryPath(): string
    {
        $settings = is_array($this->instanceDefinition['settings'] ?? null) ? $this->instanceDefinition['settings'] : [];
        $binary = $settings['binary'] ?? self::DEFAULT_BINARY;

        return is_string($binary) && trim($binary) !== '' ? trim($binary) : self::DEFAULT_BINARY;
    }

    private function account(): string
    {
        $settings = is_array($this->instanceDefinition['settings'] ?? null) ? $this->instanceDefinition['settings'] : [];

        return trim((string) ($settings['account'] ?? ''));
    }

    private function receiveMode(): string
    {
        $settings = is_array($this->instanceDefinition['settings'] ?? null) ? $this->instanceDefinition['settings'] : [];
        $mode = trim((string) ($settings['receiveMode'] ?? self::DEFAULT_RECEIVE_MODE));

        return $mode === 'on-start' ? $mode : self::DEFAULT_RECEIVE_MODE;
    }

    private function ignoreAttachments(): bool
    {
        $settings = is_array($this->instanceDefinition['settings'] ?? null) ? $this->instanceDefinition['settings'] : [];

        return !array_key_exists('ignoreAttachments', $settings) || (bool) $settings['ignoreAttachments'];
    }

    private function sendReadReceipts(): bool
    {
        $settings = is_array($this->instanceDefinition['settings'] ?? null) ? $this->instanceDefinition['settings'] : [];

        return (bool) ($settings['sendReadReceipts'] ?? false);
    }

    private function instanceName(): string
    {
        return (string) ($this->instanceDefinition['name'] ?? 'unknown');
    }

    /**
     * @param array<int, mixed> $values
     */
    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function coerceInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function truncate(string $value, int $maxBytes = 1000): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        return substr($value, 0, $maxBytes - 3) . '...';
    }

}