<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Provider\ReactHttpClientAdapter;
use CoquiBot\Coqui\Provider\ReactHttpResponse;
use CoquiBot\Coqui\Provider\ReactResponseStream;
use React\Http\Browser;
use React\Promise\PromiseInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Create a spy Browser that captures arguments passed to requestStreaming().
 *
 * Uses an ArrayObject as a shared capture container so that clones
 * (produced by withTimeout) share the same reference.
 *
 * @return array{Browser, ArrayObject} [browser, captured args container]
 */
function makeSpyBrowser(): array
{
    $captured = new ArrayObject();

    $browser = new class ($captured) extends Browser {
        public function __construct(
            private readonly ArrayObject $captured,
        ) {
            // Skip parent constructor — no real connector needed
        }

        public function requestStreaming($method, $url, $headers = [], $body = ''): PromiseInterface
        {
            $this->captured['method'] = $method;
            $this->captured['url'] = $url;
            $this->captured['headers'] = $headers;
            $this->captured['body'] = $body;

            // Return a never-resolving promise — ReactHttpResponse wraps lazily
            return new \React\Promise\Promise(function () {});
        }

        public function withTimeout($timeout): static
        {
            // Return self (no clone) — we don't need real timeout behavior in tests
            return $this;
        }
    };

    return [$browser, $captured];
}

test('implements HttpClientInterface', function () {
    $adapter = new ReactHttpClientAdapter();

    expect($adapter)->toBeInstanceOf(HttpClientInterface::class);
});

test('request() returns ReactHttpResponse', function () {
    [$browser, $captured] = makeSpyBrowser();
    $adapter = new ReactHttpClientAdapter($browser);

    $response = $adapter->request('GET', 'http://example.com/api');

    expect($response)->toBeInstanceOf(ReactHttpResponse::class);
    expect($captured['method'])->toBe('GET');
    expect($captured['url'])->toBe('http://example.com/api');
});

test('request() maps auth_bearer to Authorization header', function () {
    [$browser, $captured] = makeSpyBrowser();
    $adapter = new ReactHttpClientAdapter($browser);

    $adapter->request('POST', 'http://api.example.com', [
        'auth_bearer' => 'sk-test-token-123',
    ]);

    expect($captured['headers'])->toHaveKey('Authorization');
    expect($captured['headers']['Authorization'])->toBe('Bearer sk-test-token-123');
});

test('request() serializes json option to body and sets Content-Type', function () {
    [$browser, $captured] = makeSpyBrowser();
    $adapter = new ReactHttpClientAdapter($browser);

    $adapter->request('POST', 'http://api.example.com', [
        'json' => ['model' => 'gpt-4', 'messages' => []],
    ]);

    $decoded = json_decode($captured['body'], true);
    expect($decoded)->toBe(['model' => 'gpt-4', 'messages' => []]);
    expect($captured['headers'])->toHaveKey('Content-Type');
    expect($captured['headers']['Content-Type'])->toBe('application/json');
});

test('request() does not override existing Content-Type for json', function () {
    [$browser, $captured] = makeSpyBrowser();
    $adapter = new ReactHttpClientAdapter($browser);

    $adapter->request('POST', 'http://api.example.com', [
        'json' => ['data' => true],
        'headers' => ['Content-Type' => 'application/vnd.custom+json'],
    ]);

    expect($captured['headers']['Content-Type'])->toBe('application/vnd.custom+json');
});

test('request() treats header names case-insensitively when checking Content-Type', function () {
    [$browser, $captured] = makeSpyBrowser();
    $adapter = new ReactHttpClientAdapter($browser);

    $adapter->request('POST', 'http://api.example.com', [
        'json' => ['data' => true],
        'headers' => ['content-type' => 'application/problem+json'],
    ]);

    expect($captured['headers'])->toHaveCount(1)
        ->and($captured['headers']['content-type'])->toBe('application/problem+json');
});

