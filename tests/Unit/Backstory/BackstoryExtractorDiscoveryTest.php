<?php

declare(strict_types=1);

use CoquiBot\Coqui\Backstory\Extractor\BackstoryExtractorDiscovery;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorInterface;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorResult;

// A global-namespace fake extractor so class_exists() resolves it by name.
if (!class_exists('FakeUpperExtractor')) {
    class FakeUpperExtractor implements ExtractorInterface
    {
        public function extract(string $absolutePath): ExtractorResult
        {
            return ExtractorResult::ok('FAKE', 1);
        }

        public function supportedExtensions(): array
        {
            return ['fake'];
        }
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/coqui-extractor-discovery-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->tempDir);
});

test('discover returns empty when installed.json is missing', function () {
    $discovery = new BackstoryExtractorDiscovery($this->tempDir);
    expect($discovery->discover())->toBe([]);
});

test('discover instantiates a declared backstory extractor class', function () {
    $composerDir = $this->tempDir . '/vendor/composer';
    mkdir($composerDir, 0755, true);
    file_put_contents($composerDir . '/installed.json', json_encode([
        'packages' => [[
            'name' => 'acme/backstory-fake',
            'extra' => ['php-agents' => ['backstoryExtractors' => ['FakeUpperExtractor']]],
        ]],
    ]));

    $discovery = new BackstoryExtractorDiscovery($this->tempDir);
    $result = $discovery->discover();

    expect($result)->toHaveCount(1);
    expect($result[0])->toBeInstanceOf(FakeUpperExtractor::class);
});

test('discover skips classes that do not implement ExtractorInterface', function () {
    $composerDir = $this->tempDir . '/vendor/composer';
    mkdir($composerDir, 0755, true);
    file_put_contents($composerDir . '/installed.json', json_encode([
        'packages' => [[
            'name' => 'acme/bad',
            'extra' => ['php-agents' => ['backstoryExtractors' => ['stdClass', 'Nonexistent\\Class']]],
        ]],
    ]));

    $discovery = new BackstoryExtractorDiscovery($this->tempDir);
    expect($discovery->discover())->toBe([]);
});
