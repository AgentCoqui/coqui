<?php

declare(strict_types=1);

use CoquiBot\Coqui\Backstory\Extractor\ExtractorFactory;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorInterface;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorResult;
use CoquiBot\Coqui\Backstory\Extractor\TextExtractor;

if (!class_exists('FakeHookExtractor')) {
    class FakeHookExtractor implements ExtractorInterface
    {
        public function extract(string $absolutePath): ExtractorResult
        {
            return ExtractorResult::ok('HOOK', 1);
        }

        public function supportedExtensions(): array
        {
            return ['hook'];
        }
    }
}

test('ExtractorFactory registers injected additional extractors', function () {
    $factory = new ExtractorFactory([new FakeHookExtractor()]);

    expect($factory->get('hook'))->toBeInstanceOf(FakeHookExtractor::class);
    // Core dep-free extractors are still present.
    expect($factory->get('txt'))->toBeInstanceOf(TextExtractor::class);
});

test('ExtractorFactory with an explicit empty array registers only core extractors', function () {
    $factory = new ExtractorFactory([]);

    expect($factory->get('txt'))->toBeInstanceOf(TextExtractor::class);
    expect($factory->get('hook'))->toBeNull();
});
