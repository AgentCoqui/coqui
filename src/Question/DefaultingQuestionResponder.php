<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;

/**
 * Non-interactive responder for loop stages / background tasks (D4).
 *
 * Loops never block on a question: this responder auto-answers with the
 * agent's suggested answer, which QuestionRequest guarantees is valid and
 * non-null. It persists the asked + answered records for audit, exactly like
 * an operator answer would.
 */
final class DefaultingQuestionResponder implements QuestionResponderInterface
{
    public function __construct(
        private readonly QuestionPersistence $persistence,
        private readonly string $sessionId,
        private readonly ?string $turnId = null,
        private readonly ?string $loopId = null,
        private readonly ?string $stageId = null,
    ) {}

    public function ask(QuestionRequest $question): QuestionResponse
    {
        $this->persistence->persistAsked(
            $this->sessionId, $question, 'default', $this->turnId, $this->loopId, $this->stageId,
        );
        $this->persistence->persistAnswered(
            $question->id, $this->sessionId, $question, $question->suggested, $this->turnId,
        );

        return $question->suggested;
    }
}
