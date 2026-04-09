<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Observer;

use CarmeloSantana\PHPAgents\Contract\AgentInterface;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Storage\SessionStorage;
use SplObserver;
use SplSubject;

/**
 * Observes agent events and persists them to the turn_events table.
 *
 * Used by API turn processes to create a durable event log without
 * reusing background-task heartbeats or task-specific storage.
 */
final class TurnProcessObserver implements SplObserver
{
    public function __construct(
        private readonly SessionStorage $storage,
        private readonly string $turnProcessId,
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

    private function handleEvent(string $event, mixed $data): void
    {
        $eventData = match ($event) {
            'agent.start' => [],
            'agent.iteration' => ['number' => $data],
            'agent.reasoning' => ['content' => is_string($data) ? $data : ''],
            'agent.text_delta' => ['content' => is_string($data) ? $data : ''],
            'agent.tool_call' => $this->formatToolCall($data),
            'agent.tool_result' => $this->formatToolResult($data),
            'agent.done' => $this->formatDone($data),
            'agent.error' => ['message' => (string) $data],
            'child.start' => is_array($data) ? $data : ['role' => (string) $data],
            'child.end' => [],
            default => null,
        };

        if ($eventData === null) {
            return;
        }

        $eventType = match ($event) {
            'agent.start' => 'agent_start',
            'agent.iteration' => 'iteration',
            'agent.reasoning' => 'reasoning',
            'agent.text_delta' => 'text_delta',
            'agent.tool_call' => 'tool_call',
            'agent.tool_result' => 'tool_result',
            'agent.done' => 'done',
            'agent.error' => 'error',
            'child.start' => 'child_start',
            'child.end' => 'child_end',
            default => $event,
        };

        try {
            $this->storage->appendTurnEvent($this->turnProcessId, $eventType, $eventData);
        } catch (\Throwable) {
            // Best-effort — do not let event logging kill the agent
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatToolCall(mixed $data): ?array
    {
        if (!$data instanceof ToolCall) {
            return null;
        }

        return [
            'id' => $data->id,
            'tool' => $data->name,
            'arguments' => $data->arguments,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function formatToolResult(mixed $data): ?array
    {
        if (!$data instanceof ToolResult) {
            return null;
        }

        return [
            'content' => mb_substr($data->content, 0, 2000),
            'success' => $data->status->value === 'success',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatDone(mixed $data): array
    {
        $content = '';
        if (is_array($data) && isset($data['response'])) {
            $content = (string) $data['response'];
        } elseif (is_string($data)) {
            $content = $data;
        }

        return ['content' => mb_substr($content, 0, 2000)];
    }
}