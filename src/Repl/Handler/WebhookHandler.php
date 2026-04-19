<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Utility\SecretMasker;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\WebhookStore;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /webhooks and operator-focused subcommands.
 */
final class WebhookHandler
{
    public function __construct(
        private readonly SessionStorage $storage,
    ) {}

    public function handle(SymfonyStyle $io, string $arg): void
    {
        $webhookStore = new WebhookStore($this->storage->getPdo());

        $trimmedArg = trim($arg);
        $argParts = $trimmedArg !== '' ? explode(' ', $trimmedArg, 2) : [];
        $action = strtolower($argParts[0] ?? '');
        $target = trim($argParts[1] ?? '');

        match ($action) {
            'status' => $this->handleStatus($io, $webhookStore, $target),
            'deliveries' => $this->handleDeliveries($io, $webhookStore, $target),
            'enable' => $this->handleEnable($io, $webhookStore, $target),
            'disable' => $this->handleDisable($io, $webhookStore, $target),
            'delete' => $this->handleDelete($io, $webhookStore, $target),
            'rotate' => $this->handleRotate($io, $webhookStore, $target),
            default => $this->handleList($io, $webhookStore),
        };
    }

    private function handleList(SymfonyStyle $io, WebhookStore $webhookStore): void
    {
        $webhooks = $webhookStore->list();

        if (empty($webhooks)) {
            $io->info('No webhook subscriptions. Create webhooks via the agent (webhook_create tool) or the API.');
            return;
        }

        $stats = $webhookStore->getStats();
        $io->section(sprintf('Webhooks (%d active / %d total — %d deliveries)', $stats['enabled'], $stats['total'], $stats['total_triggers']));

        $rows = [];
        foreach ($webhooks as $w) {
            $status = ((int) $w['enabled']) ? '<fg=green>✓</>' : '<fg=red>✗</>';
            $rows[] = [
                $status,
                substr($w['id'], 0, 8) . '...',
                $w['name'],
                $w['source'],
                $w['event_filter'] ?? '*',
                $w['trigger_count'],
                $w['last_triggered_at'] ?? 'never',
            ];
        }

        $io->table(['', 'ID', 'Name', 'Source', 'Events', 'Triggers', 'Last Triggered'], $rows);
    }

    private function handleStatus(SymfonyStyle $io, WebhookStore $store, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /webhooks status <name|id>');
            return;
        }

        $webhook = $this->resolveByIdOrName($store, $target);
        if ($webhook === null) {
            $io->error("No webhook found matching '{$target}'.");
            return;
        }

        $io->section(sprintf('Webhook: %s', $webhook['name']));
        $io->definitionList(
            ['ID' => (string) $webhook['id']],
            ['Enabled' => ((int) $webhook['enabled']) === 1 ? 'yes' : 'no'],
            ['Source' => (string) ($webhook['source'] ?? 'generic')],
            ['Events' => (string) ($webhook['event_filter'] ?? '*')],
            ['Role' => (string) ($webhook['role'] ?? 'orchestrator')],
            ['Profile' => (string) ($webhook['profile'] ?? '-')],
            ['Max iterations' => (string) ($webhook['max_iterations'] ?? 48)],
            ['Created by' => (string) ($webhook['created_by'] ?? '-')],
            ['Triggers' => (string) ($webhook['trigger_count'] ?? 0)],
            ['Last triggered' => (string) ($webhook['last_triggered_at'] ?? 'never')],
            ['Signing secret' => SecretMasker::mask((string) ($webhook['secret'] ?? ''))],
        );

        if (($webhook['description'] ?? null) !== null && trim((string) $webhook['description']) !== '') {
            $io->text('<fg=cyan>Description:</>');
            $io->writeln((string) $webhook['description']);
        }

