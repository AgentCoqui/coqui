<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Support\Clock;

/**
 * On an operator answer to a block-mode loop question, reopens the current
 * iteration exactly like a #3 retry (LoopHandler::retryIteration), staging the
 * answer for injection into the reopened stage prompt
 * (LoopExecutor::prepareNextStage reads pending_answer). Durable + restart-safe.
 */
final class LoopQuestionAnswerReopener implements QuestionAnswerReopener
{
    public function __construct(private readonly LoopStore $loopStore) {}

    public function reopen(string $loopId, ?string $stageId, QuestionRequest $question, QuestionResponse $answer): void
    {
        $state = $this->loopStore->getCurrentState($loopId);
        if (!is_array($state) || !is_array($state['iteration'] ?? null)) {
            return;
        }

        // Only a genuinely blocked (block-mode) question may reopen an iteration.
        // A `default`-mode question also carries loop_id; answering one in the
        // sub-millisecond pending window (racing the atomic answer guard) must
        // never reset a RUNNING loop's live iteration or write pending_answer.
        if ((string) ($state['loop']['status'] ?? '') !== 'blocked') {
            return;
        }

        $iterationId = (string) $state['iteration']['id'];
        $iterationNumber = (int) $state['iteration']['iteration_number'];

        // Clear the escalation, reset the rework breaker, and stage the answer
        // for one-shot injection into the reopened stage prompt.
        $this->loopStore->updateLoopMetadata($loopId, [
            'escalation' => null,
            'rework_attempts' => 0,
            'pending_answer' => [
                'question' => $question->toArray(),
                'answer' => $answer->toArray(),
                'at' => Clock::nowUtc(),
            ],
        ]);

        // Reopen the iteration — the #3 retry sequence (LoopHandler::retryIteration).
        $this->loopStore->resetStagesForIteration($iterationId);
        $this->loopStore->resetIterationForRetry($iterationId);
        $this->loopStore->updateLoopStatus($loopId, 'running');
        $this->loopStore->updateLoopProgress($loopId, $iterationNumber, 0);
    }
}
