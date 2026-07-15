<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Toolkit\QuestionToolkit;

/**
 * A fake responder that records the request it received and returns whatever
 * the supplied closure yields (a QuestionResponse, or null for block mode).
 */
function fakeResponder(callable $onAsk): QuestionResponderInterface
{
    return new class($onAsk) implements QuestionResponderInterface {
        /** @var callable */
        private $onAsk;

        public ?QuestionRequest $received = null;

        public function __construct(callable $onAsk)
        {
            $this->onAsk = $onAsk;
        }

        public function ask(QuestionRequest $question): ?QuestionResponse
        {
            $this->received = $question;

            return ($this->onAsk)($question);
        }
    };
}

test('ask_user builds a single-select request and returns the answer', function () {
    $responder = fakeResponder(fn(QuestionRequest $q) => new QuestionResponse(['pear']));
    $tool = toolFromToolkit(new QuestionToolkit($responder), 'ask_user');

    $result = $tool->execute([
        'prompt' => 'Which fruit?',
        'format' => 'single_select',
        'options' => [['label' => 'apple'], ['label' => 'pear']],
        'suggested' => ['selected' => ['apple']],
    ]);

    expect($responder->received)->not->toBeNull();
    expect($responder->received->prompt)->toBe('Which fruit?');
    expect($responder->received->format->value)->toBe('single_select');
    expect($responder->received->optionLabels())->toBe(['apple', 'pear']);

    $data = assertStructuredToolResult($result);
    expect($data['answered'])->toBeTrue();
    expect($data['selected'])->toContain('pear');
});

test('ask_user accepts JSON-string options and suggested (provider stringification)', function () {
    $responder = fakeResponder(fn(QuestionRequest $q) => new QuestionResponse(['pear']));
    $tool = toolFromToolkit(new QuestionToolkit($responder), 'ask_user');

    $result = $tool->execute([
        'prompt' => 'Which fruit?',
        'format' => 'single_select',
        'options' => '[{"label":"apple"},{"label":"pear"}]',
        'suggested' => '{"selected":["apple"]}',
    ]);

    expect($responder->received)->not->toBeNull();
    expect($responder->received->optionLabels())->toBe(['apple', 'pear']);
    $data = assertStructuredToolResult($result);
    expect($data['selected'])->toContain('pear');
});

test('ask_user rejects a select with no options', function () {
    $tool = toolFromToolkit(new QuestionToolkit(fakeResponder(fn() => new QuestionResponse([]))), 'ask_user');

    $result = $tool->execute([
        'prompt' => 'Pick',
        'format' => 'single_select',
        'suggested' => ['selected' => ['a']],
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('option');
});

test('ask_user rejects a missing suggested answer', function () {
    $tool = toolFromToolkit(new QuestionToolkit(fakeResponder(fn() => new QuestionResponse([]))), 'ask_user');

    $result = $tool->execute([
        'prompt' => 'Colours?',
        'format' => 'free_text',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('suggested');
});

test('ask_user surfaces a null (blocked) return as a hard-STOP terminal result', function () {
    $responder = fakeResponder(fn(QuestionRequest $q): ?QuestionResponse => null);
    $tool = toolFromToolkit(new QuestionToolkit($responder), 'ask_user');

    $result = $tool->execute([
        'prompt' => 'Proceed?',
        'format' => 'single_select',
        'options' => [['label' => 'yes'], ['label' => 'no']],
        'suggested' => ['selected' => ['yes']],
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('QUESTION_BLOCKED');
    expect($result->content)->toContain('STOP');
});

test('ask_user surfaces QuestionUnansweredException as an error result', function () {
    $responder = fakeResponder(function (QuestionRequest $q): ?QuestionResponse {
        throw new \CoquiBot\Coqui\Question\QuestionUnansweredException('timeout');
    });
    $tool = toolFromToolkit(new QuestionToolkit($responder), 'ask_user');

    $result = $tool->execute([
        'prompt' => 'Proceed?',
        'format' => 'single_select',
        'options' => [['label' => 'yes'], ['label' => 'no']],
        'suggested' => ['selected' => ['yes']],
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('No answer received');
});
