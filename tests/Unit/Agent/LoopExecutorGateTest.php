<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Agent\StageGateEvaluator;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;

function gateProviderReturning(string $json): ProviderInterface
{
    return new class ($json) implements ProviderInterface {
        public function __construct(private readonly string $json) {}
        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response(content: $this->json, finishReason: ProviderFinishReason::Stop);
        }
        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            return [];
        }
        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return null;
        }
        public function models(): array
        {
            return [];
        }
        public function isAvailable(): bool
        {
            return true;
        }
        public function getModel(): string
        {
            return 'test/model';
        }
        public function withModel(string $model): static
        {
            return $this;
        }
    };
}

function evalBoundConfig(int $maxReworkAttempts = 3): array
{
    return [
        'name' => 'harness',
        'description' => 'gate harness',
        'roles' => [
            ['role' => 'coder', 'prompt' => 'do'],
            ['role' => 'reviewer', 'prompt' => 'review'],
        ],
        'termination_condition' => ['type' => 'evaluation_bound', 'value' => ['criteria' => 'ship it', 'max_review_rounds' => 10]],
        'max_rework_attempts' => $maxReworkAttempts,
    ];
}

function completeBothStages(LoopStore $store, string $loopId, string $reviewerOutput): void
{
    $stages = $store->getCurrentState($loopId)['stages'];
    $store->updateStage(id: $stages[0]['id'], status: 'completed', resultSummary: 'coder did the work');
    $store->updateStage(id: $stages[1]['id'], status: 'completed', resultSummary: $reviewerOutput);
}

function gateExecutor(string $verdictJson, LoopStore $loopStore, ProjectStore $projectStore): LoopExecutor
{
    return new LoopExecutor(
        loopStore: $loopStore,
        projectStore: $projectStore,
        sessionStorage: null,
        goalEvaluator: null,
        stageGateEvaluator: new StageGateEvaluator(gateProviderReturning($verdictJson)),
    );
}

test('an approved gate verdict completes the loop', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo);
    $projectStore = new ProjectStore($pdo);
    $projectId = $projectStore->createProject(title: 'p', slug: 'g-1', description: 'd');

    $executor = gateExecutor('{"requirements_met": true, "quality_pass": true, "findings": [], "rationale": "ok"}', $loopStore, $projectStore);
    $loopId = $executor->startLoop(evalBoundConfig(), 'goal', projectId: $projectId, maxIterationsOverride: 10);
    completeBothStages($loopStore, $loopId, 'reviewed, all good');

    $outcome = $executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Complete);
    expect($loopStore->getLoop($loopId)['status'])->toBe('completed');
});

test('a rejected gate verdict marks the iteration needs_rework and continues', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo);
    $projectStore = new ProjectStore($pdo);
    $projectId = $projectStore->createProject(title: 'p', slug: 'g-2', description: 'd');

    $executor = gateExecutor('{"requirements_met": false, "quality_pass": false, "findings": [{"severity":"critical","summary":"broken"}], "rationale": "no"}', $loopStore, $projectStore);
    $loopId = $executor->startLoop(evalBoundConfig(), 'goal', projectId: $projectId, maxIterationsOverride: 10);
    $firstIterId = $loopStore->getCurrentState($loopId)['iteration']['id'];
    completeBothStages($loopStore, $loopId, 'reviewed, problems found');

    $outcome = $executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Continue);
    expect($loopStore->getIteration($firstIterId)['status'])->toBe('needs_rework');
    $meta = json_decode($loopStore->getLoop($loopId)['metadata'], true);
    expect($meta['rework_attempts'])->toBe(1);
});

test('the circuit-breaker trips to blocked after max_rework_attempts rejections', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo);
    $projectStore = new ProjectStore($pdo);
    $projectId = $projectStore->createProject(title: 'p', slug: 'g-3', description: 'd');

    $reject = '{"requirements_met": false, "quality_pass": true, "findings": [{"severity":"important","summary":"x"}], "rationale": "no"}';
    $executor = gateExecutor($reject, $loopStore, $projectStore);
    // max_rework_attempts = 2 so we trip on the second rejection.
    $loopId = $executor->startLoop(evalBoundConfig(2), 'goal', projectId: $projectId, maxIterationsOverride: 10);

    // Round 1 → Continue (advances a new iteration).
    completeBothStages($loopStore, $loopId, 'round 1 review');
    expect($executor->evaluateIteration($loopId))->toBe(IterationOutcome::Continue);

    // Round 2 → breaker trips → Blocked.
    completeBothStages($loopStore, $loopId, 'round 2 review');
    expect($executor->evaluateIteration($loopId))->toBe(IterationOutcome::Blocked);
    expect($loopStore->getLoop($loopId)['status'])->toBe('blocked');
});

test('gate falls back to keyword approval when no evaluator is configured', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo);
    $projectStore = new ProjectStore($pdo);
    $projectId = $projectStore->createProject(title: 'p', slug: 'g-4', description: 'd');

    $executor = new LoopExecutor($loopStore, $projectStore); // no stageGateEvaluator
    $loopId = $executor->startLoop(evalBoundConfig(), 'goal', projectId: $projectId, maxIterationsOverride: 10);
    completeBothStages($loopStore, $loopId, 'This is APPROVED and complete.');

    expect($executor->evaluateIteration($loopId))->toBe(IterationOutcome::Complete);
});
