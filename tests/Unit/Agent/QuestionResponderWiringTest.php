<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Toolkit\QuestionToolkit;

test('QuestionToolkit exposes exactly one ask_user tool', function () {
    $responder = new class implements QuestionResponderInterface {
        public function ask(QuestionRequest $q): ?QuestionResponse
        {
            return $q->suggested;
        }
    };

    $kit = new QuestionToolkit($responder);
    $names = array_map(fn($t) => $t->name(), $kit->tools());

    expect($names)->toBe(['ask_user']);
});