        $io->text('<fg=cyan>Prompt template:</>');
        $io->writeln((string) $webhook['prompt_template']);
    }

    private function handleDeliveries(SymfonyStyle $io, WebhookStore $store, string $target): void
    {
        if ($target === '') {
            $io->error('Usage: /webhooks deliveries <name|id>');
            return;
        }

        $webhook = $this->resolveByIdOrName($store, $target);
        if ($webhook === null) {
            $io->error("No webhook found matching '{$target}'.");
            return;
        }

        $deliveries = $store->getDeliveries((string) $webhook['id'], 10);
        if ($deliveries === []) {
            $io->info(sprintf("Webhook '%s' has no delivery records yet.", $webhook['name']));
            return;
        }

        $rows = [];
        foreach ($deliveries as $delivery) {
            $rows[] = [
                substr((string) $delivery['id'], 0, 8) . '...',
                (string) ($delivery['status'] ?? '-'),
                (string) ($delivery['event_type'] ?? '*'),
                (string) ($delivery['task_id'] ?? '-'),
                (string) ($delivery['created_at'] ?? '-'),
            ];
        }

        $io->section(sprintf('Recent Deliveries: %s', $webhook['name']));
        $io->table(['ID', 'Status', 'Event', 'Task', 'Created'], $rows);
    }

    private function handleEnable(SymfonyStyle $io, WebhookStore $store, string $target): void
    {
        $webhook = $this->requireWebhook($io, $store, $target, 'enable');
        if ($webhook === null) {
            return;
        }

        $store->update((string) $webhook['id'], enabled: true);
        $io->success(sprintf("Enabled webhook '%s'.", $webhook['name']));
    }

    private function handleDisable(SymfonyStyle $io, WebhookStore $store, string $target): void
    {
        $webhook = $this->requireWebhook($io, $store, $target, 'disable');
        if ($webhook === null) {
            return;
        }

        $store->update((string) $webhook['id'], enabled: false);
        $io->success(sprintf("Disabled webhook '%s'.", $webhook['name']));
    }

    private function handleDelete(SymfonyStyle $io, WebhookStore $store, string $target): void
    {
        $webhook = $this->requireWebhook($io, $store, $target, 'delete');
        if ($webhook === null) {
            return;
        }

        if (!$io->confirm(sprintf("Delete webhook '%s'? This cannot be undone.", $webhook['name']), false)) {
            $io->text('<fg=gray>Cancelled.</>');
            return;
        }

        $store->delete((string) $webhook['id']);
        $io->success(sprintf("Deleted webhook '%s'.", $webhook['name']));
    }

    private function handleRotate(SymfonyStyle $io, WebhookStore $store, string $target): void
    {
        $webhook = $this->requireWebhook($io, $store, $target, 'rotate');
        if ($webhook === null) {
            return;
        }

        if (!$io->confirm(sprintf("Rotate the signing secret for webhook '%s'?", $webhook['name']), false)) {
            $io->text('<fg=gray>Cancelled.</>');
            return;
        }

        $secret = $store->rotateSecret((string) $webhook['id']);
        if ($secret === null) {
            $io->error("Failed to rotate webhook '{$webhook['name']}'.");
            return;
        }

        $io->success(sprintf("Rotated signing secret for '%s'.", $webhook['name']));
        $io->text('<fg=cyan>New secret:</>');
        $io->writeln($secret);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requireWebhook(SymfonyStyle $io, WebhookStore $store, string $target, string $action): ?array
    {
        if ($target === '') {
            $io->error(sprintf('Usage: /webhooks %s <name|id>', $action));
            return null;
        }

        $webhook = $this->resolveByIdOrName($store, $target);
        if ($webhook === null) {
            $io->error("No webhook found matching '{$target}'.");
            return null;
        }

        return $webhook;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveByIdOrName(WebhookStore $store, string $idOrName): ?array
    {
        $webhook = $store->get($idOrName);
        if ($webhook !== null) {
            return $webhook;
        }

        return $store->getByName($idOrName);
    }
}
