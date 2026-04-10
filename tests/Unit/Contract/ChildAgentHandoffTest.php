<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\ChildAgentHandoff;

test('child agent handoff returns bare task when no context is present', function () {
    $handoff = ChildAgentHandoff::fromInput('Implement the parser');

    expect($handoff->hasContext())->toBeFalse();
    expect($handoff->taskInstructions())->toBe('Implement the parser');
    expect($handoff->userPrompt())->toBe('Implement the parser');
});

test('child agent handoff formats context and preserves metadata', function () {
    $handoff = ChildAgentHandoff::fromInput(
        task: 'Review the refactor',
        context: "Changed files:\n- src/Foo.php",
        metadata: ['source' => 'spawn_agent', 'role' => 'reviewer'],
    );

    expect($handoff->hasContext())->toBeTrue();
    expect($handoff->userPrompt())->toContain('## Context');
    expect($handoff->userPrompt())->toContain('Changed files:');
    expect($handoff->userPrompt())->toContain('## Task');
    expect($handoff->toArray()['metadata']['role'])->toBe('reviewer');
});