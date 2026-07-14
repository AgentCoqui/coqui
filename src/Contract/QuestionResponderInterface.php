<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Renders a QuestionRequest on a surface (REPL / API / loop) and returns a
 * validated answer.
 *
 * Return contract:
 *  - a QuestionResponse that satisfies QuestionResponse::isValidFor($question), OR
 *  - null when the question was escalated to an operator and no synchronous
 *    answer is available (loop `block` mode — the caller emits a hard-STOP
 *    sentinel to the agent and halts the stage).
 * Implementations throw only on a genuine error (e.g. a cancelled or
 * timed-out API wait), never as ordinary control flow.
 */
interface QuestionResponderInterface
{
    public function ask(QuestionRequest $question): ?QuestionResponse;
}
