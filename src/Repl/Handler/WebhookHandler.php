<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\WebhookStore;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /webhooks command.
 */
final class WebhookHandler
{
    public function __construct(
        private readonly SessionStorage $storage,
    ) {}

    public function handle(SymfonyStyle $io, string $arg): void
    {
        $webhookStore = new WebhookStore($this->storage->getPdo());
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
}
