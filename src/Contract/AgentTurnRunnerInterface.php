<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use SplObserver;

/**
 * Narrow turn-execution seam consumed by AgentTurnExecutor.
 *
 * Exposes only the two turn entry points the REPL executor drives — run()
 * for a single session turn and runSegment() for a group actor's segment.
 * AgentRunner is the production implementation; test doubles implement this
 * interface directly rather than subclassing the concrete runner. Other
 * AgentRunner consumers keep using the concrete class.
 */
interface AgentTurnRunnerInterface
{
    /**
     * Run a single agent turn: create agent, execute, persist messages.
     */
    public function run(
        string $prompt,
        string $sessionId,
        ToolExecutionPolicyInterface $executionPolicy,
        ?CancellationTokenInterface $cancellationToken = null,
        ?string $role = null,
        ?string $persona = null,
        ?QuestionResponderInterface $questionResponder = null,
    ): AgentTurnResult;

    /**
     * Execute a single responder segment inside an existing stored turn.
     *
     * @param string[]|null $filePaths
     */
    public function runSegment(
        string $prompt,
        string $sessionId,
        string $turnId,
        ToolExecutionPolicyInterface $executionPolicy,
        ?SplObserver $observer = null,
        ?array $filePaths = null,
        ?string $role = null,
        ?string $persona = null,
        ?string $actorName = null,
        ?string $actorRole = null,
        ?QuestionResponderInterface $questionResponder = null,
    ): AgentTurnResult;
}
