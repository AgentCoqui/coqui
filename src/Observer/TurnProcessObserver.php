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
    private int $indentLevel = 0;

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly string $turnProcessId,
        private readonly ?string $actorName = null,
        private readonly ?string $actorRole = null,
    ) {}

    public function update(SplSubject $subject): void
    {
        if (method_exists($subject, 'getEventName') && method_exists($subject, 'getEventData')) {
            /** @var callable(): string $getEventName */
            $getEventName = [$subject, 'getEventName'];
            /** @var callable(): mixed $getEventData */
            $getEventData = [$subject, 'getEventData'];

            $this->handleEvent($getEventName(), $getEventData());
            return;
        }

        if (!$subject instanceof AgentInterface) {
            return;
        }

        if (!method_exists($subject, 'lastEvent') || !method_exists($subject, 'lastEventData')) {
            return;
        }

        /** @var callable(): string $lastEvent */
        $lastEvent = [$subject, 'lastEvent'];
        /** @var callable(): mixed $lastEventData */
        $lastEventData = [$subject, 'lastEventData'];

        $event = $lastEvent();
        $data = $lastEventData();

        $this->handleEvent($event, $data);
    }

    private function handleEvent(string $event, mixed $data): void
    {
        [$eventType, $eventData] = match ($event) {
            'agent.start' => ['agent_start', []],
            'agent.iteration' => ['iteration', ['number' => $data]],
            'agent.tool_call' => ['tool_call', $this->formatToolCall($data)],
            'agent.batch_start' => ['batch_start', is_array($data) ? $data : []],
            'agent.batch_end' => ['batch_end', is_array($data) ? $data : []],
            'agent.tool_result' => ['tool_result', $this->formatToolResult($data)],
            'agent.reasoning' => ['reasoning', ['content' => is_string($data) ? $data : '']],
            'agent.text_delta' => ['text_delta', ['content' => is_string($data) ? $data : '']],
            'agent.done' => ['done', $this->formatDone($data)],
            'agent.error' => ['error', ['message' => (string) $data]],
            'agent.warning' => ['warning', ['message' => is_string($data) ? $data : '']],
            'agent.budget_warning' => ['budget_warning', is_array($data) ? $data : []],
            'agent.summary' => ['summary', is_array($data) ? $data : []],
            'agent.memory_extraction' => ['memory_extraction', is_array($data) ? $data : []],
            'agent.notification' => ['notification', is_array($data) ? $data : []],
            'child.start' => ['child_start', $this->formatChildStart($data)],
            'child.end' => ['child_end', $this->formatChildEnd()],
            'child.review_start' => ['review_start', $this->formatReviewStart($data)],
            'child.review_end' => ['review_end', $this->formatReviewEnd($data)],
            'loop.start' => ['loop_start', is_array($data) ? $data : []],
            'loop.iteration_start' => ['loop_iteration_start', is_array($data) ? $data : []],
            'loop.stage_start' => ['loop_stage_start', is_array($data) ? $data : []],
            'loop.stage_end' => ['loop_stage_end', is_array($data) ? $data : []],
            'loop.iteration_end' => ['loop_iteration_end', is_array($data) ? $data : []],
            'loop.complete' => ['loop_complete', is_array($data) ? $data : []],
            default => [null, null],
        };

        if ($eventType === null || $eventData === null) {
            return;
        }

        try {
            $this->storage->appendTurnEvent($this->turnProcessId, $eventType, $this->withActorMetadata($eventData));
        } catch (\Throwable) {
            // Best-effort — do not let event logging kill the agent
        }
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function withActorMetadata(array $eventData): array
    {
        if ($this->actorName !== null && $this->actorName !== '') {
            $eventData['actor_name'] = $this->actorName;
        }

        if ($this->actorRole !== null && $this->actorRole !== '') {
            $eventData['actor_role'] = $this->actorRole;
        }

        return $eventData;
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
            // The correlating tool_call id (nullable on ToolResult) — captured so
            // the SSE producer can emit a schema-conformant `tool_result` frame
            // (sse-turn-event.json requires data.tool_call_id).
            'tool_call_id' => $data->callId,
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

    /**
     * @return array<string, mixed>
     */
    private function formatChildStart(mixed $data): array
    {
        $role = is_array($data) && isset($data['role']) ? $data['role'] : 'child';

        $event = [
            'role' => $role,
            'depth' => $this->indentLevel,
        ];

        $this->indentLevel++;

        return $event;
    }

    /**
     * @return array<string, int>
     */
    private function formatChildEnd(): array
    {
        $this->indentLevel = max(0, $this->indentLevel - 1);

        return [
            'depth' => $this->indentLevel,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function formatReviewStart(mixed $data): array
    {
        $round = is_array($data) ? (int) ($data['round'] ?? 1) : 1;
        $maxRounds = is_array($data) ? (int) ($data['max_rounds'] ?? 1) : 1;

        $event = [
            'round' => $round,
            'max_rounds' => $maxRounds,
            'depth' => $this->indentLevel,
        ];

        $this->indentLevel++;

        return $event;
    }

    /**
     * @return array<string, int|string|bool>
     */
    private function formatReviewEnd(mixed $data): array
    {
        $this->indentLevel = max(0, $this->indentLevel - 1);

        return [
            'round' => is_array($data) ? (int) ($data['round'] ?? 1) : 1,
            'verdict' => is_array($data) ? (string) ($data['verdict'] ?? 'needs_changes') : 'needs_changes',
            'approved' => is_array($data) && ($data['approved'] ?? false),
            'depth' => $this->indentLevel,
        ];
    }
}