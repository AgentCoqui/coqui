<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\MapParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebToolkit implements ToolkitInterface
{
    private const DEFAULT_TIMEOUT_SECONDS = 30.0;
    private const DEFAULT_RETRIES = 0;
    private const MAX_RETRIES = 5;
    private const RESPONSE_PREVIEW_BYTES = 10000;
    private const DOWNLOADS_DIR = 'downloads';

    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly ?string $searchEndpoint = null,
        private readonly ?string $searchApiKey = null,
        ?HttpClientInterface $httpClient = null,
        private readonly bool $allowPrivateNetworks = false,
        private readonly ?SessionStorage $storage = null,
        private readonly ?string $parentSessionId = null,
        private readonly ?string $workspacePath = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => self::DEFAULT_TIMEOUT_SECONDS]);
    }

    public function tools(): array
    {
        $tools = [$this->httpRequestTool(), $this->httpDownloadTool()];

        if ($this->searchEndpoint !== null) {
            $tools[] = $this->webSearchTool();
        }

        return $tools;
    }

    public function guidelines(): string
    {
        $searchLine = $this->searchEndpoint !== null
            ? "\n        - Use web_search for information discovery."
            : '';
        $downloadLine = $this->storage !== null && $this->parentSessionId !== null
            ? "\n        - Use http_download to queue file downloads into the workspace downloads directory, then monitor them with task_status."
            : "\n        - Use http_download to save files into the workspace downloads directory.";

        return <<<GUIDELINES
        <WEB-GUIDELINES>
        - Use http_request to fetch web pages or call APIs with explicit methods, headers, query parameters, and request bodies.{$searchLine}{$downloadLine}
        - Respect rate limits and robots.txt.
        - Prefer structured APIs over scraping when available.
        </WEB-GUIDELINES>
        GUIDELINES;
    }

    private function httpRequestTool(): ToolInterface
    {
        return new Tool(
            name: 'http_request',
            description: 'Make an HTTP request to a URL with method, headers, query parameters, request body, timeout, retries, and response formatting options.',
            parameters: [
                new StringParameter('url', 'The URL to request'),
                new EnumParameter('method', 'HTTP method', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'], required: false),
                new MapParameter('headers', 'Headers to send as an object map', required: false, additionalProperties: (new StringParameter('value', 'Header value'))->toSchema()),
                new MapParameter('query', 'Query parameters to append to the request URL', required: false),
                new MapParameter('body', 'Structured request body. Object bodies are JSON-encoded automatically unless raw_body is provided.', required: false),
                new StringParameter('raw_body', 'Raw request body to send as-is. Use this for plain text, XML, or pre-encoded JSON.', required: false),
                new NumberParameter('timeout', 'Request timeout in seconds (default: 30)', required: false, minimum: 0.1, maximum: 300),
                new NumberParameter('retries', 'Number of retries for transient failures or retryable HTTP statuses (default: 0, max: 5)', required: false, integer: true, minimum: 0, maximum: self::MAX_RETRIES),
                new EnumParameter('response_mode', 'How to format the response', ['full', 'body', 'json'], required: false),
                new BoolParameter('fail_on_http_error', 'Return a tool error when the server responds with HTTP 4xx or 5xx', required: false),
            ],
            callback: function (array $input): ToolResult {
                $url = trim((string) ($input['url'] ?? ''));

                if ($url === '') {
                    return ToolResult::error('URL is required');
                }

                if (!$this->allowPrivateNetworks && $this->isBlockedUrl($url)) {
                    return ToolResult::error('Request blocked: URL resolves to a private or internal network address.');
                }

                try {
                    $request = $this->buildRequestDefinition($input, $url);
                    $response = $this->performRequest(
                        method: $request['method'],
                        url: $url,
                        options: $request['options'],
                        retries: $request['retries'],
                    );

                    $formatted = $this->formatHttpResponse($request['method'], $response);

                    if ($request['fail_on_http_error'] && $formatted['status'] >= 400) {
                        return ToolResult::error(json_encode($formatted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'HTTP request failed');
                    }

                    return $this->formatHttpResponseResult($formatted, $request['response_mode']);
                } catch (\Throwable $e) {
                    return ToolResult::error("HTTP request failed: {$e->getMessage()}");
                }
            },
        );
    }

    private function httpDownloadTool(): ToolInterface
    {
        return new Tool(
            name: 'http_download',
            description: 'Download a file from a URL into the workspace downloads directory. In the main agent context this queues an async background download.',
            parameters: [
                new StringParameter('url', 'The URL to download'),
                new StringParameter('filename', 'Optional filename to use instead of deriving one from the response', required: false),
                new MapParameter('headers', 'Headers to send as an object map', required: false, additionalProperties: (new StringParameter('value', 'Header value'))->toSchema()),
                new MapParameter('query', 'Query parameters to append to the download URL', required: false),
                new NumberParameter('timeout', 'Request timeout in seconds (default: 30)', required: false, minimum: 0.1, maximum: 300),
                new NumberParameter('retries', 'Number of retries for transient failures or retryable HTTP statuses (default: 0, max: 5)', required: false, integer: true, minimum: 0, maximum: self::MAX_RETRIES),
            ],
            callback: function (array $input): ToolResult {
                $url = trim((string) ($input['url'] ?? ''));

                if ($url === '') {
                    return ToolResult::error('URL is required');
                }

                if (!$this->allowPrivateNetworks && $this->isBlockedUrl($url)) {
                    return ToolResult::error('Request blocked: URL resolves to a private or internal network address.');
                }

                try {
                    $request = $this->buildDownloadRequestDefinition($input, $url);

                    if ($this->storage !== null && $this->parentSessionId !== null) {
                        return $this->queueDownload($request);
                    }

                    return $this->executeDownload($request);
                } catch (\Throwable $e) {
                    return ToolResult::error("Download failed: {$e->getMessage()}");
                }
            },
        );
    }

    private function webSearchTool(): ToolInterface
    {
        return new Tool(
            name: 'web_search',
            description: 'Search the web for information.',
            parameters: [
                new StringParameter('query', 'Search query'),
            ],
            callback: function (array $input): ToolResult {
                $query = $input['query'] ?? '';

                if ($query === '' || $this->searchEndpoint === null) {
                    return ToolResult::error('Search query is required');
                }

                try {
                    $headers = [];
                    if ($this->searchApiKey !== null) {
                        $headers['Authorization'] = "Bearer {$this->searchApiKey}";
                    }

                    $response = $this->httpClient->request('GET', $this->searchEndpoint, [
                        'query' => ['q' => $query],
                        'headers' => $headers,
                    ]);

                    return ToolResult::success($response->getContent());
                } catch (\Throwable $e) {
                    return ToolResult::error("Search failed: {$e->getMessage()}");
                }
            },
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{method: string, options: array<string, mixed>, retries: int, response_mode: string, fail_on_http_error: bool}
     */
    private function buildRequestDefinition(array $input, string $url): array
    {
        $method = strtoupper((string) ($input['method'] ?? 'GET'));
        $headers = $this->normalizeHeaders($input['headers'] ?? null, 'headers');
        $query = $this->normalizeMap($input['query'] ?? null, 'query');
        $timeout = $this->normalizeTimeout($input['timeout'] ?? null);
        $retries = $this->normalizeRetries($input['retries'] ?? null);
        $responseMode = strtolower((string) ($input['response_mode'] ?? 'full'));
        $rawBody = $input['raw_body'] ?? null;
        $body = $input['body'] ?? null;

        if (!in_array($responseMode, ['full', 'body', 'json'], true)) {
            throw new \RuntimeException('response_mode must be one of: full, body, json');
        }

        $options = [
            'timeout' => $timeout,
        ];

        if ($headers !== []) {
            $options['headers'] = $headers;
        }

        if ($query !== []) {
            $options['query'] = $query;
        }

        $payload = $this->normalizeRequestBody($body, $rawBody, $headers);
        if ($payload !== null) {
            $options['body'] = $payload['body'];
            $headers = $payload['headers'];
            if ($headers !== []) {
                $options['headers'] = $headers;
            }
        }

        return [
            'method' => $method,
            'options' => $options,
            'retries' => $retries,
            'response_mode' => $responseMode,
            'fail_on_http_error' => (bool) ($input['fail_on_http_error'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{url: string, filename: ?string, options: array<string, mixed>, retries: int}
     */
    private function buildDownloadRequestDefinition(array $input, string $url): array
    {
        $headers = $this->normalizeHeaders($input['headers'] ?? null, 'headers');
        $query = $this->normalizeMap($input['query'] ?? null, 'query');
        $timeout = $this->normalizeTimeout($input['timeout'] ?? null);
        $retries = $this->normalizeRetries($input['retries'] ?? null);
        $filename = isset($input['filename']) ? trim((string) $input['filename']) : null;

        $options = [
            'timeout' => $timeout,
        ];

        if ($headers !== []) {
            $options['headers'] = $headers;
        }

        if ($query !== []) {
            $options['query'] = $query;
        }

        return [
            'url' => $url,
            'filename' => $filename !== '' ? $filename : null,
            'options' => $options,
            'retries' => $retries,
        ];
    }

    /**
     * @param array<string, mixed> $request
     */
    private function queueDownload(array $request): ToolResult
    {
        if ($this->storage === null || $this->parentSessionId === null || $this->workspacePath === null) {
            return ToolResult::error('Download queueing requires storage, session, and workspace context.');
        }

        $parentSession = $this->storage->getSession($this->parentSessionId);
        $parentProfile = is_array($parentSession) && is_string($parentSession['profile'] ?? null) && $parentSession['profile'] !== ''
            ? $parentSession['profile']
            : null;
        $sessionId = $this->storage->createSession('tool', 'background-tool', $parentProfile, visibility: 'hidden');
        $parentProjectId = $this->storage->getActiveProjectId($this->parentSessionId);
        if ($parentProjectId !== null) {
            $this->storage->setActiveProject($sessionId, $parentProjectId);
        }

        $argumentsJson = json_encode([
            'url' => $request['url'],
            'filename' => $request['filename'],
            'headers' => $request['options']['headers'] ?? null,
            'query' => $request['options']['query'] ?? null,
            'timeout' => $request['options']['timeout'] ?? self::DEFAULT_TIMEOUT_SECONDS,
            'retries' => $request['retries'],
        ], JSON_UNESCAPED_SLASHES);

        if ($argumentsJson === false) {
            return ToolResult::error('Failed to encode background download arguments.');
        }

        $displayName = $request['filename'] ?? $this->deriveFilenameFromUrl($request['url']);
        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: sprintf('Download file: %s', $request['url']),
            role: 'tool',
            parentSessionId: $this->parentSessionId,
            title: sprintf('Download %s', $displayName),
            maxIterations: 1,
            toolName: 'http_download',
            toolArguments: $argumentsJson,
        );

        return ToolResult::success(json_encode([
            'task_id' => $taskId,
            'session_id' => $sessionId,
            'status' => 'pending',
            'download_dir' => $this->downloadsDirectoryPath(),
            'requested_filename' => $request['filename'] ?? null,
            'message' => 'Background download queued. Use task_status to monitor progress.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'Download queued');
    }

    /**
     * @param array<string, mixed> $request
     */
    private function executeDownload(array $request): ToolResult
    {
        $response = $this->performRequest(
            method: 'GET',
            url: $request['url'],
            options: $request['options'],
            retries: $request['retries'],
        );

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 400) {
            $formatted = $this->formatHttpResponse('GET', $response);
            return ToolResult::error(json_encode($formatted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'Download request failed');
        }

        $content = $response->getContent(false);
        $headers = $this->flattenHeaders($response->getHeaders(false));
        $downloadDir = $this->ensureDownloadsDirectory();
        $filename = $this->resolveDownloadFilename($request['filename'], $headers, (string) $response->getInfo('url'), $request['url']);
        $targetPath = $this->ensureUniquePath($downloadDir . '/' . $filename);
        $tempPath = $targetPath . '.part';

        if (file_put_contents($tempPath, $content) === false) {
            throw new \RuntimeException(sprintf('Failed to write download temp file: %s', $tempPath));
        }

        if (!rename($tempPath, $targetPath)) {
            @unlink($tempPath);
            throw new \RuntimeException(sprintf('Failed to finalize download file: %s', $targetPath));
        }

        return ToolResult::success(json_encode([
            'status' => $statusCode,
            'file_path' => $targetPath,
            'filename' => basename($targetPath),
            'size' => filesize($targetPath) ?: 0,
            'url' => $request['url'],
            'final_url' => (string) $response->getInfo('url'),
            'headers' => $headers,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: 'Download completed');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function performRequest(string $method, string $url, array $options, int $retries): \Symfony\Contracts\HttpClient\ResponseInterface
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $this->httpClient->request($method, $url, $options);
                $statusCode = $response->getStatusCode();

                if ($attempt < $retries && $this->shouldRetryStatus($statusCode)) {
                    $attempt++;
                    continue;
                }

                return $response;
            } catch (\Throwable $e) {
                if ($attempt >= $retries) {
                    throw $e;
                }

                $attempt++;
            }
        }
    }

    /**
     * @param array<string, mixed> $formatted
     */
    private function formatHttpResponseResult(array $formatted, string $responseMode): ToolResult
    {
        return match ($responseMode) {
            'body' => ToolResult::success((string) $formatted['body']),
            'json' => $this->formatJsonResponseResult((string) $formatted['full_body']),
            default => ToolResult::success(json_encode($formatted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: ''),
        };
    }

    private function formatJsonResponseResult(string $body): ToolResult
    {
        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ToolResult::error('Response body is not valid JSON.');
        }

        return ToolResult::success(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @return array<string, mixed>
     */
    private function formatHttpResponse(string $method, \Symfony\Contracts\HttpClient\ResponseInterface $response): array
    {
        $body = $response->getContent(false);
        $preview = mb_substr($body, 0, self::RESPONSE_PREVIEW_BYTES);
        $headers = $this->flattenHeaders($response->getHeaders(false));

        $result = [
            'status' => $response->getStatusCode(),
            'method' => $method,
            'url' => (string) $response->getInfo('url'),
            'headers' => $headers,
            'body' => $preview,
            'content' => $preview,
            'full_body' => $body,
        ];

        if (strlen($body) > self::RESPONSE_PREVIEW_BYTES) {
            $result['truncated'] = true;
            $result['total_length'] = strlen($body);
        }

        return $result;
    }

    /**
     * @param mixed $input
     * @return array<string, string>
     */
    private function normalizeHeaders(mixed $input, string $fieldName): array
    {
        $map = $this->normalizeMap($input, $fieldName);
        $headers = [];

        foreach ($map as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new \RuntimeException(sprintf('%s values must be scalars or null.', $fieldName));
            }

            $headers[(string) $key] = $value === null ? '' : (string) $value;
        }

        return $headers;
    }

    /**
     * @param mixed $input
     * @return array<string, mixed>
     */
    private function normalizeMap(mixed $input, string $fieldName): array
    {
        if ($input === null || $input === '') {
            return [];
        }

        if (is_string($input)) {
            $decoded = json_decode($input, true);
            if (!is_array($decoded)) {
                throw new \RuntimeException(sprintf('%s must be an object map or a valid JSON object string.', $fieldName));
            }

            return $decoded;
        }

        if (!is_array($input)) {
            throw new \RuntimeException(sprintf('%s must be an object map.', $fieldName));
        }

        return $input;
    }

    /**
     * @param mixed $body
     * @param mixed $rawBody
     * @param array<string, string> $headers
     * @return array{body: string, headers: array<string, string>}|null
     */
    private function normalizeRequestBody(mixed $body, mixed $rawBody, array $headers): ?array
    {
        if ($rawBody !== null && $rawBody !== '') {
            return [
                'body' => (string) $rawBody,
                'headers' => $headers,
            ];
        }

        if ($body === null || $body === '') {
            return null;
        }

        if (is_string($body)) {
            return [
                'body' => $body,
                'headers' => $headers,
            ];
        }

        if (!is_array($body)) {
            throw new \RuntimeException('body must be an object map or raw_body must be provided.');
        }

        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode request body as JSON.');
        }

        if (!$this->hasHeader($headers, 'Content-Type')) {
            $headers['Content-Type'] = 'application/json';
        }

        return [
            'body' => $encoded,
            'headers' => $headers,
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function hasHeader(array $headers, string $headerName): bool
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $headerName) === 0) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTimeout(mixed $timeout): float
    {
        if ($timeout === null || $timeout === '') {
            return self::DEFAULT_TIMEOUT_SECONDS;
        }

        $normalized = (float) $timeout;
        if ($normalized <= 0) {
            throw new \RuntimeException('timeout must be greater than zero.');
        }

        return $normalized;
    }

    private function normalizeRetries(mixed $retries): int
    {
        if ($retries === null || $retries === '') {
            return self::DEFAULT_RETRIES;
        }

        $normalized = (int) $retries;
        if ($normalized < 0 || $normalized > self::MAX_RETRIES) {
            throw new \RuntimeException(sprintf('retries must be between 0 and %d.', self::MAX_RETRIES));
        }

        return $normalized;
    }

    private function shouldRetryStatus(int $statusCode): bool
    {
        return $statusCode === 408 || $statusCode === 425 || $statusCode === 429 || $statusCode >= 500;
    }

    /**
     * @param array<string, array<int, string>> $headers
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flattened = [];
        foreach ($headers as $name => $values) {
            $flattened[$name] = implode(', ', $values);
        }

        return $flattened;
    }

    private function downloadsDirectoryPath(): string
    {
        if ($this->workspacePath === null) {
            throw new \RuntimeException('Workspace path is required for downloads.');
        }

        return rtrim($this->workspacePath, '/') . '/' . self::DOWNLOADS_DIR;
    }

    private function ensureDownloadsDirectory(): string
    {
        $path = $this->downloadsDirectoryPath();
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException(sprintf('Failed to create downloads directory: %s', $path));
        }

        return $path;
    }

    /**
     * @param array<string, string> $headers
     */
    private function resolveDownloadFilename(?string $requestedFilename, array $headers, string $finalUrl, string $originalUrl): string
    {
        $filename = $requestedFilename;

        if ($filename === null || $filename === '') {
            $filename = $this->parseContentDispositionFilename($headers['content-disposition'] ?? $headers['Content-Disposition'] ?? null);
        }

        if ($filename === null || $filename === '') {
            $filename = $this->deriveFilenameFromUrl($finalUrl !== '' ? $finalUrl : $originalUrl);
        }

        return $this->sanitizeFilename($filename);
    }

    private function parseContentDispositionFilename(?string $header): ?string
    {
        if ($header === null || $header === '') {
            return null;
        }

        if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $header, $matches) === 1) {
            return rawurldecode($matches[1]);
        }

        if (preg_match('/filename="?([^";]+)"?/i', $header, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function deriveFilenameFromUrl(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $basename = basename($path);

        if ($basename === '' || $basename === '/' || $basename === '.') {
            return 'download';
        }

        return $basename;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename(trim($filename));
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? 'download';
        $filename = trim($filename, '.-');

        return $filename !== '' ? $filename : 'download';
    }

    private function ensureUniquePath(string $path): string
    {
        if (!file_exists($path)) {
            return $path;
        }

        $directory = dirname($path);
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $counter = 1;

        do {
            $candidate = $directory . '/' . $filename . '-' . $counter;
            if ($extension !== '') {
                $candidate .= '.' . $extension;
            }
            $counter++;
        } while (file_exists($candidate));

        return $candidate;
    }

    /**
     * Check if a URL resolves to a blocked (private/internal) network address.
     */
    private function isBlockedUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host === null || $host === false || $host === '') {
            return true; // Malformed URL
        }

        // Strip brackets from IPv6 addresses
        $host = trim($host, '[]');

        // Block common metadata hostnames
        $blockedHosts = ['metadata.google.internal', 'metadata', 'instance-data'];
        if (in_array(strtolower($host), $blockedHosts, true)) {
            return true;
        }

        // Resolve hostname to IP addresses
        $ips = gethostbynamel($host);
        if ($ips === false) {
            // Could also be an IPv6 address or unresolvable host
            // Check if it's a raw IP address
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                $ips = [$host];
            } else {
                return true; // Unresolvable
            }
        }

        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if an IP address falls within any blocked CIDR range.
     */
    private function isPrivateIp(string $ip): bool
    {
        // Quick check using PHP's built-in filter
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        return false;
    }
}
