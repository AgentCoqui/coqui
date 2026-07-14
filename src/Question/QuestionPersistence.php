<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CoquiBot\Coqui\Contract\QuestionRecord;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Single place that persists asked/answered questions and mirrors the
 * approval audit trail (audit_log actions: question_asked / question_answered).
 */
final class QuestionPersistence
{
    public function __construct(private readonly SessionStorage $storage) {}

    public function persistAsked(
        string $sessionId,
        QuestionRequest $question,
        string $responderKind,
        ?string $turnId = null,
        ?string $loopId = null,
        ?string $stageId = null,
    ): void {
        $this->storage->createQuestion($sessionId, $question, $responderKind, $turnId, $loopId, $stageId);
        $this->storage->logAudit(
            sessionId: $sessionId,
            toolName: 'ask_user',
            arguments: $question->toArray(),
            action: 'question_asked',
            reason: $question->prompt,
            turnId: $turnId,
        );
    }

    /**
     * Validate + persist an answer. Returns false when the answer is invalid
     * for the question or the question is no longer pending.
     */
    public function persistAnswered(
        string $questionId,
        string $sessionId,
        QuestionRequest $question,
        QuestionResponse $answer,
        ?string $turnId = null,
    ): bool {
        if (!$answer->isValidFor($question)) {
            return false;
        }
        if (!$this->storage->recordQuestionAnswer($questionId, $answer)) {
            return false;
        }
        $this->storage->logAudit(
            sessionId: $sessionId,
            toolName: 'ask_user',
            arguments: $answer->toArray(),
            action: 'question_answered',
            reason: $question->prompt,
            turnId: $turnId,
        );

        return true;
    }

    /**
     * @return list<QuestionRecord>
     */
    public function pending(string $sessionId): array
    {
        return array_map(
            static fn(array $row): QuestionRecord => QuestionRecord::fromRow($row),
            $this->storage->getPendingQuestions($sessionId),
        );
    }

    public function find(string $questionId): ?QuestionRecord
    {
        $row = $this->storage->getQuestion($questionId);

        return $row === null ? null : QuestionRecord::fromRow($row);
    }
}
