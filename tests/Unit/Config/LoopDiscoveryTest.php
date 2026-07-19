<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\LoopDiscovery;

beforeEach(function () {
    $this->workspacePath = sys_get_temp_dir() . '/coqui-loop-discovery-test-' . bin2hex(random_bytes(8));
    $this->loopsDir = $this->workspacePath . '/loops';
    mkdir($this->loopsDir, 0755, true);

    // Create a temp "builtin" loops dir
    $this->builtinDir = sys_get_temp_dir() . '/coqui-loop-builtin-test-' . bin2hex(random_bytes(8));
    mkdir($this->builtinDir . '/config/loops', 0755, true);
});

afterEach(function () {
    // Cleanup workspace
    $dirs = [$this->workspacePath, $this->builtinDir];
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($it as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
    }
});

// ──────────────────────────────────────────────
//  discoverAll
// ──────────────────────────────────────────────

test('discoverAll reads JSON files from loops directory', function () {
    file_put_contents($this->loopsDir . '/harness.json', json_encode([
        'name' => 'harness',
        'description' => 'Generator-evaluator',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));
    file_put_contents($this->loopsDir . '/research.json', json_encode([
        'name' => 'research',
        'description' => 'Research pattern',
        'roles' => [['role' => 'explorer', 'prompt' => 'Explore.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $discovery = new LoopDiscovery($this->workspacePath);
    $loops = $discovery->discoverAll();

    expect($loops)->toHaveCount(2);
    expect($loops)->toHaveKeys(['harness', 'research']);
});

test('discoverAll silently skips malformed JSON', function () {
    file_put_contents($this->loopsDir . '/good.json', json_encode([
        'name' => 'good',
        'description' => 'Valid definition',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));
    file_put_contents($this->loopsDir . '/bad.json', 'this is not valid json{{{');

    $discovery = new LoopDiscovery($this->workspacePath);
    $loops = $discovery->discoverAll();

    expect($loops)->toHaveCount(1);
    expect($loops)->toHaveKey('good');
});

test('discoverAll silently skips definitions with validation errors', function () {
    // Missing required "description" field
    file_put_contents($this->loopsDir . '/invalid.json', json_encode([
        'name' => 'invalid',
        'description' => '',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $discovery = new LoopDiscovery($this->workspacePath);
    $loops = $discovery->discoverAll();

    expect($loops)->toHaveCount(0);
});

test('discoverAll returns empty when loops directory does not exist', function () {
    rmdir($this->loopsDir); // Remove the loops dir

    $discovery = new LoopDiscovery($this->workspacePath);
    $loops = $discovery->discoverAll();

    expect($loops)->toBe([]);
});

test('discoverAll ignores non-json files', function () {
    file_put_contents($this->loopsDir . '/readme.md', '# Loops');
    file_put_contents($this->loopsDir . '/notes.txt', 'notes');
    file_put_contents($this->loopsDir . '/valid.json', json_encode([
        'name' => 'valid',
        'description' => 'A loop',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $discovery = new LoopDiscovery($this->workspacePath);
    $loops = $discovery->discoverAll();

    expect($loops)->toHaveCount(1);
});

// ──────────────────────────────────────────────
//  Caching
// ──────────────────────────────────────────────

test('discoverAll caches results on second call', function () {
    file_put_contents($this->loopsDir . '/first.json', json_encode([
        'name' => 'first',
        'description' => 'First loop',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $discovery = new LoopDiscovery($this->workspacePath);
    $result1 = $discovery->discoverAll();

    // Add a new file after discovery
    file_put_contents($this->loopsDir . '/second.json', json_encode([
        'name' => 'second',
        'description' => 'Second loop',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $result2 = $discovery->discoverAll();

    // Cache should return same result
    expect($result1)->toHaveCount(1);
    expect($result2)->toHaveCount(1);
});

test('invalidateCache forces re-scan', function () {
    file_put_contents($this->loopsDir . '/first.json', json_encode([
        'name' => 'first',
        'description' => 'First loop',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $discovery = new LoopDiscovery($this->workspacePath);
    $discovery->discoverAll();

    file_put_contents($this->loopsDir . '/second.json', json_encode([
        'name' => 'second',
        'description' => 'Second loop',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $discovery->invalidateCache();
    $result = $discovery->discoverAll();

    expect($result)->toHaveCount(2);
});

// ──────────────────────────────────────────────
//  get / exists / availableLoops
// ──────────────────────────────────────────────

test('get returns definition by name', function () {
    file_put_contents($this->loopsDir . '/harness.json', json_encode([
        'name' => 'harness',
        'description' => 'Test loop',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $discovery = new LoopDiscovery($this->workspacePath);
    $def = $discovery->get('harness');

    expect($def->name)->toBe('harness');
    expect($def->description)->toBe('Test loop');
});

test('get throws for unknown name', function () {
    $discovery = new LoopDiscovery($this->workspacePath);
    $discovery->get('nonexistent');
})->throws(\RuntimeException::class, 'not found');

test('exists returns true for known loop', function () {
    file_put_contents($this->loopsDir . '/harness.json', json_encode([
        'name' => 'harness',
        'description' => 'Test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $discovery = new LoopDiscovery($this->workspacePath);

    expect($discovery->exists('harness'))->toBeTrue();
});

test('exists returns false for unknown loop', function () {
    $discovery = new LoopDiscovery($this->workspacePath);

    expect($discovery->exists('nonexistent'))->toBeFalse();
});

test('availableLoops returns all names', function () {
    file_put_contents($this->loopsDir . '/a.json', json_encode([
        'name' => 'alpha',
        'description' => 'Alpha',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));
    file_put_contents($this->loopsDir . '/b.json', json_encode([
        'name' => 'bravo',
        'description' => 'Bravo',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $discovery = new LoopDiscovery($this->workspacePath);
    $names = $discovery->availableLoops();

    expect($names)->toContain('alpha');
    expect($names)->toContain('bravo');
    expect($names)->toHaveCount(2);
});

// ──────────────────────────────────────────────
//  seedBuiltinLoops
// ──────────────────────────────────────────────

test('seedBuiltinLoops copies from builtin dir to workspace', function () {
    $builtinLoopsDir = $this->builtinDir . '/config/loops';
    file_put_contents($builtinLoopsDir . '/harness.json', json_encode([
        'name' => 'harness',
        'description' => 'Built-in harness',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $discovery = new LoopDiscovery($this->workspacePath, $this->builtinDir);
    $discovery->seedBuiltinLoops();

    expect(file_exists($this->loopsDir . '/harness.json'))->toBeTrue();
    $seeded = $discovery->discoverAll();
    expect($seeded)->toHaveKey('harness');
});

test('seedBuiltinLoops does not overwrite existing user files', function () {
    $builtinLoopsDir = $this->builtinDir . '/config/loops';
    file_put_contents($builtinLoopsDir . '/harness.json', json_encode([
        'name' => 'harness',
        'description' => 'Built-in version',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    // Create user version first
    file_put_contents($this->loopsDir . '/harness.json', json_encode([
        'name' => 'harness',
        'description' => 'User customized version',
        'roles' => [['role' => 'coder', 'prompt' => 'Custom code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ]));

    $discovery = new LoopDiscovery($this->workspacePath, $this->builtinDir);
    $discovery->seedBuiltinLoops();

    $def = $discovery->get('harness');
    expect($def->description)->toBe('User customized version');
});

test('seedBuiltinLoops creates loops directory if missing', function () {
    rmdir($this->loopsDir);
    expect(is_dir($this->loopsDir))->toBeFalse();

    $discovery = new LoopDiscovery($this->workspacePath, $this->builtinDir);
    $discovery->seedBuiltinLoops();

    expect(is_dir($this->loopsDir))->toBeTrue();
});

// ──────────────────────────────────────────────
//  loopsDir / ensureLoopsDir
// ──────────────────────────────────────────────

test('loopsDir returns correct path', function () {
    $discovery = new LoopDiscovery($this->workspacePath);

    expect($discovery->loopsDir())->toBe($this->loopsDir);
});

test('ensureLoopsDir creates directory if missing', function () {
    rmdir($this->loopsDir);

    $discovery = new LoopDiscovery($this->workspacePath);
    $discovery->ensureLoopsDir();

    expect(is_dir($this->loopsDir))->toBeTrue();
});

// ──────────────────────────────────────────────
//  getRawDefinition
// ──────────────────────────────────────────────

test('getRawDefinition returns raw array for existing definition', function () {
    $data = [
        'name' => 'harness',
        'description' => 'Generator-evaluator',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ];
    file_put_contents($this->loopsDir . '/harness.json', json_encode($data));

    $discovery = new LoopDiscovery($this->workspacePath);
    $raw = $discovery->getRawDefinition('harness');

    expect($raw)->toBe($data);
    expect($raw['name'])->toBe('harness');
    expect($raw['termination_condition']['type'])->toBe('iteration_bound');
});

test('getRawDefinition throws for unknown name', function () {
    $discovery = new LoopDiscovery($this->workspacePath);

    $discovery->getRawDefinition('nonexistent');
})->throws(\RuntimeException::class, 'Loop definition not found: "nonexistent"');

test('getRawDefinition preserves goal_bound with all fields', function () {
    $data = [
        'name' => 'goal-test',
        'description' => 'Goal bound',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => [
            'type' => 'goal_bound',
            'value' => [
                'goal_prompt' => 'Is the API complete?',
                'max_iterations' => 5,
            ],
        ],
    ];
    file_put_contents($this->loopsDir . '/goal-test.json', json_encode($data));

    $discovery = new LoopDiscovery($this->workspacePath);
    $raw = $discovery->getRawDefinition('goal-test');

    expect($raw['termination_condition']['type'])->toBe('goal_bound');
    expect($raw['termination_condition']['value']['goal_prompt'])->toBe('Is the API complete?');
    expect($raw['termination_condition']['value']['max_iterations'])->toBe(5);
});

// ──────────────────────────────────────────────
//  saveDefinition / deleteDefinition / isBuiltin
// ──────────────────────────────────────────────

test('saveDefinition writes a valid definition that becomes discoverable', function (): void {
    $ws = sys_get_temp_dir() . '/coqui-defs-' . bin2hex(random_bytes(8));
    mkdir($ws . '/loops', 0755, true);
    $discovery = new CoquiBot\Coqui\Config\LoopDiscovery($ws);

    $discovery->saveDefinition('my-loop', [
        'name' => 'ignored-name',
        'description' => 'mine',
        'roles' => [['role' => 'plan', 'prompt' => 'go']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 3],
    ]);

    expect($discovery->exists('my-loop'))->toBeTrue();
    $raw = $discovery->getRawDefinition('my-loop');
    expect($raw['name'])->toBe('my-loop'); // filename is authoritative
    expect(is_file($ws . '/loops/my-loop.json'))->toBeTrue();
});

test('saveDefinition rejects traversal names without writing', function (): void {
    $ws = sys_get_temp_dir() . '/coqui-defs-' . bin2hex(random_bytes(8));
    mkdir($ws . '/loops', 0755, true);
    $discovery = new CoquiBot\Coqui\Config\LoopDiscovery($ws);

    foreach (['../evil', 'a/b', 'Bad Name', '.hidden'] as $bad) {
        expect(fn() => $discovery->saveDefinition($bad, [
            'description' => 'x',
            'roles' => [['role' => 'plan', 'prompt' => 'x']],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 1]],
        ]))->toThrow(InvalidArgumentException::class);
    }
    // Nothing written outside the loops dir.
    expect(glob($ws . '/loops/*.json'))->toBe([]);
});

test('saveDefinition rejects structurally invalid definitions', function (): void {
    $ws = sys_get_temp_dir() . '/coqui-defs-' . bin2hex(random_bytes(8));
    mkdir($ws . '/loops', 0755, true);
    $discovery = new CoquiBot\Coqui\Config\LoopDiscovery($ws);

    // Missing termination_condition.
    expect(fn() => $discovery->saveDefinition('bad', [
        'description' => 'x',
        'roles' => [['role' => 'plan', 'prompt' => 'x']],
    ]))->toThrow(InvalidArgumentException::class);

    // Empty roles.
    expect(fn() => $discovery->saveDefinition('bad', [
        'description' => 'x',
        'roles' => [],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 1]],
    ]))->toThrow(InvalidArgumentException::class);
});

test('deleteDefinition removes a custom file and reports missing', function (): void {
    $ws = sys_get_temp_dir() . '/coqui-defs-' . bin2hex(random_bytes(8));
    mkdir($ws . '/loops', 0755, true);
    $discovery = new CoquiBot\Coqui\Config\LoopDiscovery($ws);
    $discovery->saveDefinition('temp', [
        'description' => 'x',
        'roles' => [['role' => 'plan', 'prompt' => 'x']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 1],
    ]);

    expect($discovery->deleteDefinition('temp'))->toBeTrue();
    expect($discovery->exists('temp'))->toBeFalse();
    expect($discovery->deleteDefinition('never-existed'))->toBeFalse();
});

test('isBuiltin distinguishes built-in from custom', function (): void {
    $ws = sys_get_temp_dir() . '/coqui-defs-' . bin2hex(random_bytes(8));
    mkdir($ws . '/loops', 0755, true);
    // Default projectRoot points at the repo's config/loops, where harness.json ships.
    $discovery = new CoquiBot\Coqui\Config\LoopDiscovery($ws);

    expect($discovery->isBuiltin('harness'))->toBeTrue();
    expect($discovery->isBuiltin('my-custom-thing'))->toBeFalse();
});


// ──────────────────────────────────────────────
//  Shipped built-in definitions
// ──────────────────────────────────────────────

// Regression guard: discoverAll() validates definitions *before* template
// substitution and silently swallows the failures, so a shipped definition
// whose `{{...}}` placeholder violates its termination type's rules simply
// vanishes from the catalog with no error anywhere. `goal-driven` and
// `reflection` were both unreachable this way while the suite stayed green —
// every other test in this file uses synthetic fixtures, so nothing exercised
// the files we actually ship.
test('every shipped built-in definition survives discovery', function (): void {
    $shipped = array_map(
        static fn (string $path): string => basename($path, '.json'),
        glob(dirname(__DIR__, 3) . '/config/loops/*.json') ?: [],
    );

    expect($shipped)->not->toBeEmpty();

    $ws = sys_get_temp_dir() . '/coqui-shipped-loops-' . bin2hex(random_bytes(8));
    mkdir($ws . '/loops', 0755, true);
    $discovery = new LoopDiscovery($ws);
    $discovery->seedBuiltinLoops();

    expect($discovery->availableLoops())->toEqualCanonicalizing($shipped);
});
