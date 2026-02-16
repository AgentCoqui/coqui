<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\AgentFiberExecutor;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Message endpoints — including the core SSE streaming endpoint.
 *
 * GET  /api/sessions/{id}/messages             — list messages
 * POST /api/sessions/{id}/messages             — send prompt (SSE stream)
 * POST /api/sessions/{id}/messages?stream=false — send prompt (JSON response)
 */
final readonly class MessageHandler
{
    public function __construct(
        private SessionStorage $storage,
        private AgentFiberExecutor $executor,
    ) {}

    /**
     * GET /api/sessions/{id}/messages
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::jsonResponse(['error' => 'Session not found'], 404);
        }

        $messages = $this->storage->getMessages($id);

        return Router::jsonResponse([
            'session_id' => $id,
            'messages' => $messages,
            'count' => count($messages),
        ]);
    }

    /**
     * POST /api/sessions/{id}/messages  { "prompt": "..." }
     *
     * Default: returns an SSE stream.
     * With ?stream=false: blocks and returns JSON with the final result.
     */
    public function send(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::jsonResponse(['error' => 'Session not found'], 404);
        }

        $body = json_decode((string) $request->getBody(), true);

        if (!is_array($body) || !isset($body['prompt']) || trim((string) $body['prompt']) === '') {
            return Router::jsonResponse(['error' => 'Missing or empty "prompt" field'], 400);
        }

        $prompt = trim((string) $body['prompt']);

        // Check for already-active agent run on this session
        if ($this->executor->isActive($id)) {
            return Router::jsonResponse([
                'error' => 'Session already has an active agent run',
            ], 409);
        }

        // Check for streaming preference
        $params = $request->getQueryParams();
        $streamEnabled = !isset($params['stream']) || $params['stream'] !== 'false';

        if (!$streamEnabled) {
            return $this->sendBlocking($id, $prompt);
        }

        return $this->sendStreaming($id, $prompt);
    }

    /**
     * SSE streaming response — the core endpoint.
     */
    private function sendStreaming(string $sessionId, string $prompt): Response
    {
        $stream = $this->executor->execute($sessionId, $prompt);

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
     * Note: This blocks the Fiber until the agent completes, but the
     * event loop can still service other requests.
     */
    private function sendBlocking(string $sessionId, string $prompt): Response
    {
        // Execute and collect output via a ThroughStream
        $stream = $this->executor->execute($sessionId, $prompt);

        $output = '';
        $finalResult = null;

        $stream->on('data', function (string $data) use (&$output, &$finalResult): void {
            $output .= $data;

            // Parse SSE events to find the final "complete" event
            foreach (explode("\n\n", $data) as $block) {
                if (str_starts_with($block, 'event: complete')) {
                    $lines = explode("\n", $block);
                    foreach ($lines as $line) {
                        if (str_starts_with($line, 'data: ')) {
                            $json = json_decode(substr($line, 6), true);
                            if (is_array($json)) {
                                $finalResult = $json;
                            }
                        }
                    }
                }
            }
        });

        // Wait for stream to end (Fiber will resume once done)
        $stream->on('end', function () use (&$done): void {
            $done = true;
        });

        // If we captured a final result from the SSE stream, return it
        if ($finalResult !== null) {
            return Router::jsonResponse($finalResult);
        }

        return Router::jsonResponse([
            'content' => '',
            'error' => 'Agent run completed without result',
        ], 500);
    }
}
