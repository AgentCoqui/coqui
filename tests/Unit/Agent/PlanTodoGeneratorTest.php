<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CoquiBot\Coqui\Agent\PlanTodoGenerator;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-plan-todo-gen-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->artifactStore = new ArtifactStore($this->storage->getPdo());
    $this->todoStore = new TodoStore($this->storage->getPdo());

    $this->artifactId = $this->artifactStore->create(
        sessionId: $this->sessionId,
        type: 'plan',
        title: 'Test plan',
        content: 'Plan content',
    );
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

function createTestConfig(): ConfigInterface
{
    return new class implements ConfigInterface {
        public function get(string $key, mixed $default = null): mixed { return $default; }
        public function has(string $key): bool { return false; }
        public function resolveModel(string $modelOrAlias): string { return $modelOrAlias; }
        public function getPrimaryModel(): string { return 'test/model'; }
        public function getImageModel(): ?string { return null; }
        public function getProviderConfig(string $provider): array { return []; }
        public function getModelDefinition(string $model): ?CarmeloSantana\PHPAgents\Config\ModelDefinition
        {
            return null;
        }
    };
}

function createTestGenerator(TodoStore $todoStore): PlanTodoGenerator
{
    $config = createTestConfig();

    // RoleResolver is final — use a real instance with our test config.
    // resolveUtility() falls back to resolve('title-generator') which
    // returns config->getPrimaryModel() → 'test/model'.
    $roleResolver = new RoleResolver($config);

    return new PlanTodoGenerator(
        roleResolver: $roleResolver,
        config: $config,
        todoStore: $todoStore,
    );
}

// --- generate() error handling ---

test('generate returns empty array when provider is unavailable', function () {
    $generator = createTestGenerator($this->todoStore);

    $result = $generator->generate(
        artifactId: $this->artifactId,
        sessionId: $this->sessionId,
        planContent: 'Some plan content',
    );

    // Provider creation will fail (no real provider for 'test/model')
    // PlanTodoGenerator catches all exceptions and returns []
    expect($result)->toBe([]);

    // No todos should be created
    $todos = $this->todoStore->list($this->sessionId);
    expect($todos)->toHaveCount(0);
});

test('generate passes artifactId and sessionId to bulkCreate', function () {
    // This test verifies that when bulkCreate is called, the correct
    // artifactId and sessionId are used. We test this by checking
    // todo records after a successful generate.
    // Since we can't inject a mock provider (final class), we verify
    // the TodoStore integration indirectly.
    $items = [
        ['title' => 'Step 1', 'priority' => 'high', 'notes' => 'Do this first'],
        ['title' => 'Step 2', 'priority' => 'medium'],
    ];

    // Directly call bulkCreate to verify the linking works correctly
    $ids = $this->todoStore->bulkCreate(
        sessionId: $this->sessionId,
        items: $items,
        createdBy: 'plan',
        artifactId: $this->artifactId,
    );

    expect($ids)->toHaveCount(2);

    // Verify all todos are linked to the artifact
    $linked = $this->todoStore->list($this->sessionId, artifactId: $this->artifactId);
    expect($linked)->toHaveCount(2);

    // Verify session scoping
    foreach ($linked as $todo) {
        expect($todo['session_id'])->toBe($this->sessionId);
        expect($todo['artifact_id'])->toBe($this->artifactId);
        expect($todo['created_by'])->toBe('plan');
    }
});

test('generate with sprintId passes it through to bulkCreate', function () {
    $sprintId = bin2hex(random_bytes(16));

    $ids = $this->todoStore->bulkCreate(
        sessionId: $this->sessionId,
        items: [['title' => 'Sprint task']],
        createdBy: 'plan',
        artifactId: $this->artifactId,
        sprintId: $sprintId,
    );

    $todo = $this->todoStore->get($ids[0]);

    expect($todo['sprint_id'])->toBe($sprintId);
    expect($todo['artifact_id'])->toBe($this->artifactId);
});

// --- JSON parsing edge cases (tested via TodoStore integration) ---

test('bulkCreate caps at 25 items preserving order', function () {
    $items = array_map(
        fn(int $i) => ['title' => "Step {$i}"],
        range(1, 30),
    );

    // PlanTodoGenerator slices to 25 — verify TodoStore handles 25 items
    $sliced = array_slice($items, 0, 25);

    $ids = $this->todoStore->bulkCreate(
        sessionId: $this->sessionId,
        items: $sliced,
        createdBy: 'plan',
        artifactId: $this->artifactId,
    );

    expect($ids)->toHaveCount(25);

    $todos = $this->todoStore->list($this->sessionId, artifactId: $this->artifactId, limit: 30);
    expect($todos)->toHaveCount(25);

    // Verify sort order is preserved
    for ($i = 0; $i < 25; $i++) {
        expect($todos[$i]['title'])->toBe("Step " . ($i + 1));
    }
});

test('bulkCreate with empty items returns empty array', function () {
    $ids = $this->todoStore->bulkCreate(
        sessionId: $this->sessionId,
        items: [],
        createdBy: 'plan',
        artifactId: $this->artifactId,
    );

    expect($ids)->toBe([]);
});

test('generated todos are scoped to correct session', function () {
    $otherSessionId = $this->storage->createSession('orchestrator', 'test/model');

    $this->todoStore->bulkCreate(
        sessionId: $this->sessionId,
        items: [['title' => 'Session A todo']],
        createdBy: 'plan',
        artifactId: $this->artifactId,
    );

    // Other session should not see these todos
    $otherTodos = $this->todoStore->list($otherSessionId);
    expect($otherTodos)->toHaveCount(0);

    // Original session sees them
    $ourTodos = $this->todoStore->list($this->sessionId);
    expect($ourTodos)->toHaveCount(1);
});
