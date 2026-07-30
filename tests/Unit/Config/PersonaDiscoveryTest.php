<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\PersonaDiscovery;

beforeEach(function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-persona-discovery-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->workspacePath);
});

test('discoverAll returns empty when no personas directory exists', function () {
    $discovery = new PersonaDiscovery($this->workspacePath);

    expect($discovery->discoverAll())->toBe([]);
    expect($discovery->availablePersonas())->toBe([]);
});

test('discoverAll finds personas with soul.md', function () {
    $personasDir = $this->workspacePath . '/personas';
    mkdir($personasDir . '/alpha', 0755, true);
    mkdir($personasDir . '/beta', 0755, true);
    file_put_contents($personasDir . '/alpha/soul.md', '# Alpha' . "\n\nAlpha identity.");
    file_put_contents($personasDir . '/beta/soul.md', '# Beta' . "\n\nBeta identity.");

    $discovery = new PersonaDiscovery($this->workspacePath);
    $personas = $discovery->discoverAll();

    expect($personas)->toHaveCount(2);
    expect(array_keys($personas))->toBe(['alpha', 'beta']);
    expect($personas['alpha']['name'])->toBe('alpha');
    expect($personas['beta']['name'])->toBe('beta');
});

test('discoverAll skips directories without soul.md', function () {
    $personasDir = $this->workspacePath . '/personas';
    mkdir($personasDir . '/valid', 0755, true);
    mkdir($personasDir . '/invalid', 0755, true);
    file_put_contents($personasDir . '/valid/soul.md', '# Valid');
    // invalid has no soul.md

    $discovery = new PersonaDiscovery($this->workspacePath);

    expect($discovery->discoverAll())->toHaveCount(1);
    expect($discovery->personaExists('valid'))->toBeTrue();
    expect($discovery->personaExists('invalid'))->toBeFalse();
});

test('personaExists is case-insensitive', function () {
    $personasDir = $this->workspacePath . '/personas';
    mkdir($personasDir . '/caelum', 0755, true);
    file_put_contents($personasDir . '/caelum/soul.md', '# Caelum');

    $discovery = new PersonaDiscovery($this->workspacePath);

    expect($discovery->personaExists('caelum'))->toBeTrue();
    expect($discovery->personaExists('Caelum'))->toBeTrue();
    expect($discovery->personaExists('CAELUM'))->toBeTrue();
    expect($discovery->personaExists('nonexistent'))->toBeFalse();
});

test('readSoul returns body without frontmatter', function () {
    $personasDir = $this->workspacePath . '/personas';
    mkdir($personasDir . '/test', 0755, true);
    file_put_contents($personasDir . '/test/soul.md', "---\nmodel: test/model\n---\n# Test Persona\n\nYou are Test.");

    $discovery = new PersonaDiscovery($this->workspacePath);
    $soul = $discovery->readSoul('test');

    expect($soul)->toContain('# Test Persona');
    expect($soul)->toContain('You are Test.');
    expect($soul)->not->toContain('model: test/model');
});

test('readPersonaModel extracts model from frontmatter', function () {
    $personasDir = $this->workspacePath . '/personas';
    mkdir($personasDir . '/modeled', 0755, true);
    file_put_contents($personasDir . '/modeled/soul.md', "---\nmodel: anthropic/claude-sonnet-4-20250514\n---\n# Modeled");

    $discovery = new PersonaDiscovery($this->workspacePath);

    expect($discovery->readPersonaModel('modeled'))->toBe('anthropic/claude-sonnet-4-20250514');
});

test('readPersonaModel returns null when no model in frontmatter', function () {
    $personasDir = $this->workspacePath . '/personas';
    mkdir($personasDir . '/nomodel', 0755, true);
    file_put_contents($personasDir . '/nomodel/soul.md', '# No Model');

    $discovery = new PersonaDiscovery($this->workspacePath);

    expect($discovery->readPersonaModel('nomodel'))->toBeNull();
});

test('extractDescription returns first paragraph from soul.md', function () {
    $personasDir = $this->workspacePath . '/personas';
    mkdir($personasDir . '/described', 0755, true);
    file_put_contents($personasDir . '/described/soul.md', "# Described Persona\n\nA warm, curious AI companion with a philosophical bent.\n\n## Details\n\nMore content.");

    $discovery = new PersonaDiscovery($this->workspacePath);
    $description = $discovery->extractDescription('described');

    expect($description)->toBe('A warm, curious AI companion with a philosophical bent.');
});

test('getPersonaPath returns absolute path to persona directory', function () {
    $personasDir = $this->workspacePath . '/personas';
    mkdir($personasDir . '/mypersona', 0755, true);
    file_put_contents($personasDir . '/mypersona/soul.md', '# My Persona');

    $discovery = new PersonaDiscovery($this->workspacePath);

    expect($discovery->getPersonaPath('mypersona'))->toBe($personasDir . '/mypersona');
});

test('getPersonaPath throws for nonexistent persona', function () {
    $discovery = new PersonaDiscovery($this->workspacePath);

    $discovery->getPersonaPath('nonexistent');
})->throws(\InvalidArgumentException::class);

test('invalidateCache re-discovers personas after changes', function () {
    $personasDir = $this->workspacePath . '/personas';
    mkdir($personasDir . '/first', 0755, true);
    file_put_contents($personasDir . '/first/soul.md', '# First');

    $discovery = new PersonaDiscovery($this->workspacePath);
    expect($discovery->discoverAll())->toHaveCount(1);

    // Add a second persona
    mkdir($personasDir . '/second', 0755, true);
    file_put_contents($personasDir . '/second/soul.md', '# Second');

    // Cache still returns 1
    expect($discovery->discoverAll())->toHaveCount(1);

    // After invalidation, returns 2
    $discovery->invalidateCache();
    expect($discovery->discoverAll())->toHaveCount(2);
});

test('getSamplesDir returns correct path', function () {
    $personasDir = $this->workspacePath . '/personas';
    mkdir($personasDir . '/alpha', 0755, true);
    file_put_contents($personasDir . '/alpha/soul.md', '# Alpha');

    $discovery = new PersonaDiscovery($this->workspacePath);

    expect($discovery->getSamplesDir('alpha'))->toBe($personasDir . '/alpha/samples/responses');
});

test('listResponseSamples returns empty when no samples directory', function () {
    $personasDir = $this->workspacePath . '/personas';
    mkdir($personasDir . '/alpha', 0755, true);
    file_put_contents($personasDir . '/alpha/soul.md', '# Alpha');

    $discovery = new PersonaDiscovery($this->workspacePath);

    expect($discovery->listResponseSamples('alpha'))->toBe([]);
});

test('listResponseSamples finds markdown files in samples/responses', function () {
    $personasDir = $this->workspacePath . '/personas';
    $samplesDir = $personasDir . '/alpha/samples/responses';
    mkdir($samplesDir, 0755, true);
    file_put_contents($personasDir . '/alpha/soul.md', '# Alpha');
    file_put_contents($samplesDir . '/greeting.md', '# Hello there');
    file_put_contents($samplesDir . '/farewell.md', '# Goodbye');
    file_put_contents($samplesDir . '/notes.txt', 'Not a markdown file');

    $discovery = new PersonaDiscovery($this->workspacePath);
    $samples = $discovery->listResponseSamples('alpha');

    expect($samples)->toHaveCount(2);
    expect($samples[0])->toContain('farewell.md');
    expect($samples[1])->toContain('greeting.md');
});
