<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\AgentFiberExecutor;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\FileUploadStorage;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Message endpoints — including the core SSE streaming endpoint.
 *
 * GET    /api/sessions/{id}/messages             — list messages
 * POST   /api/sessions/{id}/messages             — send prompt (SSE stream)
 * POST   /api/sessions/{id}/messages?stream=false — send prompt (JSON response)
 * DELETE /api/sessions/{id}/messages/{messageId}  — delete a single message
 */
final readonly class MessageHandler
{
    /** Maximum prompt length in bytes (100 KB). */
    private const int MAX_PROMPT_BYTES = 102_400;

    public function __construct(
        private SessionStorage $storage,
        private AgentFiberExecutor $executor,
        private FileUploadStorage $uploadStorage,
    ) {}

    /**
     * GET /api/sessions/{id}/messages
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        $messages = $this->storage->getMessages($id);

        return Router::jsonResponse([
            'session_id' => $id,
            'messages' => $messages,
            'count' => count($messages),
        ]);
    }

    /**
     * POST /api/sessions/{id}/messages  { "prompt": "...", "files": ["file-id-1", ...] }
     *
     * Default: returns an SSE stream.
     * With ?stream=false: blocks and returns JSON with the final result.
     *
     * The optional "files" array references file IDs from prior uploads
     * via POST /api/sessions/{id}/files. Images are sent to the LLM as
     * vision content; text files are injected as context in the prompt.
     */
    public function send(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body) || !isset($body['prompt']) || trim((string) $body['prompt']) === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Missing or empty "prompt" field');
        }

        $prompt = trim((string) $body['prompt']);

        // Enforce prompt length cap
        if (strlen($prompt) > self::MAX_PROMPT_BYTES) {
            return Router::errorResponse(
                ApiErrorCode::PAYLOAD_TOO_LARGE,
                sprintf('Prompt exceeds maximum length of %s bytes', number_format(self::MAX_PROMPT_BYTES)),
            );
        }

        // Resolve optional file references to filesystem paths
        $filePaths = $this->resolveFilePaths($id, $body);
        if ($filePaths instanceof Response) {
            return $filePaths; // Validation error response
        }

        // Check for already-active agent run on this session
        if ($this->executor->isActive($id)) {
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
     * Resolve file IDs from the request body to filesystem paths.
     *
     * @param array<string, mixed> $body
     * @return string[]|Response  Array of file paths on success, or error Response on failure.
     */
    private function resolveFilePaths(string $sessionId, array $body): array|Response
    {
        if (!isset($body['files']) || !is_array($body['files'])) {
            return [];
        }

        $fileIds = $body['files'];
        $paths = [];

        foreach ($fileIds as $fileId) {
            if (!is_string($fileId)) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Each entry in "files" must be a string file ID',
                );
            }

            $filePath = $this->uploadStorage->getFilePath($sessionId, $fileId);

            if ($filePath === null) {
                return Router::errorResponse(
                    ApiErrorCode::NOT_FOUND,
                    sprintf('File "%s" not found in this session', $fileId),
                );
            }

            $paths[] = $filePath;
        }

        return $paths;
    }

    /**
     * SSE streaming response — the core endpoint.
     *
     * @param string[] $filePaths
     */
    private function sendStreaming(string $sessionId, string $prompt, array $filePaths = []): Response
    {
        $stream = $this->executor->execute($sessionId, $prompt, $filePaths !== [] ? $filePaths : null);

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
     * Uses executeBlocking() which runs the agent in a Fiber and resolves
     * a Promise with the AgentTurnResult directly — no SSE parsing needed.
     *
     * @param string[] $filePaths
     */
    private function sendBlocking(string $sessionId, string $prompt, array $filePaths = []): Response
    {
        $promise = $this->executor->executeBlocking($sessionId, $prompt, $filePaths !== [] ? $filePaths : null);

        // In v1, the Promise is already resolved synchronously.
        // Extract the resolved value directly.
        $result = null;
        $promise->then(function (array $data) use (&$result): void {
            $result = $data;
        });

        if (is_array($result)) {
            $hasError = isset($result['error']);
            return Router::jsonResponse($result, $hasError ? 500 : 200);
        }

        return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Agent run completed without result');
    }

    /**
     * DELETE /api/sessions/{id}/messages/{messageId}
     */
    public function delete(ServerRequestInterface $request, string $id, string $messageId): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        $deleted = $this->storage->deleteMessages([$messageId]);

        if ($deleted === 0) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Message not found');
        }

        return Router::jsonResponse(['deleted' => true, 'message_id' => $messageId]);
    }
}
