<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WebToolkit implements ToolkitInterface
{
    private HttpClientInterface $httpClient;

    public function __construct(
        private readonly ?string $searchEndpoint = null,
        private readonly ?string $searchApiKey = null,
        ?HttpClientInterface $httpClient = null,
        private readonly bool $allowPrivateNetworks = false,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create(['timeout' => 30]);
    }

    public function tools(): array
    {
        $tools = [$this->httpRequestTool()];

        if ($this->searchEndpoint !== null) {
            $tools[] = $this->webSearchTool();
        }

        return $tools;
    }

    public function guidelines(): string
    {
        return <<<GUIDELINES
        <WEB-GUIDELINES>
        - Use http_request to fetch web pages or call APIs.
        - Use web_search for information discovery.
        - Respect rate limits and robots.txt.
        - Prefer structured APIs over scraping when available.
        </WEB-GUIDELINES>
        GUIDELINES;
    }

    private function httpRequestTool(): ToolInterface
    {
        return new Tool(
            name: 'http_request',
            description: 'Make an HTTP request to a URL.',
            parameters: [
                new StringParameter('url', 'The URL to request'),
                new EnumParameter('method', 'HTTP method', ['GET', 'POST', 'PUT', 'DELETE'], required: false),
                new StringParameter('body', 'Request body (for POST/PUT)', required: false),
                new StringParameter('headers', 'JSON object of headers', required: false),
            ],
            callback: function (array $input): ToolResult {
                $url = $input['url'] ?? '';
                $method = $input['method'] ?? 'GET';
                $body = $input['body'] ?? null;
                $headersJson = $input['headers'] ?? '{}';

                if ($url === '') {
                    return ToolResult::error('URL is required');
                }

                // SSRF protection: block requests to private/internal networks
                if (!$this->allowPrivateNetworks && $this->isBlockedUrl($url)) {
                    return ToolResult::error('Request blocked: URL resolves to a private or internal network address.');
                }

                try {
                    $options = [];
                    $headers = json_decode($headersJson, true) ?? [];

                    if (!empty($headers)) {
                        $options['headers'] = $headers;
                    }

                    if ($body !== null && in_array($method, ['POST', 'PUT'])) {
                        $options['body'] = $body;
                    }

                    $response = $this->httpClient->request($method, $url, $options);
                    $content = $response->getContent(false);
                    $statusCode = $response->getStatusCode();

                    $result = [
                        'status' => $statusCode,
                        'content' => mb_substr($content, 0, 10000),
                    ];

                    if (strlen($content) > 10000) {
                        $result['truncated'] = true;
                        $result['total_length'] = strlen($content);
                    }

                    return ToolResult::success(json_encode($result, JSON_PRETTY_PRINT) ?: '');
                } catch (\Throwable $e) {
                    return ToolResult::error("HTTP request failed: {$e->getMessage()}");
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
