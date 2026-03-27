<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Contract\CredentialRequirement;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Tool\CredentialGuardToolkit;

function createStubResolver(array $available = []): CredentialResolverInterface
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

function createStubToolkit(): ToolkitInterface
{
    return new class implements ToolkitInterface {
        public function tools(): array
        {
            return [
                new class implements ToolInterface {
                    public function name(): string { return 'inner_tool'; }
                    public function description(): string { return 'Inner tool'; }
                    public function parameters(): array { return []; }
                    public function execute(array $input): ToolResult { return ToolResult::success('ok'); }
                    public function toFunctionSchema(): array { return []; }
                },
            ];
        }

        public function guidelines(): string { return 'Inner guidelines.'; }
    };
}

test('wraps tools with credential guards', function () {
    $toolkit = new CredentialGuardToolkit(
        inner: createStubToolkit(),
        requirements: [new CredentialRequirement('KEY', 'desc')],
        resolver: createStubResolver([]),
    );

    $tools = $toolkit->tools();
    expect($tools)->toHaveCount(1);
    expect($tools[0]->name())->toBe('inner_tool');

    // Tool should block because KEY is missing
    $result = $tools[0]->execute([]);
    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('KEY');
});

test('default guidelines mention credentials tool when missing', function () {
    $toolkit = new CredentialGuardToolkit(
        inner: createStubToolkit(),
        requirements: [new CredentialRequirement('KEY', 'desc')],
        resolver: createStubResolver([]),
    );

    $guidelines = $toolkit->guidelines();
    expect($guidelines)->toContain('MISSING');
    expect($guidelines)->toContain('`credentials` tool');
});

test('child mode guidelines omit credentials tool reference', function () {
    $toolkit = new CredentialGuardToolkit(
        inner: createStubToolkit(),
        requirements: [new CredentialRequirement('KEY', 'desc')],
        resolver: createStubResolver([]),
        childMode: true,
    );

    $guidelines = $toolkit->guidelines();
    expect($guidelines)->toContain('MISSING');
    expect($guidelines)->toContain('report missing credentials back to the parent agent');
    expect($guidelines)->not->toContain('`credentials` tool');
});

test('child mode tool errors reference parent agent', function () {
    $toolkit = new CredentialGuardToolkit(
        inner: createStubToolkit(),
        requirements: [new CredentialRequirement('KEY', 'desc')],
        resolver: createStubResolver([]),
        childMode: true,
    );

    $tools = $toolkit->tools();
    $result = $tools[0]->execute([]);
    expect($result->content)->toContain('parent agent');
    expect($result->content)->not->toContain('credentials(action: "set"');
});

test('guidelines show configured when all credentials present', function () {
    $toolkit = new CredentialGuardToolkit(
        inner: createStubToolkit(),
        requirements: [new CredentialRequirement('KEY', 'desc')],
        resolver: createStubResolver(['KEY' => 'value']),
        childMode: true,
    );

    $guidelines = $toolkit->guidelines();
    expect($guidelines)->toContain('All required credentials are configured');
});

test('innerClass returns wrapped toolkit FQCN', function () {
    $inner = createStubToolkit();
    $toolkit = new CredentialGuardToolkit(
        inner: $inner,
        requirements: [],
        resolver: createStubResolver([]),
    );

    expect($toolkit->innerClass())->toBe($inner::class);
});
