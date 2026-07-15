<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CoquiBot\Coqui\Contract\LoopBlockNotifier;
use CoquiBot\Coqui\Contract\OnQuestionPolicy;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;

/**
 * Non-interactive responder for loop stages / background tasks.
 *
 * default → returns the agent's suggested answer inline and logs it.
 * block   → persists the question, escalates the loop to `blocked`, and returns
 *           null (no synchronous answer). The ask_user tool maps null → a
 *           hard-STOP sentinel ToolResult. No exception, no fabricated answer.
 */
final class PolicyQuestionResponder implements QuestionResponderInterface
{
    public function __construct(
        private readonly OnQuestionPolicy $policy,
        private readonly QuestionPersistence $persistence,
        private readonly string $sessionId,
        private readonly ?LoopBlockNotifier $loopBlock = null,
        private readonly ?string $turnId = null,
        private readonly ?string $loopId = null,
        private readonly ?string $stageId = null,
    ) {}

    public function ask(QuestionRequest $question): ?QuestionResponse
    {
        $this->persistence->persistAsked(
            $this->sessionId, $question, 'policy', $this->turnId, $this->loopId, $this->stageId,
        );

        if ($this->policy === OnQuestionPolicy::DefaultAnswer) {
            $this->persistence->persistAnswered(
                $question->id, $this->sessionId, $question, $question->suggested, $this->turnId,
            );

            return $question->suggested;
        }

        // block: escalate the loop (if any) and halt the stage — the tool
        // turns the null return into a hard-STOP sentinel for the agent.
        if ($this->loopBlock !== null && $this->loopId !== null) {
            $this->loopBlock->block($this->loopId, $this->stageId, $question);
        }

        return null;
    }
}
