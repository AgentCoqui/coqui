<?php

declare(strict_types=1);

use CoquiBot\Coqui\Renderer\PromptUsageBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createPromptUsageIo(): array
{
    $input = new ArrayInput([]);
    $input->setInteractive(false);
    $output = new BufferedOutput();

    return [new SymfonyStyle($input, $output), $output];
}

test('renderSectionBreakdown shows separate memory and discovery buckets', function () {
    [$io, $output] = createPromptUsageIo();

    PromptUsageBar::renderSectionBreakdown($io, [
        'prompt_tokens' => 1000,
        'prompt_sections' => [
            ['id' => 'context.core-memories', 'group' => 'memory', 'tokens' => 250],
            ['id' => 'context.deferred-toolkits', 'group' => 'tool_discovery', 'tokens' => 150],
            ['id' => 'prompt.base', 'group' => 'identity', 'tokens' => 600],
        ],
    ]);

    $text = $output->fetch();

    expect($text)->toContain('Memory');
    expect($text)->toContain('Discovery');
    expect($text)->not->toContain('Context');
});