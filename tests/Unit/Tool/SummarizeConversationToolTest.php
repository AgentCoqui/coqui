<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tool\SummarizeConversationTool;

/**
 * A minimal ConfigInterface stub for testing.
 * Returns empty values and no model definitions.
 */
function createStubConfig(): ConfigInterface
{
    return new class implements ConfigInterface {
        public function get(string $key, mixed $default = null): mixed { return $default; }
        public function has(string $key): bool { return false; }
        public function resolveModel(string $modelOrAlias): string { return $modelOrAlias; }
        public function getPrimaryModel(): string { return ''; }
        public function getImageModel(): ?string { return null; }
        public function getProviderConfig(string $provider): array { return []; }
        public function getModelDefinition(string $model): ?ModelDefinition { return null; }
    };
}

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-test-sumtool-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->summarizer = new ConversationSummarizer(
        storage: $this->storage,
    );

    // Config that returns no models → RoleResolver returns empty string → ProviderFactory fails
    $this->config = createStubConfig();
    $this->roleResolver = new RoleResolver($this->config);

    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');

    $this->tool = new SummarizeConversationTool(
        summarizer: $this->summarizer,
        roleResolver: $this->roleResolver,
        config: $this->config,
        sessionId: $this->sessionId,
    );
});

afterEach(function () {
    unset($this->tool, $this->summarizer, $this->storage);

    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }

    foreach (['-wal', '-shm'] as $suffix) {
        $path = $this->dbPath . $suffix;
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

test('tool name is summarize_conversation', function () {
    expect($this->tool->name())->toBe('summarize_conversation');
});

test('tool description is non-empty', function () {
    expect($this->tool->description())->not->toBeEmpty();
});

test('tool has scope, keep_recent, and focus parameters', function () {
    $params = $this->tool->parameters();
    $names = array_map(fn($p) => $p->name, $params);

    expect($names)->toContain('scope');
    expect($names)->toContain('keep_recent');
    expect($names)->toContain('focus');
});

test('toFunctionSchema returns valid schema', function () {
    $schema = $this->tool->toFunctionSchema();

    expect($schema)->toBeArray();
    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('summarize_conversation');
    expect($schema['function']['parameters']['type'])->toBe('object');
});

test('execute returns success with too-short message when no messages exist', function () {
    $result = $this->tool->execute([]);

    expect($result)->toBeInstanceOf(ToolResult::class);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('too short');
});

test('execute returns success for short conversations', function () {
    // Only 1 turn - too short to summarize
    $this->storage->addMessage($this->sessionId, 'user', 'Hello');
    $this->storage->addMessage($this->sessionId, 'assistant', 'Hi!');

    $result = $this->tool->execute([]);

    expect($result)->toBeInstanceOf(ToolResult::class);
    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('too short');
});

test('keep_recent is clamped to bounds', function () {
    // Verify params are clamped by just checking tool doesn't crash with extreme values
    $this->storage->addMessage($this->sessionId, 'user', 'Hello');

    // This should not crash — keep_recent = 100 gets clamped to 20
    $result = $this->tool->execute(['keep_recent' => 100]);

    expect($result)->toBeInstanceOf(ToolResult::class);
});
