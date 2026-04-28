<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use CoquiBot\Coqui\Toolkit\SessionEvaluationToolkit;

function createSessionEvaluationFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-session-evaluation-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'evaluationStore' => new EvaluationStore($storage->getPdo()),
        'artifactStore' => new ArtifactStore($storage->getPdo()),
        'lifecycleStore' => new SkillLifecycleStore($storage->getPdo()),
        'sessionId' => $storage->createSession('orchestrator', 'test-model'),
    ];
}

function cleanupSessionEvaluationFixture(array $fixture): void
{
    if (file_exists($fixture['dbPath'])) {
        unlink($fixture['dbPath']);
    }
}

function findSessionEvaluationTool(SessionEvaluationToolkit $toolkit, string $name): object
{
    foreach ($toolkit->tools() as $tool) {
        if ($tool->name() === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("Tool not found: {$name}");
}

test('evaluation_save_report persists normalized evidence links', function () {
    $fixture = createSessionEvaluationFixture();

    try {
        $fixture['storage']->logChildRun(
            sessionId: $fixture['sessionId'],
            parentIteration: 1,
            agentRole: 'coder',
            model: 'test-child-model',
            prompt: 'Implement feature',
            result: 'Implemented feature',
        );

        $fixture['artifactStore']->create(
            sessionId: $fixture['sessionId'],
            title: 'Implementation Plan',
            content: 'Plan contents',
            type: 'plan',
        );

        $fixture['lifecycleStore']->recordSkillUsage(
            skillName: 'quality-loop-review',
            action: 'read',
            sourceTool: 'skill_read',
            sessionId: $fixture['sessionId'],
        );

        $toolkit = new SessionEvaluationToolkit(
            evaluationStore: $fixture['evaluationStore'],
            storage: $fixture['storage'],
            artifactStore: $fixture['artifactStore'],
            skillLifecycleStore: $fixture['lifecycleStore'],
        );

        $result = findSessionEvaluationTool($toolkit, 'evaluation_save_report')->execute([
            'session_id' => $fixture['sessionId'],
            'overall_grade' => 'B',
            'score_completion' => 0.8,
            'score_hallucination' => 0.9,
            'score_efficiency' => 0.7,
            'report' => 'Strong outcome with reusable skill support.',
        ]);

        $payload = json_decode($result->content, true);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');
        expect($payload)->toBeArray();
        expect($payload['evidence_links_count'])->toBe(4);

        $links = $fixture['lifecycleStore']->listEvaluationEvidenceLinks($payload['id']);
        expect($links)->toHaveCount(4);
        expect(array_column($links, 'evidence_type'))->toContain('session');
        expect(array_column($links, 'evidence_type'))->toContain('child_run');
        expect(array_column($links, 'evidence_type'))->toContain('artifact');
        expect(array_column($links, 'evidence_type'))->toContain('skill');
    } finally {
        cleanupSessionEvaluationFixture($fixture);
    }
});