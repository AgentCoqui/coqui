<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Storage\WebhookStore;

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
    ) {}

    public function tools(): array
    {
        return [
            $this->createWebhookTool(),
            $this->listWebhooksTool(),
            $this->getWebhookTool(),
            $this->deleteWebhookTool(),
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
                    values: ['generic', 'github', 'slack'],
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
                    description: 'Max agent iterations per triggered task (1-100, default: 48)',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: 100,
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
        $maxIterations = max(1, min(100, (int) ($args['max_iterations'] ?? 48)));
        $eventFilter = isset($args['event_filter']) ? trim((string) $args['event_filter']) : null;
        $description = isset($args['description']) ? trim((string) $args['description']) : null;

        $id = $this->webhookStore->create(
            name: $name,
            promptTemplate: $promptTemplate,
            source: $source,
            role: $role,
            maxIterations: $maxIterations,
            description: $description,
            eventFilter: $eventFilter !== '' ? $eventFilter : null,
            createdBy: 'agent',
        );

        $webhook = $this->webhookStore->get($id);
        $baseUrl = $this->apiBaseUrl !== '' ? $this->apiBaseUrl : 'http://localhost:3300';

        return ToolResult::success((string) json_encode([
            'id' => $id,
            'name' => $name,
            'url' => "{$baseUrl}/api/v1/webhooks/incoming/{$name}",
            'secret' => $webhook['secret'] ?? '',
            'source' => $source,
            'message' => "Webhook '{$name}' created. Configure the external service with the URL and secret above.",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
        if (mb_strlen($secret) > 8) {
            $webhook['secret'] = mb_substr($secret, 0, 4) . '****' . mb_substr($secret, -4);
        }

        return ToolResult::success((string) json_encode([
            'webhook' => $webhook,
            'recent_deliveries' => $deliveries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
