<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Storage\WebhookStore;
use CoquiBot\Coqui\Utility\SecretMasker;

/**
 * Agent-facing tools for managing webhook subscriptions.
 *
 * Enables the agent to create, configure, and monitor webhook endpoints
 * that receive external HTTP requests and spawn background tasks.
 */
final readonly class WebhookToolkit implements ToolkitInterface
{
    public function __construct(
        private WebhookStore $webhookStore,
        private string $apiBaseUrl = '',
        private ?string $activeProfileId = null,
    ) {}

    public function tools(): array
    {
        return [
            $this->createWebhookTool(),
            $this->listWebhooksTool(),
            $this->getWebhookTool(),
            $this->updateWebhookTool(),
            $this->deleteWebhookTool(),
            $this->rotateSecretTool(),
        ];
    }

    public function guidelines(): string
    {
        $stats = $this->webhookStore->getStats();
        $activeCount = $stats['enabled'];
        $totalTriggers = $stats['total_triggers'];

        $statusLine = $activeCount > 0
            ? "Active webhooks: {$activeCount} ({$totalTriggers} total deliveries)"
            : 'No active webhooks';

        $baseUrl = $this->apiBaseUrl !== '' ? $this->apiBaseUrl : 'http://localhost:3300';

        return <<<GUIDELINES
        ## Webhooks

        You can create webhook endpoints that receive HTTP POST requests from external services
        (GitHub, Slack, CI/CD pipelines, etc.) and automatically spawn background tasks.

        **Status:** {$statusLine}

        ### Webhook URL Format
        `{$baseUrl}/api/v1/webhooks/incoming/{name}`

        ### Prompt Template Placeholders
        - `{{payload}}` — full JSON payload (truncated to 4KB)
        - `{{event_type}}` — extracted event type (e.g. "push" for GitHub)
        - `{{summary}}` — brief payload summary
        - `{{field.path}}` — nested field access (e.g. `{{repository.full_name}}`)

        ### Source Types
        - `github` — verifies X-Hub-Signature-256 (HMAC-SHA256)
        - `slack` — verifies X-Slack-Signature with replay protection
        - `generic` — verifies X-Webhook-Signature or X-Signature (HMAC-SHA256)

        ### Event Filtering
        Use comma-separated event types to only process specific events.
        Example: `push,pull_request` for GitHub, `message,app_mention` for Slack.
        GUIDELINES;
    }

    private function createWebhookTool(): Tool
    {
        return new Tool(
            name: 'webhook_create',
            description: 'Create a webhook endpoint that receives HTTP POST requests and spawns background tasks. Returns the webhook URL and signing secret.',
            parameters: [
                new StringParameter(
                    name: 'name',
                    description: 'Unique name for this webhook (used in the URL path)',
                    required: true,
                ),
                new StringParameter(
                    name: 'prompt_template',
                    description: 'Prompt template with {{payload}}, {{event_type}}, {{summary}} placeholders',
                    required: true,
                ),
                new EnumParameter(
                    name: 'source',
                    description: 'Signature verification scheme',
                    values: WebhookStore::VALID_SOURCES,
                    required: false,
                ),
                new StringParameter(
                    name: 'role',
                    description: 'Role for the spawned agent (default: orchestrator)',
                    required: false,
                ),
                new StringParameter(
                    name: 'event_filter',
                    description: 'Comma-separated list of event types to accept (empty = accept all)',
                    required: false,
                ),
                new StringParameter(
                    name: 'description',
                    description: 'Human-readable description of this webhook',
                    required: false,
                ),
                new NumberParameter(
                    name: 'max_iterations',
                    description: 'Max agent iterations per triggered task (1-' . CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS . ', default: 48)',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeCreate($args),
        );
    }

    private function listWebhooksTool(): Tool
    {
        return new Tool(
            name: 'webhook_list',
            description: 'List all webhook subscriptions with their status and trigger counts.',
            parameters: [],
            callback: fn(array $args): ToolResult => $this->executeList($args),
        );
    }

    private function getWebhookTool(): Tool
    {
        return new Tool(
            name: 'webhook_get',
            description: 'Get details and recent delivery log for a webhook.',
            parameters: [
                new StringParameter(
                    name: 'id',
                    description: 'Webhook ID or name',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeGet($args),
        );
    }

    private function updateWebhookTool(): Tool
    {
        return new Tool(
            name: 'webhook_update',
            description: 'Update a webhook subscription\'s prompt template, source type, event filter, enabled state, or other properties.',
            parameters: [
                new StringParameter(
                    name: 'id',
                    description: 'Webhook ID or name',
                    required: true,
                ),
                new StringParameter(
                    name: 'prompt_template',
                    description: 'New prompt template',
                    required: false,
                ),
                new EnumParameter(
                    name: 'source',
                    description: 'New signature verification scheme',
                    values: WebhookStore::VALID_SOURCES,
                    required: false,
                ),
                new StringParameter(
                    name: 'event_filter',
                    description: 'New comma-separated event type filter (empty string to clear)',
                    required: false,
                ),
                new StringParameter(
                    name: 'description',
                    description: 'New description',
                    required: false,
                ),
                new NumberParameter(
                    name: 'max_iterations',
                    description: 'New max iterations (1-' . CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS . ')',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS,
                ),
                new StringParameter(
                    name: 'role',
                    description: 'New role for triggered tasks',
                    required: false,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeUpdate($args),
        );
    }

    private function deleteWebhookTool(): Tool
    {
        return new Tool(
            name: 'webhook_delete',
            description: 'Delete a webhook subscription.',
            parameters: [
                new StringParameter(
                    name: 'id',
                    description: 'Webhook ID or name',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeDelete($args),
        );
    }

    private function rotateSecretTool(): Tool
    {
        return new Tool(
            name: 'webhook_rotate_secret',
            description: 'Rotate the signing secret for a webhook. Returns the new secret — update the external service configuration immediately.',
            parameters: [
                new StringParameter(
                    name: 'id',
                    description: 'Webhook ID or name',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeRotateSecret($args),
        );
    }

    // =========================================================================
    // Tool Implementations
    // =========================================================================

    /**
     * @param array<string, mixed> $args
     */
    private function executeCreate(array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        $promptTemplate = trim((string) ($args['prompt_template'] ?? ''));

        if ($name === '' || $promptTemplate === '') {
            return ToolResult::error('name and prompt_template are required');
        }

        if ($this->webhookStore->getByName($name) !== null) {
            return ToolResult::error("Webhook '{$name}' already exists");
        }

        $source = (string) ($args['source'] ?? 'generic');
        $role = (string) ($args['role'] ?? 'orchestrator');
        $maxIterations = max(1, min(CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS, (int) ($args['max_iterations'] ?? 48)));
        $eventFilter = isset($args['event_filter']) ? trim((string) $args['event_filter']) : null;
        $description = isset($args['description']) ? trim((string) $args['description']) : null;

        $id = $this->webhookStore->create(
            name: $name,
            promptTemplate: $promptTemplate,
            source: $source,
            role: $role,
            profile: $this->activeProfileId,
            maxIterations: $maxIterations,
            description: $description,
            eventFilter: $eventFilter !== '' ? $eventFilter : null,
            createdBy: 'agent',
        );

        $webhook = $this->webhookStore->get($id);
        $baseUrl = $this->apiBaseUrl !== '' ? $this->apiBaseUrl : 'http://localhost:3300';

        return ToolResult::json([
            'id' => $id,
            'name' => $name,
            'url' => "{$baseUrl}/api/v1/webhooks/incoming/{$name}",
            'secret' => $webhook['secret'] ?? '',
            'source' => $source,
            'profile' => $webhook['profile'] ?? null,
            'message' => "Webhook '{$name}' created. Configure the external service with the URL and secret above.",
        ]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeList(array $args): ToolResult
    {
        $webhooks = $this->webhookStore->list();

        if ($webhooks === []) {
            return ToolResult::success('No webhook subscriptions found.');
        }

        $lines = [];
        $baseUrl = $this->apiBaseUrl !== '' ? $this->apiBaseUrl : 'http://localhost:3300';
        foreach ($webhooks as $w) {
            $status = ((int) $w['enabled']) ? '✓' : '✗';
            $triggers = (int) $w['trigger_count'];
            $lines[] = sprintf(
                '%s [%s] %s (%s) — URL: %s/api/v1/webhooks/incoming/%s | triggers: %d',
                $status,
                $w['id'],
                $w['name'],
                $w['source'],
                $baseUrl,
                $w['name'],
                $triggers,
            );
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeGet(array $args): ToolResult
    {
        $webhook = $this->resolveWebhook((string) ($args['id'] ?? ''));
        if ($webhook === null) {
            return ToolResult::error('Webhook not found');
        }

        $deliveries = $this->webhookStore->getDeliveries((string) $webhook['id'], 10);

        // Mask the secret
        $secret = (string) $webhook['secret'];
        $webhook['secret'] = SecretMasker::mask($secret);

        return ToolResult::json([
            'webhook' => $webhook,
            'recent_deliveries' => $deliveries,
        ]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeDelete(array $args): ToolResult
    {
        $webhook = $this->resolveWebhook((string) ($args['id'] ?? ''));
        if ($webhook === null) {
            return ToolResult::error('Webhook not found');
        }

        $name = (string) $webhook['name'];
        $this->webhookStore->delete((string) $webhook['id']);

        return ToolResult::success("Webhook '{$name}' deleted.");
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeUpdate(array $args): ToolResult
    {
        $webhook = $this->resolveWebhook((string) ($args['id'] ?? ''));
        if ($webhook === null) {
            return ToolResult::error('Webhook not found');
        }

        $id = (string) $webhook['id'];

        $promptTemplate = isset($args['prompt_template']) ? trim((string) $args['prompt_template']) : null;
        $source = isset($args['source']) ? (string) $args['source'] : null;
        $role = isset($args['role']) ? (string) $args['role'] : null;
        $description = isset($args['description']) ? trim((string) $args['description']) : null;
        $maxIterations = isset($args['max_iterations']) ? max(1, min(CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS, (int) $args['max_iterations'])) : null;

        $eventFilter = null;
        $hasEventFilter = isset($args['event_filter']);
        if ($hasEventFilter) {
            $filter = trim((string) $args['event_filter']);
            $eventFilter = $filter !== '' ? $filter : '';
        }

        if ($promptTemplate === null && $source === null && $role === null
            && $description === null && $maxIterations === null && !$hasEventFilter) {
            return ToolResult::error('No fields to update. Provide at least one field.');
        }

        $this->webhookStore->update(
            id: $id,
            promptTemplate: $promptTemplate,
            source: $source,
            role: $role,
            description: $description,
            maxIterations: $maxIterations,
            eventFilter: $hasEventFilter ? ($eventFilter !== '' ? $eventFilter : '') : null,
        );

        $updated = $this->webhookStore->get($id);

        if ($updated !== null) {
            $updated['secret'] = SecretMasker::mask((string) $updated['secret']);
        }

        return ToolResult::json([
            'message' => "Webhook '{$webhook['name']}' updated",
            'webhook' => $updated,
        ]);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeRotateSecret(array $args): ToolResult
    {
        $webhook = $this->resolveWebhook((string) ($args['id'] ?? ''));
        if ($webhook === null) {
            return ToolResult::error('Webhook not found');
        }

        $id = (string) $webhook['id'];
        $name = (string) $webhook['name'];

        $newSecret = $this->webhookStore->rotateSecret($id);
        $baseUrl = $this->apiBaseUrl !== '' ? $this->apiBaseUrl : 'http://localhost:3300';

        return ToolResult::json([
            'message' => "Secret rotated for webhook '{$name}'. Update the external service immediately.",
            'webhook_url' => "{$baseUrl}/api/v1/webhooks/incoming/{$name}",
            'new_secret' => $newSecret,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveWebhook(string $idOrName): ?array
    {
        if ($idOrName === '') {
            return null;
        }

        $webhook = $this->webhookStore->get($idOrName);
        if ($webhook !== null) {
            return $webhook;
        }

        return $this->webhookStore->getByName($idOrName);
    }
}
