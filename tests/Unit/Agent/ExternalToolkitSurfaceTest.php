<?php

declare(strict_types=1);

use CarmeloSantana\CoquiToolkitImages\ImagesToolkit;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Agent\OrchestratorAgent;
use CoquiBot\Coqui\Agent\OrchestratorDependencies;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitLoadingRegistry;
use CoquiBot\Coqui\Contract\ToolkitLoadingMode;
use CoquiBot\Toolkits\Mcp\McpToolkit;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir() . '/coqui-external-toolkit-surface-' . bin2hex(random_bytes(8));
    mkdir($this->workspace, 0755, true);
    file_put_contents($this->workspace . '/.env', '');
    $this->projectRoot = dirname(__DIR__, 3);
});

afterEach(function () {
    cleanupTestTree($this->workspace);
});

/**
 * @return array<string, list<string>>
 */
function externalToolkitProviderMatrix(): array
{
    return [
        'openai' => ['openai/gpt-4o'],
        'anthropic' => ['anthropic/claude-sonnet-4-20250514'],
        'mistral' => ['mistral/mistral-large-latest'],
        'ollama' => ['ollama/qwen3:latest'],
    ];
}

function createExternalToolkitSurfaceAgent(string $chatModel, string $workspacePath, string $projectRoot): OrchestratorAgent
{
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => [
                    'primary' => $chatModel,
                    'imageModel' => 'openai/gpt-image-1.5',
                ],
                'roles' => [
                    'orchestrator' => $chatModel,
                ],
            ],
        ],
        'models' => [
            'providers' => [
                'openai' => ['apiKey' => 'test-openai-key'],
                'anthropic' => ['apiKey' => 'test-anthropic-key'],
                'mistral' => ['apiKey' => 'test-mistral-key'],
                'ollama' => ['baseUrl' => 'http://localhost:11434/v1'],
            ],
        ],
        'images' => [
            'providers' => [
                'openai' => ['apiKey' => 'test-openai-key'],
            ],
        ],
    ]);

    $discovery = new ToolkitDiscovery(
        projectRoot: $projectRoot,
        workspacePath: $workspacePath,
    );
    $discovery->register('carmelosantana/coqui-toolkit-images', [ImagesToolkit::class]);
    $discovery->register('coquibot/coqui-toolkit-mcp-client', [McpToolkit::class]);

    $loadingRegistry = new ToolkitLoadingRegistry($workspacePath);
    $loadingRegistry->setMode('ImagesToolkit', ToolkitLoadingMode::Eager);

    $provider = (new ProviderFactory($config))->create($chatModel);

    return new OrchestratorAgent(
        provider: $provider,
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: $projectRoot,
        workspacePath: $workspacePath,
        deps: new OrchestratorDependencies(
            discovery: $discovery,
            loadingRegistry: $loadingRegistry,
        ),
    );
}

function toolFromAgent(OrchestratorAgent $agent, string $name): ToolInterface
{
    $reflection = new ReflectionProperty($agent, 'ownToolkits');
    $reflection->setAccessible(true);

    foreach ($reflection->getValue($agent) as $toolkit) {
        foreach ($toolkit->tools() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }
    }

    throw new InvalidArgumentException(sprintf('Tool "%s" not found.', $name));
}

function createPreviewFixture(string $workspacePath): string
{
    if (!function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('ext-gd not available');
    }

    $imagesDir = $workspacePath . '/images';
    mkdir($imagesDir, 0755, true);

    $path = $imagesDir . '/example.png';
    $image = imagecreatetruecolor(4, 4);
    assert($image !== false);

    $color = imagecolorallocate($image, 20, 120, 240);
    assert($color !== false);

    imagefill($image, 0, 0, $color);
    imagepng($image, $path);
    imagedestroy($image);

    return $path;
}

test('coqui loads external image and mcp tool surfaces across supported chat providers', function (string $chatModel) {
    $agent = createExternalToolkitSurfaceAgent($chatModel, $this->workspace, $this->projectRoot);

    $toolNames = array_map(static fn($tool) => $tool->name(), [
        toolFromAgent($agent, 'image_preflight'),
        toolFromAgent($agent, 'image_generate'),
        toolFromAgent($agent, 'image_preview'),
        toolFromAgent($agent, 'image_library'),
        toolFromAgent($agent, 'image_config'),
        toolFromAgent($agent, 'mcp'),
    ]);

    expect($agent->getAppliedLoadingModes()['ImagesToolkit'] ?? null)->toBe(ToolkitLoadingMode::Eager);
    expect($toolNames)->toContain('image_preflight');
    expect($toolNames)->toContain('image_generate');
    expect($toolNames)->toContain('image_preview');
    expect($toolNames)->toContain('image_library');
    expect($toolNames)->toContain('image_config');
    expect($toolNames)->toContain('mcp');
})->with(externalToolkitProviderMatrix());

test('coqui preserves structured image preview output across supported chat providers', function (string $chatModel) {
    if (!function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('ext-gd not available');
    }

    $fixturePath = createPreviewFixture($this->workspace);
    $agent = createExternalToolkitSurfaceAgent($chatModel, $this->workspace, $this->projectRoot);

    $result = toolFromAgent($agent, 'image_preview')->execute([
        'path' => 'images/example.png',
        'width' => 8,
    ]);

    $payload = assertStructuredToolResult($result);

    expect($payload['path'])->toBe(realpath($fixturePath));
    expect($payload['preview_format'])->toBe('ansi_blocks');
    expect($payload['preview'])->toContain("\033[38;2;");
})->with(externalToolkitProviderMatrix());

test('coqui exposes mcp management as a text-first tool across supported chat providers', function (string $chatModel) {
    $agent = createExternalToolkitSurfaceAgent($chatModel, $this->workspace, $this->projectRoot);

    $result = toolFromAgent($agent, 'mcp')->execute([
        'action' => 'list',
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->mimeType)->toBeNull();
    expect($result->displayHint)->toBeNull();
    expect($result->content)->toContain('No MCP servers configured.');
})->with(externalToolkitProviderMatrix());