<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Contract\AgentTurnRunnerInterface;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Observer\EscCancellationObserver;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Question\InteractiveQuestionResponder;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Question\SuspendingQuestionResponder;
use CoquiBot\Coqui\Repl\AgentTurnExecutor;
use CoquiBot\Coqui\Repl\ExecutionPolicyFactory;
use CoquiBot\Coqui\Repl\TerminalStateManager;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * These tests verify RESPONDER ATTACHMENT across the turn paths — that each
 * execution path hands the agent the correct QuestionResponderInterface — not
 * tool counts. The group-path test in particular guards the Task-4 E1 fix:
 * before E1 the group path called runSegment() with no responder (null), so
 * `ask_user` questions in group turns had no responder. The captured-argument
 * assertion below fails on that pre-E1 code and passes only once E1 attaches an
 * InteractiveQuestionResponder per actor.
 */

/**
 * A benign, fully-populated turn result. Empty content emits no @mention, so the
 * group coordinator stops after one round (each member is exercised exactly once).
 */
function wiringBenignTurnResult(): AgentTurnResult
{
    return new AgentTurnResult(
        content: '',
        iterations: 0,
        promptTokens: 0,
        completionTokens: 0,
        totalTokens: 0,
        durationMs: 0,
        toolsUsed: [],
        childAgentCount: 0,
        restartRequested: false,
    );
}

/** Sentinel used to abort execute() right after the single-path wiring is captured. */
final class ResponderCaptured extends \RuntimeException {}

/**
 * Spy runner: implements the narrow AgentTurnRunnerInterface and records the
 * questionResponder each turn method receives, without running a real turn or
 * touching AgentRunner's heavy dependency graph.
 */
final class CapturingAgentRunner implements AgentTurnRunnerInterface
{
    /** @var list<?QuestionResponderInterface> */
    public array $runResponders = [];

    /** @var list<?QuestionResponderInterface> */
    public array $segmentResponders = [];

    public function run(
        string $prompt,
        string $sessionId,
        ToolExecutionPolicyInterface $executionPolicy,
        ?CancellationTokenInterface $cancellationToken = null,
        ?string $role = null,
        ?string $persona = null,
        ?QuestionResponderInterface $questionResponder = null,
    ): AgentTurnResult {
        $this->runResponders[] = $questionResponder;

        // Abort before AgentTurnExecutor::execute() reaches the renderer/BootManager.
        throw new ResponderCaptured();
    }

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
    ): AgentTurnResult {
        $this->segmentResponders[] = $questionResponder;

        return wiringBenignTurnResult();
    }
}

function wiringSymfonyStyle(): SymfonyStyle
{
    return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
}

function wiringEscObserver(): EscCancellationObserver
{
    $output = new BufferedOutput();

    return new EscCancellationObserver(
        new TerminalObserver($output),
        new ProcessCancellationToken(),
        $output,
        isTty: false,
    );
}

/**
 * Build an AgentTurnExecutor around the spy runner. `boot` and `policyFactory`
 * are only exercised by the single-session path; the group-path reflection test
 * never touches them, so uninitialized instances are safe there.
 */
function wiringExecutor(CapturingAgentRunner $runner, SessionStorage $storage, ?ExecutionPolicyFactory $policyFactory = null): AgentTurnExecutor
{
    $boot = (new ReflectionClass(BootManager::class))->newInstanceWithoutConstructor();
    $policyFactory ??= (new ReflectionClass(ExecutionPolicyFactory::class))->newInstanceWithoutConstructor();

    return new AgentTurnExecutor(
        $runner,
        $boot,
        $storage,
        wiringEscObserver(),
        new TerminalStateManager(isTty: false),
        $policyFactory,
    );
}

