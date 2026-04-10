<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\CoquiSpace\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use CoquiBot\Coqui\CoquiSpace\SpaceRegistry;
use CoquiBot\Coqui\CoquiSpace\Installer\ToolkitInstaller;
use CoquiBot\Coqui\Support\StringHelper;

/**
 * Agent-facing tool for discovering and managing toolkits on Coqui Space.
 *
 * Actions: search, list, popular, details, reviews, install, update
 */
final class SpaceToolkitsTool implements ToolInterface
{
    public function __construct(
        private readonly SpaceClient $client,
        private readonly ToolkitInstaller $installer,
    ) {}

    public function name(): string
    {
        return 'space_toolkits';
    }

    public function description(): string
    {
        return 'Discover, install, and manage Composer toolkits from Coqui Space. '
            . 'Actions: search (keyword search), list (browse with sorting/tags — cursor-paginated), '
            . 'popular (browse popular — page-based, legacy), '
            . 'details (full metadata by package name or owner/name), '
            . 'reviews (community reviews), install (composer require into workspace), '
            . 'update (composer update).';
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                'action',
                'The operation to perform',
                ['search', 'list', 'popular', 'details', 'reviews', 'install', 'update'],
            ),
            new StringParameter('query', 'Search keywords (required for search)', required: false),
            new StringParameter('package', 'Full Composer package name in vendor/package format (for details/install/update)', required: false),
            new StringParameter('owner', 'GitHub username of the toolkit owner (for details/reviews)', required: false),
            new StringParameter('name', 'Toolkit slug on coqui.space (for details/reviews)', required: false),
            new StringParameter('tags', 'Comma-separated tag slugs to filter by (for list)', required: false),
            new EnumParameter('sort', 'Sort order for list', ['downloads', 'favers', 'newest', 'name'], required: false),
            new NumberParameter('per_page', 'Results per page for search/popular (1-100, default 15)', required: false),
            new NumberParameter('page', 'Page number for search/popular (default 1)', required: false),
            new StringParameter('version', 'Version constraint for install (e.g. ^1.0)', required: false),
            new NumberParameter('limit', 'Maximum results (1-100 for list, 1-10 for reviews)', required: false),
            new StringParameter('cursor', 'Pagination cursor for list/reviews', required: false),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? '');

        try {
            return match ($action) {
                'search' => $this->search($input),
                'list' => $this->listToolkits($input),
                'popular' => $this->popular($input),
                'details' => $this->details($input),
                'reviews' => $this->reviews($input),
                'install' => $this->install($input),
                'update' => $this->update($input),
                default => ToolResult::error("Unknown action: '{$action}'. Valid actions: search, list, popular, details, reviews, install, update"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    public function toFunctionSchema(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->parameters() as $param) {
            $properties[$param->name] = $param->toSchema();
            if ($param->required) {
                $required[] = $param->name;
            }
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                ],
            ],
        ];
    }

    // ── Actions ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function listToolkits(array $input): ToolResult
    {
        $sort = (string) ($input['sort'] ?? 'downloads');
        $tags = isset($input['tags']) ? (string) $input['tags'] : null;
        $limit = (int) ($input['limit'] ?? 20);
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;

        $data = $this->client->listToolkits($sort, $tags, $limit, $cursor);
        $items = $data['items'] ?? [];

        if ($items === []) {
            return ToolResult::success('No toolkits found.');
        }

        $lines = ["## Toolkits (sorted by {$sort})\n"];
        $lines[] = '| Package | Owner | Downloads | Favers | Tags | Verified |';
        $lines[] = '|---------|-------|-----------|--------|------|----------|';

        foreach ($items as $item) {
            $name = (string) ($item['packageName'] ?? $item['name'] ?? '');
            $owner = SpaceRegistry::extractOwner($item);
            $downloads = $this->formatNumber((int) ($item['downloads'] ?? 0));
            $favers = $this->formatNumber((int) ($item['favers'] ?? 0));
            $verified = !empty($item['verified_publisher']) ? '✓' : '—';
            $itemTags = (array) ($item['tags'] ?? []);
            $tagStr = $itemTags !== [] ? implode(', ', array_slice($itemTags, 0, 3)) : '—';

            $lines[] = "| `{$name}` | {$owner} | {$downloads} | {$favers} | {$tagStr} | {$verified} |";
        }

        $nextCursor = $data['nextCursor'] ?? null;
        if ($nextCursor !== null) {
            $lines[] = '';
            $lines[] = "*More results available — use `cursor: \"{$nextCursor}\"` for the next page.*";
        }

        $lines[] = '';
        $lines[] = '*Use `space_toolkits(action: "details", package: "vendor/package")` to see full details.*';

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function search(array $input): ToolResult
    {
        $query = (string) ($input['query'] ?? '');
        if ($query === '') {
            return ToolResult::error('Parameter "query" is required for search.');
        }

        $perPage = (int) ($input['per_page'] ?? 15);
        $page = (int) ($input['page'] ?? 1);

        $data = $this->client->searchToolkits($query, $perPage, $page);
        $results = $data['results'] ?? [];
        $total = (int) ($data['total'] ?? count($results));

        if ($results === []) {
            return ToolResult::success("No toolkits found for \"{$query}\".");
        }

        $lines = ["## Toolkits matching \"{$query}\" ({$total} total)\n"];
        $lines[] = '| Package | Downloads | Favers | Verified | Description |';
        $lines[] = '|---------|-----------|--------|----------|-------------|';

        foreach ($results as $item) {
            $name = (string) ($item['name'] ?? '');
            $downloads = $this->formatNumber((int) ($item['downloads'] ?? 0));
            $favers = $this->formatNumber((int) ($item['favers'] ?? 0));
            $verified = !empty($item['verified_publisher']) ? '✓' : '—';
            $desc = StringHelper::truncate((string) ($item['description'] ?? ''), 60);

            $lines[] = "| `{$name}` | {$downloads} | {$favers} | {$verified} | {$desc} |";
        }

        $next = $data['next'] ?? null;
        if ($next !== null) {
            $nextPage = $page + 1;
            $lines[] = '';
            $lines[] = "*More results — use `page: {$nextPage}` for the next page.*";
        }

        $lines[] = '';
        $lines[] = '*Use `space_toolkits(action: "details", package: "vendor/package")` to see full details.*';

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function popular(array $input): ToolResult
    {
        $perPage = (int) ($input['per_page'] ?? 15);
        $page = (int) ($input['page'] ?? 1);

        $data = $this->client->popularToolkits($perPage, $page);
        $packages = $data['packages'] ?? [];
        $total = (int) ($data['total'] ?? count($packages));

        if ($packages === []) {
            return ToolResult::success('No popular toolkits found.');
        }

        $lines = ["## Popular Toolkits ({$total} total)\n"];
        $lines[] = '| # | Package | Downloads | Favers | Verified | Description |';
        $lines[] = '|---|---------|-----------|--------|----------|-------------|';

        $rank = ($page - 1) * $perPage;
        foreach ($packages as $item) {
            $rank++;
            $name = (string) ($item['name'] ?? '');
            $downloads = $this->formatNumber((int) ($item['downloads'] ?? 0));
            $favers = $this->formatNumber((int) ($item['favers'] ?? 0));
            $verified = !empty($item['verified_publisher']) ? '✓' : '—';
            $desc = StringHelper::truncate((string) ($item['description'] ?? ''), 50);

            $lines[] = "| {$rank} | `{$name}` | {$downloads} | {$favers} | {$verified} | {$desc} |";
        }

        $next = $data['next'] ?? null;
        if ($next !== null) {
            $nextPage = $page + 1;
            $lines[] = '';
            $lines[] = "*More results — use `page: {$nextPage}` for the next page.*";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function details(array $input): ToolResult
    {
        $package = (string) ($input['package'] ?? '');
        $owner = (string) ($input['owner'] ?? '');
        $name = (string) ($input['name'] ?? '');

        // Support both package (vendor/name) and owner/name lookup
        if ($package !== '' && str_contains($package, '/')) {
            return $this->detailsByPackage($package);
        }

        if ($owner !== '' && $name !== '') {
            return $this->detailsByOwnerName($owner, $name);
        }

        return ToolResult::error('Provide either "package" (vendor/name format) or both "owner" and "name" for details.');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function reviews(array $input): ToolResult
    {
        $owner = (string) ($input['owner'] ?? '');
        $name = (string) ($input['name'] ?? '');

        if ($owner === '' || $name === '') {
            return ToolResult::error('Parameters "owner" and "name" are required for reviews.');
        }

        $limit = (int) ($input['limit'] ?? 10);
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;

        $data = $this->client->toolkitReviews($owner, $name, $limit, $cursor);
        $items = $data['items'] ?? [];
        $stats = (array) ($data['stats'] ?? []);

        $avgRating = isset($stats['average']) ? number_format((float) $stats['average'], 1) : '-';
        $totalReviews = (int) ($stats['count'] ?? count($items));

        $lines = ["## Reviews for {$owner}/{$name}"];
        $lines[] = '';
        $lines[] = "**Average Rating:** {$avgRating}/5 ({$totalReviews} reviews)";

        if ($items === []) {
            $lines[] = '';
            $lines[] = 'No reviews yet.';
        } else {
            $lines[] = '';
            foreach ($items as $review) {
                $rating = (int) ($review['rating'] ?? 0);
                $title = (string) ($review['title'] ?? '');
                $body = (string) ($review['body'] ?? '');
                $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);

                $lines[] = "**{$stars}** " . ($title !== '' ? "— {$title}" : '');
                if ($body !== '') {
                    $lines[] = StringHelper::truncate($body, 200);
                }
                $lines[] = '';
            }
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function install(array $input): ToolResult
    {
        $package = (string) ($input['package'] ?? '');
        if ($package === '' || !str_contains($package, '/')) {
            return ToolResult::error('Parameter "package" is required in vendor/package format for install.');
        }

        $version = isset($input['version']) ? (string) $input['version'] : null;

        $result = $this->installer->install($package, $version);

        return ToolResult::success($result['message']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function update(array $input): ToolResult
    {
        $package = (string) ($input['package'] ?? '');
        if ($package === '' || !str_contains($package, '/')) {
            return ToolResult::error('Parameter "package" is required in vendor/package format for update.');
        }

        $result = $this->installer->update($package);

        return ToolResult::success($result['message']);
    }

    // ── Detail helpers ───────────────────────────────────────────────

    private function detailsByPackage(string $package): ToolResult
    {
        [$vendor, $pkg] = explode('/', $package, 2);

        $data = $this->client->toolkitPackage($vendor, $pkg);
        $pkg = (array) ($data['package'] ?? []);

        $name = (string) ($pkg['name'] ?? $package);
        $description = (string) ($pkg['description'] ?? 'No description');
        $repository = (string) ($pkg['repository'] ?? '-');
        $type = (string) ($pkg['type'] ?? 'library');
        $abandoned = $pkg['abandoned'] ?? false;
        $verified = !empty($pkg['verified_publisher']);

        $downloads = (array) ($pkg['downloads'] ?? []);
        $totalDl = $this->formatNumber((int) ($downloads['total'] ?? 0));
        $monthlyDl = $this->formatNumber((int) ($downloads['monthly'] ?? 0));
        $dailyDl = $this->formatNumber((int) ($downloads['daily'] ?? 0));
        $favers = $this->formatNumber((int) ($pkg['favers'] ?? 0));

        $lines = ["## {$name}"];
        $lines[] = '';
        $lines[] = StringHelper::truncate($description, 300);
        $lines[] = '';
        $lines[] = "**Type:** {$type}";
        $lines[] = "**Repository:** {$repository}";
        $lines[] = '**Verified Publisher:** ' . ($verified ? 'Yes ✓' : 'No');

        if ($abandoned) {
            $lines[] = '';
            $lines[] = '⚠️ **This package is abandoned.**' . (is_string($abandoned) ? " Suggested replacement: `{$abandoned}`" : '');
        }

        $lines[] = '';
        $lines[] = '### Downloads';
        $lines[] = "| Total | Monthly | Daily | Favers |";
        $lines[] = '|-------|---------|-------|--------|';
        $lines[] = "| {$totalDl} | {$monthlyDl} | {$dailyDl} | {$favers} |";

        // Show recent versions
        $versions = (array) ($pkg['versions'] ?? []);
        if ($versions !== []) {
            $lines[] = '';
            $lines[] = '### Recent Versions';
            $shown = 0;
            foreach ($versions as $v => $info) {
                if ($shown >= 5 || str_starts_with((string) $v, 'dev-')) {
                    continue;
                }
                $lines[] = "- **{$v}**";
                $shown++;
            }
        }

        $lines[] = '';
        $lines[] = '### Quick Actions';
        $lines[] = "- Install: `space_toolkits(action: \"install\", package: \"{$name}\")`";

        return ToolResult::success(implode("\n", $lines));
    }

    private function detailsByOwnerName(string $owner, string $name): ToolResult
    {
        $data = $this->client->toolkitDetails($owner, $name);
        $toolkit = (array) ($data['toolkit'] ?? []);

        $packageName = (string) ($toolkit['packageName'] ?? '');
        $displayName = (string) ($toolkit['displayName'] ?? $name);
        $description = (string) ($toolkit['description'] ?? 'No description');
        $repository = (string) ($toolkit['repository'] ?? '-');
        $downloads = $this->formatNumber((int) ($toolkit['downloads'] ?? 0));
        $favers = $this->formatNumber((int) ($toolkit['favers'] ?? 0));
        $verified = !empty($toolkit['verified_publisher']);
        $featured = !empty($toolkit['featured']);
        $tags = (array) ($toolkit['tags'] ?? []);

        $ownerInfo = (array) ($toolkit['owner'] ?? []);

        $lines = ["## {$displayName}"];
        $lines[] = '';
        $lines[] = StringHelper::truncate($description, 300);
        $lines[] = '';
        $ownerDisplay = (string) ($ownerInfo['displayName'] ?? $owner);
        $ownerHandle = (string) ($ownerInfo['handle'] ?? $owner);

        $lines[] = "**Package:** `{$packageName}`";
        $lines[] = "**Owner:** {$ownerDisplay} (`{$ownerHandle}`)";
        $lines[] = "**Repository:** {$repository}";
        $lines[] = '**Verified Publisher:** ' . ($verified ? 'Yes ✓' : 'No');

        if ($featured) {
            $lines[] = '**Featured:** Yes ⭐';
        }

        $lines[] = '';
        $lines[] = "**Downloads:** {$downloads}";
        $lines[] = "**Favers:** {$favers}";

        if ($tags !== []) {
            $lines[] = '';
            $lines[] = '**Tags:** ' . implode(', ', $tags);
        }

        // Credentials info
        $credentials = $toolkit['credentials'] ?? null;
        if ($credentials !== null && is_array($credentials) && $credentials !== []) {
            $lines[] = '';
            $lines[] = '### Credentials Required';
            foreach ($credentials as $key => $desc) {
                $lines[] = "- `{$key}`: {$desc}";
            }
        }

        $lines[] = '';
        $lines[] = '### Quick Actions';
        if ($packageName !== '') {
            $lines[] = "- Install: `space_toolkits(action: \"install\", package: \"{$packageName}\")`";
            $lines[] = "- Reviews: `space_toolkits(action: \"reviews\", owner: \"{$owner}\", name: \"{$name}\")`";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    private function formatNumber(int $value): string
    {
        if ($value >= 1_000_000) {
            return number_format($value / 1_000_000, 1) . 'M';
        }
        if ($value >= 1_000) {
            return number_format($value / 1_000, 1) . 'K';
        }

        return (string) $value;
    }

}
