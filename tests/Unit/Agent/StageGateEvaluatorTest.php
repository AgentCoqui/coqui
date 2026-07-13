<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\ProviderFinishReason;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Agent\StageGateEvaluator;

function makeGateProvider(string $responseContent): ProviderInterface
{
    return new class ($responseContent) implements ProviderInterface {
        public function __construct(private readonly string $responseContent) {}
        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response(content: $this->responseContent, finishReason: ProviderFinishReason::Stop);
        }
        public function stream(array $messages, array $tools = [], array $options = []): iterable { return []; }
        public function structured(array $messages, string $schema, array $options = []): mixed { return null; }
        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
}

test('judge parses an approved JSON verdict', function () {
    $json = '{"requirements_met": true, "quality_pass": true, "findings": [], "rationale": "Meets the goal."}';
    $evaluator = new StageGateEvaluator(makeGateProvider($json));

    $verdict = $evaluator->judge('Build X', 'All tests pass.', 'I reviewed it; it is complete.');

    expect($verdict->isApproved())->toBeTrue();
    expect($verdict->rationale)->toBe('Meets the goal.');
});

test('judge parses findings with severities into a non-approved verdict', function () {
    $json = '{"requirements_met": true, "quality_pass": false, "findings": [{"severity":"critical","summary":"crashes on empty input"}], "rationale":"One critical bug."}';
    $evaluator = new StageGateEvaluator(makeGateProvider($json));

    $verdict = $evaluator->judge('Build X', null, 'Reviewed.');

    expect($verdict->isApproved())->toBeFalse();
    expect($verdict->hasBlockingFindings())->toBeTrue();
    expect($verdict->findings[0]->summary)->toBe('crashes on empty input');
});

test('judge extracts a fenced json block when the model wraps it in prose', function () {
    $content = "Here is my assessment:\n```json\n{\"requirements_met\": false, \"quality_pass\": true, \"findings\": [], \"rationale\": \"Incomplete.\"}\n```\nDone.";
    $evaluator = new StageGateEvaluator(makeGateProvider($content));

    $verdict = $evaluator->judge('Build X', null, 'Reviewed.');

    expect($verdict->requirementsMet)->toBeFalse();
});

test('judge falls back to keyword parsing on unparseable output', function () {
    $evaluator = new StageGateEvaluator(makeGateProvider('APPROVED — everything checks out.'));

    $verdict = $evaluator->judge('Build X', null, 'Reviewed.');

    expect($verdict->isApproved())->toBeTrue();
});

test('judge falls back to a not-approved verdict when the provider throws', function () {
    $throwing = new class implements ProviderInterface {
        public function chat(array $messages, array $tools = [], array $options = []): Response { throw new \RuntimeException('boom'); }
        public function stream(array $messages, array $tools = [], array $options = []): iterable { return []; }
        public function structured(array $messages, string $schema, array $options = []): mixed { return null; }
        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
    $evaluator = new StageGateEvaluator($throwing);

    $verdict = $evaluator->judge('Build X', null, 'Reviewed.');

    expect($verdict->isApproved())->toBeFalse();
});
