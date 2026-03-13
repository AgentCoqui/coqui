<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Enum\FinishReason;
use CoquiBot\Coqui\Provider\FallbackProvider;

/**
 * Create a stub provider that succeeds with the given content.
 */
function stubProvider(string $model, string $content = 'ok'): ProviderInterface
{
    return new class ($model, $content) implements ProviderInterface {
        public function __construct(
            private readonly string $model,
            private readonly string $content,
        ) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response(content: $this->content, finishReason: FinishReason::Stop, model: $this->model);
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            yield new Response(content: $this->content, finishReason: FinishReason::Stop, model: $this->model);
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            return ['result' => $this->content];
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function getModel(): string
        {
            return $this->model;
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
}

/**
 * Create a stub provider that throws the given exception.
 */
function failingProvider(string $model, \Throwable $error): ProviderInterface
{
    return new class ($model, $error) implements ProviderInterface {
        public function __construct(
            private readonly string $model,
            private readonly \Throwable $error,
        ) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            throw $this->error;
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            throw $this->error;
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            throw $this->error;
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
            return $this->model;
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
}

// --- chat() tests ---

test('chat returns primary response when primary succeeds', function () {
    $primary = stubProvider('primary-model', 'primary response');
    $fallback = stubProvider('fallback-model', 'fallback response');

    $provider = new FallbackProvider($primary, [$fallback]);
    $response = $provider->chat([]);

    expect($response->content)->toBe('primary response');
    expect($response->model)->toBe('primary-model');
});

test('chat falls through to fallback on retryable error', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 429 Too Many Requests'));
    $fallback = stubProvider('fallback-model', 'fallback response');

    $provider = new FallbackProvider($primary, [$fallback]);
    $response = $provider->chat([]);

    expect($response->content)->toBe('fallback response');
    expect($response->model)->toBe('fallback-model');
});

test('chat throws immediately on non-retryable error', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 401 Unauthorized'));
    $fallback = stubProvider('fallback-model', 'fallback response');

    $provider = new FallbackProvider($primary, [$fallback]);
    $provider->chat([]);
})->throws(\RuntimeException::class, 'HTTP 401 Unauthorized');

test('chat throws original error when all fallbacks fail', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 500 Internal Server Error'));
    $fallback1 = failingProvider('fallback-1', new \RuntimeException('HTTP 502 Bad Gateway'));
    $fallback2 = failingProvider('fallback-2', new \RuntimeException('HTTP 503 Service Unavailable'));

    $provider = new FallbackProvider($primary, [$fallback1, $fallback2]);
    $provider->chat([]);
})->throws(\RuntimeException::class, 'HTTP 500 Internal Server Error');

test('chat tries second fallback when first fails', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 500 Internal Server Error'));
    $fallback1 = failingProvider('fallback-1', new \RuntimeException('HTTP 502 Bad Gateway'));
    $fallback2 = stubProvider('fallback-2', 'second fallback');

    $provider = new FallbackProvider($primary, [$fallback1, $fallback2]);
    $response = $provider->chat([]);

    expect($response->content)->toBe('second fallback');
    expect($response->model)->toBe('fallback-2');
});

// --- stream() tests ---

test('stream yields primary chunks when primary succeeds', function () {
    $primary = stubProvider('primary-model', 'streamed');
    $fallback = stubProvider('fallback-model', 'fallback streamed');

    $provider = new FallbackProvider($primary, [$fallback]);
    $chunks = iterator_to_array($provider->stream([]));

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]->content)->toBe('streamed');
});

test('stream falls through to fallback on retryable error', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 502 Bad Gateway'));
    $fallback = stubProvider('fallback-model', 'fallback streamed');

    $provider = new FallbackProvider($primary, [$fallback]);
    $chunks = iterator_to_array($provider->stream([]));

    expect($chunks)->toHaveCount(1);
    expect($chunks[0]->content)->toBe('fallback streamed');
});

test('stream throws immediately on non-retryable error', function () {
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 403 Forbidden'));
    $fallback = stubProvider('fallback-model', 'fallback');

    $provider = new FallbackProvider($primary, [$fallback]);
    iterator_to_array($provider->stream([]));
})->throws(\RuntimeException::class, 'HTTP 403 Forbidden');

// --- onNotify callback tests ---

test('onNotify receives fallback start and success messages', function () {
    $messages = [];
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 429 Too Many Requests'));
    $fallback = stubProvider('fallback-model', 'ok');

    $provider = new FallbackProvider($primary, [$fallback]);
    $provider->setOnNotify(function (string $msg) use (&$messages) {
        $messages[] = $msg;
    });

    $provider->chat([]);

    expect($messages)->toHaveCount(2);
    expect($messages[0])->toContain('Primary provider failed (rate_limited)');
    expect($messages[0])->toContain('1 fallback model(s)');
    expect($messages[1])->toContain('Fallback to fallback-model succeeded');
});

test('onNotify receives failure messages for each failed fallback', function () {
    $messages = [];
    $primary = failingProvider('primary-model', new \RuntimeException('HTTP 500 Server Error'));
    $fallback1 = failingProvider('fallback-1', new \RuntimeException('HTTP 502 Bad Gateway'));
    $fallback2 = failingProvider('fallback-2', new \RuntimeException('HTTP 503 Unavailable'));

    $provider = new FallbackProvider($primary, [$fallback1, $fallback2]);
    $provider->setOnNotify(function (string $msg) use (&$messages) {
        $messages[] = $msg;
    });

    try {
        $provider->chat([]);
    } catch (\RuntimeException) {
        // expected
    }

    expect($messages)->toHaveCount(3);
    expect($messages[0])->toContain('Primary provider failed');
    expect($messages[1])->toContain('fallback-1 also failed');
    expect($messages[2])->toContain('fallback-2 also failed');
});

// --- Delegation tests ---

test('getModel returns primary model', function () {
    $primary = stubProvider('my-model');
    $provider = new FallbackProvider($primary, []);

    expect($provider->getModel())->toBe('my-model');
});

test('isAvailable delegates to primary', function () {
    $primary = stubProvider('model');
    $provider = new FallbackProvider($primary, []);

    expect($provider->isAvailable())->toBeTrue();
});