test('request() collapses duplicate headers that differ only by case', function () {
    [$browser, $captured] = makeSpyBrowser();
    $adapter = new ReactHttpClientAdapter($browser);

    $adapter->request('GET', 'http://api.example.com', [
        'headers' => [
            'authorization' => 'Bearer old-token',
            'Authorization' => 'Bearer new-token',
        ],
    ]);

    expect($captured['headers'])->toHaveCount(1)
        ->and($captured['headers'])->toHaveKey('Authorization')
        ->and($captured['headers']['Authorization'])->toBe('Bearer new-token');
});

test('request() passes body option as string', function () {
    [$browser, $captured] = makeSpyBrowser();
    $adapter = new ReactHttpClientAdapter($browser);

    $adapter->request('PUT', 'http://api.example.com', [
        'body' => 'raw body content',
    ]);

    expect($captured['body'])->toBe('raw body content');
});

test('request() appends query parameters to URL', function () {
    [$browser, $captured] = makeSpyBrowser();
    $adapter = new ReactHttpClientAdapter($browser);

    $adapter->request('GET', 'http://api.example.com/search', [
        'query' => ['q' => 'test', 'limit' => '10'],
    ]);

    expect($captured['url'])->toBe('http://api.example.com/search?q=test&limit=10');
});

test('request() appends query with & when URL already has query string', function () {
    [$browser, $captured] = makeSpyBrowser();
    $adapter = new ReactHttpClientAdapter($browser);

    $adapter->request('GET', 'http://api.example.com/search?existing=1', [
        'query' => ['extra' => 'value'],
    ]);

    expect($captured['url'])->toBe('http://api.example.com/search?existing=1&extra=value');
});

test('stream() throws for non-ReactHttpResponse', function () {
    $adapter = new ReactHttpClientAdapter();

    // Create a stub ResponseInterface (no Mockery)
    $fakeResponse = new class implements ResponseInterface {
        public function getStatusCode(): int { return 200; }
        public function getHeaders(bool $throw = true): array { return []; }
        public function getContent(bool $throw = true): string { return ''; }
        public function toArray(bool $throw = true): array { return []; }
        public function cancel(): void {}
        public function getInfo(?string $type = null): mixed { return null; }
    };

    expect(fn() => $adapter->stream($fakeResponse))->toThrow(
        \InvalidArgumentException::class,
        'Expected ' . ReactHttpResponse::class,
    );
});

test('stream() throws for empty iterable', function () {
    $adapter = new ReactHttpClientAdapter();

    expect(fn() => $adapter->stream([]))->toThrow(
        \InvalidArgumentException::class,
        'No responses provided',
    );
});

test('withOptions() returns new instance', function () {
    $adapter = new ReactHttpClientAdapter();
    $cloned = $adapter->withOptions(['auth_bearer' => 'token']);

    expect($cloned)->toBeInstanceOf(ReactHttpClientAdapter::class);
    expect($cloned)->not->toBe($adapter);
});

test('withOptions() merges default options into requests', function () {
    [$browser, $captured] = makeSpyBrowser();
    $adapter = new ReactHttpClientAdapter($browser, [
        'auth_bearer' => 'default-token',
    ]);

    $adapter->request('GET', 'http://api.example.com');

    expect($captured['headers'])->toHaveKey('Authorization');
    expect($captured['headers']['Authorization'])->toBe('Bearer default-token');
});

test('request is cancelled when the cancellation token is triggered', function () {
    $captured = new ArrayObject();

    $browser = new class ($captured) extends Browser {
        public function __construct(
            private readonly ArrayObject $captured,
        ) {}

        public function requestStreaming($method, $url, $headers = [], $body = ''): PromiseInterface
        {
            return new \React\Promise\Promise(
                function () {},
                function () use ($method, $url): void {
                    $this->captured['cancelled'] = true;
                    $this->captured['method'] = $method;
                    $this->captured['url'] = $url;
                },
            );
        }

        public function withTimeout($timeout): static
        {
            return $this;
        }
    };

    $token = new ProcessCancellationToken();
    $adapter = new ReactHttpClientAdapter($browser, cancellationToken: $token);

    $response = $adapter->request('GET', 'http://example.com/cancel');
    $token->cancel();

    expect($response->getInfo('canceled'))->toBeTrue()
        ->and($captured['cancelled'] ?? false)->toBeTrue();
});
