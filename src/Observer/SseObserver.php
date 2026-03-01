<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Observer;

use CarmeloSantana\PHPAgents\Contract\AgentInterface;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use React\Stream\WritableStreamInterface;
use SplObserver;
use SplSubject;

/**
 * Observes agent events and streams them as Server-Sent Events (SSE).
 *
 * Each event is written in SSE format:
 *   event: <type>\n
 *   data: <json>\n\n
 *
 * Used by the API server to provide real-time streaming to GUI clients.
 */
final class SseObserver implements SplObserver
{
    private int $indentLevel = 0;

    public function __construct(
        private readonly WritableStreamInterface $stream,
    ) {}

    public function update(SplSubject $subject): void
    {
        if (!$subject instanceof AgentInterface) {
            return;
        }

        if (!method_exists($subject, 'lastEvent') || !method_exists($subject, 'lastEventData')) {
            return;
        }

        $event = $subject->lastEvent();
        $data = $subject->lastEventData();

        $this->handleEvent($event, $data);
    }

    public function handleEvent(string $event, mixed $data): void
    {
        match ($event) {
            'agent.start' => $this->writeEvent('agent_start', []),

            'agent.iteration' => $this->writeEvent('iteration', [
                'number' => $data,
            ]),

            'agent.tool_call' => $this->handleToolCall($data),

            'agent.tool_result' => $this->handleToolResult($data),

            'agent.text_delta' => $this->writeEvent('text_delta', [
                'content' => is_string($data) ? $data : '',
            ]),

            'agent.done' => $this->handleDone($data),

            'agent.error' => $this->writeEvent('error', [
                'message' => (string) $data,
            ]),

            'child.start' => $this->handleChildStart($data),

            'child.end' => $this->handleChildEnd(),

            default => null,
        };
    }

    /**
     * Write the final "complete" event with full result data.
     *
     * @param array<string, mixed> $resultData
     */
    public function writeComplete(array $resultData): void
    {
        $this->writeEvent('complete', $resultData);
    }

    /**
     * Write a "title" event with the generated session title.
     */
    public function writeTitle(string $title): void
    {
        $this->writeEvent('title', ['title' => $title]);
    }

    private function handleToolCall(mixed $data): void
    {
        if (!$data instanceof ToolCall) {
            return;
        }

        $this->writeEvent('tool_call', [
            'id' => $data->id,
            'tool' => $data->name,
            'arguments' => $data->arguments,
        ]);
    }

    private function handleToolResult(mixed $data): void
    {
        if (!$data instanceof ToolResult) {
            return;
        }

        $this->writeEvent('tool_result', [
            'content' => $data->content,
            'success' => $data->status->value === 'success',
        ]);
    }

    private function handleDone(mixed $data): void
    {
        $content = '';
        if (is_array($data) && isset($data['response'])) {
            $content = (string) $data['response'];
        }

        $this->writeEvent('done', [
            'content' => $content,
        ]);
    }

    private function handleChildStart(mixed $data): void
    {
        $role = is_array($data) && isset($data['role']) ? $data['role'] : 'child';

        $this->writeEvent('child_start', [
            'role' => $role,
            'depth' => $this->indentLevel,
        ]);

        $this->indentLevel++;
    }

    private function handleChildEnd(): void
    {
        $this->indentLevel = max(0, $this->indentLevel - 1);

        $this->writeEvent('child_end', [
            'depth' => $this->indentLevel,
        ]);
    }

    /**
     * Write a single SSE event to the stream.
     *
     * @param array<string, mixed> $data
     */
    private function writeEvent(string $event, array $data): void
    {
        if (!$this->stream->isWritable()) {
            return;
        }

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->stream->write("event: {$event}\ndata: {$json}\n\n");
    }
}
