<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRecord;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\Clock;

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

    /**
     * Resolve the most recent question raised for a `(session, turn)` pair,
     * regardless of status — the turn-scoped answer path (submitTurnAnswer)
     * uses it to distinguish "no question for this turn" (null) from an
     * already-answered one.
     */
    public function findByTurn(string $sessionId, string $turnId): ?QuestionRecord
    {
        $row = $this->storage->getQuestionByTurn($sessionId, $turnId);

        return $row === null ? null : QuestionRecord::fromRow($row);
    }

    /**
     * Project a persisted `questions` row into a CAP 0.5.0 `question.json` wire
     * object.
     *
     * Emits ONLY schema-declared properties (additionalProperties:false-clean):
     * coqui's internal columns (turn_id/loop_id/stage_id/responder_kind) are not
     * part of the CAP Question and are dropped. coqui's `free_text` format maps to
     * the schema's `text`; each coqui `QuestionOption` (label + optional
     * description) maps to a typed CAP option {value, label?} where `value` is the
     * answer identifier and `label` the optional display text. `status` is mapped
     * into the schema's closed set (open|answered|cancelled); coqui's stored
     * `pending` becomes `open`. `answer` collapses to a scalar for text/single_select
     * and stays an array for multi_select; `suggested` is a scalar best-guess.
     * Timestamps are normalized to RFC-3339 UTC (Z).
     *
     * @param array<string, mixed> $row  A raw row from `SessionStorage::getQuestion()`.
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        $record = QuestionRecord::fromRow($row);
        $request = $record->request;

        return [
            'id' => $record->id,
            'session_id' => $record->sessionId,
            'prompt' => $request->prompt,
            'format' => self::wireFormat($request->format),
            'options' => self::wireOptions($request->options),
            'allow_other' => $request->allowOther,
            'status' => self::wireStatus($record->status),
            'answer' => self::wireAnswer($request->format, $record->answer),
            'suggested' => self::wireSuggested($request->suggested),
            'created_at' => self::wireTimestamp($row['created_at'] ?? null) ?? Clock::nowUtc(),
            'answered_at' => self::wireTimestamp($row['answered_at'] ?? null),
        ];
    }

    private static function wireFormat(QuestionFormat $format): string
    {
        return match ($format) {
            QuestionFormat::FreeText => 'text',
            QuestionFormat::SingleSelect => 'single_select',
            QuestionFormat::MultiSelect => 'multi_select',
        };
    }

    /**
     * @param list<QuestionOption> $options
     * @return list<array<string, string>>|null  Typed {value, label?} options, or null for free-form.
     */
    public static function wireOptions(array $options): ?array
    {
        if ($options === []) {
            return null;
        }

        return array_map(static function (QuestionOption $option): array {
            $wire = ['value' => $option->label];
            if ($option->description !== null) {
                $wire['label'] = $option->description;
            }

            return $wire;
        }, $options);
    }

    private static function wireStatus(string $status): string
    {
        return match ($status) {
            'answered' => 'answered',
            'cancelled', 'canceled' => 'cancelled',
            // coqui persists 'pending' for an unanswered question.
            default => 'open',
        };
    }

    /**
     * @return string|list<string>|null
     */
    private static function wireAnswer(QuestionFormat $format, ?QuestionResponse $answer): string|array|null
    {
        if ($answer === null) {
            return null;
        }

        if ($format === QuestionFormat::MultiSelect) {
            return $answer->selected;
        }

        // text / single_select collapse to a single value: a chosen option, else the free/Other text.
        if ($answer->selected !== []) {
            return $answer->selected[0];
        }

        return $answer->text;
    }

    /**
     * The CAP `suggested` is a single best-guess string; a multi-select suggestion
     * with several selections is projected to its first value.
     */
    public static function wireSuggested(QuestionResponse $suggested): ?string
    {
        if ($suggested->selected !== []) {
            return $suggested->selected[0];
        }

        return ($suggested->text ?? '') !== '' ? $suggested->text : null;
    }

    /**
     * Normalize a stored timestamp to RFC-3339 UTC (Z), or null when absent.
     */
    private static function wireTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return $value;
        }
    }
}