test('single-session path attaches an InteractiveQuestionResponder to run()', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');

    $runner = new CapturingAgentRunner();
    // autoApprove=true keeps buildInteractive on the AutoApprovalPolicy branch,
    // which needs only the blacklist + storage (no interactive prompt, no TTY).
    $policyFactory = new ExecutionPolicyFactory(
        new CatastrophicBlacklist(),
        $storage,
        (new ReflectionClass(ToolkitDiscovery::class))->newInstanceWithoutConstructor(),
    );
    $executor = wiringExecutor($runner, $storage, $policyFactory);

    $savedStty = null;
    try {
        $executor->execute(
            prompt: 'hello',
            sessionId: $sessionId,
            activeRole: 'orchestrator',
            io: wiringSymfonyStyle(),
            autoApprove: true,
            hasSignals: false,
            savedStty: $savedStty,
        );
    } catch (ResponderCaptured) {
        // Expected: the spy captured the responder and aborted before rendering.
    }

    expect($runner->runResponders)->toHaveCount(1);
    expect($runner->runResponders[0])->toBeInstanceOf(InteractiveQuestionResponder::class);
});

test('group path attaches an InteractiveQuestionResponder to runSegment() per actor (guards E1)', function () {
    $storage = new SessionStorage(':memory:');
    // Non-empty model avoids BootManager (roleResolver) in executeGroupTurn.
    $sessionId = $storage->createGroupSession(
        modelRole: 'orchestrator',
        model: 'ollama/qwen3:latest',
        members: ['alice', 'bob'],
        groupMaxRounds: 1,
    );

    $runner = new CapturingAgentRunner();
    $executor = wiringExecutor($runner, $storage);

    // Drive the private group-turn path directly (execute()'s TTY/renderer
    // prologue is irrelevant to the wiring under test).
    $session = $storage->getSession($sessionId);
    $policy = new class implements ToolExecutionPolicyInterface {
        public function shouldExecute(string $toolName, array $arguments): true|string
        {
            return true;
        }
    };

    $method = new ReflectionMethod(AgentTurnExecutor::class, 'executeGroupTurn');
    $method->invoke($executor, 'hello team', $sessionId, $session, $policy, wiringSymfonyStyle());

    // One captured responder per member, each a non-null InteractiveQuestionResponder.
    expect($runner->segmentResponders)->toHaveCount(2);
    foreach ($runner->segmentResponders as $responder) {
        expect($responder)->toBeInstanceOf(InteractiveQuestionResponder::class);
    }
});

test('the API turn path (turn:run) wires a SuspendingQuestionResponder that blocks on the DB', function () {
    // Wiring anchor: TurnRunCommand builds the suspending responder, not the
    // interactive one. This guards against a regression that swaps the API path
    // back to a TTY prompt.
    $source = file_get_contents(__DIR__ . '/../../../src/Command/TurnRunCommand.php');
    expect($source)->toContain('new \CoquiBot\Coqui\Question\SuspendingQuestionResponder(');
    expect($source)->not->toContain('InteractiveQuestionResponder');

    // Behavioral proof of why the API path uses it: ask() persists the question
    // and block-polls the DB until the REST answer endpoint records an answer —
    // it does not prompt a terminal. The injected sleeper stands in for that
    // out-of-band answer arriving between polls.
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    // A real turn_processes row is required: appendTurnEvent FKs to it.
    $turnProcessId = $storage->createTurnProcess($sessionId, 'hello');

    $responder = new SuspendingQuestionResponder(
        $persistence,
        $storage,
        $sessionId,
        $turnProcessId,
        null,
        pollIntervalMicros: 1,
        timeoutSeconds: 5,
        sleeper: function () use ($storage): void {
            // Simulate the answer being recorded out-of-band before the next poll.
            $storage->recordQuestionAnswer('q1', new QuestionResponse(['apple']));
        },
    );

    expect($responder)->toBeInstanceOf(QuestionResponderInterface::class);

    $answer = $responder->ask(sampleRequest());

    // The question was persisted as a suspending question and resolved from the DB.
    expect($answer->selected)->toBe(['apple']);
    expect($storage->getQuestion('q1')['status'])->toBe('answered');
});
