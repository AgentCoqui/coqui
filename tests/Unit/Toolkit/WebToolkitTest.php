<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Storage\SessionStorage;
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

function webCreateTempPath(string $prefix): string
{
    return sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(6));
}

function webDeletePath(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        unlink($path);
        return;
    }

    $items = scandir($path);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        webDeletePath($path . '/' . $item);
    }

    rmdir($path);
}

// ---------------------------------------------------------------
// Tool registration
// ---------------------------------------------------------------

test('registers http_request and http_download tools by default', function () {
    $toolkit = new WebToolkit();
    $names = array_map(fn($tool) => $tool->toFunctionSchema()['function']['name'], $toolkit->tools());

    expect($names)->toContain('http_request');
    expect($names)->toContain('http_download');
    expect($names)->not->toContain('web_search');
});

test('registers web_search tool when search endpoint is configured', function () {
    $toolkit = new WebToolkit(searchEndpoint: 'https://api.example.com/search');
    $names = array_map(fn($tool) => $tool->toFunctionSchema()['function']['name'], $toolkit->tools());

    expect($names)->toContain('http_request');
    expect($names)->toContain('http_download');
    expect($names)->toContain('web_search');
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
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data['status'])->toBe(200);
    expect($data['method'])->toBe('GET');
    expect($data['content'])->toContain('{"ok": true}');
});

test('http_request sends headers query params and JSON body', function () {
    $capturedOptions = null;
    $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedOptions): MockResponse {
        $capturedOptions = ['method' => $method, 'url' => $url, 'options' => $options];

        return new MockResponse('{"created": true}', ['http_code' => 201]);
    });

    $toolkit = new WebToolkit(httpClient: $httpClient, allowPrivateNetworks: true);
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute([
        'url' => 'https://api.example.com/data',
        'method' => 'POST',
        'headers' => ['Authorization' => 'Bearer token'],
        'query' => ['page' => 2, 'per_page' => 10],
        'body' => ['name' => 'Coqui'],
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($capturedOptions['method'])->toBe('POST');
    expect($capturedOptions['url'])->toContain('https://api.example.com/data');
    expect($capturedOptions['url'])->toContain('page=2');
    expect($capturedOptions['url'])->toContain('per_page=10');
    expect($capturedOptions['options']['query'])->toBe(['page' => 2, 'per_page' => 10]);
    expect($capturedOptions['options']['normalized_headers']['authorization'][0])->toBe('Authorization: Bearer token');
    expect($capturedOptions['options']['normalized_headers']['content-type'][0])->toBe('Content-Type: application/json');
    expect($capturedOptions['options']['body'])->toBe('{"name":"Coqui"}');
});

