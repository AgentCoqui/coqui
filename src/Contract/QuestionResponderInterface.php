<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Renders a QuestionRequest on a surface (REPL / API / loop) and returns a
 * validated answer.
 *
 * Return contract: always a QuestionResponse that satisfies
 * QuestionResponse::isValidFor($question). Implementations throw only on a
 * genuine error (e.g. a cancelled or timed-out API wait), never as ordinary
 * control flow. Loops never block on a question — the loop/background
 * responder auto-answers with the agent's suggested answer.
 */
interface QuestionResponderInterface
{
    public function ask(QuestionRequest $question): QuestionResponse;
}
