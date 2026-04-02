<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Toolkit\WebToolkit;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

function webFindTool(WebToolkit $toolkit, string $name): ToolInterface
{
    foreach ($toolkit->tools() as $tool) {
        if ($tool->toFunctionSchema()['function']['name'] === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("Tool '{$name}' not found");
}

// ---------------------------------------------------------------
// Tool registration
// ---------------------------------------------------------------

test('registers http_request tool by default', function () {
    $toolkit = new WebToolkit();
    $tools = $toolkit->tools();

    expect(count($tools))->toBe(1);
    expect($tools[0]->toFunctionSchema()['function']['name'])->toBe('http_request');
});

test('registers web_search tool when search endpoint is configured', function () {
    $toolkit = new WebToolkit(searchEndpoint: 'https://api.example.com/search');
    $tools = $toolkit->tools();

    expect(count($tools))->toBe(2);

    $names = array_map(fn($t) => $t->toFunctionSchema()['function']['name'], $tools);
    expect($names)->toContain('http_request');
    expect($names)->toContain('web_search');
});

test('does not register web_search when no endpoint', function () {
    $toolkit = new WebToolkit();
    $names = array_map(fn($t) => $t->toFunctionSchema()['function']['name'], $toolkit->tools());

    expect($names)->not->toContain('web_search');
});

// ---------------------------------------------------------------
// http_request tool
// ---------------------------------------------------------------

test('http_request returns error for empty URL', function () {
    $toolkit = new WebToolkit();
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute(['url' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('URL is required');
});

test('http_request makes successful GET request', function () {
    $mockResponse = new MockResponse('{"ok": true}', ['http_code' => 200]);
    $httpClient = new MockHttpClient($mockResponse);

    $toolkit = new WebToolkit(httpClient: $httpClient, allowPrivateNetworks: true);
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute(['url' => 'https://api.example.com/test']);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $data = json_decode($result->content, true);
    expect($data['status'])->toBe(200);
    expect($data['content'])->toContain('{"ok": true}');
});

test('http_request returns error status for failed requests', function () {
    $mockResponse = new MockResponse('Not Found', ['http_code' => 404]);
    $httpClient = new MockHttpClient($mockResponse);

    $toolkit = new WebToolkit(httpClient: $httpClient, allowPrivateNetworks: true);
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute(['url' => 'https://api.example.com/missing']);

    // The tool returns success with the status code in the response body
    expect($result->status)->toBe(ToolResultStatus::Success);
    $data = json_decode($result->content, true);
    expect($data['status'])->toBe(404);
});

test('http_request truncates long responses', function () {
    $longContent = str_repeat('x', 15000);
    $mockResponse = new MockResponse($longContent, ['http_code' => 200]);
    $httpClient = new MockHttpClient($mockResponse);

    $toolkit = new WebToolkit(httpClient: $httpClient, allowPrivateNetworks: true);
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute(['url' => 'https://api.example.com/large']);

    $data = json_decode($result->content, true);
    expect(mb_strlen($data['content']))->toBe(10000);
    expect($data['truncated'])->toBeTrue();
    expect($data['total_length'])->toBe(15000);
});

// ---------------------------------------------------------------
// SSRF protection
// ---------------------------------------------------------------

test('http_request blocks private IP addresses', function () {
    $toolkit = new WebToolkit();
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute(['url' => 'http://127.0.0.1/admin']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('private or internal network');
});

test('http_request blocks metadata endpoints', function () {
    $toolkit = new WebToolkit();
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute(['url' => 'http://metadata.google.internal/computeMetadata/v1/']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('private or internal network');
});

test('http_request blocks malformed URLs', function () {
    $toolkit = new WebToolkit();
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute(['url' => 'not-a-url']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('http_request allows private networks when configured', function () {
    $mockResponse = new MockResponse('internal data', ['http_code' => 200]);
    $httpClient = new MockHttpClient($mockResponse);

    $toolkit = new WebToolkit(httpClient: $httpClient, allowPrivateNetworks: true);
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute(['url' => 'http://127.0.0.1/admin']);

    expect($result->status)->toBe(ToolResultStatus::Success);
});

// ---------------------------------------------------------------
// Guidelines
// ---------------------------------------------------------------

test('guidelines returns non-empty string', function () {
    $toolkit = new WebToolkit();

    expect($toolkit->guidelines())->toContain('WEB-GUIDELINES');
    expect($toolkit->guidelines())->toContain('http_request');
});

// ---------------------------------------------------------------
// web_search tool
// ---------------------------------------------------------------

test('web_search returns error for empty query', function () {
    $toolkit = new WebToolkit(searchEndpoint: 'https://api.example.com/search');
    $tool = webFindTool($toolkit, 'web_search');

    $result = $tool->execute(['query' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('web_search makes request with query and API key', function () {
    $mockResponse = new MockResponse('{"results": []}', ['http_code' => 200]);
    $httpClient = new MockHttpClient($mockResponse);

    $toolkit = new WebToolkit(
        searchEndpoint: 'https://api.example.com/search',
        searchApiKey: 'test-key',
        httpClient: $httpClient,
    );
    $tool = webFindTool($toolkit, 'web_search');

    $result = $tool->execute(['query' => 'test search']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('results');
});
