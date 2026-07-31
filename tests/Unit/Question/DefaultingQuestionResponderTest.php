<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\DefaultingQuestionResponder;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;

test('a loop question is auto-answered with the suggested answer and never blocks', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-defresp-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    try {
        $sessionId = $storage->createSession('orchestrator', null, 'caelum');
        $responder = new DefaultingQuestionResponder(new QuestionPersistence($storage), $sessionId);

        $question = new QuestionRequest(
            id: 'q_1',
            prompt: 'Proceed?',
            format: QuestionFormat::FreeText,
            options: [],
            allowOther: false,
            suggested: new QuestionResponse(text: 'yes'),
        );

        $answer = $responder->ask($question);
        expect($answer)->toBeInstanceOf(QuestionResponse::class);   // never null
        expect($answer->text)->toBe('yes');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});
