<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CoquiBot\Coqui\Contract\ContextUsageSnapshot;
use CoquiBot\Coqui\Renderer\ContextUsageBar;

test('builds snapshot from conversation with all message types', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('You are a helpful assistant.'));
    $conversation->add(new UserMessage('Hello world'));
    $conversation->add(new AssistantMessage('Hi there! How can I help?'));

    $snapshot = ContextUsageBar::buildSnapshot($conversation);

    expect($snapshot)->toBeInstanceOf(ContextUsageSnapshot::class);
    expect($snapshot->breakdown)->toHaveKeys(['system', 'memory', 'user', 'assistant', 'tool', 'summary']);
    expect($snapshot->breakdown['system'])->toBeGreaterThan(0);
    expect($snapshot->breakdown['memory'])->toBe(0);
    expect($snapshot->breakdown['user'])->toBeGreaterThan(0);
    expect($snapshot->breakdown['assistant'])->toBeGreaterThan(0);
    expect($snapshot->breakdown['tool'])->toBe(0);
    expect($snapshot->breakdown['summary'])->toBe(0);
});

test('detects summary messages by marker regardless of persisted message role', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('Base system prompt.'));
    $conversation->add(new UserMessage('[CONVERSATION SUMMARY - 2026-03-26] Prior discussion covered testing.'));
    $conversation->add(new UserMessage('Continue'));

    $snapshot = ContextUsageBar::buildSnapshot($conversation);

    expect($snapshot->breakdown['summary'])->toBeGreaterThan(0);
    expect($snapshot->breakdown['system'])->toBeGreaterThan(0);
    expect($snapshot->breakdown['user'])->toBeGreaterThan(0);
});

test('does not classify instructional mentions of the summary marker as a real summary', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('Messages marked [CONVERSATION SUMMARY] provide background context only.'));
    $conversation->add(new UserMessage('Hi'));

    $snapshot = ContextUsageBar::buildSnapshot($conversation);

    expect($snapshot->breakdown['summary'])->toBe(0);
    expect($snapshot->breakdown['system'])->toBeGreaterThan(0);
});

test('adds prompt memory tokens to the live context breakdown', function () {
    $conversation = new Conversation();
    $conversation->add(new UserMessage('Hello'));

    $snapshot = ContextUsageBar::buildSnapshot(
        $conversation,
        promptSections: [
            ['group' => 'memory', 'tokens' => 240],
            ['group' => 'identity', 'tokens' => 600],
        ],
    );

    expect($snapshot->breakdown['memory'])->toBe(240);
    expect($snapshot->breakdown['system'])->toBe(600);
    expect($snapshot->usedTokens)->toBe(array_sum($snapshot->breakdown));
});

test('uses context window data when available', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('Test'));
    $conversation->add(new UserMessage('Hello'));

    $contextWindow = new ContextWindow(maxTok: 200_000, reservedTok: 8_000);
    $contextWindow->estimate(50_000);

    $snapshot = ContextUsageBar::buildSnapshot($conversation, $contextWindow);

    expect($snapshot->maxTokens)->toBe(200_000);
    expect($snapshot->reservedTokens)->toBe(8_000);
    expect($snapshot->usedTokens)->toBe(50_000);
    expect($snapshot->effectiveBudget())->toBe(192_000);
});

test('falls back to defaults when no context window', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage('Test'));

    $snapshot = ContextUsageBar::buildSnapshot($conversation);

    expect($snapshot->maxTokens)->toBe(128_000);
    expect($snapshot->reservedTokens)->toBe(4_096);
});

test('toSegments returns colored segments for non-zero categories', function () {
    $snapshot = new ContextUsageSnapshot(
        maxTokens: 128_000,
        reservedTokens: 4_096,
        usedTokens: 10_000,
        usagePercent: 8.1,
        breakdown: [
            'system' => 5000,
            'memory' => 1500,
            'user' => 2000,
            'assistant' => 3000,
            'tool' => 0,
            'summary' => 0,
        ],
    );

    $segments = $snapshot->toSegments();

    expect($segments)->toHaveCount(4); // Only non-zero categories
    expect($segments[0]->label)->toBe('System');
    expect($segments[0]->value)->toBe(5000);
    expect($segments[1]->label)->toBe('Memory');
    expect($segments[2]->label)->toBe('User');
    expect($segments[3]->label)->toBe('Assistant');
});

test('formatMaxTokens renders human-readable labels', function () {
    $make = fn(int $max) => new ContextUsageSnapshot($max, 0, 0, 0, []);

    expect($make(128_000)->formatMaxTokens())->toBe('128K');
    expect($make(1_000_000)->formatMaxTokens())->toBe('1M');
    expect($make(200_000)->formatMaxTokens())->toBe('200K');
    expect($make(500)->formatMaxTokens())->toBe('500');
    expect($make(2_500_000)->formatMaxTokens())->toBe('2.5M');
});

test('effectiveBudget subtracts reserved from max', function () {
    $snapshot = new ContextUsageSnapshot(
        maxTokens: 128_000,
        reservedTokens: 4_096,
        usedTokens: 0,
        usagePercent: 0.0,
        breakdown: [],
    );

    expect($snapshot->effectiveBudget())->toBe(123_904);
});

test('availableTokens accounts for used and reserved', function () {
    $snapshot = new ContextUsageSnapshot(
        maxTokens: 128_000,
        reservedTokens: 4_096,
        usedTokens: 50_000,
        usagePercent: 40.3,
        breakdown: [],
    );

    expect($snapshot->availableTokens())->toBe(73_904);
});

test('toArray serializes all fields', function () {
    $snapshot = new ContextUsageSnapshot(
        maxTokens: 128_000,
        reservedTokens: 4_096,
        usedTokens: 10_000,
        usagePercent: 8.1,
        breakdown: ['system' => 5000, 'user' => 5000],
    );

    $array = $snapshot->toArray();

    expect($array)->toHaveKeys([
        'max_tokens',
        'reserved_tokens',
        'used_tokens',
        'usage_percent',
        'available_tokens',
        'effective_budget',
        'breakdown',
    ]);
    expect($array['breakdown'])->toBe(['system' => 5000, 'user' => 5000]);
});

test('breakdown token estimates are consistent with conversation estimateTokens', function () {
    $conversation = new Conversation();
    $conversation->add(new SystemMessage(str_repeat('System prompt content. ', 100)));
    $conversation->add(new UserMessage(str_repeat('User query text. ', 50)));
    $conversation->add(new AssistantMessage(str_repeat('Assistant response. ', 80)));

    $snapshot = ContextUsageBar::buildSnapshot($conversation);

    $breakdownTotal = array_sum($snapshot->breakdown);
    $conversationEstimate = $conversation->estimateTokens();

    // Breakdown total should match conversation estimate (same heuristic)
    expect($breakdownTotal)->toBe($conversationEstimate);
});
