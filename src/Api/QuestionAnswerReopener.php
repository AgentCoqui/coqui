<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;

/**
 * Reopens a suspended loop stage once its structured question is answered
 * over the API. Implemented in Task 9; passed as null for non-loop questions
 * and until the loop wiring lands.
 */
interface QuestionAnswerReopener
{
    public function reopen(string $loopId, ?string $stageId, QuestionRequest $question, QuestionResponse $answer): void;
}
