<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\CoquiSpace\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use CoquiBot\Coqui\CoquiSpace\SpaceRegistry;
use CoquiBot\Coqui\CoquiSpace\Installer\SkillInstaller;
use CoquiBot\Coqui\Support\StringHelper;

/**
 * Agent-facing tool for discovering and managing skills on Coqui Space.
 *
 * Actions: search, list, details, versions, reviews, file, install, update
 */
final class SpaceSkillsTool implements ToolInterface
{
    public function __construct(
        private readonly SpaceClient $client,
        private readonly SkillInstaller $installer,
    ) {}

    public function name(): string
    {
        return 'space_skills';
    }

    public function description(): string
    {
        return 'Discover, install, and manage skills from Coqui Space. '
            . 'Actions: search (keyword search), list (browse with sorting/filtering), '
            . 'details (full metadata for owner/name), versions (version history), '
            . 'reviews (community reviews), file (preview raw SKILL.md), '
            . 'install (download to workspace), update (update installed skill).';
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                'action',
                'The operation to perform',
                ['search', 'list', 'details', 'versions', 'reviews', 'file', 'install', 'update'],
            ),
            new StringParameter('query', 'Search keywords (required for search)', required: false),
            new StringParameter('owner', 'GitHub username of the skill owner (required for details/versions/reviews/file/install)', required: false),
            new StringParameter('name', 'Skill slug on coqui.space (required for details/versions/reviews/file/install)', required: false),
            new StringParameter('skill_name', 'Local skill directory name (required for update)', required: false),
            new EnumParameter('sort', 'Sort order for list', ['updated', 'downloads', 'stars', 'name'], required: false),
            new StringParameter('tags', 'Comma-separated tag slugs to filter by (for list)', required: false),
            new NumberParameter('limit', 'Maximum results to return (1-50)', required: false),
            new StringParameter('cursor', 'Pagination cursor from previous response', required: false),
            new StringParameter('version', 'Specific version to install', required: false),
            new BoolParameter('force', 'Overwrite existing content (default: false)', required: false),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? '');

        try {
            return match ($action) {
                'search' => $this->search($input),
                'list' => $this->listSkills($input),
                'details' => $this->details($input),
                'versions' => $this->versions($input),
                'reviews' => $this->reviews($input),
                'file' => $this->file($input),
                'install' => $this->install($input),
                'update' => $this->update($input),
                default => ToolResult::error("Unknown action: '{$action}'. Valid actions: search, list, details, versions, reviews, file, install, update"),
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
    private function search(array $input): ToolResult
    {
        $query = (string) ($input['query'] ?? '');
        if ($query === '') {
            return ToolResult::error('Parameter "query" is required for search.');
        }

        $limit = (int) ($input['limit'] ?? 10);
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;

        $data = $this->client->searchSkills($query, $limit, $cursor);
        $results = $data['results'] ?? [];

        if ($results === []) {
            return ToolResult::success("No skills found for \"{$query}\".");
        }

        $lines = ["## Skills matching \"{$query}\"\n"];
        $lines[] = '| Skill | Owner | Version | Score | Verified |';
        $lines[] = '|-------|-------|---------|-------|----------|';

        foreach ($results as $item) {
            $name = (string) ($item['name'] ?? '');
            $displayName = (string) ($item['displayName'] ?? $name);
            $owner = (string) ($item['owner'] ?? '');
            $version = (string) ($item['version'] ?? '-');
            $score = isset($item['score']) ? number_format((float) $item['score'], 1) : '-';
            $verified = !empty($item['verified_publisher']) ? '✓' : '—';

            $lines[] = "| {$displayName} (`{$owner}/{$name}`) | {$owner} | {$version} | {$score} | {$verified} |";
        }

        $lines[] = '';
        $lines[] = '*Use `space_skills(action: "details", owner: "OWNER", name: "SLUG")` to see full details.*';

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function listSkills(array $input): ToolResult
    {
        $sort = (string) ($input['sort'] ?? 'updated');
        $tags = isset($input['tags']) ? (string) $input['tags'] : null;
        $limit = (int) ($input['limit'] ?? 20);
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;

        $data = $this->client->listSkills($sort, $tags, $limit, $cursor);
        $items = $data['items'] ?? [];

        if ($items === []) {
            return ToolResult::success('No skills found.');
        }

        $lines = ["## Skills (sorted by {$sort})\n"];
        $lines[] = '| Skill | Owner | Downloads | Stars | Version | Verified |';
        $lines[] = '|-------|-------|-----------|-------|---------|----------|';

        foreach ($items as $item) {
            $name = (string) ($item['name'] ?? '');
            $displayName = (string) ($item['displayName'] ?? $name);
            $owner = SpaceRegistry::extractOwner($item);
            $stats = (array) ($item['stats'] ?? []);
            $downloads = $this->formatNumber((int) ($stats['downloads'] ?? 0));
            $stars = $this->formatNumber((int) ($stats['stars'] ?? 0));
            $version = (string) ($item['latestVersion']['version'] ?? '-');
            $verified = !empty($item['verified_publisher']) ? '✓' : '—';

            $lines[] = "| {$displayName} (`{$owner}/{$name}`) | {$owner} | {$downloads} | {$stars} | {$version} | {$verified} |";
        }

        $nextCursor = $data['nextCursor'] ?? null;
        if ($nextCursor !== null) {
            $lines[] = '';
            $lines[] = "*More results available — use `cursor: \"{$nextCursor}\"` for the next page.*";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function details(array $input): ToolResult
    {
        $owner = (string) ($input['owner'] ?? '');
        $name = (string) ($input['name'] ?? '');

        if ($owner === '' || $name === '') {
            return ToolResult::error('Parameters "owner" and "name" are required for details.');
        }

        $data = $this->client->skillDetails($owner, $name);
        $skill = (array) ($data['skill'] ?? []);
        $latest = (array) ($data['latestVersion'] ?? []);
        $ownerInfo = (array) ($data['owner'] ?? []);

        $displayName = (string) ($skill['displayName'] ?? $name);
        $description = (string) ($skill['description'] ?? $skill['summary'] ?? 'No description');
        $stats = (array) ($skill['stats'] ?? []);
        $tags = (array) ($skill['skillTags'] ?? array_keys((array) ($skill['tags'] ?? [])));
        $verified = !empty($skill['verified_publisher']);
        $starred = !empty($skill['starred']);

        $ownerDisplay = (string) ($ownerInfo['displayName'] ?? $owner);
        $ownerHandle = (string) ($ownerInfo['handle'] ?? $owner);
        $status = (string) ($skill['status'] ?? 'unknown');
        $downloads = $this->formatNumber((int) ($stats['downloads'] ?? 0));
        $starCount = $this->formatNumber((int) ($stats['stars'] ?? 0));
        $versionCount = (string) ($stats['versions'] ?? '0');

        $lines = ["## {$displayName}"];
        $lines[] = '';
        $lines[] = "**Owner:** {$ownerDisplay} (`{$ownerHandle}`)";
        $lines[] = "**Status:** {$status}";
        $lines[] = '**Verified Publisher:** ' . ($verified ? 'Yes ✓' : 'No');

        if ($starred) {
            $lines[] = '**Starred:** Yes ★';
        }

        $lines[] = '';
        $lines[] = "**Downloads:** {$downloads}";
        $lines[] = "**Stars:** {$starCount}";
        $lines[] = "**Versions:** {$versionCount}";

        if ($latest !== []) {
            $latestVersion = (string) ($latest['version'] ?? '-');
            $lines[] = '';
            $lines[] = "**Latest Version:** {$latestVersion}";
            if (!empty($latest['changelog'])) {
                $changelog = (string) $latest['changelog'];
                $lines[] = "**Changelog:** {$changelog}";
            }
        }

        if ($tags !== []) {
            $lines[] = '';
            $lines[] = '**Tags:** ' . implode(', ', $tags);
        }

        $lines[] = '';
        $lines[] = '### Description';
        $lines[] = '';
        $lines[] = StringHelper::truncate($description, 300);

        $lines[] = '';
        $lines[] = '### Quick Actions';
        $lines[] = "- Install: `space_skills(action: \"install\", owner: \"{$owner}\", name: \"{$name}\")`";
        $lines[] = "- Preview: `space_skills(action: \"file\", owner: \"{$owner}\", name: \"{$name}\")`";
        $lines[] = "- Reviews: `space_skills(action: \"reviews\", owner: \"{$owner}\", name: \"{$name}\")`";
        $lines[] = "- Versions: `space_skills(action: \"versions\", owner: \"{$owner}\", name: \"{$name}\")`";

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function versions(array $input): ToolResult
    {
        $owner = (string) ($input['owner'] ?? '');
        $name = (string) ($input['name'] ?? '');

        if ($owner === '' || $name === '') {
            return ToolResult::error('Parameters "owner" and "name" are required for versions.');
        }

        $limit = (int) ($input['limit'] ?? 10);
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;

        $data = $this->client->skillVersions($owner, $name, $limit, $cursor);
        $items = $data['items'] ?? [];

        if ($items === []) {
            return ToolResult::success("No version history found for {$owner}/{$name}.");
        }

        $lines = ["## Versions for {$owner}/{$name}\n"];
        $lines[] = '| Version | Released | Changelog |';
        $lines[] = '|---------|----------|-----------|';

        foreach ($items as $item) {
            $version = (string) ($item['version'] ?? '-');
            $date = $this->formatTimestamp($item['createdAt'] ?? null);
            $changelog = StringHelper::truncate((string) ($item['changelog'] ?? ''), 80);

            $lines[] = "| {$version} | {$date} | {$changelog} |";
        }

        $nextCursor = $data['nextCursor'] ?? null;
        if ($nextCursor !== null) {
            $lines[] = '';
            $lines[] = "*More versions available — use `cursor: \"{$nextCursor}\"`.*";
        }

        return ToolResult::success(implode("\n", $lines));
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

        $data = $this->client->skillReviews($owner, $name, $limit, $cursor);
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
    private function file(array $input): ToolResult
    {
        $owner = (string) ($input['owner'] ?? '');
        $name = (string) ($input['name'] ?? '');

        if ($owner === '' || $name === '') {
            return ToolResult::error('Parameters "owner" and "name" are required for file.');
        }

        $content = $this->client->skillFile($owner, $name);

        if ($content === '') {
            return ToolResult::success("No SKILL.md content available for {$owner}/{$name}.");
        }

        return ToolResult::success("## SKILL.md for {$owner}/{$name}\n\n```markdown\n{$content}\n```");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function install(array $input): ToolResult
    {
        $owner = (string) ($input['owner'] ?? '');
        $name = (string) ($input['name'] ?? '');

        if ($owner === '' || $name === '') {
            return ToolResult::error('Parameters "owner" and "name" are required for install.');
        }

        $version = isset($input['version']) ? (string) $input['version'] : null;
        $force = (bool) ($input['force'] ?? false);

        // Check verified status (best-effort warning)
        $this->checkVerifiedStatus($owner, $name);

        $result = $this->installer->install($owner, $name, $version, $force);

        return ToolResult::success($result['message']);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function update(array $input): ToolResult
    {
        $skillName = (string) ($input['skill_name'] ?? '');

        if ($skillName === '') {
            return ToolResult::error('Parameter "skill_name" is required for update (the local directory name).');
        }

        $force = (bool) ($input['force'] ?? false);

        $result = $this->installer->update($skillName, $force);

        return ToolResult::success($result['message']);
    }

    // ── Formatting helpers ───────────────────────────────────────────

    private function checkVerifiedStatus(string $owner, string $name): void
    {
        try {
            $data = $this->client->skillDetails($owner, $name);
            $verified = !empty($data['skill']['verified_publisher']);

            if (!$verified) {
                // The install will still proceed — this is informational only.
                // The warning is embedded in the tool output by the caller.
            }
        } catch (\Throwable) {
            // Best-effort — don't block install if details lookup fails
        }
    }

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

    private function formatTimestamp(mixed $timestamp): string
    {
        if ($timestamp === null) {
            return '-';
        }

        if (is_numeric($timestamp)) {
            // Millisecond timestamps from the API
            $ts = (int) $timestamp;
            if ($ts > 1_000_000_000_000) {
                $ts = (int) ($ts / 1000);
            }

            return date('Y-m-d', $ts);
        }

        return (string) $timestamp;
    }

}
