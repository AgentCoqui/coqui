<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\GroupTurnCoordinator;
use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-group-turn-coordinator-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createGroupSession('orchestrator', 'test/model', ['caelum', 'iris', 'nova'], 3);
    $this->coordinator = new GroupTurnCoordinator($this->storage);
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

test('group turn coordinator routes explicit mentions and follow-up mentions into later rounds', function () {
    $calls = [];

    $turnResult = $this->coordinator->run(
        sessionId: $this->sessionId,
        prompt: '@nova take first pass on this request.',
        modelString: 'test/model',
        modelRole: 'orchestrator',
        members: ['caelum', 'iris', 'nova'],
        maxRounds: 3,
        turnProcessId: null,
        filePaths: null,
        executeActor: function (string $actorPrompt, string $actorName, int $round, ?array $filePaths, string $turnId) use (&$calls): AgentTurnResult {
            $calls[] = ['actor' => $actorName, 'round' => $round, 'prompt' => $actorPrompt];

            $content = match ($actorName . ':' . $round) {
                'nova:1' => 'I will outline the approach and @caelum can confirm the execution details.',
                'caelum:2' => 'Confirmed. The execution path looks correct.',
                default => 'No further notes.',
            };

            $this->storage->addMessage(
                $this->sessionId,
                'assistant',
                $content,
                turnId: $turnId,
                actorName: $actorName,
                actorRole: 'orchestrator',
            );

            return new AgentTurnResult(
                content: $content,
                iterations: 1,
                promptTokens: 10,
                completionTokens: 5,
                totalTokens: 15,
                durationMs: 20,
                toolsUsed: $actorName === 'nova' ? ['list_dir'] : [],
                childAgentCount: 0,
                restartRequested: false,
            );
        },
    );

    expect(array_column($calls, 'actor'))->toBe(['nova', 'caelum']);
    expect(array_column($calls, 'round'))->toBe([1, 2]);
    expect($turnResult->actorResponses)->toBe([
        [
            'actor_name' => 'nova',
            'actor_role' => 'orchestrator',
            'content' => 'I will outline the approach and @caelum can confirm the execution details.',
            'round' => 1,
        ],
        [
            'actor_name' => 'caelum',
            'actor_role' => 'orchestrator',
            'content' => 'Confirmed. The execution path looks correct.',
            'round' => 2,
        ],
    ]);
    expect($turnResult->content)->toBe(
        '@nova: I will outline the approach and @caelum can confirm the execution details.'
        . "\n\n"
        . '@caelum: Confirmed. The execution path looks correct.'
    );

    $turns = $this->storage->getTurns($this->sessionId);
    $messages = $this->storage->getMessages($this->sessionId);

    expect($turns)->toHaveCount(1);
    expect($turns[0]['content'])->toBe($turnResult->content);
    expect($turns[0]['actor_responses'])->toBe($turnResult->actorResponses);
    expect(array_column($messages, 'role'))->toBe(['user', 'assistant', 'assistant']);
    expect(array_column($messages, 'actor_name'))->toBe([null, 'nova', 'caelum']);
});

test('group turn coordinator finalizes the stored turn when a responder fails mid-turn', function () {
    $turnResult = $this->coordinator->run(
        sessionId: $this->sessionId,
        prompt: 'Please discuss the rollout plan.',
        modelString: 'test/model',
        modelRole: 'orchestrator',
        members: ['caelum', 'iris', 'nova'],
        maxRounds: 2,
        turnProcessId: null,
        filePaths: null,
        executeActor: function (string $actorPrompt, string $actorName, int $round, ?array $filePaths, string $turnId): AgentTurnResult {
            if ($actorName === 'caelum') {
                $this->storage->addMessage(
                    $this->sessionId,
                    'assistant',
                    'I can cover the implementation risks.',
                    turnId: $turnId,
                    actorName: $actorName,
                    actorRole: 'orchestrator',
                );

                return new AgentTurnResult(
                    content: 'I can cover the implementation risks.',
                    iterations: 1,
                    promptTokens: 8,
                    completionTokens: 4,
                    totalTokens: 12,
                    durationMs: 15,
                    toolsUsed: [],
                    childAgentCount: 0,
                    restartRequested: false,
                );
            }

            return AgentTurnResult::fromError('Provider timeout');
        },
    );

    expect($turnResult->error)->toBe('Provider timeout');
    expect($turnResult->actorResponses)->toBe([
        [
            'actor_name' => 'caelum',
            'actor_role' => 'orchestrator',
            'content' => 'I can cover the implementation risks.',
            'round' => 1,
        ],
    ]);

    $turn = $this->storage->getTurns($this->sessionId)[0];

    expect($turn['response_text'])->toBe('Error: Provider timeout');
    expect($turn['content'])->toBe('@caelum: I can cover the implementation risks.');
    expect($turn['error'])->toBe('Provider timeout');
    expect($turn['actor_responses'])->toBe($turnResult->actorResponses);
});

