<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\JsonHelper;
use CoquiBot\Coqui\Utility\PromptSizeValidator;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use React\Stream\ThroughStream;

/**
 * Background task API endpoints.
 *
 * POST   /api/v1/tasks              — create a new background task
 * GET    /api/v1/tasks              — list tasks
 * GET    /api/v1/tasks/{id}         — get task detail
 * GET    /api/v1/tasks/{id}/events  — SSE event stream (long-poll)
 * POST   /api/v1/tasks/{id}/input   — inject user input into running task
 * POST   /api/v1/tasks/{id}/cancel  — cancel a task
 */
final readonly class TaskHandler
{
    public function __construct(
        private SessionStorage $storage,
        private BackgroundTaskManager $taskManager,
        private RoleResolver $roleResolver,
        private ProfileDiscovery $profileDiscovery,
    ) {}

    /**
     * POST /api/v1/tasks
     *
     * Body: { "prompt": "...", "role"?: "orchestrator", "title"?: "...",
     *         "parent_session_id"?: "...", "max_iterations"?: 25 }
     */
    public function create(ServerRequestInterface $request): Response
    {
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

        $role = isset($body['role']) ? trim((string) $body['role']) : 'orchestrator';

        if (!$this->roleResolver->hasRole($role)) {
            return Router::errorResponse(
                ApiErrorCode::ROLE_NOT_FOUND,
                sprintf('Unknown role "%s". Use GET /api/v1/config/roles to see available roles.', $role),
            );
        }

        $title = isset($body['title']) ? trim((string) $body['title']) : null;
        $parentSessionId = isset($body['parent_session_id']) ? (string) $body['parent_session_id'] : null;
        $maxIterations = isset($body['max_iterations']) ? max(1, min((int) $body['max_iterations'], CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS)) : 25;
        $requestedProfile = isset($body['profile']) ? strtolower(trim((string) $body['profile'])) : null;
        if ($requestedProfile === '') {
            $requestedProfile = null;
        }

        // Validate parent session exists if provided
        $parentSession = null;
        if ($parentSessionId !== null) {
            $parentSession = $this->storage->getSession($parentSessionId);
        }

        if ($parentSessionId !== null && $parentSession === null) {
            return Router::errorResponse(
                ApiErrorCode::SESSION_NOT_FOUND,
                'Parent session not found',
            );
        }

        $inheritedProfile = is_array($parentSession) && is_string($parentSession['profile'] ?? null) && $parentSession['profile'] !== ''
            ? $parentSession['profile']
            : null;

        if ($requestedProfile !== null && $inheritedProfile !== null && $requestedProfile !== $inheritedProfile) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Requested profile "%s" conflicts with parent session profile "%s".', $requestedProfile, $inheritedProfile),
            );
        }

        $profile = $requestedProfile ?? $inheritedProfile;
        if ($profile !== null && !$this->profileDiscovery->profileExists($profile)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown profile "%s". Create profiles/{name}/soul.md in the workspace or omit the profile.', $profile),
            );
        }

        // Create the dedicated session for this task
        $model = $this->roleResolver->resolve($role, $profile);
        $sessionId = $this->storage->createSession($role, $model, $profile);

        // Create the task record
        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: $prompt,
            role: $role,
            parentSessionId: $parentSessionId,
            title: $title,
            maxIterations: $maxIterations,
        );

        // Try to start immediately (returns false if at max concurrency)
        $started = $this->taskManager->start($taskId);

        $task = $this->storage->getTask($taskId);

        return Router::jsonResponse([
            'id' => $taskId,
            'session_id' => $sessionId,
            'status' => $started ? 'running' : 'pending',
            'prompt' => $prompt,
            'role' => $role,
            'profile' => $profile,
            'title' => $title,
            'created_at' => $task['created_at'] ?? date('c'),
        ], 201);
    }

    /**
     * GET /api/v1/tasks?status=running&limit=50
     */
    public function list(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $status = isset($params['status']) ? (string) $params['status'] : null;
        $limit = isset($params['limit']) ? min((int) $params['limit'], 200) : 50;

        $tasks = $this->storage->listTasks($status, $limit);
        $tasks = array_map(fn(array $task): array => $this->normalizeTask($task), $tasks);

        return Router::jsonResponse([
            'tasks' => $tasks,
            'count' => count($tasks),
            'counts' => $this->storage->getTaskCounts(),
        ]);
    }

    /**
     * GET /api/v1/tasks/{id}
     */
    public function get(ServerRequestInterface $request, string $id): Response
    {
        $task = $this->storage->getTask($id);

        if ($task === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Task not found');
        }

        // Add live process status
        $task = $this->normalizeTask($task);
        $task['process_alive'] = $this->taskManager->isRunning($id);

        return Router::jsonResponse($task);
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>
     */
    private function normalizeTask(array $task): array
    {
        $task['metadata'] = JsonHelper::decodeJsonObject($task['metadata'] ?? null);

        return $task;
    }

    /**
     * GET /api/v1/tasks/{id}/events?since_id=0
     *
     * Returns an SSE stream that delivers task events in real time.
     * Uses long-polling: the stream checks for new events every second
     * and closes automatically when the task reaches a terminal state.
     */
    public function events(ServerRequestInterface $request, string $id): Response
    {
        $task = $this->storage->getTask($id);

        if ($task === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Task not found');
        }

        $params = $request->getQueryParams();
        $sinceId = isset($params['since_id']) ? (int) $params['since_id'] : null;

        $stream = new ThroughStream();

        // Send initial connection event
        $stream->write("event: connected\ndata: {\"task_id\":\"{$id}\"}\n\n");

        // Send any existing events immediately
        $events = $this->storage->getTaskEvents($id, $sinceId);
        $lastEventId = $sinceId;

        foreach ($events as $event) {
            $this->writeEvent($stream, $event);
            $lastEventId = (int) $event['id'];
        }

        // Set up periodic polling via ReactPHP timer
        $timer = \React\EventLoop\Loop::addPeriodicTimer(1.0, function () use ($stream, $id, &$lastEventId, &$timer): void {
            try {
                $events = $this->storage->getTaskEvents($id, $lastEventId);

                foreach ($events as $event) {
                    $this->writeEvent($stream, $event);
                    $lastEventId = (int) $event['id'];
                }

                // Check if task is in terminal state and all events have been flushed
                $task = $this->storage->getTask($id);

                if ($task !== null && in_array($task['status'], ['completed', 'failed', 'cancelled'], true)) {
                    // One final poll to ensure we got everything
                    $finalEvents = $this->storage->getTaskEvents($id, $lastEventId);
                    foreach ($finalEvents as $event) {
                        $this->writeEvent($stream, $event);
                    }

                    $stream->write("event: done\ndata: {\"status\":\"{$task['status']}\"}\n\n");
                    $stream->end();
                    if ($timer instanceof \React\EventLoop\TimerInterface) {
                        \React\EventLoop\Loop::cancelTimer($timer);
                    }
                }
            } catch (\Throwable) {
                // Best effort — stream may have been closed by client
                try {
                    $stream->end();
                    if ($timer instanceof \React\EventLoop\TimerInterface) {
                        \React\EventLoop\Loop::cancelTimer($timer);
                    }
                } catch (\Throwable) {
                    // Already closed
                }
            }
        });

        // Clean up timer when client disconnects
        $stream->on('close', function () use (&$timer): void {
            /** @phpstan-ignore instanceof.alwaysTrue */
            if ($timer instanceof \React\EventLoop\TimerInterface) {
                \React\EventLoop\Loop::cancelTimer($timer);
            }
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
     * POST /api/v1/tasks/{id}/input  { "content": "..." }
     *
     * Inject user input into a running task's conversation.
     */
    public function addInput(ServerRequestInterface $request, string $id): Response
    {
        $task = $this->storage->getTask($id);

        if ($task === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Task not found');
        }

        if ($task['status'] !== 'running') {
            return Router::errorResponse(
                ApiErrorCode::CONFLICT,
                sprintf('Cannot add input to task with status "%s" — task must be running', $task['status']),
            );
        }

        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body) || !isset($body['content']) || trim((string) $body['content']) === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Missing or empty "content" field');
        }

        $content = trim((string) $body['content']);
        $sizeError = PromptSizeValidator::validateApiText($content, 'Content');
        if ($sizeError !== null) {
            return Router::errorResponse(
                ApiErrorCode::PAYLOAD_TOO_LARGE,
                $sizeError,
            );
        }

        $inputId = $this->storage->addTaskInput($id, $content);

        return Router::jsonResponse([
            'id' => $inputId,
            'task_id' => $id,
            'content' => $content,
            'status' => 'queued',
        ], 201);
    }

    /**
     * POST /api/v1/tasks/{id}/cancel
     */
    public function cancel(ServerRequestInterface $request, string $id): Response
    {
        $task = $this->storage->getTask($id);

        if ($task === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Task not found');
        }

        if (in_array($task['status'], ['completed', 'failed', 'cancelled'], true)) {
            return Router::errorResponse(
                ApiErrorCode::CONFLICT,
                sprintf('Task already in terminal state "%s"', $task['status']),
            );
        }

        $cancelled = $this->taskManager->cancel($id);

        if (!$cancelled) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to cancel task');
        }

        return Router::jsonResponse([
            'id' => $id,
            'status' => 'cancelling',
            'message' => 'Cancellation signal sent',
        ]);
    }

    /**
     * Write a single SSE event to the stream.
     *
     * @param array<string, mixed> $event
     */
    private function writeEvent(ThroughStream $stream, array $event): void
    {
        $data = $event['data'] ?? '{}';

        // Ensure data is a string
        if (!is_string($data)) {
            $data = json_encode($data, JSON_UNESCAPED_SLASHES) ?: '{}';
        }

        $eventType = $event['event_type'] ?? 'message';
        $id = $event['id'] ?? '';

        $stream->write("id: {$id}\nevent: {$eventType}\ndata: {$data}\n\n");
    }
}
