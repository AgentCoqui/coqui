<?php

declare(strict_types=1);

/**
 * Integration test: verify that switching profiles produces correct system prompts.
 *
 * Creates synthetic profiles with unique soul content and bond glyphs,
 * then verifies each profile's soul.md is correctly loaded into the
 * OrchestratorAgent system prompt via the PromptLoader 3-tier chain.
 */

use CoquiBot\Coqui\Agent\OrchestratorAgent;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\Response;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir() . '/coqui-profile-integration-' . bin2hex(random_bytes(4));
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

    // Create three test profiles mimicking the real profiles
    $profiles = [
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

    $profilesDir = $this->workspace . '/profiles';
    foreach ($profiles as $slug => $profile) {
        mkdir($profilesDir . '/' . $slug, 0755, true);
        file_put_contents(
            $profilesDir . '/' . $slug . '/soul.md',
            "# {$profile['name']}\n\nBond glyph: {$profile['glyph']}\n\n{$profile['identity']}\n",
        );
    }

    $this->profiles = $profiles;
});

afterEach(function () {
    cleanupTestTree($this->workspace);
});

test('switching between profiles loads correct soul.md into system prompt', function () {
    foreach ($this->profiles as $slug => $profile) {
        $profilePath = $this->workspace . '/profiles/' . $slug;

        $agent = new OrchestratorAgent(
            provider: $this->provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspace,
            activeProfile: $slug,
            activeProfilePath: $profilePath,
        );

        $instructions = $agent->instructions();
        $systemPrompt = $agent->getSystemPromptText();

        // Each profile's name appears in the instructions
        expect($instructions)->toContain("# {$profile['name']}")
            ->and($instructions)->toContain($profile['identity']);

        // Bond glyph is present
        expect($instructions)->toContain("Bond glyph: {$profile['glyph']}");

        // getSystemPromptText also includes the soul
        expect($systemPrompt)->toContain("# {$profile['name']}");
        expect($systemPrompt)->toContain("Bond glyph: {$profile['glyph']}");
    }
});

test('profile switching does not leak soul content between profiles', function () {
    foreach ($this->profiles as $slug => $profile) {
        $profilePath = $this->workspace . '/profiles/' . $slug;

        $agent = new OrchestratorAgent(
            provider: $this->provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspace,
            activeProfile: $slug,
            activeProfilePath: $profilePath,
        );

        $instructions = $agent->instructions();

        // Only THIS profile's identity should appear, not others
        foreach ($this->profiles as $otherSlug => $otherProfile) {
            if ($otherSlug === $slug) {
                continue;
            }
            expect($instructions)->not->toContain($otherProfile['identity']);
        }
    }
});

test('profile discovery finds all profiles and round-trips correctly', function () {
    $discovery = new ProfileDiscovery($this->workspace);

    $discovered = $discovery->discoverAll();
    expect($discovered)->toHaveCount(3);

    foreach ($this->profiles as $slug => $profile) {
        expect($discovery->profileExists($slug))->toBeTrue();

        $soul = $discovery->readSoul($slug);
        expect($soul)->toContain("# {$profile['name']}");
        expect($soul)->toContain("Bond glyph: {$profile['glyph']}");
        expect($soul)->toContain($profile['identity']);

        $path = $discovery->getProfilePath($slug);
        expect(is_dir($path))->toBeTrue();
        expect(is_file($path . '/soul.md'))->toBeTrue();
    }
});

test('profile with role prepends identity preamble to role instructions', function () {
    // Create a role
    $rolesDir = $this->workspace . '/roles';
    mkdir($rolesDir, 0755, true);
    file_put_contents($rolesDir . '/coder.md', "---\nname: coder\ndisplay_name: Coder\ndescription: Writes code\naccess_level: full\n---\nYou write excellent code.");

    $roleDiscovery = new RoleDiscovery(workspacePath: $this->workspace);

    // Test each profile with the coder role
    foreach ($this->profiles as $slug => $profile) {
        $profilePath = $this->workspace . '/profiles/' . $slug;

        $agent = new OrchestratorAgent(
            provider: $this->provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspace,
            roleDiscovery: $roleDiscovery,
            activeRole: 'coder',
            activeProfile: $slug,
            activeProfilePath: $profilePath,
        );

        $instructions = $agent->instructions();

        // Profile identity preamble present
        expect($instructions)->toContain($profile['identity']);
        expect($instructions)->toContain("Bond glyph: {$profile['glyph']}");

        // Role instructions present
        expect($instructions)->toContain('You write excellent code.');

        // Preamble appears before role instructions
        $preamblePos = strpos($instructions, $profile['identity']);
        $rolePos = strpos($instructions, 'You write excellent code.');
        expect($preamblePos)->toBeLessThan($rolePos);
    }
});

test('no profile shows default soul instead of profile soul', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $instructions = $agent->instructions();

    // No profile soul content should appear
    foreach ($this->profiles as $profile) {
        expect($instructions)->not->toContain($profile['identity']);
    }
});
