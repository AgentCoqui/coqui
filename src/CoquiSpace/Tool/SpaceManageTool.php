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

/**
 * Agent-facing tool for social actions and community features on Coqui Space.
 *
 * Actions: star, unstar, submit, tags, search_all,
 *          collections, review, notifications, health
 *
 * Local lifecycle management (installed, disable, enable, remove) has moved
 * to coqui_toolkits and coqui_skills tools.
 */
final class SpaceManageTool implements ToolInterface
{
    public function __construct(
        private readonly SpaceClient $client,
    ) {}

    public function name(): string
    {
        return 'coqui_space';
    }

    public function description(): string
    {
        return 'Interact with Coqui Space community features. '
            . 'Actions: star/unstar (community feedback — requires auth), '
            . 'submit (submit a URL for review on coqui.space), '
            . 'tags (discover available tags for filtering), '
            . 'search_all (unified search across skills and toolkits), '
            . 'collections (manage collections — sub_action: list/create/details/update/delete/add_item/remove_item), '
            . 'review (post a review — requires auth), '
            . 'notifications (sub_action: list/mark_read/mark_all_read — requires auth), '
            . 'health (check API status). '
            . 'For local management (list/disable/enable/remove), use coqui_toolkits or coqui_skills instead.';
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                'action',
                'The operation to perform',
                ['star', 'unstar', 'submit', 'tags', 'search_all', 'collections', 'review', 'notifications', 'health'],
            ),
            new StringParameter('sub_action', 'Sub-action for collections/notifications', required: false),
            new StringParameter('name', 'Content identifier: owner/name for skills, vendor/package for toolkits.', required: false),
            new EnumParameter('type', 'Content type filter (for tags)', ['all', 'skills', 'toolkits', 'skill', 'toolkit'], required: false),
            new EnumParameter('entity_type', 'Entity type for star/unstar/review/collection items', ['skill', 'toolkit'], required: false),
            new StringParameter('owner', 'GitHub username (required for star/unstar/review)', required: false),
            new StringParameter('source_url', 'Repository or source URL (required for submit)', required: false),
            new StringParameter('notes', 'Additional notes for submission', required: false),
            new StringParameter('query', 'Search keywords (required for search_all)', required: false),
            new NumberParameter('limit', 'Maximum results (1-50)', required: false),
            new StringParameter('cursor', 'Pagination cursor', required: false),
            // Collection fields
            new NumberParameter('collection_id', 'Collection ID (for details/update/delete/add_item/remove_item)', required: false),
            new StringParameter('collection_name', 'Collection name (for create/update)', required: false),
            new StringParameter('description', 'Description (for collection create/update)', required: false),
            new BoolParameter('is_public', 'Public visibility for collection (default: true)', required: false),
            new NumberParameter('entity_id', 'Entity ID for collection item', required: false),
            new StringParameter('note', 'Note for collection item', required: false),
            // Review fields
            new NumberParameter('rating', 'Rating from 1 to 5 (required for review)', required: false),
            new StringParameter('title', 'Review title', required: false),
            new StringParameter('body', 'Review body', required: false),
            // Notification fields
            new NumberParameter('notification_id', 'Notification ID (for mark_read)', required: false),
            new BoolParameter('unread', 'Filter to unread only (for notifications list)', required: false),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? '');

        try {
            return match ($action) {
                'star' => $this->star($input),
                'unstar' => $this->unstar($input),
                'submit' => $this->submit($input),
                'tags' => $this->tags($input),
                'search_all' => $this->searchAll($input),
                'collections' => $this->collections($input),
                'review' => $this->review($input),
                'notifications' => $this->notifications($input),
                'health' => $this->health(),
                default => ToolResult::error("Unknown action: '{$action}'. Valid: star, unstar, submit, tags, search_all, collections, review, notifications, health"),
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
    private function star(array $input): ToolResult
    {
        $entityType = (string) ($input['entity_type'] ?? '');
        $owner = (string) ($input['owner'] ?? '');
        $name = (string) ($input['name'] ?? '');

        if ($entityType === '' || $owner === '' || $name === '') {
            return ToolResult::error('Parameters "entity_type", "owner", and "name" are required for star.');
        }

        $result = $this->client->star($entityType, $owner, $name);

        $alreadyStarred = !empty($result['alreadyStarred']);

        return ToolResult::success(
            $alreadyStarred
                ? "You already starred {$entityType} `{$owner}/{$name}`."
                : "Starred {$entityType} `{$owner}/{$name}` ★",
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function unstar(array $input): ToolResult
    {
        $entityType = (string) ($input['entity_type'] ?? '');
        $owner = (string) ($input['owner'] ?? '');
        $name = (string) ($input['name'] ?? '');

        if ($entityType === '' || $owner === '' || $name === '') {
            return ToolResult::error('Parameters "entity_type", "owner", and "name" are required for unstar.');
        }

        $result = $this->client->unstar($entityType, $owner, $name);

        $alreadyUnstarred = !empty($result['alreadyUnstarred']);

        return ToolResult::success(
            $alreadyUnstarred
                ? "{$entityType} `{$owner}/{$name}` was not starred."
                : "Unstarred {$entityType} `{$owner}/{$name}`.",
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function submit(array $input): ToolResult
    {
        $type = (string) ($input['type'] ?? '');
        $sourceUrl = (string) ($input['source_url'] ?? '');
        $notes = isset($input['notes']) ? (string) $input['notes'] : null;

        if ($type === '' || !in_array($type, ['skill', 'toolkit'], true)) {
            return ToolResult::error('Parameter "type" is required for submit (must be "skill" or "toolkit").');
        }

        if ($sourceUrl === '') {
            return ToolResult::error('Parameter "source_url" is required for submit.');
        }

        $result = $this->client->createSubmission($type, $sourceUrl, $notes);
        $id = $result['id'] ?? 'unknown';

        return ToolResult::success(
            "Submission created (#" . (string) $id . "). "
            . "A moderator will review your {$type} at `{$sourceUrl}`. "
            . 'You can track the status from your dashboard on coqui.space.',
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function tags(array $input): ToolResult
    {
        $type = (string) ($input['type'] ?? 'all');

        // Normalize type to the API expected values
        $apiType = match ($type) {
            'skill', 'skills' => 'skills',
            'toolkit', 'toolkits' => 'toolkits',
            default => 'all',
        };

        $data = $this->client->getTags($apiType);

        $skillTags = (array) ($data['skills'] ?? []);
        $toolkitTags = (array) ($data['toolkits'] ?? []);

        $lines = ['## Available Tags'];

        if ($apiType === 'all' || $apiType === 'skills') {
            $lines[] = '';
            $lines[] = '### Skill Tags';
            if ($skillTags === []) {
                $lines[] = 'No skill tags available.';
            } else {
                $slugs = array_map(static fn(array $tag): string => (string) ($tag['slug'] ?? $tag['name'] ?? ''), $skillTags);
                $lines[] = implode(', ', array_filter($slugs));
            }
        }

        if ($apiType === 'all' || $apiType === 'toolkits') {
            $lines[] = '';
            $lines[] = '### Toolkit Tags';
            if ($toolkitTags === []) {
                $lines[] = 'No toolkit tags available.';
            } else {
                $slugs = array_map(static fn(array $tag): string => (string) ($tag['slug'] ?? $tag['name'] ?? ''), $toolkitTags);
                $lines[] = implode(', ', array_filter($slugs));
            }
        }

        $lines[] = '';
        $lines[] = '*Use tags to filter results: `coqui_space_skills(action: "list", tags: "tag-slug")` or `coqui_space_toolkits(action: "list", tags: "tag-slug")`*';

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function searchAll(array $input): ToolResult
    {
        $query = (string) ($input['query'] ?? '');
        if ($query === '') {
            return ToolResult::error('Parameter "query" is required for search_all.');
        }

        $limit = (int) ($input['limit'] ?? 10);
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;

        $data = $this->client->searchAll($query, $limit, $cursor);

        $skillResults = (array) ($data['skills']['results'] ?? []);
        $toolkitResults = (array) ($data['toolkits']['results'] ?? []);
        $toolkitTotal = (int) ($data['toolkits']['total'] ?? count($toolkitResults));

        if ($skillResults === [] && $toolkitResults === []) {
            return ToolResult::success("No results found for \"{$query}\".");
        }

        $lines = ["## Search results for \"{$query}\"\n"];

        // Skills section
        $lines[] = '### Skills';
        if ($skillResults === []) {
            $lines[] = 'No matching skills.';
        } else {
            $lines[] = '';
            $lines[] = '| Skill | Owner | Version | Verified |';
            $lines[] = '|-------|-------|---------|----------|';

            foreach ($skillResults as $item) {
                $name = (string) ($item['name'] ?? '');
                $displayName = (string) ($item['displayName'] ?? $name);
                $owner = (string) ($item['owner'] ?? '');
                $version = (string) ($item['version'] ?? '-');
                $verified = !empty($item['verified_publisher']) ? '✓' : '—';

                $lines[] = "| {$displayName} (`{$owner}/{$name}`) | {$owner} | {$version} | {$verified} |";
            }
        }

        // Toolkits section
        $lines[] = '';
        $lines[] = "### Toolkits ({$toolkitTotal} total)";
        if ($toolkitResults === []) {
            $lines[] = 'No matching toolkits.';
        } else {
            $lines[] = '';
            $lines[] = '| Package | Downloads | Favers | Verified |';
            $lines[] = '|---------|-----------|--------|----------|';

            foreach ($toolkitResults as $item) {
                $name = (string) ($item['name'] ?? '');
                $downloads = $this->formatNumber((int) ($item['downloads'] ?? 0));
                $favers = $this->formatNumber((int) ($item['favers'] ?? 0));
                $verified = !empty($item['verified_publisher']) ? '✓' : '—';

                $lines[] = "| `{$name}` | {$downloads} | {$favers} | {$verified} |";
            }
        }

        $lines[] = '';
        $lines[] = '*Use entity-specific tools for more details and pagination.*';

        return ToolResult::success(implode("\n", $lines));
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function collections(array $input): ToolResult
    {
        $subAction = (string) ($input['sub_action'] ?? 'list');

        return match ($subAction) {
            'list' => $this->collectionsList($input),
            'create' => $this->collectionsCreate($input),
            'details' => $this->collectionsDetails($input),
            'update' => $this->collectionsUpdate($input),
            'delete' => $this->collectionsDelete($input),
            'add_item' => $this->collectionsAddItem($input),
            'remove_item' => $this->collectionsRemoveItem($input),
            default => ToolResult::error("Unknown collections sub_action: '{$subAction}'. Valid: list, create, details, update, delete, add_item, remove_item"),
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private function collectionsList(array $input): ToolResult
    {
        $limit = (int) ($input['limit'] ?? 20);
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;

        $data = $this->client->listCollections($limit, $cursor);
        $items = $data['items'] ?? [];

        if ($items === []) {
            return ToolResult::success('No public collections found.');
        }

        $lines = ['## Public Collections', ''];
        $lines[] = '| ID | Name | Items | Visibility | Owner |';
        $lines[] = '|----|------|-------|------------|-------|';

        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');
            $name = (string) ($item['name'] ?? '');
            $count = (string) ($item['itemCount'] ?? '0');
            $visibility = !empty($item['isPublic']) ? 'public' : 'private';
            $owner = (string) ($item['owner']['handle'] ?? '');

            $lines[] = "| {$id} | {$name} | {$count} | {$visibility} | {$owner} |";
        }

        $nextCursor = $data['nextCursor'] ?? null;
        if ($nextCursor !== null) {
            $lines[] = '';
            $lines[] = "*More available — use `cursor: \"{$nextCursor}\"`.*";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function collectionsCreate(array $input): ToolResult
    {
        $name = (string) ($input['collection_name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Parameter "collection_name" is required for collections create.');
        }

        $description = isset($input['description']) ? (string) $input['description'] : null;
        $isPublic = (bool) ($input['is_public'] ?? true);

        $data = $this->client->createCollection($name, $description, $isPublic);
        $id = $data['id'] ?? 'unknown';

        return ToolResult::success("Collection \"{$name}\" created (ID: {$id}).");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function collectionsDetails(array $input): ToolResult
    {
        $id = (int) ($input['collection_id'] ?? 0);
        if ($id === 0) {
            return ToolResult::error('Parameter "collection_id" is required for collections details.');
        }

        $data = $this->client->getCollection($id);
        $name = (string) ($data['name'] ?? '');
        $description = (string) ($data['description'] ?? '');
        $isPublic = !empty($data['isPublic']);
        $items = (array) ($data['items'] ?? []);

        $lines = ["## Collection: {$name}"];
        if ($description !== '') {
            $lines[] = $description;
        }
        $lines[] = '';
        $lines[] = '**Visibility:** ' . ($isPublic ? 'Public' : 'Private');
        $lines[] = '**Items:** ' . count($items);

        if ($items !== []) {
            $lines[] = '';
            $lines[] = '| Type | Name | Note |';
            $lines[] = '|------|------|------|';

            foreach ($items as $item) {
                $type = (string) ($item['entityType'] ?? '');
                $itemName = (string) ($item['name'] ?? $item['displayName'] ?? '');
                $note = (string) ($item['note'] ?? '');
                $lines[] = "| {$type} | {$itemName} | {$note} |";
            }
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function collectionsUpdate(array $input): ToolResult
    {
        $id = (int) ($input['collection_id'] ?? 0);
        if ($id === 0) {
            return ToolResult::error('Parameter "collection_id" is required for collections update.');
        }

        $data = [];
        if (isset($input['collection_name'])) {
            $data['name'] = (string) $input['collection_name'];
        }
        if (isset($input['description'])) {
            $data['description'] = (string) $input['description'];
        }
        if (isset($input['is_public'])) {
            $data['isPublic'] = (bool) $input['is_public'];
        }

        if ($data === []) {
            return ToolResult::error('At least one field (collection_name, description, is_public) is required for update.');
        }

        $this->client->updateCollection($id, $data);

        return ToolResult::success("Collection #{$id} updated.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function collectionsDelete(array $input): ToolResult
    {
        $id = (int) ($input['collection_id'] ?? 0);
        if ($id === 0) {
            return ToolResult::error('Parameter "collection_id" is required for collections delete.');
        }

        $this->client->deleteCollection($id);

        return ToolResult::success("Collection #{$id} deleted.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function collectionsAddItem(array $input): ToolResult
    {
        $id = (int) ($input['collection_id'] ?? 0);
        $entityType = (string) ($input['entity_type'] ?? '');
        $entityId = (int) ($input['entity_id'] ?? 0);

        if ($id === 0 || $entityType === '' || $entityId === 0) {
            return ToolResult::error('Parameters "collection_id", "entity_type", and "entity_id" are required for add_item.');
        }

        $note = isset($input['note']) ? (string) $input['note'] : null;
        $this->client->addCollectionItem($id, $entityType, $entityId, $note);

        return ToolResult::success("Added {$entityType} #{$entityId} to collection #{$id}.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function collectionsRemoveItem(array $input): ToolResult
    {
        $id = (int) ($input['collection_id'] ?? 0);
        $entityType = (string) ($input['entity_type'] ?? '');
        $entityId = (int) ($input['entity_id'] ?? 0);

        if ($id === 0 || $entityType === '' || $entityId === 0) {
            return ToolResult::error('Parameters "collection_id", "entity_type", and "entity_id" are required for remove_item.');
        }

        $this->client->removeCollectionItem($id, $entityType, $entityId);

        return ToolResult::success("Removed {$entityType} #{$entityId} from collection #{$id}.");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function review(array $input): ToolResult
    {
        $entityType = (string) ($input['entity_type'] ?? '');
        $owner = (string) ($input['owner'] ?? '');
        $name = (string) ($input['name'] ?? '');
        $rating = (int) ($input['rating'] ?? 0);

        if ($entityType === '' || $owner === '' || $name === '' || $rating < 1 || $rating > 5) {
            return ToolResult::error('Parameters "entity_type", "owner", "name", and "rating" (1-5) are required for review.');
        }

        $title = isset($input['title']) ? (string) $input['title'] : null;
        $body = isset($input['body']) ? (string) $input['body'] : null;

        $this->client->createReview($entityType, $owner, $name, $rating, $title, $body);

        $stars = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);

        return ToolResult::success("Review posted for {$entityType} `{$owner}/{$name}` ({$stars}).");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function notifications(array $input): ToolResult
    {
        $subAction = (string) ($input['sub_action'] ?? 'list');

        return match ($subAction) {
            'list' => $this->notificationsList($input),
            'mark_read' => $this->notificationsMarkRead($input),
            'mark_all_read' => $this->notificationsMarkAllRead(),
            default => ToolResult::error("Unknown notifications sub_action: '{$subAction}'. Valid: list, mark_read, mark_all_read"),
        };
    }

    /**
     * @param array<string, mixed> $input
     */
    private function notificationsList(array $input): ToolResult
    {
        $limit = (int) ($input['limit'] ?? 50);
        $unread = isset($input['unread']) ? (bool) $input['unread'] : null;

        $data = $this->client->myNotifications($limit, $unread);
        $items = $data['items'] ?? [];

        if ($items === []) {
            return ToolResult::success($unread ? 'No unread notifications.' : 'No notifications.');
        }

        $lines = ['## Notifications', ''];

        foreach ($items as $item) {
            $read = !empty($item['read']);
            $icon = $read ? '○' : '●';
            $title = (string) ($item['title'] ?? $item['message'] ?? '');
            $lines[] = "{$icon} {$title}";
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $input
     */
    private function notificationsMarkRead(array $input): ToolResult
    {
        $id = (int) ($input['notification_id'] ?? 0);
        if ($id === 0) {
            return ToolResult::error('Parameter "notification_id" is required for mark_read.');
        }

        $this->client->markNotificationsRead(['id' => $id]);

        return ToolResult::success("Notification #{$id} marked as read.");
    }

    private function notificationsMarkAllRead(): ToolResult
    {
        $this->client->markNotificationsRead(['all' => true]);

        return ToolResult::success('All notifications marked as read.');
    }

    private function health(): ToolResult
    {
        $data = $this->client->healthCheck();
        $status = (string) ($data['status'] ?? 'unknown');
        $version = (string) ($data['version'] ?? '-');
        $timestamp = (string) ($data['timestamp'] ?? '-');

        return ToolResult::success("API Status: {$status} | Version: {$version} | Time: {$timestamp}");
    }

    // ── Content helpers ──────────────────────────────────────────────

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