test('http_request supports body response mode', function () {
    $httpClient = new MockHttpClient(new MockResponse('plain body', ['http_code' => 200]));
    $toolkit = new WebToolkit(httpClient: $httpClient, allowPrivateNetworks: true);
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute([
        'url' => 'https://api.example.com/text',
        'response_mode' => 'body',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toBe('plain body');
});

test('http_request supports json response mode', function () {
    $httpClient = new MockHttpClient(new MockResponse('{"ok":true,"count":2}', ['http_code' => 200]));
    $toolkit = new WebToolkit(httpClient: $httpClient, allowPrivateNetworks: true);
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute([
        'url' => 'https://api.example.com/json',
        'response_mode' => 'json',
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');
    expect(json_decode($result->content, true))->toBe([
        'ok' => true,
        'count' => 2,
    ]);
});

test('http_request returns tool error for failed HTTP responses when requested', function () {
    $httpClient = new MockHttpClient(new MockResponse('Not Found', ['http_code' => 404]));
    $toolkit = new WebToolkit(httpClient: $httpClient, allowPrivateNetworks: true);
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute([
        'url' => 'https://api.example.com/missing',
        'fail_on_http_error' => true,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('404');
});

test('http_request retries retryable status codes', function () {
    $httpClient = new MockHttpClient([
        new MockResponse('Try again', ['http_code' => 503]),
        new MockResponse('{"ok": true}', ['http_code' => 200]),
    ]);
    $toolkit = new WebToolkit(httpClient: $httpClient, allowPrivateNetworks: true);
    $tool = webFindTool($toolkit, 'http_request');

    $result = $tool->execute([
        'url' => 'https://api.example.com/retry',
        'retries' => 1,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(json_decode($result->content, true)['status'])->toBe(200);
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
// http_download tool
// ---------------------------------------------------------------

test('http_download saves files into the workspace downloads directory', function () {
    $workspacePath = webCreateTempPath('web-toolkit-workspace');
    mkdir($workspacePath, 0777, true);

    try {
        $httpClient = new MockHttpClient(new MockResponse('file content', ['http_code' => 200]));
        $toolkit = new WebToolkit(httpClient: $httpClient, workspacePath: $workspacePath);
        $tool = webFindTool($toolkit, 'http_download');

        $result = $tool->execute([
            'url' => 'https://example.com/file.txt',
            'filename' => 'report.txt',
        ]);

        expect($result->status)->toBe(ToolResultStatus::Success);
        expect($result->mimeType)->toBe('application/json');
        expect($result->displayHint)->toBe('structured-json');

        $data = json_decode($result->content, true);
        expect($data['filename'])->toBe('report.txt');
        expect($data['file_path'])->toContain('/downloads/report.txt');
        expect(file_exists($data['file_path']))->toBeTrue();
        expect(file_get_contents($data['file_path']))->toBe('file content');
    } finally {
        webDeletePath($workspacePath);
    }
});

test('http_download queues a background task when session context is available', function () {
    $workspacePath = webCreateTempPath('web-toolkit-workspace');
    $dbPath = webCreateTempPath('web-toolkit-db') . '.sqlite';
    mkdir($workspacePath, 0777, true);

    try {
        $storage = new SessionStorage($dbPath);
        $parentSessionId = $storage->createSession('orchestrator', 'test-model');
        $toolkit = new WebToolkit(
            storage: $storage,
            parentSessionId: $parentSessionId,
            workspacePath: $workspacePath,
        );
        $tool = webFindTool($toolkit, 'http_download');

        $result = $tool->execute([
            'url' => 'https://example.com/report.pdf',
            'filename' => 'report.pdf',
        ]);

        expect($result->status)->toBe(ToolResultStatus::Success);
        expect($result->mimeType)->toBe('application/json');
        expect($result->displayHint)->toBe('structured-json');

        $data = json_decode($result->content, true);
        expect($data['status'])->toBe('pending');
        expect($data['download_dir'])->toBe($workspacePath . '/downloads');

        $task = $storage->getTask($data['task_id']);
        expect($task)->not->toBeNull();
        expect($task['tool_name'])->toBe('http_download');
        expect(json_decode($task['tool_arguments'], true))->toMatchArray([
            'url' => 'https://example.com/report.pdf',
            'filename' => 'report.pdf',
        ]);
    } finally {
        webDeletePath($workspacePath);
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
    }
});

test('http_download inherits profile from parent session for queued task session', function () {
    $workspacePath = webCreateTempPath('web-toolkit-workspace');
    $dbPath = webCreateTempPath('web-toolkit-db') . '.sqlite';
    mkdir($workspacePath, 0777, true);

    try {
        $storage = new SessionStorage($dbPath);
        $parentSessionId = $storage->createSession('orchestrator', 'test-model', 'caelum');
        $toolkit = new WebToolkit(
            storage: $storage,
            parentSessionId: $parentSessionId,
            workspacePath: $workspacePath,
        );
        $tool = webFindTool($toolkit, 'http_download');

        $result = $tool->execute([
            'url' => 'https://example.com/report.pdf',
            'filename' => 'report.pdf',
        ]);

        $data = json_decode($result->content, true);
        $session = $storage->getSession((string) $data['session_id']);

        expect($result->status)->toBe(ToolResultStatus::Success);
        expect($session)->not->toBeNull();
        expect($session['profile'])->toBe('caelum');
    } finally {
        webDeletePath($workspacePath);
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
    }
});

// ---------------------------------------------------------------
// Guidelines
// ---------------------------------------------------------------

test('guidelines returns non-empty string', function () {
    $toolkit = new WebToolkit();

    expect($toolkit->guidelines())->toContain('WEB-GUIDELINES');
    expect($toolkit->guidelines())->toContain('http_request');
    expect($toolkit->guidelines())->toContain('http_download');
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
