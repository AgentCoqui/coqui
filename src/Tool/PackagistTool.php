<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tool that queries the Packagist API for package discovery and evaluation.
 *
 * Provides search and detailed package info — all anonymous endpoints.
 * Use this tool to find and evaluate packages before installing them
 * with the `composer` tool's `add` action.
 */
final class PackagistTool implements ToolInterface
{
    private const string BASE_URL = 'https://packagist.org';
    private const string REPO_URL = 'https://repo.packagist.org';
    private const int DEFAULT_PER_PAGE = 15;
    private const int MAX_VERSIONS_SHOWN = 10;

    private readonly HttpClientInterface $httpClient;

    public function __construct(
        ?HttpClientInterface $httpClient = null,
    ) {
        $this->httpClient = $httpClient ?? HttpClient::create([
            'headers' => [
                'User-Agent' => 'Coqui/1.0 (https://github.com/AgentCoqui/coqui)',
            ],
        ]);
    }

    public function name(): string
    {
        return 'packagist';
    }

    public function description(): string
    {
        return <<<'DESC'
            Search and explore packages on Packagist.org (the main Composer repository).

            Use this tool to discover and evaluate PHP packages BEFORE installing them
            with the `composer` tool. Always search Packagist for existing solutions
            before building something from scratch.

            Available actions:
            - search: Search packages by keyword, tag, or type. Returns name, description,
              downloads, and favers. Paginated. Use this to find packages that solve your
              problem — there is almost always an existing package.
            - details: Get full metadata for a specific package including description,
              maintainers, download stats, latest versions, PHP requirements, and
              security advisories. Always check details before installing.

            All endpoints are anonymous — no authentication required.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The Packagist action to perform',
                values: ['search', 'details'],
                required: true,
            ),
            new StringParameter(
                name: 'query',
                description: 'Search keywords. Required for search action.',
                required: false,
            ),
            new StringParameter(
                name: 'package',
                description: 'Full package name (vendor/package). Required for details action.',
                required: false,
            ),
            new StringParameter(
                name: 'tags',
                description: 'Filter search results by tag (e.g. "psr-3", "http"). Only for search action.',
                required: false,
            ),
            new StringParameter(
                name: 'type',
                description: 'Filter by package type (e.g. "library", "symfony-bundle"). Only for search action.',
                required: false,
            ),
            new NumberParameter(
                name: 'page',
                description: 'Page number for pagination. Default: 1.',
                required: false,
                integer: true,
                minimum: 1,
            ),
            new NumberParameter(
                name: 'per_page',
                description: 'Results per page (max 100). Default: 15.',
                required: false,
                integer: true,
                minimum: 1,
                maximum: 100,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        return match ($action) {
            'search' => $this->search($input),
            'details' => $this->details($input),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private function search(array $input): ToolResult
    {
        $query = $input['query'] ?? '';
        if ($query === '') {
            return ToolResult::error('The `query` parameter is required for the search action.');
        }

        $params = ['q' => $query];

        $tags = $input['tags'] ?? '';
        if ($tags !== '') {
            $params['tags'] = $tags;
        }

        $type = $input['type'] ?? '';
        if ($type !== '') {
            $params['type'] = $type;
        }

        $params['per_page'] = (int) ($input['per_page'] ?? self::DEFAULT_PER_PAGE);

        $page = (int) ($input['page'] ?? 1);
        if ($page > 1) {
            $params['page'] = $page;
        }

        $data = $this->apiGet(self::BASE_URL . '/search.json', $params);
        if ($data === null) {
            return ToolResult::error('Failed to reach Packagist search API.');
        }

        $results = $data['results'] ?? [];
        $total = $data['total'] ?? 0;
        $next = $data['next'] ?? null;

        $output = "## Packagist Search: \"{$query}\"\n\n";
        $output .= "**Total results:** {$total} | **Page:** {$page}\n\n";

        if ($results === []) {
            $output .= "No packages found matching your query.\n";
            return ToolResult::success($output);
        }

        $output .= "| # | Package | Downloads | Favers | Description |\n";
        $output .= "|---|---------|-----------|--------|-------------|\n";

        $rank = ($page - 1) * $params['per_page'];
        foreach ($results as $pkg) {
            $rank++;
            $name = $pkg['name'] ?? 'unknown';
            $desc = $this->truncate($pkg['description'] ?? '', 60);
            $downloads = $this->formatNumber($pkg['downloads'] ?? 0);
            $favers = $this->formatNumber($pkg['favers'] ?? 0);
            $output .= "| {$rank} | {$name} | {$downloads} | {$favers} | {$desc} |\n";
        }

        if ($next !== null) {
            $nextPage = $page + 1;
            $output .= "\n*More results available — use `page: {$nextPage}` to see the next page.*\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function details(array $input): ToolResult
    {
        $package = $input['package'] ?? '';
        if ($package === '' || !str_contains($package, '/')) {
            return ToolResult::error('The `package` parameter (vendor/package) is required for the details action.');
        }

        // Fetch package metadata
        $data = $this->apiGet(self::BASE_URL . "/packages/{$package}.json");
        if ($data === null) {
            return ToolResult::error("Failed to fetch details for package '{$package}'. It may not exist.");
        }

        $pkg = $data['package'] ?? [];
        if ($pkg === []) {
            return ToolResult::error("Package '{$package}' not found.");
        }

        $name = $pkg['name'] ?? $package;
        $description = $pkg['description'] ?? 'No description';
        $type = $pkg['type'] ?? 'unknown';
        $repository = $pkg['repository'] ?? 'N/A';
        $downloads = $pkg['downloads'] ?? [];
        $favers = $pkg['favers'] ?? 0;
        $time = $pkg['time'] ?? 'unknown';

        // Extract latest stable version
        $latestVersion = $this->extractLatestVersion($pkg['versions'] ?? []);

        // Extract maintainers
        $maintainers = array_map(
            fn(array $m) => $m['name'] ?? $m['username'] ?? 'unknown',
            $pkg['maintainers'] ?? [],
        );

        // Check if abandoned
        $abandoned = $pkg['abandoned'] ?? false;

        $output = "## {$name}\n\n";

        if ($abandoned !== false) {
            $replacement = is_string($abandoned) ? " Use **{$abandoned}** instead." : '';
            $output .= "> **WARNING: This package is abandoned.**{$replacement}\n\n";
        }

        $output .= "**Description:** {$description}\n";
        $output .= "**Type:** {$type}\n";
        $output .= "**Repository:** {$repository}\n";
        $output .= "**Created:** {$time}\n";
        $output .= "**Maintainers:** " . implode(', ', $maintainers) . "\n";
        $output .= "**Favers:** " . $this->formatNumber($favers) . "\n";

        if ($latestVersion !== null) {
            $output .= "**Latest stable:** {$latestVersion}\n";
        }

        $output .= "\n### Downloads\n\n";
        $output .= "| Period | Count |\n";
        $output .= "|--------|-------|\n";
        $output .= "| Total | " . $this->formatNumber($downloads['total'] ?? 0) . " |\n";
        $output .= "| Monthly | " . $this->formatNumber($downloads['monthly'] ?? 0) . " |\n";
        $output .= "| Daily | " . $this->formatNumber($downloads['daily'] ?? 0) . " |\n";

        // Fetch version info from v2 metadata API
        $versionData = $this->apiGet(self::REPO_URL . "/p2/{$package}.json");
        if ($versionData !== null) {
            $versions = $versionData['packages'][$package] ?? [];
            if ($versions !== []) {
                $versions = $this->expandMinifiedVersions($versions, $package);
                $stableVersions = array_filter(
                    $versions,
                    fn(array $v) => !str_contains($v['version'] ?? '', 'dev'),
                );
                if ($stableVersions === []) {
                    $stableVersions = $versions;
                }

                $shown = array_slice($stableVersions, 0, self::MAX_VERSIONS_SHOWN);

                $output .= "\n### Recent Versions\n\n";
                $output .= "| Version | PHP Requirement | Released |\n";
                $output .= "|---------|----------------|----------|\n";

                foreach ($shown as $v) {
                    $version = $v['version'] ?? '?';
                    $phpReq = $v['require']['php'] ?? 'any';
                    $vTime = isset($v['time']) ? substr($v['time'], 0, 10) : 'unknown';
                    $output .= "| {$version} | {$phpReq} | {$vTime} |\n";
                }

                $totalStable = count($stableVersions);
                if ($totalStable > self::MAX_VERSIONS_SHOWN) {
                    $output .= "\n*Showing " . self::MAX_VERSIONS_SHOWN . " of {$totalStable} stable versions.*\n";
                }
            }
        }

        // Fetch security advisories
        $advisoryData = $this->apiGet(
            self::BASE_URL . '/api/security-advisories/',
            ['packages[]' => $package],
        );
        if ($advisoryData !== null) {
            $advisories = $advisoryData['advisories'][$package] ?? [];
            if ($advisories !== []) {
                $output .= "\n### ⚠ Security Advisories\n\n";
                $output .= "**Found " . count($advisories) . " advisory(ies):**\n\n";
                $output .= "| CVE | Title | Affected Versions | Severity |\n";
                $output .= "|-----|-------|-------------------|----------|\n";

                foreach ($advisories as $advisory) {
                    $cve = $advisory['cve'] ?? 'N/A';
                    $title = $this->truncate($advisory['title'] ?? 'Unknown', 50);
                    $affected = $advisory['affectedVersions'] ?? 'unknown';
                    $severity = $advisory['severity'] ?? 'unknown';
                    $output .= "| {$cve} | {$title} | {$affected} | {$severity} |\n";
                }
            } else {
                $output .= "\n### Security\n\nNo known security vulnerabilities. ✓\n";
            }
        }

        return ToolResult::success($output);
    }

    /**
     * Make a GET request to the Packagist API.
     *
     * @param array<string, string|int> $query
     * @return array<string, mixed>|null
     */
    private function apiGet(string $url, array $query = []): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'query' => $query,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode < 200 || $statusCode >= 300) {
                return null;
            }

            return $response->toArray();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Expand minified Composer v2 metadata if the minifier is available.
     *
     * @param array<int, array<string, mixed>> $versions
     * @return array<int, array<string, mixed>>
     */
    private function expandMinifiedVersions(array $versions, string $package): array
    {
        if (!class_exists(\Composer\MetadataMinifier\MetadataMinifier::class)) {
            return $versions;
        }

        try {
            /** @var array<int, array<string, mixed>> */
            return \Composer\MetadataMinifier\MetadataMinifier::expand($versions);
        } catch (\Throwable) {
            return $versions;
        }
    }

    /**
     * Extract the latest stable version string from the versions map.
     *
     * @param array<string, mixed> $versions
     */
    private function extractLatestVersion(array $versions): ?string
    {
        foreach ($versions as $version => $data) {
            if (!str_starts_with((string) $version, 'dev-')) {
                return (string) $version;
            }
        }

        return null;
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (strlen($text) <= $maxLength) {
            return $text;
        }

        return substr($text, 0, $maxLength - 3) . '...';
    }

    private function formatNumber(int $number): string
    {
        if ($number >= 1_000_000) {
            return round($number / 1_000_000, 1) . 'M';
        }
        if ($number >= 1_000) {
            return round($number / 1_000, 1) . 'K';
        }

        return (string) $number;
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The Packagist action to perform',
                            'enum' => ['search', 'details'],
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search keywords. Required for search action.',
                        ],
                        'package' => [
                            'type' => 'string',
                            'description' => 'Full package name (vendor/package). Required for details action.',
                        ],
                        'tags' => [
                            'type' => 'string',
                            'description' => 'Filter search results by tag. Only for search action.',
                        ],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Filter by package type. Only for search action.',
                        ],
                        'page' => [
                            'type' => 'integer',
                            'description' => 'Page number for pagination. Default: 1.',
                            'minimum' => 1,
                        ],
                        'per_page' => [
                            'type' => 'integer',
                            'description' => 'Results per page (max 100). Default: 15.',
                            'minimum' => 1,
                            'maximum' => 100,
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }
}
