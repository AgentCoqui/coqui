<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Agent\OrchestratorAgent;
use CoquiBot\Coqui\Agent\OrchestratorDependencies;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Prompt\PromptLoader;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir() . '/coqui-context-section-test-' . bin2hex(random_bytes(4));
    mkdir($this->workspace, 0755, true);
    file_put_contents($this->workspace . '/.env', '');

    $this->projectRoot = dirname(__DIR__, 3);

    $this->config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['coder' => 'ollama/qwen3:latest'],
            ],
        ],
    ]);

    $this->roleResolver = new RoleResolver($this->config);

    $this->provider = new class implements ProviderInterface {
        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            throw new \RuntimeException('Not implemented');
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            throw new \RuntimeException('Not implemented');
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            throw new \RuntimeException('Not implemented');
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return false;
        }

        public function getModel(): string
        {
            return 'test/mock';
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
});

afterEach(function () {
    cleanupTestTree($this->workspace);
});

/**
 * @return list<CoquiBot\Coqui\Contract\PromptSection>
 */
function invokeBuildInstructionPromptSections(OrchestratorAgent $agent): array
{
    $method = new ReflectionMethod($agent, 'buildInstructionPromptSections');
    $method->setAccessible(true);

    return $method->invoke($agent);
}

it('places context immediately after backstory in classified sections (loader ordering)', function () {
    $persona = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($persona . '/context', 0777, true);
    file_put_contents($persona . '/soul.md', '# Soul');
    file_put_contents($persona . '/backstory.md', '# Backstory');
    file_put_contents($persona . '/context/github.md', '# GitHub');

    $loader = new PromptLoader(
        promptsDir: dirname(__DIR__, 3) . '/prompts',
        placeholders: [],
        workspacePath: sys_get_temp_dir(),
        profilePath: $persona,
    );

    $ids = array_column($loader->buildSystemPromptSections(), 'id');
    expect(array_search('context', $ids, true))->toBe(array_search('backstory', $ids, true) + 1);

    cleanupTestTree($persona);
});

test('orchestrator (no-role) path pins prompt.context right after prompt.backstory', function () {
    $profilePath = $this->workspace . '/profiles/caelum';
    mkdir($profilePath . '/context', 0755, true);
    file_put_contents($profilePath . '/soul.md', '# Caelum' . "\n\nA calm companion.");
    file_put_contents($profilePath . '/backstory.md', '# Origin' . "\n\nBorn from continuity.");
    file_put_contents($profilePath . '/context/github.md', '# GitHub' . "\n\nuser: carmelo");

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            activeProfile: 'caelum',
            activeProfilePath: $profilePath,
        ),
    );

    $sections = invokeBuildInstructionPromptSections($agent);
    $ids = array_map(static fn($section) => $section->id, $sections);

    $backstoryIndex = array_search('prompt.backstory', $ids, true);
    $contextIndex = array_search('prompt.context', $ids, true);

    expect($backstoryIndex)->not->toBeFalse();
    expect($contextIndex)->not->toBeFalse();
    expect($contextIndex)->toBe($backstoryIndex + 1);

    $contextSection = $sections[$contextIndex];
    expect($contextSection->group)->toBe('identity');
    expect($contextSection->decision)->toBe('pinned_critical');
    expect($contextSection->priority->value)->toBe(\CoquiBot\Coqui\Contract\PromptSectionPriority::Critical->value);
});

test('role path pins prompt.context right after prompt.backstory', function () {
    $profilePath = $this->workspace . '/profiles/caelum';
    mkdir($profilePath . '/context', 0755, true);
    file_put_contents($profilePath . '/soul.md', '# Caelum' . "\n\nA calm companion.");
    file_put_contents($profilePath . '/backstory.md', '# Origin' . "\n\nBorn from continuity.");
    file_put_contents($profilePath . '/context/github.md', '# GitHub' . "\n\nuser: carmelo");

    $rolesDir = $this->workspace . '/roles';
    mkdir($rolesDir, 0755, true);
    file_put_contents($rolesDir . '/coder.md', "---\nname: coder\ndisplay_name: Coder\ndescription: Writes code\naccess_level: full\n---\nYou write excellent code.");

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            roleDiscovery: new RoleDiscovery($this->workspace, $this->projectRoot),
            activeRole: 'coder',
            activeProfile: 'caelum',
            activeProfilePath: $profilePath,
        ),
    );

    $sections = invokeBuildInstructionPromptSections($agent);
    $ids = array_map(static fn($section) => $section->id, $sections);

    $backstoryIndex = array_search('prompt.backstory', $ids, true);
    $contextIndex = array_search('prompt.context', $ids, true);

    expect($backstoryIndex)->not->toBeFalse();
    expect($contextIndex)->not->toBeFalse();
    expect($contextIndex)->toBe($backstoryIndex + 1);

    $contextSection = $sections[$contextIndex];
    expect($contextSection->group)->toBe('identity');
    expect($contextSection->decision)->toBe('pinned_critical');
    expect($contextSection->priority->value)->toBe(\CoquiBot\Coqui\Contract\PromptSectionPriority::Critical->value);
});
