<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\AuditRedactor;
use CoquiBot\Coqui\Storage\SessionStorage;

covers(SessionStorage::class);

function auditRedactionDb(): string
{
    return sys_get_temp_dir() . '/coqui-audit-redaction-' . bin2hex(random_bytes(8)) . '.db';
}

function readAuditRow(SessionStorage $storage, string $id): array
{
    $stmt = $storage->getPdo()->prepare('SELECT arguments, reason FROM audit_log WHERE id = :id');
    $stmt->execute(['id' => $id]);

    /** @var array{arguments: string, reason: ?string} $row */
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row;
}

// NEGATIVE CONTROL: if the redactor is removed from logAudit, this test fails.
test('logAudit never persists a secret present in exec arguments', function (): void {
    $dbPath = auditRedactionDb();
    $redactor = new AuditRedactor(fakeCredentials(['GITHUB_TOKEN' => 'supersecretvalue123']));
    $storage = new SessionStorage($dbPath, null, auditRedactor: $redactor);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');

        $id = $storage->logAudit(
            sessionId: $sessionId,
            toolName: 'exec',
            arguments: ['command' => 'curl -H "X-Token: supersecretvalue123" https://x.test'],
            action: 'auto_approved',
        );

        $row = readAuditRow($storage, $id);

        expect($row['arguments'])->not->toContain('supersecretvalue123');
        expect($row['arguments'])->toContain('[REDACTED]');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

// NEGATIVE CONTROL: reason is a separate field and needs its own proof.
test('logAudit redacts the reason field, which carries question prompts', function (): void {
    $dbPath = auditRedactionDb();
    $redactor = new AuditRedactor(fakeCredentials(['TOKEN' => 'prompt-secret-xyz']));
    $storage = new SessionStorage($dbPath, null, auditRedactor: $redactor);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');

        $id = $storage->logAudit(
            sessionId: $sessionId,
            toolName: 'ask_user',
            arguments: ['prompt' => 'ok'],
            action: 'question_asked',
            reason: 'Should I use prompt-secret-xyz for this?',
        );

        $row = readAuditRow($storage, $id);

        expect($row['reason'])->not->toContain('prompt-secret-xyz');
        expect($row['reason'])->toContain('[REDACTED]');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('a throwing redactor is fail-closed and never writes raw arguments', function (): void {
    $dbPath = auditRedactionDb();

    $exploding = new class implements CoquiBot\Coqui\Contract\AuditRedactorInterface {
        public function redact(array $arguments): array
        {
            throw new RuntimeException('redaction bug');
        }

        public function redactScalar(?string $value): ?string
        {
            throw new RuntimeException('redaction bug');
        }
    };

    $storage = new SessionStorage($dbPath, null, auditRedactor: $exploding);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');

        $id = $storage->logAudit(
            sessionId: $sessionId,
            toolName: 'exec',
            arguments: ['command' => 'echo raw-secret-must-not-appear'],
            action: 'auto_approved',
            reason: 'because raw-secret-must-not-appear',
        );

        $row = readAuditRow($storage, $id);

        expect($row['arguments'])->not->toContain('raw-secret-must-not-appear');
        expect($row['reason'])->not->toContain('raw-secret-must-not-appear');
        expect($row['arguments'])->toContain('redaction-failed');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('unencodable arguments are fail-closed rather than written empty', function (): void {
    $dbPath = auditRedactionDb();
    $storage = new SessionStorage($dbPath, null, auditRedactor: new AuditRedactor());

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');

        // Invalid UTF-8 makes json_encode fail.
        $id = $storage->logAudit(
            sessionId: $sessionId,
            toolName: 'exec',
            arguments: ['command' => "bad \xB1\x31 bytes"],
            action: 'auto_approved',
        );

        $row = readAuditRow($storage, $id);

        expect($row['arguments'])->toContain('redaction-failed');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('storage without a redactor still writes valid rows', function (): void {
    $dbPath = auditRedactionDb();
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');
        $id = $storage->logAudit($sessionId, 'exec', ['command' => 'echo hi'], 'auto_approved');

        expect(readAuditRow($storage, $id)['arguments'])->toBe('{"command":"echo hi"}');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});
