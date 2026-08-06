<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\AgentTurnManager;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Api\Sse\SseCursor;
use CoquiBot\Coqui\Api\SseStream;
use CoquiBot\Coqui\Content\ContentStore;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Export\WireFormat;
use CoquiBot\Coqui\Question\QuestionPersistence;
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

        // Honor a resumable reconnect: the client may echo the transport cursor
        // (Last-Event-ID header / ?since / legacy ?since_id) so the stream replays
        // strictly after it rather than from the beginning.
        $replayCursor = self::resolveReplayCursor($request);

        return $this->sendStreaming($id, $prompt, $filePaths, $replayCursor);
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
     * @param ?int $replayCursor Resumable reconnect cursor (numeric rowid) — the
     *        stream replays events strictly after it; null replays from the start.
     */
    private function sendStreaming(string $sessionId, string $prompt, array $filePaths = [], ?int $replayCursor = null): Response
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

        // No `connected` handshake: `connected` is not in the closed turn-stream
        // event set (schema/sse-turn-event.json — token|message|tool_call|
        // tool_result|question|done|error). A client learns the outcome from the
        // terminal `done` frame's turn record, not from a transport handshake.
        //
        // Seed the cursor from a resumable reconnect (Last-Event-ID / ?since):
        // getTurnEvents filters `id > :since_id`, so a non-null cursor replays
        // strictly after it.
        $lastEventId = $replayCursor;
        /** @var ?TimerInterface $timer */
        $timer = null;

        // Poll SQLite for new events from the child process
        $timer = Loop::addPeriodicTimer(self::POLL_INTERVAL, function () use (
            $stream,
            $turnProcessId,
            $sessionId,
            &$lastEventId,
            &$timer,
        ): void {
            try {
                $events = $this->storage->getTurnEvents($turnProcessId, $lastEventId);

                // Each coqui-internal turn event is mapped onto the closed CAP
                // turn-stream event set (or dropped) before it reaches the wire.
                foreach ($events as $event) {
                    $this->writeSseEvent($stream, $event, $turnProcessId, $sessionId);
                    $lastEventId = (int) $event['id'];
                }

                // Check if the turn process has completed
                $turnProcess = $this->storage->getTurnProcess($turnProcessId);

                if ($turnProcess !== null && in_array($turnProcess['status'], ['completed', 'failed'], true)) {
                    // Final poll to ensure all events are flushed
                    $finalEvents = $this->storage->getTurnEvents($turnProcessId, $lastEventId);
                    foreach ($finalEvents as $event) {
                        $this->writeSseEvent($stream, $event, $turnProcessId, $sessionId);
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
                    // Terminal `error` frame — the turn stream's closed set includes
                    // `error` (schema/sse-turn-event.json / sse-error.json), so a
                    // stream failure classifies with a catalog code rather than a
                    // silent close.
                    $this->writeErrorFrame($stream, $lastEventId, 'The agent turn stream failed');
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
     * Map a coqui-internal turn event onto the closed CAP turn-stream event set
     * and write it as a typed SSE frame (schema/sse-turn-event.json). Events with
     * no CAP turn-channel equivalent — or whose data cannot yet be shaped
     * conformantly — are dropped rather than emitted non-conformant.
     *
     * Internal event_type → CAP turn event (closed set: token | message |
     * tool_call | tool_result | question | done | error):
     *
     *   text_delta  → token       (data { content } → { text })
     *   tool_call   → tool_call   (data { id, tool, arguments } →
     *                             { tool_call_id, name, arguments })
     *   tool_result → tool_result (data { tool_call_id, content } →
     *                             { tool_call_id, result }); dropped when the
     *                             observer captured no tool_call_id, since the
     *                             schema requires it
     *   question    → question    (data { id, prompt, format, options, suggested }
     *                             → { question_id, prompt?, options?, suggested? },
     *                             projected via {@see projectQuestionData})
     *   complete    → done        (data replaced with the full turn.json record,
     *                             projected via TurnHandler::toWire)
     *
     * DROPPED (no conformant CAP mapping in this task): agent_start, iteration,
     * batch_start, batch_end, reasoning, `done` (the observer's mid-run
     * agent.done nudge — the terminal CAP `done` is derived from `complete`),
     * error (its sse-error.json Error-record shaping is Task 13), warning,
     * budget_warning, summary, memory_extraction, notification, child_start,
     * child_end, review_start, review_end, loop_* and title.
     *
     * @param array<string, mixed> $event
     */
    private function writeSseEvent(ThroughStream $stream, array $event, string $turnProcessId, string $sessionId): void
    {
        if (!$stream->isWritable()) {
            return;
        }

        $mapped = $this->mapTurnEvent($event, $turnProcessId, $sessionId);
        if ($mapped === null) {
            return;
        }

        [$capEvent, $data] = $mapped;

        $rawId = $event['id'] ?? null;
        $id = ($rawId !== null && $rawId !== '') ? SseCursor::encode((int) $rawId) : null;

        $frame = self::buildTurnEventFrame($capEvent, $data, $id ?? SseCursor::encode(0));

        $stream->write(SseStream::format($frame['event'], $frame['data'], $frame['id']));
    }

    /**
     * Write the terminal `error` frame to the wire. Shares the {@see SseStream}
     * encoding used by every other frame; the id is the encoded string cursor of
     * the last delivered event (or 0 when none were delivered).
     */
    private function writeErrorFrame(ThroughStream $stream, ?int $lastEventId, string $message): void
    {
        if (!$stream->isWritable()) {
            return;
        }

        $frame = self::buildErrorFrame(
            ApiErrorCode::INTERNAL_ERROR,
            $message,
            SseCursor::encode($lastEventId ?? 0),
        );

        $stream->write(SseStream::format($frame['event'], $frame['data'], $frame['id']));
    }

    /**
     * Resolve a coqui-internal turn event to a `[capEvent, data]` pair, or null
     * to drop it. See {@see writeSseEvent} for the full mapping table.
     *
     * @param array<string, mixed> $event
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function mapTurnEvent(array $event, string $turnProcessId, string $sessionId): ?array
    {
        $type = (string) ($event['event_type'] ?? '');
        $data = $this->decodeEventData($event['data'] ?? null);

        return match ($type) {
            'text_delta' => ['token', ['text' => (string) ($data['content'] ?? '')]],
            'tool_call' => ['tool_call', $this->shapeToolCall($data)],
            'tool_result' => $this->mapToolResult($data),
            'question' => ['question', self::projectQuestionData($data)],
            'complete' => ['done', $this->turnRecord($turnProcessId, $sessionId)],
            default => null,
        };
    }

    /**
     * Shape an observer tool_result payload onto the CAP tool_result data shape
     * (schema/sse-turn-event.json: required `tool_call_id`, optional any-typed
     * `result`). Dropped (null) when the observer captured no correlating
     * tool_call_id, since a frame without it would violate the schema.
     *
     * @param array<string, mixed> $data
     * @return array{0: string, 1: array<string, mixed>}|null
     */
    private function mapToolResult(array $data): ?array
    {
        $callId = $data['tool_call_id'] ?? null;
        if (!is_string($callId) || $callId === '') {
            return null;
        }

        $frame = ['tool_call_id' => $callId];

        if (array_key_exists('content', $data)) {
            $frame['result'] = $data['content'];
        }

        return ['tool_result', $frame];
    }

    /**
     * Build a typed turn-stream frame. Pure — the single place the `{id, event,
     * data}` shape is assembled, so it is unit-testable without a live stream and
     * shared by the stream path. The `$id` is the already-encoded string cursor.
     *
     * @param array<string, mixed> $data
     * @return array{id: string, event: string, data: array<string, mixed>}
     */
    public static function buildTurnEventFrame(string $event, array $data, string $id): array
    {
        return ['id' => $id, 'event' => $event, 'data' => $data];
    }

    /**
     * Build a typed `question` turn-stream frame (schema/sse-question.json) from a
     * recorded `question` turn event. Pure — the single place the frame is
     * assembled, so it is unit-testable and shared by the stream path (mapTurnEvent
     * projects the same `data` via {@see projectQuestionData}). The `$id` is the
     * already-encoded string cursor.
     *
     * @param array<string, mixed> $questionEventData A QuestionRequest::toArray payload.
     * @return array{id: string, event: string, data: array<string, mixed>}
     */
    public static function buildQuestionFrame(array $questionEventData, string $id): array
    {
        return self::buildTurnEventFrame('question', self::projectQuestionData($questionEventData), $id);
    }

    /**
     * Project a recorded `question` turn event onto the sse-question.json `data`
     * shape: the REQUIRED `question_id` (from the request id — never null, since a
     * QuestionRequest id is non-empty), the optional `prompt`, the typed
     * `{value, label?}` `options`, and a scalar `suggested`. Reuses the Task-5
     * option/suggested projection ({@see QuestionPersistence::wireOptions} /
     * {@see QuestionPersistence::wireSuggested}) rather than re-deriving it.
     *
     * @param array<string, mixed> $questionEventData A QuestionRequest::toArray payload.
     * @return array<string, mixed>
     */
    private static function projectQuestionData(array $questionEventData): array
    {
        $request = QuestionRequest::fromArray($questionEventData);

        $data = ['question_id' => $request->id];

        if ($request->prompt !== '') {
            $data['prompt'] = $request->prompt;
        }

        $options = QuestionPersistence::wireOptions($request->options);
        if ($options !== null) {
            $data['options'] = $options;
        }

        $suggested = QuestionPersistence::wireSuggested($request->suggested);
        if ($suggested !== null) {
            $data['suggested'] = $suggested;
        }

        return $data;
    }

    /**
     * Resolve the SSE replay cursor for a reconnect. Pure — no storage access —
     * so it is unit-testable and shared by the turn and task streams.
     *
     * Precedence: the `Last-Event-ID` header (an opaque {@see SseCursor} string
     * the client echoes back) wins, then the `?since` query, then the legacy
     * `?since_id` query; null when the client presents no cursor (a fresh
     * connection replays from the beginning). Every source is decoded through
     * {@see SseCursor::decode}, so the returned cursor is always the numeric
     * rowid the replay stores filter on with `id > :since_id`.
     */
    public static function resolveReplayCursor(ServerRequestInterface $request): ?int
    {
        $header = $request->getHeaderLine('Last-Event-ID');
        if ($header !== '') {
            return SseCursor::decode($header);
        }

        $params = $request->getQueryParams();

        if (isset($params['since']) && $params['since'] !== '') {
            return SseCursor::decode((string) $params['since']);
        }

        if (isset($params['since_id']) && $params['since_id'] !== '') {
            return SseCursor::decode((string) $params['since_id']);
        }

        return null;
    }

    /**
     * Build a terminal `error` frame for a streaming failure. Pure — the single
     * place the `{id, event: 'error', data}` shape is assembled, so it is
     * unit-testable and shared by the stream path.
     *
     * The `data` payload is a full Error record ({@see ApiErrorCode::toPayload}:
     * `{error, code, details?}`), so a stream failure classifies with the same
     * closed code catalog an ordinary HTTP error carries (schema/sse-error.json →
     * schema/error.json). The `$id` is the already-encoded string cursor.
     *
     * @return array{id: string, event: string, data: array{error: string, code: string, details?: mixed}}
     */
    public static function buildErrorFrame(ApiErrorCode $code, string $message, string $id): array
    {
        return ['id' => $id, 'event' => 'error', 'data' => $code->toPayload($message)];
    }

    /**
     * Project the terminal turn record for the `done` frame. Prefers the finalized
     * turns row (already written by {@see \CoquiBot\Coqui\Agent\AgentRunner} before
     * the `complete` event fires); falls back to a minimal schema-valid record when
     * no row is bound (e.g. a spawn that failed before the turn row was created).
     * The fallback uses the route's real session id (and the turn-process id as a
     * stand-in turn id) so the synthesized record still satisfies turn.json's
     * `minLength:1` Id fields — a `done` frame must never be schema-invalid.
     *
     * @return array<string, mixed>
     */
    private function turnRecord(string $turnProcessId, string $sessionId): array
    {
        $turn = $this->storage->getTurnByProcessId($turnProcessId);
        if ($turn !== null) {
            return TurnHandler::toWire($turn);
        }

        return TurnHandler::toWire([
            'id' => $turnProcessId,
            'session_id' => $sessionId,
            'turn_number' => 1,
            'user_prompt' => '',
            'status' => 'failed',
        ]);
    }

    /**
     * Shape an observer tool_call payload onto the CAP tool_call data shape
     * (schema/sse-turn-event.json: required `name`, optional `tool_call_id` and
     * object `arguments`).
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function shapeToolCall(array $data): array
    {
        $shaped = ['name' => (string) ($data['tool'] ?? '')];

        if (isset($data['id']) && is_string($data['id']) && $data['id'] !== '') {
            $shaped['tool_call_id'] = $data['id'];
        }

        // Cast to object so an empty or list-shaped arguments array still encodes
        // as a JSON object (the schema types `arguments` as an object).
        if (isset($data['arguments']) && is_array($data['arguments'])) {
            $shaped['arguments'] = (object) $data['arguments'];
        }

        return $shaped;
    }

    /**
     * Decode a stored turn-event `data` column to an associative array.
     *
     * @return array<string, mixed>
     */
    private function decodeEventData(mixed $data): array
    {
        if (is_array($data)) {
            return $data;
        }

        if (is_string($data) && $data !== '') {
            $decoded = json_decode($data, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
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
