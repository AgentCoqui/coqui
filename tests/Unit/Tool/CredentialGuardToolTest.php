<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Contract\CredentialRequirement;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Tool\CredentialGuardTool;

function createMockResolver(array $available = []): CredentialResolverInterface
{
    return new class ($available) implements CredentialResolverInterface {
        public function __construct(private readonly array $available) {}
        public function get(string $key): ?string { return $this->available[$key] ?? null; }
        public function has(string $key): bool { return isset($this->available[$key]); }
        public function set(string $key, string $value): void {}
        public function delete(string $key): void {}
        public function keys(): array { return array_keys($this->available); }
        public function envPath(): string { return '/tmp/.env'; }
        public function loadIntoProcessEnv(): void {}
    };
}

function createMockTool(): ToolInterface
{
    return new class implements ToolInterface {
        public function name(): string { return 'test_tool'; }
        public function description(): string { return 'A test tool'; }
        public function parameters(): array { return []; }
        public function execute(array $input): ToolResult { return ToolResult::success('executed'); }
        public function toFunctionSchema(): array { return ['type' => 'function', 'function' => ['name' => 'test_tool']]; }
    };
}

test('executes inner tool when credentials are present', function () {
    $guard = new CredentialGuardTool(
        inner: createMockTool(),
        requirements: [new CredentialRequirement('API_KEY', 'Test key')],
        resolver: createMockResolver(['API_KEY' => 'secret']),
    );

    $result = $guard->execute([]);
    expect($result->content)->toBe('executed');
});

test('blocks execution when credentials are missing', function () {
    $guard = new CredentialGuardTool(
        inner: createMockTool(),
        requirements: [new CredentialRequirement('API_KEY', 'Test key')],
        resolver: createMockResolver([]),
    );

    $result = $guard->execute([]);
    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('Missing Credentials');
    expect($result->content)->toContain('API_KEY');
});

test('default mode suggests credentials tool', function () {
    $guard = new CredentialGuardTool(
        inner: createMockTool(),
        requirements: [new CredentialRequirement('API_KEY', 'Test key')],
        resolver: createMockResolver([]),
    );

    $result = $guard->execute([]);
    expect($result->content)->toContain('credentials(action: "set"');
    expect($result->content)->toContain('How to Fix');
    expect($result->content)->not->toContain('Cannot Fix Here');
});

test('child mode tells agent to report to parent', function () {
    $guard = new CredentialGuardTool(
        inner: createMockTool(),
        requirements: [new CredentialRequirement('API_KEY', 'Test key')],
        resolver: createMockResolver([]),
        childMode: true,
    );

    $result = $guard->execute([]);
    expect($result->content)->toContain('Cannot Fix Here');
    expect($result->content)->toContain('child agent');
    expect($result->content)->toContain('parent agent');
    expect($result->content)->not->toContain('credentials(action: "set"');
});

test('child mode still executes when credentials are present', function () {
    $guard = new CredentialGuardTool(
        inner: createMockTool(),
        requirements: [new CredentialRequirement('API_KEY', 'Test key')],
        resolver: createMockResolver(['API_KEY' => 'secret']),
        childMode: true,
    );

    $result = $guard->execute([]);
    expect($result->content)->toBe('executed');
});

test('optional credentials do not block execution', function () {
    $guard = new CredentialGuardTool(
        inner: createMockTool(),
        requirements: [
            new CredentialRequirement('REQUIRED_KEY', 'Required', false),
            new CredentialRequirement('OPTIONAL_KEY', 'Optional', true),
        ],
        resolver: createMockResolver(['REQUIRED_KEY' => 'val']),
    );

    $result = $guard->execute([]);
    expect($result->content)->toBe('executed');
});

test('delegates name and description to inner tool', function () {
    $guard = new CredentialGuardTool(
        inner: createMockTool(),
        requirements: [],
        resolver: createMockResolver([]),
    );

    expect($guard->name())->toBe('test_tool');
    expect($guard->description())->toBe('A test tool');
});
