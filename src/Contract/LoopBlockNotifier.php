<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Flips a loop to `blocked` carrying a QuestionRequest as the escalation
 * payload. Implemented in Task 9 over LoopStore; null for non-loop tasks.
 */
interface LoopBlockNotifier
{
    public function block(string $loopId, ?string $stageId, QuestionRequest $question): void;
}
