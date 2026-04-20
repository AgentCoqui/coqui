<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Repl\Handler\ImageHandler;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

final class FakeImageToolkit implements ToolkitInterface
{
    /** @var array<string, mixed> */
    public static array $lastGenerateInput = [];

    /** @var array<string, mixed> */
    public static array $lastLibraryInput = [];

    /** @var array<string, mixed> */
    public static array $lastPreflightInput = [];

    public static function fromCoquiContext(array $context): self
    {
        return new self();
    }

    public function guidelines(): string
    {
        return 'Fake toolkit instructions';
    }

    public function tools(): array
    {
        return [
            new Tool(
                name: 'image_preflight',
                description: 'Image preflight',
                parameters: [],
                callback: function (array $input): string {
                    self::$lastPreflightInput = $input;

                    return json_encode([
                        'vendor' => $input['vendor'] ?? 'openai',
                        'model' => $input['model'] ?? 'gpt-image-1.5',
                        'can_generate' => true,
                        'download_required' => false,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
                },
            ),
            new Tool(
                name: 'image_generate',
                description: 'Generate image',
                parameters: [new StringParameter('prompt', 'Prompt', true)],
                callback: function (array $input): string {
                    self::$lastGenerateInput = $input;

                    if (($input['output_format'] ?? null) === 'json') {
                        return json_encode([
                            'message' => 'Image generated successfully.',
                            'saved_path' => '/tmp/fake.png',
                            'preview' => "@@@\n###",
                            'preview_unavailable_reason' => null,
                            'metadata_unavailable_reason' => null,
                            'record' => [
                                'id' => 'img_fake123',
                                'path' => '/tmp/fake.png',
                                'vendor' => 'openai',
                                'model' => 'gpt-image-1.5',
                                'format' => 'png',
                                'metadata_embedded' => true,
                                'provider_payload' => [
                                    'stdout' => 'ignored',
                                ],
                            ],
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
                    }

                    return "Image generated successfully.\nSaved path: /tmp/fake.png";
                },
            ),
            new Tool(
                name: 'image_library',
                description: 'Image library',
                parameters: [new StringParameter('action', 'Action', true)],
                callback: function (array $input): string {
                    self::$lastLibraryInput = $input;
                    return json_encode(['ok' => true, 'input' => $input], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
                },
            ),
            new Tool(
                name: 'image_config',
                description: 'Image config',
                parameters: [],
                callback: static fn(array $input): string => '{"ok":true}',
            ),
        ];
    }
}

final class MissingOllamaImageToolkit implements ToolkitInterface
{
    /** @var array<string, mixed> */
    public static array $lastGenerateInput = [];

    public static function fromCoquiContext(array $context): self
    {
        return new self();
    }

    public function guidelines(): string
    {
        return 'Missing toolkit instructions';
    }

    public function tools(): array
    {
        return [
            new Tool(
                name: 'image_preflight',
                description: 'Image preflight',
                parameters: [],
                callback: static fn(array $input): string => json_encode([
                    'vendor' => 'ollama',
                    'model' => 'jmorgan/z-image-turbo:fp8',
                    'can_generate' => false,
                    'download_required' => true,
                    'download_command' => 'ollama pull jmorgan/z-image-turbo:fp8',
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            ),
            new Tool(
                name: 'image_generate',
                description: 'Generate image',
                parameters: [new StringParameter('prompt', 'Prompt', true)],
                callback: function (array $input): string {
                    self::$lastGenerateInput = $input;

                    return 'should not run';
                },
            ),
            new Tool(
                name: 'image_library',
                description: 'Image library',
                parameters: [new StringParameter('action', 'Action', true)],
                callback: static fn(array $input): string => '{}',
            ),
            new Tool(
                name: 'image_config',
                description: 'Image config',
                parameters: [],
                callback: static fn(array $input): string => '{}',
            ),
        ];
    }
}

test('image handler routes generate arguments into the image toolkit tool', function (): void {
    $workspacePath = sys_get_temp_dir() . '/coqui-image-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    FakeImageToolkit::$lastGenerateInput = [];
    FakeImageToolkit::$lastLibraryInput = [];
    FakeImageToolkit::$lastPreflightInput = [];

    try {
        $discovery = new ToolkitDiscovery(dirname(__DIR__, 5), $workspacePath, null, new ToolkitVisibilityRegistry($workspacePath));
        $discovery->register('acme/fake-image-toolkit', [FakeImageToolkit::class]);

        $bootReflection = new ReflectionClass(BootManager::class);
        /** @var BootManager $boot */
        $boot = $bootReflection->newInstanceWithoutConstructor();
        $initializer = function () use ($workspacePath, $discovery): void {
            $this->workspacePath = $workspacePath;
            $this->discovery = $discovery;
            $this->visibilityRegistry = new ToolkitVisibilityRegistry($workspacePath);
            $this->config = OpenClawConfig::fromArray([]);
        };
        \Closure::bind($initializer, $boot, BootManager::class)();

        $handler = new ImageHandler($boot);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $handler->handle($io, 'generate "cinematic fox" --vendor=openai --tags=animal,forest --category=concept', 'caelum', 'session-1');

        $display = $output->fetch();

        expect(FakeImageToolkit::$lastPreflightInput['vendor'])->toBe('openai');
        expect(FakeImageToolkit::$lastGenerateInput['prompt'])->toBe('cinematic fox');
        expect(FakeImageToolkit::$lastGenerateInput['vendor'])->toBe('openai');
        expect(FakeImageToolkit::$lastGenerateInput['category'])->toBe('concept');
        expect(FakeImageToolkit::$lastGenerateInput['tags_json'])->toBe('["animal","forest"]');
        expect(FakeImageToolkit::$lastGenerateInput['output_format'])->toBe('json');
        expect($display)->toContain('Image generated successfully.');
        expect($display)->toContain('Record ID:');
        expect($display)->toContain('img_fake123');
        expect($display)->toContain('Preview');
        expect($display)->not->toContain('provider_payload');
    } finally {
        cleanupTestTree($workspacePath);
    }
});

test('image handler routes list filters into the image library tool', function (): void {
    $workspacePath = sys_get_temp_dir() . '/coqui-image-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    FakeImageToolkit::$lastLibraryInput = [];

    try {
        $discovery = new ToolkitDiscovery(dirname(__DIR__, 5), $workspacePath, null, new ToolkitVisibilityRegistry($workspacePath));
        $discovery->register('acme/fake-image-toolkit', [FakeImageToolkit::class]);

        $bootReflection = new ReflectionClass(BootManager::class);
        /** @var BootManager $boot */
        $boot = $bootReflection->newInstanceWithoutConstructor();
        $initializer = function () use ($workspacePath, $discovery): void {
            $this->workspacePath = $workspacePath;
            $this->discovery = $discovery;
            $this->visibilityRegistry = new ToolkitVisibilityRegistry($workspacePath);
            $this->config = OpenClawConfig::fromArray([]);
        };
        \Closure::bind($initializer, $boot, BootManager::class)();

        $handler = new ImageHandler($boot);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $handler->handle($io, 'list --profile=caelum --vendor=openai', 'caelum', 'session-1');

        expect(FakeImageToolkit::$lastLibraryInput)->toBe([
            'action' => 'list',
            'profile' => 'caelum',
            'vendor' => 'openai',
        ]);
        expect($output->fetch())->toContain('"ok": true');
    } finally {
        cleanupTestTree($workspacePath);
    }
});

test('image handler refuses silent ollama model pulls in non-interactive mode', function (): void {
    $workspacePath = sys_get_temp_dir() . '/coqui-image-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    MissingOllamaImageToolkit::$lastGenerateInput = [];

    try {
        $discovery = new ToolkitDiscovery(dirname(__DIR__, 5), $workspacePath, null, new ToolkitVisibilityRegistry($workspacePath));
        $discovery->register('acme/missing-ollama-image-toolkit', [MissingOllamaImageToolkit::class]);

        $bootReflection = new ReflectionClass(BootManager::class);
        /** @var BootManager $boot */
        $boot = $bootReflection->newInstanceWithoutConstructor();
        $initializer = function () use ($workspacePath, $discovery): void {
            $this->workspacePath = $workspacePath;
            $this->discovery = $discovery;
            $this->visibilityRegistry = new ToolkitVisibilityRegistry($workspacePath);
            $this->config = OpenClawConfig::fromArray([]);
        };
        \Closure::bind($initializer, $boot, BootManager::class)();

        $handler = new ImageHandler($boot);
        $output = new BufferedOutput();
        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $io = new SymfonyStyle($input, $output);

        $handler->handle($io, 'generate "cinematic fox" --vendor=ollama --model=jmorgan/z-image-turbo:fp8', 'caelum', 'session-1');

        expect(MissingOllamaImageToolkit::$lastGenerateInput)->toBe([]);
        expect($output->fetch())->toContain('ollama pull jmorgan/z-image-turbo:fp8');
    } finally {
        cleanupTestTree($workspacePath);
    }
});