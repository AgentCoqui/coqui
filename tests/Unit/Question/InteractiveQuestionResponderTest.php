<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\InteractiveQuestionResponder;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Drive SymfonyStyle prompts with scripted keystrokes. SymfonyStyle delegates
 * to SymfonyQuestionHelper, which reads from $input->getStream() when the input
 * is a StreamableInputInterface (ArrayInput is), falling back to STDIN. We seed
 * that stream so choice()/ask()/confirm() consume our scripted lines instead of
 * blocking on the terminal.
 *
 * @return array{0: SymfonyStyle, 1: BufferedOutput}
 */
function scriptedIo(string $keystrokes): array
{
    $input = new ArrayInput([]);
    $input->setStream(fopenString($keystrokes));
    $input->setInteractive(true);
    $output = new BufferedOutput();

    return [new SymfonyStyle($input, $output), $output];
}

/**
 * @return resource
 */
function fopenString(string $content)
{
    $stream = fopen('php://memory', 'r+');
    assert(is_resource($stream));
    fwrite($stream, $content);
    rewind($stream);

    return $stream;
}

test('single-select returns the chosen option and persists the answer', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    [$io] = scriptedIo("pear\n");

    $responder = new InteractiveQuestionResponder($io, new QuestionPersistence($storage), $sessionId);
    $request = new QuestionRequest(
        id: 'q1',
        prompt: 'Which fruit?',
        format: QuestionFormat::SingleSelect,
        options: [new QuestionOption('apple'), new QuestionOption('pear')],
        allowOther: false,
        suggested: new QuestionResponse(['apple']),
    );

    $answer = $responder->ask($request);

    expect($answer)->not->toBeNull();
    expect($answer->selected)->toBe(['pear']);
    expect($answer->isValidFor($request))->toBeTrue();
    expect($storage->getQuestion('q1')['status'])->toBe('answered');
});

test('free-text returns typed text', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    [$io] = scriptedIo("blue and green\n");

    $responder = new InteractiveQuestionResponder($io, new QuestionPersistence($storage), $sessionId);
    $request = new QuestionRequest(
        id: 'q2',
        prompt: 'Colours?',
        format: QuestionFormat::FreeText,
        options: [],
        allowOther: false,
        suggested: new QuestionResponse([], 'none'),
    );

    $answer = $responder->ask($request);

    expect($answer)->not->toBeNull();
    expect($answer->text)->toBe('blue and green');
    expect($answer->selected)->toBe([]);
});