test('group turn coordinator defaults the first round to all members and emits selection rationale', function () {
    $calls = [];
    $events = [];

    $turnResult = $this->coordinator->run(
        sessionId: $this->sessionId,
        prompt: 'How is everyone doing today?',
        modelString: 'test/model',
        modelRole: 'orchestrator',
        members: ['caelum', 'iris', 'nova'],
        maxRounds: 1,
        turnProcessId: null,
        filePaths: null,
        executeActor: function (string $actorPrompt, string $actorName, int $round, ?array $filePaths, string $turnId) use (&$calls): AgentTurnResult {
            $calls[] = ['actor' => $actorName, 'round' => $round];

            $content = sprintf('@%s checking in.', $actorName);
            $this->storage->addMessage(
                $this->sessionId,
                'assistant',
                $content,
                turnId: $turnId,
                actorName: $actorName,
                actorRole: 'orchestrator',
            );

            return new AgentTurnResult(
                content: $content,
                iterations: 1,
                promptTokens: 8,
                completionTokens: 4,
                totalTokens: 12,
                durationMs: 15,
                toolsUsed: [],
                childAgentCount: 0,
                restartRequested: false,
            );
        },
        notifyLifecycleEvent: function (string $eventType, array $data) use (&$events): void {
            $events[] = ['event_type' => $eventType, 'data' => $data];
        },
    );

    expect(array_column($calls, 'actor'))->toBe(['caelum', 'iris', 'nova']);
    expect($turnResult->actorResponses)->toHaveCount(3);
    expect($events[0]['event_type'])->toBe('group_round_start');
    expect($events[0]['data']['selection_source'])->toBe('default_all');
    expect($events[0]['data']['selection_rationale'])->toContain('all group members respond in stored order');
});

test('group turn coordinator expands broadcast mentions for initial and follow-up selection', function () {
    $calls = [];
    $events = [];

    $this->coordinator->run(
        sessionId: $this->sessionId,
        prompt: '@group weigh in on the plan.',
        modelString: 'test/model',
        modelRole: 'orchestrator',
        members: ['caelum', 'iris', 'nova'],
        maxRounds: 2,
        turnProcessId: null,
        filePaths: null,
        executeActor: function (string $actorPrompt, string $actorName, int $round, ?array $filePaths, string $turnId) use (&$calls): AgentTurnResult {
            $calls[] = $actorName . ':' . $round;

            $content = match ($actorName . ':' . $round) {
                'caelum:1' => 'Backend looks good. @everyone share any final concerns.',
                default => sprintf('@%s has no blockers.', $actorName),
            };

            $this->storage->addMessage(
                $this->sessionId,
                'assistant',
                $content,
                turnId: $turnId,
                actorName: $actorName,
                actorRole: 'orchestrator',
            );

            return new AgentTurnResult(
                content: $content,
                iterations: 1,
                promptTokens: 8,
                completionTokens: 4,
                totalTokens: 12,
                durationMs: 15,
                toolsUsed: [],
                childAgentCount: 0,
                restartRequested: false,
            );
        },
        notifyLifecycleEvent: function (string $eventType, array $data) use (&$events): void {
            $events[] = ['event_type' => $eventType, 'data' => $data];
        },
    );

    expect($calls)->toBe([
        'caelum:1',
        'iris:1',
        'nova:1',
        'iris:2',
        'nova:2',
    ]);
    expect($events[0]['data']['selection_source'])->toBe('broadcast');
    expect($events[0]['data']['selection_rationale'])->toContain('@everyone/@group');

    $roundEndEvents = array_values(array_filter(
        $events,
        static fn(array $event): bool => $event['event_type'] === 'group_round_end',
    ));

    expect($roundEndEvents)->toHaveCount(2);
    expect($roundEndEvents[0]['data']['selection_source'])->toBe('broadcast');
});
