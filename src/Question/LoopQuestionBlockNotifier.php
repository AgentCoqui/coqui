<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CoquiBot\Coqui\Contract\LoopBlockNotifier;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Support\Clock;

/**
 * Escalates a loop to `blocked` with a QuestionRequest as the escalation
 * payload — mirroring LoopExecutor::escalateBlocked() so the existing
 * blocked-loop UI + notifications surface it. The operator answers over the
 * REST endpoint, which reopens the iteration (see LoopQuestionAnswerReopener).
 */
final class LoopQuestionBlockNotifier implements LoopBlockNotifier
{
    public function __construct(private readonly LoopStore $loopStore) {}

    public function block(string $loopId, ?string $stageId, QuestionRequest $question): void
    {
        $this->loopStore->updateLoopMetadata($loopId, [
            'escalation' => [
                'reason' => 'Agent asked the operator a question: ' . $question->prompt,
                'question' => $question->toArray(),
                'at' => Clock::nowUtc(),
            ],
        ]);

        $state = $this->loopStore->getCurrentState($loopId);
        if (is_array($state) && is_array($state['iteration'] ?? null)) {
            $this->loopStore->updateIterationStatus(
                (string) $state['iteration']['id'],
                'needs_rework',
                'Blocked awaiting an answer',
            );
        }

        $this->loopStore->updateLoopStatus($loopId, 'blocked');
    }
}
