<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * API responder for interactive turns running in a `turn:run` child process.
 *
 * Persists the question, emits a `question` turn-event (streamed to the client
 * by MessageHandler's SSE poller), then block-polls the DB for the answer that
 * the REST answer endpoint records. Blocking is safe: this is a dedicated
 * child process, not the API event loop. The poll is bounded by a timeout and
 * an optional cancellation token so a never-answered question cannot hang.
 */
final class SuspendingQuestionResponder implements QuestionResponderInterface
{
    /** @var callable */
    private $sleeper;

    public function __construct(
        private readonly QuestionPersistence $persistence,
        private readonly SessionStorage $storage,
        private readonly string $sessionId,
        private readonly string $turnProcessId,
        private readonly ?CancellationTokenInterface $cancellationToken = null,
        private readonly int $pollIntervalMicros = 200000,
        private readonly int $timeoutSeconds = 1800,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static fn(int $micros) => usleep($micros);
    }

    public function ask(QuestionRequest $question): QuestionResponse
    {
        // The audit link stays null: audit_log.turn_id FKs to turns(id) and the
        // API child-turn flow has a `turn_processes` id, not a `turns` row. That
        // same turnProcessId is the client-facing correlation key (the {turnId}
        // in /sessions/{id}/turns/{turnId}/answer), so it is written to the FK-free
        // questions.turn_id column — without it submitTurnAnswer can never resolve
        // this question.
        $this->persistence->persistAsked(
            $this->sessionId,
            $question,
            'suspending',
            turnId: null,
            questionTurnId: $this->turnProcessId,
        );
        $this->storage->appendTurnEvent($this->turnProcessId, 'question', $question->toArray());

        $deadline = hrtime(true) + ($this->timeoutSeconds * 1_000_000_000);

        while (true) {
            $row = $this->storage->getQuestion($question->id);
            if (is_array($row) && ($row['status'] ?? '') === 'answered' && is_string($row['answer'] ?? null)) {
                return QuestionResponse::fromArray(
                    json_decode($row['answer'], true, 512, JSON_THROW_ON_ERROR),
                );
            }

            if ($this->cancellationToken?->isCancelled() === true) {
                throw new QuestionUnansweredException('Turn cancelled before the question was answered.');
            }
            if (hrtime(true) >= $deadline) {
                throw new QuestionUnansweredException('Timed out waiting for an answer.');
            }

            ($this->sleeper)($this->pollIntervalMicros);
        }
    }
}
