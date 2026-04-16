<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ProfileDiscovery;

beforeEach(function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-profile-discovery-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->workspacePath);
});

test('discoverAll returns empty when no profiles directory exists', function () {
    $discovery = new ProfileDiscovery($this->workspacePath);

    expect($discovery->discoverAll())->toBe([]);
    expect($discovery->availableProfiles())->toBe([]);
});

test('discoverAll finds profiles with soul.md', function () {
    $profilesDir = $this->workspacePath . '/profiles';
    mkdir($profilesDir . '/alpha', 0755, true);
    mkdir($profilesDir . '/beta', 0755, true);
    file_put_contents($profilesDir . '/alpha/soul.md', '# Alpha' . "\n\nAlpha identity.");
    file_put_contents($profilesDir . '/beta/soul.md', '# Beta' . "\n\nBeta identity.");

    $discovery = new ProfileDiscovery($this->workspacePath);
    $profiles = $discovery->discoverAll();

    expect($profiles)->toHaveCount(2);
    expect(array_keys($profiles))->toBe(['alpha', 'beta']);
    expect($profiles['alpha']['name'])->toBe('alpha');
    expect($profiles['beta']['name'])->toBe('beta');
});

test('discoverAll skips directories without soul.md', function () {
    $profilesDir = $this->workspacePath . '/profiles';
    mkdir($profilesDir . '/valid', 0755, true);
    mkdir($profilesDir . '/invalid', 0755, true);
    file_put_contents($profilesDir . '/valid/soul.md', '# Valid');
    // invalid has no soul.md

    $discovery = new ProfileDiscovery($this->workspacePath);

    expect($discovery->discoverAll())->toHaveCount(1);
    expect($discovery->profileExists('valid'))->toBeTrue();
    expect($discovery->profileExists('invalid'))->toBeFalse();
});

test('profileExists is case-insensitive', function () {
    $profilesDir = $this->workspacePath . '/profiles';
    mkdir($profilesDir . '/caelum', 0755, true);
    file_put_contents($profilesDir . '/caelum/soul.md', '# Caelum');

    $discovery = new ProfileDiscovery($this->workspacePath);

    expect($discovery->profileExists('caelum'))->toBeTrue();
    expect($discovery->profileExists('Caelum'))->toBeTrue();
    expect($discovery->profileExists('CAELUM'))->toBeTrue();
    expect($discovery->profileExists('nonexistent'))->toBeFalse();
});

test('readSoul returns body without frontmatter', function () {
    $profilesDir = $this->workspacePath . '/profiles';
    mkdir($profilesDir . '/test', 0755, true);
    file_put_contents($profilesDir . '/test/soul.md', "---\nmodel: test/model\n---\n# Test Profile\n\nYou are Test.");

    $discovery = new ProfileDiscovery($this->workspacePath);
    $soul = $discovery->readSoul('test');

    expect($soul)->toContain('# Test Profile');
    expect($soul)->toContain('You are Test.');
    expect($soul)->not->toContain('model: test/model');
});

test('readProfileModel extracts model from frontmatter', function () {
    $profilesDir = $this->workspacePath . '/profiles';
    mkdir($profilesDir . '/modeled', 0755, true);
    file_put_contents($profilesDir . '/modeled/soul.md', "---\nmodel: anthropic/claude-sonnet-4-20250514\n---\n# Modeled");

    $discovery = new ProfileDiscovery($this->workspacePath);

    expect($discovery->readProfileModel('modeled'))->toBe('anthropic/claude-sonnet-4-20250514');
});

test('readProfileModel returns null when no model in frontmatter', function () {
    $profilesDir = $this->workspacePath . '/profiles';
    mkdir($profilesDir . '/nomodel', 0755, true);
    file_put_contents($profilesDir . '/nomodel/soul.md', '# No Model');

    $discovery = new ProfileDiscovery($this->workspacePath);

    expect($discovery->readProfileModel('nomodel'))->toBeNull();
});

test('extractDescription returns first paragraph from soul.md', function () {
    $profilesDir = $this->workspacePath . '/profiles';
    mkdir($profilesDir . '/described', 0755, true);
    file_put_contents($profilesDir . '/described/soul.md', "# Described Profile\n\nA warm, curious AI companion with a philosophical bent.\n\n## Details\n\nMore content.");

    $discovery = new ProfileDiscovery($this->workspacePath);
    $description = $discovery->extractDescription('described');

    expect($description)->toBe('A warm, curious AI companion with a philosophical bent.');
});

test('getProfilePath returns absolute path to profile directory', function () {
    $profilesDir = $this->workspacePath . '/profiles';
    mkdir($profilesDir . '/myprofile', 0755, true);
    file_put_contents($profilesDir . '/myprofile/soul.md', '# My Profile');

    $discovery = new ProfileDiscovery($this->workspacePath);

    expect($discovery->getProfilePath('myprofile'))->toBe($profilesDir . '/myprofile');
});

test('getProfilePath throws for nonexistent profile', function () {
    $discovery = new ProfileDiscovery($this->workspacePath);

    $discovery->getProfilePath('nonexistent');
})->throws(\InvalidArgumentException::class);

test('invalidateCache re-discovers profiles after changes', function () {
    $profilesDir = $this->workspacePath . '/profiles';
    mkdir($profilesDir . '/first', 0755, true);
    file_put_contents($profilesDir . '/first/soul.md', '# First');

    $discovery = new ProfileDiscovery($this->workspacePath);
    expect($discovery->discoverAll())->toHaveCount(1);

    // Add a second profile
    mkdir($profilesDir . '/second', 0755, true);
    file_put_contents($profilesDir . '/second/soul.md', '# Second');

    // Cache still returns 1
    expect($discovery->discoverAll())->toHaveCount(1);

    // After invalidation, returns 2
    $discovery->invalidateCache();
    expect($discovery->discoverAll())->toHaveCount(2);
});
