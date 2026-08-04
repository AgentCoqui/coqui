<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Content\ContentStore;
use CoquiBot\Coqui\Export\WireFormat;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Utility\PromptSizeValidator;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\EventLoop\TimerInterface;
use React\Http\Message\Response;
use React\Stream\ThroughStream;

/**
 * Message endpoints — including the core SSE streaming endpoint.
 *
 * GET    /api/v1/sessions/{id}/messages             — list messages
 * POST   /api/v1/sessions/{id}/messages             — send prompt (SSE stream)
 * POST   /api/v1/sessions/{id}/messages?stream=false — send prompt (JSON response)
 * DELETE /api/v1/sessions/{id}/messages/{messageId}  — delete a single message
 *
 * Agent turns run in child processes via AgentTurnManager. Events are polled
 * from SQLite and streamed to the client, keeping the ReactPHP event loop
 * fully responsive for concurrent requests.
 */
final readonly class MessageHandler
{
    /** How often to poll SQLite for new events (seconds). */
    private const float POLL_INTERVAL = 0.5;

    private ContentStore $contentStore;

    public function __construct(
        private SessionStorage $storage,
        private AgentTurnManager $turnManager,
    ) {
        $this->contentStore = new ContentStore($storage->getPdo());
    }

    /**
     * GET /api/v1/sessions/{id}/messages
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $messages = $this->storage->getMessages($id);

        return Router::jsonResponse([
            'session_id' => $id,
            'messages' => $messages,
            'count' => count($messages),
        ]);
    }

    /**
     * POST /api/v1/sessions/{id}/messages  { "prompt": "...", "attachments": [{"content_ref": "...", "mime_type": "..."}] }
     *
     * Default: returns an SSE stream.
     * With ?stream=false: blocks and returns JSON with the final result.
     *
     * The optional "attachments" array references content-addressed blobs by
     * `content_ref` (from a prior POST /api/v1/sessions/{id}/files upload). Each
     * blob's bytes are resolved from the {@see ContentStore} and passed to the
     * turn: images are sent to the LLM as vision content; text/document blobs are
     * injected as context in the prompt.
     */
    public function send(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireWritableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body) || !isset($body['prompt']) || trim((string) $body['prompt']) === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Missing or empty "prompt" field');
        }

        $prompt = trim((string) $body['prompt']);

        $sizeError = PromptSizeValidator::validateApiText($prompt);
        if ($sizeError !== null) {
            return Router::errorResponse(
                ApiErrorCode::PAYLOAD_TOO_LARGE,
                $sizeError,
            );
        }

        // Resolve optional typed attachments (content_ref -> materialized paths)
        $filePaths = $this->resolveAttachmentPaths($body);
        if ($filePaths instanceof Response) {
            return $filePaths; // Validation error response
        }

        // Check for already-active agent run on this session
        if ($this->turnManager->isActive($id)) {
            return Router::errorResponse(ApiErrorCode::AGENT_BUSY, 'Session already has an active agent run');
        }

        // Check for streaming preference
        $params = $request->getQueryParams();
        $streamEnabled = !isset($params['stream']) || $params['stream'] !== 'false';

        if (!$streamEnabled) {
            return $this->sendBlocking($id, $prompt, $filePaths);
        }

        return $this->sendStreaming($id, $prompt, $filePaths);
    }

    /**
     * Resolve typed `attachments[]` from the request body into filesystem paths.
     *
     * Each entry is an attachment.json object; its `content_ref` is looked up in
     * the content-addressed {@see ContentStore} (the store the upload endpoint
     * writes) and its bytes are materialized to a short-lived scratch file, since
     * the turn pipeline consumes filesystem paths. An unknown ref is a
     * `content_not_found` (404); a malformed entry is a `validation_error` (422).
     *
     * @param array<string, mixed> $body
     * @return string[]|Response  Array of file paths on success, or error Response on failure.
     */
    private function resolveAttachmentPaths(array $body): array|Response
    {
        if (!isset($body['attachments']) || !is_array($body['attachments'])) {
            return [];
        }

        $paths = [];

        foreach ($body['attachments'] as $attachment) {
            if (!is_array($attachment) || !isset($attachment['content_ref']) || !is_string($attachment['content_ref'])) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Each entry in "attachments" must be an object with a string "content_ref"',
                );
            }

            $contentRef = $attachment['content_ref'];
            $bytes = $this->contentStore->readBytes($contentRef);

            if ($bytes === null) {
                return Router::errorResponse(
                    ApiErrorCode::CONTENT_NOT_FOUND,
                    sprintf('Content "%s" not found', $contentRef),
                );
            }

            $path = $this->materializeAttachment($contentRef, $bytes);

            if ($path === null) {
                return Router::errorResponse(
                    ApiErrorCode::INTERNAL_ERROR,
                    'Failed to materialize attachment content',
                );
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * Materialize a content blob's bytes to a scratch file for the turn to read.
     *
     * The turn pipeline (and {@see \CoquiBot\Coqui\Agent\AgentRunner::buildUserMessage})
     * consumes filesystem paths and sniffs the MIME from the file bytes, so the
     * scratch name is arbitrary. Returns the path, or null on a write failure.
     */
    private function materializeAttachment(string $contentRef, string $bytes): ?string
    {
        $dir = sys_get_temp_dir() . '/coqui-attachments';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }

        $safeRef = preg_replace('/[^a-zA-Z0-9_-]/', '', $contentRef) ?? '';
        $path = $dir . '/' . bin2hex(random_bytes(6)) . '-' . $safeRef;

        return file_put_contents($path, $bytes) !== false ? $path : null;
    }

    /**
     * SSE streaming response — the core endpoint.
     *
     * Spawns the agent turn in a child process and returns an SSE stream
     * that polls SQLite for events emitted by the child process.
     *
     * @param string[] $filePaths
     */
    private function sendStreaming(string $sessionId, string $prompt, array $filePaths = []): Response
    {
        $turnProcessId = $this->turnManager->start(
            $sessionId,
            $prompt,
            $filePaths !== [] ? $filePaths : null,
        );

        if ($turnProcessId === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to start agent turn');
        }

        $stream = new ThroughStream();

        // Send initial connection event
        $stream->write("event: connected\ndata: {\"turn_process_id\":\"{$turnProcessId}\"}\n\n");

        $lastEventId = null;
        /** @var ?TimerInterface $timer */
        $timer = null;

        // Poll SQLite for new events from the child process
        $timer = Loop::addPeriodicTimer(self::POLL_INTERVAL, function () use (
            $stream,
            $turnProcessId,
            &$lastEventId,
            &$timer,
        ): void {
            try {
                $events = $this->storage->getTurnEvents($turnProcessId, $lastEventId);

                // All turn-event types stream through unchanged (no allowlist);
                // this includes the `question` event emitted for structured questions.
                foreach ($events as $event) {
                    $this->writeSseEvent($stream, $event);
                    $lastEventId = (int) $event['id'];
                }

                // Check if the turn process has completed
                $turnProcess = $this->storage->getTurnProcess($turnProcessId);

                if ($turnProcess !== null && in_array($turnProcess['status'], ['completed', 'failed'], true)) {
                    // Final poll to ensure all events are flushed
                    $finalEvents = $this->storage->getTurnEvents($turnProcessId, $lastEventId);
                    foreach ($finalEvents as $event) {
                        $this->writeSseEvent($stream, $event);
                    }

                    if ($stream->isWritable()) {
                        $stream->end();
                    }
                    if ($timer !== null) {
                        Loop::cancelTimer($timer);
                    }
                }
            } catch (\Throwable) {
                try {
                    if ($stream->isWritable()) {
                        $stream->end();
                    }
                    if ($timer !== null) {
                        Loop::cancelTimer($timer);
                    }
                } catch (\Throwable) {
                    // Already closed
                }
            }
        });

        // Clean up on client disconnect — cancel timer and kill child process
        $stream->on('close', function () use (&$timer, $sessionId): void {
            Loop::cancelTimer($timer);
            $this->turnManager->cancel($sessionId);
        });

        return new Response(
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
            $stream,
        );
    }

    /**
     * Blocking JSON response — for clients that don't support SSE.
     *
     * Spawns the agent turn in a child process, polls for the "complete"
     * event, and returns the result as a single JSON response.
     * The response body streams once the turn finishes (long-poll).
     *
     * @param string[] $filePaths
     */
    private function sendBlocking(string $sessionId, string $prompt, array $filePaths = []): Response
    {
        $turnProcessId = $this->turnManager->start(
            $sessionId,
            $prompt,
            $filePaths !== [] ? $filePaths : null,
        );

        if ($turnProcessId === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to start agent turn');
        }

        $stream = new ThroughStream();
        $lastEventId = null;
        /** @var ?TimerInterface $timer */
        $timer = null;

        // Poll until the turn process completes, then write the JSON result
        $timer = Loop::addPeriodicTimer(self::POLL_INTERVAL, function () use (
            $stream,
            $turnProcessId,
            &$lastEventId,
            &$timer,
        ): void {
            try {
                // Advance the event cursor so we can find the complete event
                $events = $this->storage->getTurnEvents($turnProcessId, $lastEventId);
                foreach ($events as $event) {
                    $lastEventId = (int) $event['id'];
                }

                $turnProcess = $this->storage->getTurnProcess($turnProcessId);

                if ($turnProcess === null || !in_array($turnProcess['status'], ['completed', 'failed'], true)) {
                    return;
                }

                // Turn is done — find the "complete" event with the result payload
                $result = $this->extractCompleteResult($turnProcessId);

                if ($result !== null) {
                    $json = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $stream->write($json);
                } else {
                    $json = json_encode([
                        'error' => 'Internal error',
                        'code' => 'internal_error',
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $stream->write($json);
                }

                $stream->end();
                if ($timer !== null) {
                    Loop::cancelTimer($timer);
                }
            } catch (\Throwable) {
                try {
                    $errorJson = json_encode([
                        'error' => 'Internal error',
                        'code' => 'internal_error',
                    ], JSON_UNESCAPED_SLASHES);
                    $stream->write($errorJson ?: '{"error":"Internal error"}');
                    $stream->end();
                    if ($timer !== null) {
                        Loop::cancelTimer($timer);
                    }
                } catch (\Throwable) {
                    // Already closed
                }
            }
        });

        // Clean up on client disconnect
        $stream->on('close', function () use (&$timer, $sessionId): void {
            Loop::cancelTimer($timer);
            $this->turnManager->cancel($sessionId);
        });

        return new Response(
            200,
            [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache',
            ],
            $stream,
        );
    }

    /**
     * Extract the result payload from the "complete" event.
     *
     * @return array<string, mixed>|null
     */
    private function extractCompleteResult(string $turnProcessId): ?array
    {
        // Read all events and find the last "complete" event
        $events = $this->storage->getTurnEvents($turnProcessId, limit: 500);

        for ($i = count($events) - 1; $i >= 0; $i--) {
            if (($events[$i]['event_type'] ?? '') === 'complete') {
                $data = $events[$i]['data'] ?? '{}';
                $decoded = is_string($data) ? json_decode($data, true) : $data;
                return is_array($decoded) ? $decoded : null;
            }
        }

        return null;
    }

    /**
     * Write a single SSE event to the stream.
     *
     * @param array<string, mixed> $event
     */
    private function writeSseEvent(ThroughStream $stream, array $event): void
    {
        if (!$stream->isWritable()) {
            return;
        }

        $data = $event['data'] ?? '{}';
        if (!is_string($data)) {
            $data = json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        $eventType = $event['event_type'] ?? 'message';
        $id = $event['id'] ?? '';

        $sse = '';
        if ($id !== '') {
            $sse .= "id: {$id}\n";
        }
        $sse .= "event: {$eventType}\n";
        $sse .= "data: {$data}\n\n";

        $stream->write($sse);
    }

    /**
     * Project a persisted message row onto the strict `message.json` wire shape.
     *
     * Emits only schema-declared properties (the schema is
     * `additionalProperties: false`): the required `id`/`session_id`/`role`/
     * `content`/`created_at`, the nullable `turn_id`, the typed `tool_calls`
     * (decoded from its stored JSON string), the nullable `tool_call_id`/
     * `actor_name`/`actor_role`, and the typed `attachments[]` of
     * `{content_ref, mime_type}`. `attachments` is omitted when the message
     * carries none, keeping the object minimal.
     *
     * @param array<string, mixed> $row A row from {@see SessionStorage::getMessageRow()}.
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        $turnId = $row['turn_id'] ?? null;
        $toolCallId = $row['tool_call_id'] ?? null;
        $actorName = $row['actor_name'] ?? null;
        $actorRole = $row['actor_role'] ?? null;

        $wire = [
            'id' => (string) ($row['id'] ?? ''),
            'session_id' => (string) ($row['session_id'] ?? ''),
            'turn_id' => is_string($turnId) && $turnId !== '' ? $turnId : null,
            'role' => (string) ($row['role'] ?? ''),
            'content' => (string) ($row['content'] ?? ''),
            'tool_calls' => WireFormat::array($row['tool_calls'] ?? null),
            'tool_call_id' => is_string($toolCallId) && $toolCallId !== '' ? $toolCallId : null,
            'actor_name' => is_string($actorName) && $actorName !== '' ? $actorName : null,
            'actor_role' => is_string($actorRole) && $actorRole !== '' ? $actorRole : null,
            'created_at' => WireFormat::timestamp($row['created_at'] ?? null),
        ];

        $attachments = self::projectAttachments($row['attachments'] ?? []);
        if ($attachments !== []) {
            $wire['attachments'] = $attachments;
        }

        return $wire;
    }

    /**
     * Reduce joined attachment rows to the strict `{content_ref, mime_type}` shape.
     *
     * @param mixed $attachments
     * @return list<array{content_ref: string, mime_type: string}>
     */
    private static function projectAttachments(mixed $attachments): array
    {
        if (!is_array($attachments)) {
            return [];
        }

        $projected = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || !isset($attachment['content_ref'], $attachment['mime_type'])) {
                continue;
            }

            $projected[] = [
                'content_ref' => (string) $attachment['content_ref'],
                'mime_type' => (string) $attachment['mime_type'],
            ];
        }

        return $projected;
    }

    /**
     * DELETE /api/v1/sessions/{id}/messages/{messageId}
     */
    public function delete(ServerRequestInterface $request, string $id, string $messageId): Response
    {
        $session = SessionAccess::requireWritableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $deleted = $this->storage->deleteMessages([$messageId]);

        if ($deleted === 0) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Message not found');
        }

        return Router::jsonResponse(['deleted' => true, 'message_id' => $messageId]);
    }
}
