<?php

declare(strict_types=1);

use CoquiBot\Coqui\CoquiSpace\SpaceRegistry;

// ── Constants ────────────────────────────────────────────────────────────────

test('DEFAULT_BASE_URL contains coqui.space', function () {
    expect(SpaceRegistry::DEFAULT_BASE_URL)->toContain('coqui.space');
});

test('ORIGIN_FILE is .space-origin.json', function () {
    expect(SpaceRegistry::ORIGIN_FILE)->toBe('.space-origin.json');
});

test('STATE_FILE is .space-state.json', function () {
    expect(SpaceRegistry::STATE_FILE)->toBe('.space-state.json');
});

// ── isExcluded ───────────────────────────────────────────────────────────────

test('isExcluded returns true for coquibot/coqui-toolkit-composer', function () {
    expect(SpaceRegistry::isExcluded('coquibot/coqui-toolkit-composer'))->toBeTrue();
});

test('isExcluded returns true for carmelosantana/php-agents', function () {
    expect(SpaceRegistry::isExcluded('carmelosantana/php-agents'))->toBeTrue();
});

test('isExcluded returns false for coquibot/coqui-toolkit-brave-search', function () {
    expect(SpaceRegistry::isExcluded('coquibot/coqui-toolkit-brave-search'))->toBeFalse();
});

test('isExcluded returns false for arbitrary packages', function () {
    expect(SpaceRegistry::isExcluded('vendor/some-package'))->toBeFalse();
    expect(SpaceRegistry::isExcluded('acme/toolkit'))->toBeFalse();
});

test('isExcluded is case-insensitive', function () {
    expect(SpaceRegistry::isExcluded('COQUIBOT/COQUI-TOOLKIT-COMPOSER'))->toBeTrue();
    expect(SpaceRegistry::isExcluded('CoquiBot/Coqui-Toolkit-Composer'))->toBeTrue();
    expect(SpaceRegistry::isExcluded('CarmeloSantana/PHP-Agents'))->toBeTrue();
});

// ── filterExcluded ───────────────────────────────────────────────────────────

test('filterExcluded with flat string array removes excluded packages', function () {
    $input = [
        'coquibot/coqui-toolkit-composer',
        'vendor/my-toolkit',
        'carmelosantana/php-agents',
        'another/package',
    ];

    $result = SpaceRegistry::filterExcluded($input);

    expect($result)->toContain('vendor/my-toolkit');
    expect($result)->toContain('another/package');
    expect($result)->not->toContain('coquibot/coqui-toolkit-composer');
    expect($result)->not->toContain('carmelosantana/php-agents');
});

test('filterExcluded keeps non-excluded packages', function () {
    $input = ['vendor/good-package', 'acme/toolkit'];

    $result = SpaceRegistry::filterExcluded($input);

    expect($result)->toHaveCount(2);
});

test('filterExcluded with array-of-arrays using package key works', function () {
    $input = [
        ['package' => 'coquibot/coqui-toolkit-composer', 'status' => 'enabled'],
        ['package' => 'vendor/my-toolkit', 'status' => 'enabled'],
    ];

    $result = SpaceRegistry::filterExcluded($input);

    expect($result)->toHaveCount(1);
    expect($result[0]['package'])->toBe('vendor/my-toolkit');
});

test('filterExcluded with array-of-arrays using name key works', function () {
    $input = [
        ['name' => 'coquibot/coqui-toolkit-composer'],
        ['name' => 'vendor/my-toolkit'],
        ['name' => 'carmelosantana/php-agents'],
    ];

    $result = SpaceRegistry::filterExcluded($input);

    expect($result)->toHaveCount(1);
    expect($result[0]['name'])->toBe('vendor/my-toolkit');
});

test('filterExcluded re-indexes the result', function () {
    $input = [
        'coquibot/coqui-toolkit-composer',
        'vendor/keep-this',
        'carmelosantana/php-agents',
    ];

    $result = SpaceRegistry::filterExcluded($input);

    // After filtering, indices should be re-indexed starting at 0
    expect(array_keys($result))->toBe([0]);
    expect($result[0])->toBe('vendor/keep-this');
});

// ── extractOwner ─────────────────────────────────────────────────────────────

test('extractOwner with string owner returns it directly', function () {
    $item = ['owner' => 'carmelosantana'];

    expect(SpaceRegistry::extractOwner($item))->toBe('carmelosantana');
});

test('extractOwner with array owner returns handle key', function () {
    $item = [
        'owner' => [
            'handle' => 'carmelosantana',
            'displayName' => 'Agent Coqui',
            'image' => 'https://example.com/avatar.jpg',
        ],
    ];

    expect(SpaceRegistry::extractOwner($item))->toBe('carmelosantana');
});

test('extractOwner with missing owner returns empty string', function () {
    $item = ['name' => 'some-skill'];

    expect(SpaceRegistry::extractOwner($item))->toBe('');
});

test('extractOwner with empty string owner returns empty string', function () {
    $item = ['owner' => ''];

    expect(SpaceRegistry::extractOwner($item))->toBe('');
});

test('extractOwner with array owner missing handle returns empty string', function () {
    $item = ['owner' => ['displayName' => 'Someone']];

    expect(SpaceRegistry::extractOwner($item))->toBe('');
});
