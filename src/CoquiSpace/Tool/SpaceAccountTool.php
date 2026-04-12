<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\CoquiSpace\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;

/**
 * Agent-facing tool for the authenticated user's account dashboard.
 *
 * Only registered when a COQUI_SPACE_API_TOKEN is set.
 *
 * Actions: profile, my_skills, my_toolkits, my_collections, my_submissions, my_installs, my_analytics, my_stars
 */
final class SpaceAccountTool implements ToolInterface
{
    public function __construct(
        private readonly SpaceClient $client,
    ) {}

    public function name(): string
    {
        return 'coqui_space_account';
    }

    public function description(): string
    {
        return 'View your Coqui Space account dashboard. '
            . 'Actions: profile (your profile + capabilities), '
            . 'my_skills (your published skills), my_toolkits (your toolkits), '
            . 'my_collections (your collections), my_submissions (submission status), '
            . 'my_installs (install history), my_analytics (download/star stats), '
            . 'my_stars (starred items).';
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                'action',
                'The operation to perform',
                ['profile', 'my_skills', 'my_toolkits', 'my_collections', 'my_submissions', 'my_installs', 'my_analytics', 'my_stars'],
            ),
            new NumberParameter('limit', 'Maximum results (1-100)', required: false),
            new StringParameter('cursor', 'Pagination cursor', required: false),
            new NumberParameter('days', 'Days for analytics (1-365, default 30)', required: false),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? '');

        try {
            return match ($action) {
                'profile' => $this->profile(),
                'my_skills' => $this->mySkills($input),
                'my_toolkits' => $this->myToolkits(),
                'my_collections' => $this->myCollections(),
                'my_submissions' => $this->mySubmissions(),
                'my_installs' => $this->myInstalls($input),
                'my_analytics' => $this->myAnalytics($input),
                'my_stars' => $this->myStars($input),
                default => ToolResult::error("Unknown action: '{$action}'. Valid: profile, my_skills, my_toolkits, my_collections, my_submissions, my_installs, my_analytics, my_stars"),
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

    private function profile(): ToolResult
    {
        $data = $this->client->me();

        $handle = (string) ($data['handle'] ?? '');
        $displayName = (string) ($data['displayName'] ?? $handle);
        $role = (string) ($data['role'] ?? 'user');
        $verified = !empty($data['verified']);
        $canPublish = !empty($data['canPublish']);
        $canModerate = !empty($data['canModerate']);

        $lines = ["## Profile: {$displayName} (@{$handle})"];
        $lines[] = '';
        $lines[] = "**Role:** {$role}";
        $lines[] = '**Verified:** ' . ($verified ? 'Yes ✓' : 'No');
        $lines[] = '**Can Publish:** ' . ($canPublish ? 'Yes' : 'No');
        $lines[] = '**Can Moderate:** ' . ($canModerate ? 'Yes' : 'No');

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function mySkills(array $input): ToolResult
    {
        $limit = (int) ($input['limit'] ?? 20);
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;

        $data = $this->client->mySkills($limit, $cursor);
        $items = $data['items'] ?? [];

        if ($items === []) {
            return ToolResult::success('You have no skills on Coqui Space.');
        }

        $lines = ['## Your Skills', ''];
        $lines[] = '| Name | Status | Downloads | Stars |';
        $lines[] = '|------|--------|-----------|-------|';

        foreach ($items as $item) {
            $name = (string) ($item['displayName'] ?? $item['name'] ?? '');
            $status = (string) ($item['status'] ?? '');
            $stats = (array) ($item['stats'] ?? []);
            $downloads = $this->formatNumber((int) ($stats['downloads'] ?? 0));
            $stars = $this->formatNumber((int) ($stats['stars'] ?? 0));
            $lines[] = "| {$name} | {$status} | {$downloads} | {$stars} |";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    private function myToolkits(): ToolResult
    {
        $data = $this->client->myToolkits();
        $items = $data['items'] ?? [];

        if ($items === []) {
            return ToolResult::success('You have no toolkits on Coqui Space.');
        }

        $lines = ['## Your Toolkits', ''];
        $lines[] = '| Package | Status | Downloads | Favers |';
        $lines[] = '|---------|--------|-----------|--------|';

        foreach ($items as $item) {
            $name = (string) ($item['packageName'] ?? $item['name'] ?? '');
            $status = (string) ($item['status'] ?? '');
            $stats = (array) ($item['stats'] ?? []);
            $downloads = $this->formatNumber((int) ($stats['downloads'] ?? 0));
            $favers = $this->formatNumber((int) ($stats['favers'] ?? 0));
            $lines[] = "| `{$name}` | {$status} | {$downloads} | {$favers} |";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    private function myCollections(): ToolResult
    {
        $data = $this->client->myCollections();
        $items = $data['items'] ?? [];

        if ($items === []) {
            return ToolResult::success('You have no collections.');
        }

        $lines = ['## Your Collections', ''];

        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            $name = (string) ($item['name'] ?? '');
            $count = (string) ($item['itemCount'] ?? '0');
            $visibility = !empty($item['isPublic']) ? 'public' : 'private';
            $lines[] = "- **{$name}** (#{$id}, {$count} items, {$visibility})";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    private function mySubmissions(): ToolResult
    {
        $data = $this->client->mySubmissions();
        $items = $data['items'] ?? $data['submissions'] ?? [];

        if ($items === []) {
            return ToolResult::success('You have no submissions.');
        }

        $lines = ['## Your Submissions', ''];
        $lines[] = '| ID | Type | Status | Source |';
        $lines[] = '|----|------|--------|--------|';

        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            $type = (string) ($item['type'] ?? '');
            $status = (string) ($item['status'] ?? '');
            $source = (string) ($item['sourceUrl'] ?? '');
            $lines[] = "| {$id} | {$type} | {$status} | {$source} |";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function myInstalls(array $input): ToolResult
    {
        $limit = (int) ($input['limit'] ?? 50);
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;

        $data = $this->client->myInstalls($limit, $cursor);
        $items = $data['items'] ?? [];

        if ($items === []) {
            return ToolResult::success('No install activity found.');
        }

        $lines = ['## Your Install Activity', ''];
        $lines[] = '| Item | Type | Installed |';
        $lines[] = '|------|------|-----------|';

        foreach ($items as $item) {
            $name = (string) ($item['name'] ?? $item['displayName'] ?? '');
            $type = (string) ($item['type'] ?? $item['entityType'] ?? '');
            $date = isset($item['createdAt']) ? $this->formatTimestamp($item['createdAt']) : '-';
            $lines[] = "| {$name} | {$type} | {$date} |";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function myAnalytics(array $input): ToolResult
    {
        $days = (int) ($input['days'] ?? 30);

        $data = $this->client->myAnalytics($days);

        $totalDownloads = $this->formatNumber((int) ($data['totalDownloads'] ?? 0));
        $totalStars = $this->formatNumber((int) ($data['totalStars'] ?? 0));

        $lines = ["## Your Analytics (last {$days} days)"];
        $lines[] = '';
        $lines[] = "**Total Downloads:** {$totalDownloads}";
        $lines[] = "**Total Stars:** {$totalStars}";

        $daily = (array) ($data['dailyInstalls'] ?? []);
        if ($daily !== []) {
            $lines[] = '';
            $lines[] = '### Daily Installs (last 7 days)';
            $recent = array_slice($daily, -7);
            foreach ($recent as $day) {
                $date = (string) ($day['date'] ?? '');
                $count = (int) ($day['count'] ?? 0);
                $bar = str_repeat('█', min($count, 30));
                $lines[] = "{$date}: {$bar} {$count}";
            }
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function myStars(array $input): ToolResult
    {
        $limit = (int) ($input['limit'] ?? 20);
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;

        $data = $this->client->myStars($limit, $cursor);
        $items = $data['items'] ?? [];

        if ($items === []) {
            return ToolResult::success('You have no starred items.');
        }

        $lines = ['## Your Stars', ''];

        foreach ($items as $item) {
            $type = (string) ($item['entityType'] ?? $item['type'] ?? '');
            $name = (string) ($item['name'] ?? $item['displayName'] ?? '');
            $owner = (string) ($item['owner'] ?? '');
            $lines[] = "- ★ [{$type}] {$name}" . ($owner !== '' ? " by {$owner}" : '');
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

    private function formatTimestamp(mixed $timestamp): string
    {
        if ($timestamp === null) {
            return '-';
        }

        if (is_numeric($timestamp)) {
            $ts = (int) $timestamp;
            if ($ts > 1_000_000_000_000) {
                $ts = (int) ($ts / 1000);
            }

            return date('Y-m-d', $ts);
        }

        return (string) $timestamp;
    }
}
