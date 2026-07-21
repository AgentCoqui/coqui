<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Repl\TimeFormatter;
use CoquiBot\Coqui\Storage\AuditLogQuery;
use CoquiBot\Coqui\Storage\AuditLogStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /audit — a browse-only view over the audit log.
 *
 * Thin adapter over AuditLogStore, in-process (no HTTP round trip), mirroring
 * how LoopHandler adapts LoopStore for /loops.
 */
final class AuditHandler
{
    private const int DEFAULT_LIMIT = 25;

    public function __construct(
        private readonly SessionStorage $storage,
    ) {}

    public function handle(SymfonyStyle $io, string $arg, string $sessionId = ''): void
    {
        $store = new AuditLogStore($this->storage->getPdo());

        $parts = $arg !== '' ? explode(' ', trim($arg), 2) : [];
        $action = strtolower($parts[0] ?? '');
        $target = trim($parts[1] ?? '');

        if ($action !== '' && $target === '') {
            $io->error('Usage: /audit [tool <name>|session <id>|action <name>|limit <n>]');

            return;
        }

        $query = match ($action) {
            'tool' => new AuditLogQuery(toolName: $target, limit: self::DEFAULT_LIMIT),
            'session' => new AuditLogQuery(sessionId: $target, limit: self::DEFAULT_LIMIT),
            'action' => new AuditLogQuery(action: $target, limit: self::DEFAULT_LIMIT),
            'limit' => new AuditLogQuery(limit: max(1, min((int) $target, AuditLogQuery::MAX_LIMIT))),
            default => new AuditLogQuery(limit: self::DEFAULT_LIMIT),
        };

        $entries = $store->query($query);
        $total = $store->count($query);

        if ($entries === []) {
            $io->info('No audit entries found. The audit log records approval decisions and questions.');

            return;
        }

        $io->section(sprintf('Audit log (showing %d of %d)', count($entries), $total));

        $rows = [];
        foreach ($entries as $entry) {
            $status = match ($entry['action']) {
                'auto_approved', 'approved' => '<fg=green>●</>',
                'blocked' => '<fg=red>✗</>',
                'denied' => '<fg=yellow>⊘</>',
                'question_asked', 'question_answered' => '<fg=cyan>?</>',
                default => ' ',
            };

            $arguments = json_encode($entry['arguments'], JSON_UNESCAPED_SLASHES) ?: '';
            if (mb_strlen($arguments) > 60) {
                $arguments = mb_substr($arguments, 0, 57) . '...';
            }

            $rows[] = [
                $status,
                (string) $entry['action'],
                (string) $entry['tool_name'],
                substr((string) ($entry['session_id'] ?? ''), 0, 8) . '...',
                $arguments,
                TimeFormatter::timeSince((string) $entry['created_at']),
            ];
        }

        $io->table(['', 'Action', 'Tool', 'Session', 'Arguments', 'When'], $rows);
        $io->text('<fg=gray>Secrets are redacted at write time. Full query filters: GET /api/v1/audit</>');
    }
}
