<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A persisted question row rehydrated into typed form.
 */
final readonly class QuestionRecord
{
    public function __construct(
        public string $id,
        public string $sessionId,
        public QuestionRequest $request,
        public string $responderKind,
        public string $status,
        public ?QuestionResponse $answer = null,
        public ?string $loopId = null,
        public ?string $stageId = null,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $request = QuestionRequest::fromArray(
            json_decode((string) $row['request'], true, 512, JSON_THROW_ON_ERROR),
        );
        $answer = null;
        if (is_string($row['answer'] ?? null) && $row['answer'] !== '') {
            $answer = QuestionResponse::fromArray(json_decode($row['answer'], true, 512, JSON_THROW_ON_ERROR));
        }

        return new self(
            id: (string) $row['id'],
            sessionId: (string) $row['session_id'],
            request: $request,
            responderKind: (string) $row['responder_kind'],
            status: (string) $row['status'],
            answer: $answer,
            loopId: isset($row['loop_id']) && $row['loop_id'] !== '' ? (string) $row['loop_id'] : null,
            stageId: isset($row['stage_id']) && $row['stage_id'] !== '' ? (string) $row['stage_id'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'request' => $this->request->toArray(),
            'responder_kind' => $this->responderKind,
            'status' => $this->status,
            'answer' => $this->answer?->toArray(),
            'loop_id' => $this->loopId,
            'stage_id' => $this->stageId,
        ];
    }
}
