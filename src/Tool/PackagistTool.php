<?php

declare(strict_types=1);

namespace Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Tool that queries the Packagist API for package discovery and evaluation.
 *
 * Provides search, popularity browsing, detailed package info, download stats,
 * version listing, and security advisory lookup — all anonymous endpoints.
 *
 * Use this tool to find and evaluate packages before installing them with the
 * `composer` tool's `require` action.
 */
final class PackagistTool implements ToolInterface
{
    private const BASE_URL = 'https://packagist.org';
    private const REPO_URL = 'https://repo.packagist.org';
    private const DEFAULT_PER_PAGE = 15;
    private const MAX_VERSIONS_SHOWN = 10;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function name(): string
    {
        return 'packagist';
    }

    public function description(): string
    {
        return <<<'DESC'
            Search and explore packages on Packagist.org (the main Composer repository).

            Use this tool to discover, evaluate, and research PHP packages BEFORE installing
            them with the `composer` tool. This enables an informed install workflow:
            packagist search → packagist details → packagist advisories → composer require.

            Available actions:
            - search: Search packages by keyword, tag, or type. Returns name, description,
              downloads, and favers. Paginated.
            - popular: Browse the most popular packages sorted by weekly downloads. Paginated.
            - details: Get full metadata for a specific package including description,
              maintainers, download stats (total/monthly/daily), favers, and repository URL.
            - stats: Get focused download statistics for a specific package.
            - versions: List recent tagged releases with PHP requirements and release dates.
            - advisories: Check a specific package for known security vulnerabilities (CVEs).
              Always run this before installing a package.

            All endpoints are anonymous — no authentication required.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The Packagist action to perform',
                values: ['search', 'popular', 'details', 'stats', 'versions', 'advisories'],
                required: true,
            ),
            new StringParameter(
                name: 'query',
                description: 'Search keywords. Required for search action.',
                required: false,
            ),
            new StringParameter(
                name: 'package',
                description: 'Full package name (vendor/package). Required for details, stats, versions, advisories.',
                required: false,
            ),
            new StringParameter(
                name: 'tags',
                description: 'Filter search results by tag (e.g. "psr-3", "http"). Only for search action.',
                required: false,
            ),
            new StringParameter(
                name: 'type',
                description: 'Filter by package type (e.g. "library", "symfony-bundle", "composer-plugin"). Only for search action.',
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
            'popular' => $this->popular($input),
            'details' => $this->details($input),
            'stats' => $this->stats($input),
            'versions' => $this->versions($input),
            'advisories' => $this->advisories($input),
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
    private function popular(array $input): ToolResult
    {
        $params = [
            'per_page' => (int) ($input['per_page'] ?? self::DEFAULT_PER_PAGE),
        ];

        $page = (int) ($input['page'] ?? 1);
        if ($page > 1) {
            $params['page'] = $page;
        }

        $data = $this->apiGet(self::BASE_URL . '/explore/popular.json', $params);
        if ($data === null) {
            return ToolResult::error('Failed to reach Packagist popular packages API.');
        }

        $packages = $data['packages'] ?? [];
        $total = $data['total'] ?? 0;
        $next = $data['next'] ?? null;

        $output = "## Popular Packages (by weekly downloads)\n\n";
        $output .= "**Total:** {$total} | **Page:** {$page}\n\n";

        if ($packages === []) {
            $output .= "No packages returned.\n";
            return ToolResult::success($output);
        }

        $output .= "| # | Package | Downloads | Favers | Description |\n";
        $output .= "|---|---------|-----------|--------|-------------|\n";

        $rank = ($page - 1) * $params['per_page'];
        foreach ($packages as $pkg) {
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

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function stats(array $input): ToolResult
    {
        $package = $input['package'] ?? '';
        if ($package === '' || !str_contains($package, '/')) {
            return ToolResult::error('The `package` parameter (vendor/package) is required for the stats action.');
        }

        $data = $this->apiGet(self::BASE_URL . "/packages/{$package}/stats.json");
        if ($data === null) {
            return ToolResult::error("Failed to fetch stats for package '{$package}'.");
        }

        $downloads = $data['downloads'] ?? [];
        $versions = $data['versions'] ?? [];

        $output = "## Download Stats: {$package}\n\n";
        $output .= "| Period | Count |\n";
        $output .= "|--------|-------|\n";
        $output .= "| Total | " . $this->formatNumber($downloads['total'] ?? 0) . " |\n";
        $output .= "| Monthly | " . $this->formatNumber($downloads['monthly'] ?? 0) . " |\n";
        $output .= "| Daily | " . $this->formatNumber($downloads['daily'] ?? 0) . " |\n";

        if ($versions !== []) {
            $output .= "\n### Available Versions\n\n";
            $shown = array_slice($versions, 0, 20);
            $output .= implode(', ', $shown);
            if (count($versions) > 20) {
                $output .= " ... and " . (count($versions) - 20) . " more";
            }
            $output .= "\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function versions(array $input): ToolResult
    {
        $package = $input['package'] ?? '';
        if ($package === '' || !str_contains($package, '/')) {
            return ToolResult::error('The `package` parameter (vendor/package) is required for the versions action.');
        }

        $data = $this->apiGet(self::REPO_URL . "/p2/{$package}.json");
        if ($data === null) {
            return ToolResult::error("Failed to fetch version data for '{$package}'. It may not exist.");
        }

        $versions = $data['packages'][$package] ?? [];
        if ($versions === []) {
            return ToolResult::error("No version data found for '{$package}'.");
        }

        // Use composer/metadata-minifier expand if available, otherwise work with raw data
        $versions = $this->expandMinifiedVersions($versions, $package);

        // Filter to stable versions and take the most recent ones
        $stableVersions = array_filter(
            $versions,
            fn(array $v) => !str_contains($v['version'] ?? '', 'dev'),
        );

        if ($stableVersions === []) {
            $stableVersions = $versions;
        }

        $shown = array_slice($stableVersions, 0, self::MAX_VERSIONS_SHOWN);

        $output = "## Versions: {$package}\n\n";
        $output .= "| Version | PHP Requirement | Released |\n";
        $output .= "|---------|----------------|----------|\n";

        foreach ($shown as $v) {
            $version = $v['version'] ?? '?';
            $phpReq = $v['require']['php'] ?? 'any';
            $time = isset($v['time']) ? substr($v['time'], 0, 10) : 'unknown';
            $output .= "| {$version} | {$phpReq} | {$time} |\n";
        }

        $totalStable = count($stableVersions);
        if ($totalStable > self::MAX_VERSIONS_SHOWN) {
            $output .= "\n*Showing {" . self::MAX_VERSIONS_SHOWN . "} of {$totalStable} stable versions.*\n";
        }

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function advisories(array $input): ToolResult
    {
        $package = $input['package'] ?? '';
        if ($package === '' || !str_contains($package, '/')) {
            return ToolResult::error('The `package` parameter (vendor/package) is required for the advisories action.');
        }

        $data = $this->apiGet(
            self::BASE_URL . '/api/security-advisories/',
            ['packages[]' => $package],
        );
        if ($data === null) {
            return ToolResult::error("Failed to fetch security advisories for '{$package}'.");
        }

        $advisories = $data['advisories'][$package] ?? [];

        $output = "## Security Advisories: {$package}\n\n";

        if ($advisories === []) {
            $output .= "No known security vulnerabilities found. Package appears safe.\n";
            return ToolResult::success($output);
        }

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
     */
    /**
     * @param array<string, mixed> $versions
     */
    private function extractLatestVersion(array $versions): ?string
    {
        foreach ($versions as $version => $data) {
            if (!str_starts_with($version, 'dev-')) {
                return $version;
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
                            'enum' => ['search', 'popular', 'details', 'stats', 'versions', 'advisories'],
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search keywords. Required for search action.',
                        ],
                        'package' => [
                            'type' => 'string',
                            'description' => 'Full package name (vendor/package). Required for details, stats, versions, advisories.',
                        ],
                        'tags' => [
                            'type' => 'string',
                            'description' => 'Filter search results by tag. Only for search action.',
                        ],
                        'type' => [
                            'type' => 'string',
                            'description' => 'Filter by package type (e.g. "library"). Only for search action.',
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
