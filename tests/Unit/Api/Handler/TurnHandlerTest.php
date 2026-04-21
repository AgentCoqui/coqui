<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\TurnHandler;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createTurnHandlerFixture(): array
{
    $tmpDir = sys_get_temp_dir() . '/coqui-turn-handler-' . bin2hex(random_bytes(8));
    mkdir($tmpDir, 0755, true);

    $dbPath = $tmpDir . '/coqui.db';
    $storage = new SessionStorage($dbPath);

    return [
        'tmpDir' => $tmpDir,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'handler' => new TurnHandler($storage),
    ];
}

function cleanupTurnHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['tmpDir']);
}

test('turn handler list includes historical turn summary payload fields', function () {
    $fixture = createTurnHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'openai/gpt-5');
        $turnProcessId = $fixture['storage']->createTurnProcess($sessionId, 'List files');
        $turnId = $fixture['storage']->createTurn($sessionId, 'List files', 'openai/gpt-5', $turnProcessId);

        $fixture['storage']->completeTurn(
            turnId: $turnId,
            responseText: 'Here are the files...',
            promptTokens: 1250,
            completionTokens: 340,
            totalTokens: 1590,
            iterations: 2,
            durationMs: 4521,
            toolsUsed: json_encode(['list_dir'], JSON_UNESCAPED_SLASHES) ?: '[]',
            childAgentCount: 1,
        );

        $fixture['storage']->storeTurnResultPayload($turnId, [
            'content' => 'Here are the files...',
            'iterations' => 2,
            'prompt_tokens' => 1250,
            'completion_tokens' => 340,
            'total_tokens' => 1590,
            'duration_ms' => 4521,
            'tools_used' => ['list_dir'],
            'child_agent_count' => 1,
            'restart_requested' => false,
            'iteration_limit_reached' => false,
            'budget_exhausted' => false,
            'context_usage' => [
                'max_tokens' => 128000,
                'reserved_tokens' => 8192,
                'used_tokens' => 24500,
                'usage_percent' => 20.4,
                'available_tokens' => 95308,
                'effective_budget' => 119808,
                'breakdown' => [
                    'system' => 5000,
                    'memory' => 1200,
                    'user' => 800,
                    'assistant' => 7000,
                    'tool' => 9000,
                    'summary' => 1500,
                ],
            ],
            'file_edits' => [
                ['file_path' => '/workspace/src/Example.php', 'operation' => 'update'],
            ],
            'error' => null,
            'review_feedback' => 'Looks good',
            'review_approved' => true,
            'background_tasks' => [
                'agents' => [[
                    'id' => 'task_123',
                    'status' => 'running',
                    'title' => 'Refactor auth',
                    'role' => 'coder',
                    'started_at' => '2026-04-21T12:00:00+00:00',
                    'created_at' => '2026-04-21T11:59:30+00:00',
                ]],
                'tools' => [],
                'total_count' => 1,
            ],
        ]);

        $response = $fixture['handler']->list(new ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/turns'), $sessionId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['turns'])->toHaveCount(1);
        expect($body['turns'][0]['turn_process_id'])->toBe($turnProcessId);
        expect($body['turns'][0]['tools_used'])->toBe(['list_dir']);
        expect($body['turns'][0]['content'])->toBe('Here are the files...');
        expect($body['turns'][0]['context_usage']['breakdown']['tool'])->toBe(9000);
        expect($body['turns'][0]['background_tasks']['agents'][0]['started_at'])->toBe('2026-04-21T12:00:00+00:00');
        expect($body['turns'][0]['review_approved'])->toBeTrue();
    } finally {
        cleanupTurnHandlerFixture($fixture);
    }
});

test('turn handler get includes messages and replayable event history for API turns', function () {
    $fixture = createTurnHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'openai/gpt-5');
        $turnProcessId = $fixture['storage']->createTurnProcess($sessionId, 'Refactor auth');
        $turnId = $fixture['storage']->createTurn($sessionId, 'Refactor auth', 'openai/gpt-5', $turnProcessId);

        $fixture['storage']->addMessage($sessionId, 'user', 'Refactor auth', turnId: $turnId);
        $fixture['storage']->addMessage($sessionId, 'assistant', 'Done', turnId: $turnId);
        $fixture['storage']->completeTurn(
            turnId: $turnId,
            responseText: 'Done',
            promptTokens: 10,
            completionTokens: 5,
            totalTokens: 15,
            iterations: 1,
            durationMs: 250,
            toolsUsed: json_encode(['edit_file'], JSON_UNESCAPED_SLASHES) ?: '[]',
            childAgentCount: 0,
        );
        $fixture['storage']->storeTurnResultPayload($turnId, [
            'content' => 'Done',
            'tools_used' => ['edit_file'],
            'restart_requested' => false,
            'iteration_limit_reached' => false,
            'budget_exhausted' => false,
            'context_usage' => null,
            'file_edits' => null,
            'error' => null,
            'review_feedback' => null,
            'review_approved' => null,
            'background_tasks' => null,
        ]);

        $fixture['storage']->appendTurnEvent($turnProcessId, 'review_start', [
            'round' => 1,
            'max_rounds' => 2,
            'depth' => 0,
        ]);
        $fixture['storage']->appendTurnEvent($turnProcessId, 'budget_warning', [
            'usage_percent' => 92.5,
            'threshold_percent' => 90.0,
        ]);
        $fixture['storage']->appendTurnEvent($turnProcessId, 'title', [
            'title' => 'Auth refactor',
        ]);

        $response = $fixture['handler']->get(
            new ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/turns/' . $turnId),
            $sessionId,
            $turnId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['messages'])->toHaveCount(2);
        expect($body['events'])->toHaveCount(3);
        expect($body['events'][0]['event_type'])->toBe('review_start');
        expect($body['events'][0]['data']['max_rounds'])->toBe(2);
        expect($body['events'][1]['event_type'])->toBe('budget_warning');
        expect($body['events'][1]['data']['usage_percent'])->toBe(92.5);
        expect($body['events'][2]['event_type'])->toBe('title');
        expect($body['events'][2]['data']['title'])->toBe('Auth refactor');
    } finally {
        cleanupTurnHandlerFixture($fixture);
    }
});