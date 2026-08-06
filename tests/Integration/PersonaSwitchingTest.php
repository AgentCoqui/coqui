<?php

declare(strict_types=1);

/**
 * Integration test: verify that switching personas produces correct system prompts.
 *
 * Creates synthetic personas with unique soul content and bond glyphs,
 * then verifies each persona's soul.md is correctly loaded into the
 * OrchestratorAgent system prompt via the PromptLoader 3-tier chain.
 */

use CoquiBot\Coqui\Agent\OrchestratorAgent;
use CoquiBot\Coqui\Agent\OrchestratorDependencies;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\Response;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir() . '/coqui-persona-integration-' . bin2hex(random_bytes(4));
    mkdir($this->workspace, 0755, true);
    file_put_contents($this->workspace . '/.env', '');

    $this->projectRoot = dirname(__DIR__, 2);

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

    // Create three test personas mimicking the real personas
    $personas = [
        'alpha' => [
            'name' => 'Alpha',
            'glyph' => '$',
            'identity' => 'You are Alpha: direct, commercially sharp, and pragmatic.',
        ],
        'beta' => [
            'name' => 'Beta',
            'glyph' => '𑁍',
            'identity' => 'You are Beta: a playful, mischievous trickster.',
        ],
        'gamma' => [
            'name' => 'Gamma',
            'glyph' => '∞',
            'identity' => 'You are Gamma: calm, enigmatic, and precise.',
        ],
    ];

    $personasDir = $this->workspace . '/personas';
    foreach ($personas as $slug => $persona) {
        mkdir($personasDir . '/' . $slug, 0755, true);
        file_put_contents(
            $personasDir . '/' . $slug . '/soul.md',
            "# {$persona['name']}\n\nBond glyph: {$persona['glyph']}\n\n{$persona['identity']}\n",
        );
    }

    $this->personas = $personas;
});

afterEach(function () {
    cleanupTestTree($this->workspace);
});

test('switching between personas loads correct soul.md into system prompt', function () {
    foreach ($this->personas as $slug => $persona) {
        $personaPath = $this->workspace . '/personas/' . $slug;

        $agent = new OrchestratorAgent(
            provider: $this->provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspace,
            deps: new OrchestratorDependencies(
                activePersona: $slug,
                activePersonaPath: $personaPath,
            ),
        );

        $instructions = $agent->instructions();
        $systemPrompt = $agent->getSystemPromptText();

        // Each persona's name appears in the instructions
        expect($instructions)->toContain("# {$persona['name']}")
            ->and($instructions)->toContain($persona['identity']);

        // Bond glyph is present
        expect($instructions)->toContain("Bond glyph: {$persona['glyph']}");

        // getSystemPromptText also includes the soul
        expect($systemPrompt)->toContain("# {$persona['name']}");
        expect($systemPrompt)->toContain("Bond glyph: {$persona['glyph']}");
    }
});

test('persona switching does not leak soul content between personas', function () {
    foreach ($this->personas as $slug => $persona) {
        $personaPath = $this->workspace . '/personas/' . $slug;

        $agent = new OrchestratorAgent(
            provider: $this->provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspace,
            deps: new OrchestratorDependencies(
                activePersona: $slug,
                activePersonaPath: $personaPath,
            ),
        );

        $instructions = $agent->instructions();

        // Only THIS persona's identity should appear, not others
        foreach ($this->personas as $otherSlug => $otherPersona) {
            if ($otherSlug === $slug) {
                continue;
            }
            expect($instructions)->not->toContain($otherPersona['identity']);
        }
    }
});

test('persona discovery finds all personas and round-trips correctly', function () {
    $discovery = new PersonaDiscovery($this->workspace);

    $discovered = $discovery->discoverAll();
    expect($discovered)->toHaveCount(3);

    foreach ($this->personas as $slug => $persona) {
        expect($discovery->personaExists($slug))->toBeTrue();

        $soul = $discovery->readSoul($slug);
        expect($soul)->toContain("# {$persona['name']}");
        expect($soul)->toContain("Bond glyph: {$persona['glyph']}");
        expect($soul)->toContain($persona['identity']);

        $path = $discovery->getPersonaPath($slug);
        expect(is_dir($path))->toBeTrue();
        expect(is_file($path . '/soul.md'))->toBeTrue();
    }
});

test('persona with role prepends identity preamble to role instructions', function () {
    // Create a role
    $rolesDir = $this->workspace . '/roles';
    mkdir($rolesDir, 0755, true);
    file_put_contents($rolesDir . '/coder.md', "---\nname: coder\ndisplay_name: Coder\ndescription: Writes code\naccess_level: full\n---\nYou write excellent code.");

    $roleDiscovery = new RoleDiscovery(workspacePath: $this->workspace);

    // Test each persona with the coder role
    foreach ($this->personas as $slug => $persona) {
        $personaPath = $this->workspace . '/personas/' . $slug;

        $agent = new OrchestratorAgent(
            provider: $this->provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspace,
            deps: new OrchestratorDependencies(
                roleDiscovery: $roleDiscovery,
                activeRole: 'coder',
                activePersona: $slug,
                activePersonaPath: $personaPath,
            ),
        );

        $instructions = $agent->instructions();

        // Persona identity preamble present
        expect($instructions)->toContain($persona['identity']);
        expect($instructions)->toContain("Bond glyph: {$persona['glyph']}");

        // Role instructions present
        expect($instructions)->toContain('You write excellent code.');

        // Preamble appears before role instructions
        $preamblePos = strpos($instructions, $persona['identity']);
        $rolePos = strpos($instructions, 'You write excellent code.');
        expect($preamblePos)->toBeLessThan($rolePos);
    }
});

test('no persona shows default soul instead of persona soul', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $instructions = $agent->instructions();

    // No persona soul content should appear
    foreach ($this->personas as $persona) {
        expect($instructions)->not->toContain($persona['identity']);
    }
});
