<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\DefaultBudgetPruningStrategy;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;

uses()->group('performance');

function expectPerformanceThreshold(float $elapsed, float $threshold): void
{
    if (getenv('COQUI_TEST_PROFILE_ACTIVE') !== false) {
        expect($elapsed)->toBeGreaterThan(0.0);

        return;
    }

    expect($elapsed)->toBeLessThan($threshold);
}

test('token estimation scales linearly with message count', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage(str_repeat('System prompt. ', 200)));

    for ($i = 0; $i < 40; $i++) {
        $conversation->add(new UserMessage("User message {$i}"));
        $conversation->add(new AssistantMessage("Response {$i} " . str_repeat('content ', 50)));
    }

    $iterations = 500;
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $conversation->estimateTokens();
    }
    $elapsed = (hrtime(true) - $start) / 1_000_000;

    // 500 estimations of an 81-message conversation should complete in under 200ms
    expectPerformanceThreshold($elapsed, 200.0);
});

test('budget pruning completes within time limit', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage(str_repeat('System prompt. ', 500)));

    for ($i = 0; $i < 50; $i++) {
        $conversation->add(new UserMessage("User message {$i} " . str_repeat('text ', 30)));
        $conversation->add(new AssistantMessage("Response {$i} " . str_repeat('content ', 100)));
    }

    $strategy = new DefaultBudgetPruningStrategy();

    // Force aggressive pruning by setting a tight budget
    $budget = 2000;

    $start = hrtime(true);
    $pruned = $strategy->prune($conversation, $budget);
    $elapsed = (hrtime(true) - $start) / 1_000_000;

    // Pruning a 101-message conversation should complete in under 100ms
    expectPerformanceThreshold($elapsed, 100.0);
    // Pruning should reduce the conversation size significantly
    expect($pruned->estimateTokens())->toBeLessThan($conversation->estimateTokens());
});

test('conversation filter by role is efficient', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('System'));

    for ($i = 0; $i < 100; $i++) {
        $conversation->add(new UserMessage("User {$i}"));
        $conversation->add(new AssistantMessage("Assistant {$i}"));
    }

    $iterations = 1000;
    $start = hrtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        $conversation->filter(\CarmeloSantana\PHPAgents\Enum\Role::User);
    }
    $elapsed = (hrtime(true) - $start) / 1_000_000;

    // 1000 filter operations on a 201-message conversation should be fast
    expectPerformanceThreshold($elapsed, 200.0);
});
