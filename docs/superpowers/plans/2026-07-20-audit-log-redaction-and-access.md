# Audit Log: Write-Time Redaction + Read Access — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `audit_log` never persist a secret (Part A), then expose it through an authenticated read API and a `/audit` REPL command (Part B).

**Architecture:** A single `AuditRedactor` is injected into `SessionStorage` and applied inside `logAudit()` — the one write choke point all three callers funnel through — with fail-closed error handling. Reads go through a separate `AuditLogStore` over the shared PDO, consumed in-process by both a thin API handler and a thin REPL handler, mirroring how `LoopStore` serves `LoopHandler` and `/loops`.

**Tech Stack:** PHP 8.4 (strict types), SQLite via PDO, Pest 4, PHPStan, ReactPHP HTTP (`React\Http\Message\Response`), Symfony Console (`SymfonyStyle`).

**Spec:** `docs/superpowers/specs/2026-07-15-audit-log-redaction-and-access-design.md`

## Global Constraints

- `declare(strict_types=1);` in every PHP file; `final` by default; one class per file; 4-space indent; constructor injection.
- The command tool is named **`exec`**, never `shell`. `CatastrophicBlacklist::CHECKED_TOOLS` is `['exec', 'php_execute']`. Use `exec` in all tests and docs.
- **Redaction is fail-closed.** If redaction or `json_encode` throws, persist a placeholder — never the raw arguments.
- Redact **both** `arguments` and `reason`. Question payloads flow through `arguments`, and `QuestionPersistence` passes `$question->prompt` as `reason`.
- Cache nothing that holds a secret **value**. Resolve values at write time via `CredentialResolverInterface::get()`.
- **No migration, deprecation, or legacy-support code anywhere.** Coqui is pre-release with no installed base.
- **No export surface**: no export endpoint, no CSV, no `?format=`, no `Content-Disposition`.
- All audit routes use `$router->get(...)`. **Never `addPublicRoute`.**
- `/audit` is a core static `ReplCommandSpec` in `ReplCommandCatalog`. No `/logs` alias.
- Baseline to beat: `composer test` → 2393 passing, 1 skipped. `composer analyse` → `[OK] 382/382`. Test count must rise; analyse must stay OK.
- Work in the worktree `/home/carmelo/Projects/CoquiBot/Core/coqui-audit-log` on branch `feat/audit-log-access`.

---

## File Structure

**Part A**
- Create `src/Storage/AuditRedactor.php` — the three detection layers. Lives beside `SessionStorage` because it is only ever used from the audit write path.
- Modify `src/Storage/SessionStorage.php` — constructor gains a nullable redactor; `logAudit()` applies it fail-closed.
- Modify `src/Config/BootManager.php` — owns one `AuditRedactor`, exposed via `auditRedactor()`.
- Modify the seven `new SessionStorage(` sites to pass it.
- Create `tests/Unit/Storage/AuditRedactorTest.php`, `tests/Unit/Storage/AuditLogRedactionTest.php`, `tests/Unit/Storage/AuditRedactorWiringTest.php`.

**Part B**
- Create `src/Storage/AuditLogQuery.php` — validated query value object.
- Create `src/Storage/AuditLogStore.php` — read/query/count over the shared PDO.
- Create `src/Api/Handler/AuditHandler.php` — two authenticated GET routes, self-registering.
- Create `src/Repl/Handler/AuditHandler.php` — SymfonyStyle table.
- Modify `src/Repl/ReplCommandCatalog.php`, `src/Repl/SlashCommandRouter.php`, `src/Repl/TabCompletion.php`, `src/Command/RunCommand.php`, `src/Command/ApiCommand.php`.
- Create `tests/Unit/Storage/AuditLogStoreTest.php`, `tests/Unit/Api/Handler/AuditHandlerTest.php`, `tests/Unit/Repl/Handler/AuditHandlerTest.php`.
- Modify `docs/API.md`, `docs/COMMANDS.md`.

---

## Boot-Ordering Constraint (read before Task 3)

Verified against `src/Config/BootManager.php`:

- `boot()` calls `initializeCredentials()` (sets `$this->credentialResolver`), **then** `initializeArtifacts()` (constructs `SessionStorage`), **then** `discoverToolkits()` (sets `$this->discovery`).
- So at `SessionStorage` construction time the credential resolver **is** available but `$this->discovery` is **not** — it is an uninitialized typed property, and touching it throws `Error: typed property must not be accessed before initialization`.

Therefore `AuditRedactor` must obtain toolkit-declared credential names **lazily, through a closure**, and must swallow the resulting `Error`/`Throwable` if invoked before discovery exists. This is why Task 1's constructor takes a `?\Closure` rather than a `ToolkitDiscovery`.

Also note: `CredentialResolver::keys()` reads only workspace `.env` keys, **not** process-env keys, while `get()` falls back to `getenv()`. The union with toolkit-declared names plus `EXTRA_NAMES` is what covers process-env-only credentials.

`CredentialResolver::loadEnvFile()` is deliberately re-read on every `get()` for hot-reload, so calling `keys()` once per redaction costs no more than one existing credential lookup. Do **not** add a name cache or a `refresh()` method — resolving fresh each time is correct by construction and leaves no unused API to rot.

---

## Task 1: AuditRedactor

**Files:**
- Create: `src/Contract/AuditRedactorInterface.php`
- Create: `src/Storage/AuditRedactor.php`
- Modify: `tests/Pest.php` (add the `fakeCredentials()` helper)
- Test: `tests/Unit/Storage/AuditRedactorTest.php`

**Interfaces:**
- Consumes: `CoquiBot\Coqui\Contract\CredentialResolverInterface` — `get(string $key): ?string`, `keys(): array` (`@return string[]`).
- Produces:
  - `CoquiBot\Coqui\Contract\AuditRedactorInterface` — `redact(array $arguments): array`, `redactScalar(?string $value): ?string`
  - `AuditRedactor implements AuditRedactorInterface`
  - `AuditRedactor::__construct(?CredentialResolverInterface $credentials = null, ?\Closure $toolkitCredentialNames = null, array $extraNames = [])`
  - `AuditRedactor::PLACEHOLDER` = `'[REDACTED]'`

**Why an interface:** `AuditRedactor` is `final` per the global constraints, so Task 2's fail-closed test cannot subclass it to simulate a throwing redactor. `SessionStorage` therefore depends on the interface, and the test supplies a throwing implementation. This matches the existing `src/Contract/` convention (`CredentialResolverInterface`).

- [ ] **Step 1: Add the shared test helper**

Append to `tests/Pest.php` (it is autoloaded for the whole suite, so every test
file can use it — this is required because Task 2's tests run standalone):

```php
/**
 * @param array<string, string> $values
 */
function fakeCredentials(array $values): CoquiBot\Coqui\Contract\CredentialResolverInterface
{
    return new class($values) implements CoquiBot\Coqui\Contract\CredentialResolverInterface {
        /** @param array<string, string> $values */
        public function __construct(private array $values) {}

        public function get(string $key): ?string
        {
            return $this->values[$key] ?? null;
        }

        public function has(string $key): bool
        {
            return isset($this->values[$key]);
        }

        public function set(string $key, string $value): void
        {
            $this->values[$key] = $value;
        }

        public function delete(string $key): void
        {
            unset($this->values[$key]);
        }

        public function loadIntoProcessEnv(): void {}

        /** @return string[] */
        public function keys(): array
        {
            return array_keys($this->values);
        }

        public function envPath(): string
        {
            return '/tmp/.env';
        }
    };
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Storage/AuditRedactorTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\AuditRedactor;

covers(AuditRedactor::class);

test('L1 redacts a known credential value embedded in an exec command', function (): void {
    $redactor = new AuditRedactor(fakeCredentials(['GITHUB_TOKEN' => 'supersecretvalue123']));

    $result = $redactor->redact([
        'command' => 'curl -H "X-Token: supersecretvalue123" https://api.example.com',
    ]);

    expect($result['command'])->not->toContain('supersecretvalue123');
    expect($result['command'])->toContain('[REDACTED]');
    expect($result['command'])->toContain('curl -H');
});

test('L1 resolves values at call time so a credential added later is still redacted', function (): void {
    $credentials = fakeCredentials([]);
    $redactor = new AuditRedactor($credentials);

    $credentials->set('LATE_KEY', 'added-after-construction');

    $result = $redactor->redact(['note' => 'value is added-after-construction here']);

    expect($result['note'])->not->toContain('added-after-construction');
});

test('L1 ignores empty credential values so unrelated text is untouched', function (): void {
    $redactor = new AuditRedactor(fakeCredentials(['EMPTY_KEY' => '']));

    $result = $redactor->redact(['command' => 'echo hello']);

    expect($result['command'])->toBe('echo hello');
});

test('L2 redacts values under sensitive key names, recursively', function (): void {
    $redactor = new AuditRedactor();

    $result = $redactor->redact([
        'config' => [
            'password' => 'hunter2',
            'nested' => ['api_key' => 'abcdef', 'harmless' => 'keep-me'],
        ],
    ]);

    expect($result['config']['password'])->toBe('[REDACTED]');
    expect($result['config']['nested']['api_key'])->toBe('[REDACTED]');
    expect($result['config']['nested']['harmless'])->toBe('keep-me');
});

test('L2 key matching is case-insensitive', function (): void {
    $redactor = new AuditRedactor();

    $result = $redactor->redact(['Authorization' => 'Basic abc', 'API_KEY' => 'xyz']);

    expect($result['Authorization'])->toBe('[REDACTED]');
    expect($result['API_KEY'])->toBe('[REDACTED]');
});

test('L3 redacts a Bearer token with no credential store at all', function (): void {
    $redactor = new AuditRedactor();

    $result = $redactor->redact([
        'command' => 'curl -H "Authorization: Bearer sk-live-abc123def456ghi789" https://x.test',
    ]);

    expect($result['command'])->not->toContain('sk-live-abc123def456ghi789');
    expect($result['command'])->toContain('[REDACTED]');
});

test('L3 redacts provider-prefixed tokens and PEM blocks', function (): void {
    $redactor = new AuditRedactor();

    $result = $redactor->redact([
        'a' => 'token ghp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa here',
        'b' => 'slack xoxb-1111111111-2222222222-abcdefghijkl end',
        'c' => "-----BEGIN RSA PRIVATE KEY-----\nMIIabc\n-----END RSA PRIVATE KEY-----",
    ]);

    expect($result['a'])->not->toContain('ghp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    expect($result['b'])->not->toContain('xoxb-1111111111-2222222222-abcdefghijkl');
    expect($result['c'])->not->toContain('MIIabc');
});

test('arguments with no secrets pass through completely unchanged', function (): void {
    $redactor = new AuditRedactor(fakeCredentials(['GITHUB_TOKEN' => 'supersecretvalue123']));

    $input = [
        'path' => '/tmp/notes.md',
        'content' => 'Just some ordinary text.',
        'count' => 42,
        'flag' => true,
        'nothing' => null,
        'list' => ['a', 'b'],
    ];

    expect($redactor->redact($input))->toBe($input);
});

test('redactScalar redacts reason text and passes null through', function (): void {
    $redactor = new AuditRedactor(fakeCredentials(['TOKEN' => 'reason-secret-value']));

    expect($redactor->redactScalar('prompt mentioning reason-secret-value'))
        ->not->toContain('reason-secret-value');
    expect($redactor->redactScalar(null))->toBeNull();
    expect($redactor->redactScalar('plain prompt'))->toBe('plain prompt');
});

test('a throwing toolkit-name provider does not break redaction', function (): void {
    $redactor = new AuditRedactor(
        fakeCredentials(['GITHUB_TOKEN' => 'supersecretvalue123']),
        static fn (): array => throw new Error('typed property not initialized'),
    );

    $result = $redactor->redact(['command' => 'echo supersecretvalue123']);

    expect($result['command'])->not->toContain('supersecretvalue123');
});

test('extra names are resolved through the credential resolver', function (): void {
    $redactor = new AuditRedactor(
        fakeCredentials(['COQUI_API_KEY' => 'core-key-value-here']),
        null,
        ['COQUI_API_KEY'],
    );

    $result = $redactor->redact(['command' => 'auth core-key-value-here']);

    expect($result['command'])->not->toContain('core-key-value-here');
});

test('object values are stringified rather than crashing', function (): void {
    $redactor = new AuditRedactor();

    $result = $redactor->redact(['obj' => (object) ['password' => 'hunter2']]);

    expect(json_encode($result))->not->toContain('hunter2');
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/AuditRedactorTest.php`
Expected: FAIL — `Class "CoquiBot\Coqui\Storage\AuditRedactor" not found`.

- [ ] **Step 4: Write the interface**

Create `src/Contract/AuditRedactorInterface.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Removes secrets from audit-log payloads before they are persisted.
 *
 * SessionStorage depends on this contract rather than the concrete redactor so
 * the fail-closed write path can be tested with a deliberately throwing
 * implementation.
 */
interface AuditRedactorInterface
{
    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function redact(array $arguments): array;

    public function redactScalar(?string $value): ?string;
}
```

- [ ] **Step 5: Write the implementation**

Create `src/Storage/AuditRedactor.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Contract\AuditRedactorInterface;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;

/**
 * Redacts secrets out of audit-log payloads before they are persisted.
 *
 * Three layers, applied in order over the whole argument tree and over the
 * free-text `reason`:
 *
 *   L1 — exact occurrences of resolved credential VALUES (most precise)
 *   L2 — values sitting under a sensitive KEY name (structured secrets)
 *   L3 — high-confidence value PATTERNS (free-text embeds the first two miss)
 *
 * Credential values are never held as state: names are collected per call and
 * values resolved through the resolver, which re-reads the workspace .env on
 * every lookup for hot-reload.
 */
final class AuditRedactor implements AuditRedactorInterface
{
    public const string PLACEHOLDER = '[REDACTED]';

    /** Key names whose value is always redacted, matched case-insensitively as a substring. */
    private const array SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'token',
        'secret',
        'api_key',
        'apikey',
        'authorization',
        'auth',
        'credential',
        'private_key',
        'privatekey',
    ];

    /** High-confidence secret shapes. Each must match the secret itself, not surrounding text. */
    private const array VALUE_PATTERNS = [
        '/Bearer\s+[A-Za-z0-9._\-]{8,}/i',
        '/\bsk-[A-Za-z0-9._\-]{8,}/',
        '/\bghp_[A-Za-z0-9]{20,}/',
        '/\bgithub_pat_[A-Za-z0-9_]{20,}/',
        '/\bxox[bpsar]-[A-Za-z0-9\-]{10,}/',
        '/\beyJ[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}\.[A-Za-z0-9_\-]{8,}/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
    ];

    /**
     * @param \Closure(): array<int, string>|null $toolkitCredentialNames Lazy provider for
     *        toolkit-declared credential names. Invoked per redaction and guarded, because
     *        ToolkitDiscovery is initialized AFTER SessionStorage during boot.
     * @param array<int, string> $extraNames Additional credential names (core, provider, api.key).
     */
    public function __construct(
        private readonly ?CredentialResolverInterface $credentials = null,
        private readonly ?\Closure $toolkitCredentialNames = null,
        private readonly array $extraNames = [],
    ) {}

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function redact(array $arguments): array
    {
        $values = $this->secretValues();

        /** @var array<string, mixed> $result */
        $result = $this->redactNode($arguments, $values, false);

        return $result;
    }

    public function redactScalar(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return $this->redactString($value, $this->secretValues());
    }

    /**
     * Walk any node. $underSensitiveKey short-circuits to a full replacement (L2).
     *
     * @param array<int, string> $values
     */
    private function redactNode(mixed $node, array $values, bool $underSensitiveKey): mixed
    {
        if ($underSensitiveKey && $node !== null) {
            return self::PLACEHOLDER;
        }

        if (is_array($node)) {
            $out = [];
            foreach ($node as $key => $child) {
                $sensitive = is_string($key) && $this->isSensitiveKey($key);
                $out[$key] = $this->redactNode($child, $values, $sensitive);
            }

            return $out;
        }

        if (is_object($node)) {
            $encoded = json_decode(json_encode($node) ?: '{}', true);

            return is_array($encoded)
                ? $this->redactNode($encoded, $values, false)
                : self::PLACEHOLDER;
        }

        if (is_string($node)) {
            return $this->redactString($node, $values);
        }

        return $node;
    }

    private function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $values
     */
    private function redactString(string $text, array $values): string
    {
        // L1 — exact known values first, so the placeholder survives L3.
        foreach ($values as $secret) {
            if ($secret !== '' && str_contains($text, $secret)) {
                $text = str_replace($secret, self::PLACEHOLDER, $text);
            }
        }

        // L3 — pattern backstop.
        foreach (self::VALUE_PATTERNS as $pattern) {
            $replaced = preg_replace($pattern, self::PLACEHOLDER, $text);
            if (is_string($replaced)) {
                $text = $replaced;
            }
        }

        return $text;
    }

    /**
     * Resolve every candidate credential name to its current value.
     *
     * @return array<int, string>
     */
    private function secretValues(): array
    {
        if ($this->credentials === null) {
            return [];
        }

        $names = $this->extraNames;

        try {
            $names = [...$names, ...$this->credentials->keys()];
        } catch (\Throwable) {
            // A broken resolver must not stop L2/L3 from running.
        }

        if ($this->toolkitCredentialNames !== null) {
            try {
                $names = [...$names, ...($this->toolkitCredentialNames)()];
            } catch (\Throwable) {
                // Discovery is not initialized yet during early boot. Expected; ignore.
            }
        }

        $values = [];
        foreach (array_unique($names) as $name) {
            try {
                $value = $this->credentials->get($name);
            } catch (\Throwable) {
                continue;
            }

            if (is_string($value) && $value !== '') {
                $values[] = $value;
            }
        }

        // Longest first, so a secret that contains another is redacted whole.
        usort($values, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $values;
    }
}
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Storage/AuditRedactorTest.php`
Expected: PASS, 12 tests.

- [ ] **Step 7: Static analysis**

Run: `./vendor/bin/phpstan analyse src/Contract/AuditRedactorInterface.php src/Storage/AuditRedactor.php --memory-limit=512M`
Expected: `[OK] No errors`.

- [ ] **Step 8: Commit**

```bash
git add src/Contract/AuditRedactorInterface.php src/Storage/AuditRedactor.php tests/Pest.php tests/Unit/Storage/AuditRedactorTest.php
git commit -m "feat(audit): add AuditRedactor with known-value, key-name, and pattern layers"
```

---

## Task 2: Apply the redactor in SessionStorage::logAudit, fail-closed

**Files:**
- Modify: `src/Storage/SessionStorage.php` (constructor at `:37`; `logAudit()` at `:1755`)
- Test: `tests/Unit/Storage/AuditLogRedactionTest.php`

**Interfaces:**
- Consumes: `AuditRedactorInterface::redact()`, `AuditRedactorInterface::redactScalar()`, `AuditRedactor::PLACEHOLDER` from Task 1.
- Produces: `SessionStorage::__construct(string $dbPath, ?\Closure $expectedCoquiProcessChecker = null, ?AuditRedactorInterface $auditRedactor = null)`. The third parameter **must** be passed as the named argument `auditRedactor:` at every production call site — Task 3 adds a test that enforces this.

**This task contains the required negative control.** The first test below fails if the redactor is removed, stubbed, or silently no-ops.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Storage/AuditLogRedactionTest.php`:

```php
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
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/AuditLogRedactionTest.php`
Expected: FAIL — `SessionStorage::__construct()` does not accept `auditRedactor:`.

- [ ] **Step 3: Add the constructor parameter**

In `src/Storage/SessionStorage.php`, add the property near the other private properties and change the constructor at `:37`:

```php
    private ?AuditRedactorInterface $auditRedactor;

    public function __construct(
        string $dbPath,
        ?\Closure $expectedCoquiProcessChecker = null,
        ?AuditRedactorInterface $auditRedactor = null,
    ) {
        $this->auditRedactor = $auditRedactor;
        $this->processChecker = new CoquiProcessChecker($expectedCoquiProcessChecker);
```

Leave the rest of the constructor body untouched. Add `use CoquiBot\Coqui\Contract\AuditRedactorInterface;` to the imports.

- [ ] **Step 4: Apply redaction inside logAudit**

Replace the body of `logAudit()` between `$now = date('c');` and the `$stmt = $this->db->prepare(...)` call, and change the two bound values. The full method becomes:

```php
    public function logAudit(
        ?string $sessionId,
        string $toolName,
        array $arguments,
        string $action,
        ?string $reason = null,
        ?string $turnId = null,
    ): string {
        $id = IdGenerator::hex();
        $now = date('c');

        // Fail-closed: a redaction or encoding failure must never fall back to
        // the raw arguments. Persist a placeholder instead.
        try {
            $safeArguments = $this->auditRedactor?->redact($arguments) ?? $arguments;
            $encodedArguments = json_encode(
                $safeArguments,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable) {
            $encodedArguments = '{"_redaction":"redaction-failed"}';
        }

        try {
            $safeReason = $this->auditRedactor?->redactScalar($reason) ?? $reason;
        } catch (\Throwable) {
            $safeReason = '[redaction-failed]';
        }

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO audit_log (id, session_id, tool_name, arguments, action, reason, turn_id, created_at)
            VALUES (:id, :session_id, :tool_name, :arguments, :action, :reason, :turn_id, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'tool_name' => $toolName,
            'arguments' => $encodedArguments,
            'action' => $action,
            'reason' => $safeReason,
            'turn_id' => $turnId,
            'created_at' => $now,
        ]);

        return $id;
    }
```

Note the two behavioural changes beyond redaction: `JSON_THROW_ON_ERROR` replaces the silent `false` return of the old `json_encode`, and the `false` could previously be bound into a `NOT NULL TEXT` column as an empty string.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Storage/AuditLogRedactionTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 6: Prove the negative control actually controls**

Temporarily comment out the redaction line so it reads `$safeArguments = $arguments;`, then run:

Run: `./vendor/bin/pest tests/Unit/Storage/AuditLogRedactionTest.php`
Expected: **FAIL** — "logAudit never persists a secret present in exec arguments".

If it still passes, the test is decorative and must be fixed before continuing. Restore the line afterwards and re-run to confirm PASS.

- [ ] **Step 7: Full suite + analysis**

Run: `composer test`
Expected: all green, count above 2393.

Run: `composer analyse`
Expected: `[OK]`.

- [ ] **Step 8: Commit**

```bash
git add src/Storage/SessionStorage.php tests/Unit/Storage/AuditLogRedactionTest.php
git commit -m "feat(audit): redact arguments and reason in logAudit, fail-closed"
```

---

## Task 3: Wire the redactor at all seven construction sites

**Files:**
- Modify: `src/Config/BootManager.php`
- Modify: `src/Command/TurnRunCommand.php`, `src/Command/ApiCommand.php`, `src/Command/SessionTitleRunCommand.php`, `src/Command/TaskRunCommand.php`, `src/Command/DoctorCommand.php`, `src/Command/RunCommand.php`
- Test: `tests/Unit/Storage/AuditRedactorWiringTest.php`

**Interfaces:**
- Consumes: `AuditRedactor` (Task 1); `SessionStorage::__construct(..., auditRedactor:)` (Task 2).
- Produces: `BootManager::auditRedactor(): AuditRedactor`.

- [ ] **Step 1: Write the failing wiring test**

This test enumerates construction sites from source rather than booting the app, so it catches an eighth site added later. Create `tests/Unit/Storage/AuditRedactorWiringTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\AuditRedactor;

test('every production SessionStorage construction attaches an audit redactor', function (): void {
    $srcDir = dirname(__DIR__, 3) . '/src';

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
    $offenders = [];
    $siteCount = 0;

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname()) ?: '';
        $offset = 0;

        while (($pos = strpos($contents, 'new SessionStorage(', $offset)) !== false) {
            $offset = $pos + 1;
            $siteCount++;

            // Look at the call through to its closing paren, allowing multi-line calls.
            $window = substr($contents, $pos, 400);

            if (!str_contains($window, 'auditRedactor:')) {
                $line = substr_count(substr($contents, 0, $pos), "\n") + 1;
                $offenders[] = str_replace($srcDir . '/', '', $file->getPathname()) . ':' . $line;
            }
        }
    }

    expect($siteCount)->toBeGreaterThanOrEqual(7);
    expect($offenders)->toBe([]);
});

test('BootManager exposes an AuditRedactor', function (): void {
    $method = new ReflectionMethod(CoquiBot\Coqui\Config\BootManager::class, 'auditRedactor');

    expect((string) $method->getReturnType())->toBe(AuditRedactor::class);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/AuditRedactorWiringTest.php`
Expected: FAIL — seven offenders listed, and `auditRedactor` method missing.

- [ ] **Step 3: Add the redactor to BootManager**

In `src/Config/BootManager.php`, add the property beside `credentialResolver` and `discovery`:

```php
    private AuditRedactor $auditRedactor;
```

Add the `use CoquiBot\Coqui\Storage\AuditRedactor;` import.

In `initializeCredentials()` — which runs **before** `initializeArtifacts()` — append the redactor construction after `$this->credentialResolver` is assigned:

```php
        // Toolkit names are provided lazily: ToolkitDiscovery is initialized after
        // this point in boot(), so eager access would hit an uninitialized property.
        $this->auditRedactor = new AuditRedactor(
            $this->credentialResolver,
            fn (): array => array_keys($this->discovery->collectAllCredentialRequirements()),
            ['COQUI_API_KEY'],
        );
```

Add the accessor beside `credentialResolver()` at `:181`:

```php
    public function auditRedactor(): AuditRedactor
    {
        return $this->auditRedactor;
    }
```

Then update the construction in `initializeArtifacts()`:

```php
        $storage = new SessionStorage($dbPath, auditRedactor: $this->auditRedactor);
```

- [ ] **Step 4: Update the six command sites**

For each file below, open the `new SessionStorage(` call and add the named argument. The redactor comes from the already-booted `BootManager` in scope (`$boot` in the run commands, `$this->boot` in `RunCommand`). **Read each site before editing** — the variable holding the BootManager differs, and `bootForWizard()` never initializes credentials, so if any site runs on that path it must pass `auditRedactor: null` explicitly and you must note it in your task report.

- `src/Command/TurnRunCommand.php:85`
- `src/Command/ApiCommand.php:138`
- `src/Command/SessionTitleRunCommand.php:66`
- `src/Command/TaskRunCommand.php:86`
- `src/Command/DoctorCommand.php:439`
- `src/Command/RunCommand.php:204`

Each becomes, adapting the receiver name:

```php
        $storage = new SessionStorage($dbPath, auditRedactor: $boot->auditRedactor());
```

If a site constructs `SessionStorage` **before** `boot()` has run, do not paper over it — construct the storage after boot, or report the ordering conflict in your task report rather than passing `null` silently.

- [ ] **Step 5: Run the wiring test**

Run: `./vendor/bin/pest tests/Unit/Storage/AuditRedactorWiringTest.php`
Expected: PASS, 2 tests, `$offenders` empty.

- [ ] **Step 6: Full suite + analysis**

Run: `composer test && composer analyse`
Expected: green, `[OK]`.

- [ ] **Step 7: Commit**

```bash
git add src/Config/BootManager.php src/Command tests/Unit/Storage/AuditRedactorWiringTest.php
git commit -m "feat(audit): wire AuditRedactor at all seven SessionStorage construction sites"
```

**Part A is complete at this commit. Do not start Part B until the full suite is green here** — the spec forbids a read surface over an unredacted write path.

---

## Task 4: AuditLogQuery + AuditLogStore

**Files:**
- Create: `src/Storage/AuditLogQuery.php`, `src/Storage/AuditLogStore.php`
- Test: `tests/Unit/Storage/AuditLogStoreTest.php`

**Interfaces:**
- Produces:
  - `AuditLogQuery::__construct(?string $sessionId, ?string $toolName, ?string $action, ?string $after, ?string $before, int $limit, int $offset)` with `MAX_LIMIT = 500`, `DEFAULT_LIMIT = 100`
  - `AuditLogQuery::fromParams(array $params): self` — throws `\InvalidArgumentException` on a malformed timestamp
  - `AuditLogStore::__construct(PDO $db)`
  - `AuditLogStore::query(AuditLogQuery $query): array` — list of rows with keys `id, session_id, turn_id, tool_name, action, reason, arguments, created_at`; `arguments` is a decoded array
  - `AuditLogStore::count(AuditLogQuery $query): int`

Time semantics: `after` inclusive (`>=`), `before` exclusive (`<`). Ordering: `created_at DESC, id DESC` — `created_at` is second-resolution `date('c')`, so `id` is a required tiebreaker for stable pagination.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Storage/AuditLogStoreTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\AuditLogQuery;
use CoquiBot\Coqui\Storage\AuditLogStore;
use CoquiBot\Coqui\Storage\SessionStorage;

covers(AuditLogStore::class);
covers(AuditLogQuery::class);

function auditStoreFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-audit-store-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    return ['dbPath' => $dbPath, 'storage' => $storage, 'store' => new AuditLogStore($storage->getPdo())];
}

test('query returns rows newest first with turn_id and decoded arguments', function (): void {
    $f = auditStoreFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        $turnId = 'turn-abc';

        $f['storage']->logAudit($sessionId, 'exec', ['command' => 'echo one'], 'auto_approved', null, $turnId);
        $f['storage']->logAudit($sessionId, 'write_file', ['path' => '/tmp/x'], 'auto_approved');

        $rows = $f['store']->query(new AuditLogQuery());

        expect($rows)->toHaveCount(2);
        expect($rows[0])->toHaveKeys(['id', 'session_id', 'turn_id', 'tool_name', 'action', 'reason', 'arguments', 'created_at']);
        expect($rows[0]['arguments'])->toBeArray();
        expect(array_column($rows, 'turn_id'))->toContain($turnId);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('filters by session, tool, and action', function (): void {
    $f = auditStoreFixture();

    try {
        $a = $f['storage']->createSession('orchestrator', 'test/model');
        $b = $f['storage']->createSession('orchestrator', 'test/model');

        $f['storage']->logAudit($a, 'exec', ['command' => 'one'], 'auto_approved');
        $f['storage']->logAudit($a, 'exec', ['command' => 'two'], 'blocked');
        $f['storage']->logAudit($b, 'write_file', ['path' => '/tmp/y'], 'auto_approved');

        expect($f['store']->query(new AuditLogQuery(sessionId: $a)))->toHaveCount(2);
        expect($f['store']->query(new AuditLogQuery(toolName: 'exec')))->toHaveCount(2);
        expect($f['store']->query(new AuditLogQuery(action: 'blocked')))->toHaveCount(1);
        expect($f['store']->query(new AuditLogQuery(sessionId: $a, action: 'blocked')))->toHaveCount(1);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('pagination is deterministic across pages with identical timestamps', function (): void {
    $f = auditStoreFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        for ($i = 0; $i < 10; $i++) {
            $f['storage']->logAudit($sessionId, 'exec', ['n' => $i], 'auto_approved');
        }

        $page1 = $f['store']->query(new AuditLogQuery(limit: 4, offset: 0));
        $page2 = $f['store']->query(new AuditLogQuery(limit: 4, offset: 4));
        $page3 = $f['store']->query(new AuditLogQuery(limit: 4, offset: 8));

        $ids = [...array_column($page1, 'id'), ...array_column($page2, 'id'), ...array_column($page3, 'id')];

        expect($ids)->toHaveCount(10);
        expect(array_unique($ids))->toHaveCount(10);
        expect($f['store']->count(new AuditLogQuery()))->toBe(10);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('count ignores limit and offset but honours filters', function (): void {
    $f = auditStoreFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        for ($i = 0; $i < 5; $i++) {
            $f['storage']->logAudit($sessionId, 'exec', ['n' => $i], 'auto_approved');
        }
        $f['storage']->logAudit($sessionId, 'exec', ['n' => 99], 'blocked');

        expect($f['store']->count(new AuditLogQuery(limit: 2)))->toBe(6);
        expect($f['store']->count(new AuditLogQuery(action: 'blocked')))->toBe(1);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('a row with undecodable arguments falls back rather than throwing', function (): void {
    $f = auditStoreFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        $id = $f['storage']->logAudit($sessionId, 'exec', ['ok' => true], 'auto_approved');

        $f['storage']->getPdo()
            ->prepare('UPDATE audit_log SET arguments = :a WHERE id = :id')
            ->execute(['a' => 'not json at all', 'id' => $id]);

        $rows = $f['store']->query(new AuditLogQuery());

        expect($rows[0]['arguments'])->toBe(['_raw' => 'not json at all']);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('fromParams clamps limit and offset into range', function (): void {
    expect(AuditLogQuery::fromParams([])->limit)->toBe(100);
    expect(AuditLogQuery::fromParams(['limit' => '9999'])->limit)->toBe(500);
    expect(AuditLogQuery::fromParams(['limit' => '0'])->limit)->toBe(1);
    expect(AuditLogQuery::fromParams(['limit' => '-5'])->limit)->toBe(1);
    expect(AuditLogQuery::fromParams(['offset' => '-3'])->offset)->toBe(0);
    expect(AuditLogQuery::fromParams(['offset' => '25'])->offset)->toBe(25);
});

test('fromParams rejects a malformed timestamp', function (): void {
    expect(fn () => AuditLogQuery::fromParams(['after' => 'not-a-date']))
        ->toThrow(InvalidArgumentException::class);
});

test('fromParams accepts ISO-8601 boundaries and applies inclusive/exclusive semantics', function (): void {
    $f = auditStoreFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        $id = $f['storage']->logAudit($sessionId, 'exec', ['n' => 1], 'auto_approved');

        $stmt = $f['storage']->getPdo()->prepare('SELECT created_at FROM audit_log WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $createdAt = (string) $stmt->fetchColumn();

        // `after` is inclusive: the row's own timestamp still matches.
        expect($f['store']->query(AuditLogQuery::fromParams(['after' => $createdAt])))->toHaveCount(1);

        // `before` is exclusive: the row's own timestamp does not match.
        expect($f['store']->query(AuditLogQuery::fromParams(['before' => $createdAt])))->toHaveCount(0);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Storage/AuditLogStoreTest.php`
Expected: FAIL — `AuditLogQuery` not found.

- [ ] **Step 3: Write AuditLogQuery**

Create `src/Storage/AuditLogQuery.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

/**
 * Validated filter/pagination criteria for an audit-log read.
 *
 * Time semantics: `after` is inclusive (>=), `before` is exclusive (<).
 */
final readonly class AuditLogQuery
{
    public const int MAX_LIMIT = 500;
    public const int DEFAULT_LIMIT = 100;

    public function __construct(
        public ?string $sessionId = null,
        public ?string $toolName = null,
        public ?string $action = null,
        public ?string $after = null,
        public ?string $before = null,
        public int $limit = self::DEFAULT_LIMIT,
        public int $offset = 0,
    ) {}

    /**
     * @param array<string, mixed> $params Raw query parameters.
     *
     * @throws \InvalidArgumentException When a timestamp boundary is not parseable.
     */
    public static function fromParams(array $params): self
    {
        return new self(
            sessionId: self::str($params, 'session_id'),
            toolName: self::str($params, 'tool_name'),
            action: self::str($params, 'action'),
            after: self::timestamp($params, 'after'),
            before: self::timestamp($params, 'before'),
            limit: isset($params['limit'])
                ? max(1, min((int) $params['limit'], self::MAX_LIMIT))
                : self::DEFAULT_LIMIT,
            offset: isset($params['offset']) ? max(0, (int) $params['offset']) : 0,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function str(array $params, string $key): ?string
    {
        $value = $params[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws \InvalidArgumentException
     */
    private static function timestamp(array $params, string $key): ?string
    {
        $value = self::str($params, $key);

        if ($value === null) {
            return null;
        }

        if (strtotime($value) === false) {
            throw new \InvalidArgumentException("Invalid ISO-8601 timestamp for \"{$key}\": {$value}");
        }

        return $value;
    }
}
```

- [ ] **Step 4: Write AuditLogStore**

Create `src/Storage/AuditLogStore.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

/**
 * Read side of the audit log.
 *
 * Shares the same PDO instance as SessionStorage, which owns the table and
 * remains the sole write path (see SessionStorage::logAudit).
 */
final class AuditLogStore
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->createIndexes();
    }

    private function createIndexes(): void
    {
        // session/action/turn indexes are created by SessionStorage; these two
        // serve the new time-ordered and tool-filtered query paths.
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_tool ON audit_log(tool_name)');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function query(AuditLogQuery $query): array
    {
        [$where, $params] = $this->conditions($query);

        $sql = 'SELECT id, session_id, turn_id, tool_name, action, reason, arguments, created_at
                FROM audit_log
                ' . $where . '
                ORDER BY created_at DESC, id DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limit', $query->limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $query->offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->decodeRow(...), $rows);
    }

    public function count(AuditLogQuery $query): int
    {
        [$where, $params] = $this->conditions($query);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM audit_log ' . $where);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function conditions(AuditLogQuery $query): array
    {
        $conditions = [];
        $params = [];

        if ($query->sessionId !== null) {
            $conditions[] = 'session_id = :session_id';
            $params[':session_id'] = $query->sessionId;
        }

        if ($query->toolName !== null) {
            $conditions[] = 'tool_name = :tool_name';
            $params[':tool_name'] = $query->toolName;
        }

        if ($query->action !== null) {
            $conditions[] = 'action = :action';
            $params[':action'] = $query->action;
        }

        if ($query->after !== null) {
            $conditions[] = 'created_at >= :after';
            $params[':after'] = $query->after;
        }

        if ($query->before !== null) {
            $conditions[] = 'created_at < :before';
            $params[':before'] = $query->before;
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeRow(array $row): array
    {
        $raw = is_string($row['arguments'] ?? null) ? $row['arguments'] : '';
        $decoded = json_decode($raw, true);

        $row['arguments'] = is_array($decoded) ? $decoded : ['_raw' => $raw];

        return $row;
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Storage/AuditLogStoreTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 6: Analysis and commit**

```bash
./vendor/bin/phpstan analyse src/Storage/AuditLogStore.php src/Storage/AuditLogQuery.php --memory-limit=512M
git add src/Storage/AuditLogQuery.php src/Storage/AuditLogStore.php tests/Unit/Storage/AuditLogStoreTest.php
git commit -m "feat(audit): add AuditLogStore read side with validated query object"
```

---

## Task 5: API endpoints

**Files:**
- Create: `src/Api/Handler/AuditHandler.php`
- Modify: `src/Command/ApiCommand.php`
- Test: `tests/Unit/Api/Handler/AuditHandlerTest.php`

**Interfaces:**
- Consumes: `AuditLogStore::query()`, `AuditLogStore::count()`, `AuditLogQuery::fromParams()` (Task 4); `SessionAccess::requireReadableSession(SessionStorage $storage, string $sessionId): array|Response`; `Router::jsonResponse(array $data, int $status = 200, array $headers = []): Response`; `Router::errorResponse(ApiErrorCode $code, string $message, mixed $details = null): Response`.
- Produces: `AuditHandler::register(Router $router): void`, `AuditHandler::list(ServerRequestInterface $request): Response`, `AuditHandler::listForSession(ServerRequestInterface $request, string $id): Response`.

Response envelope: `{"entries": [...], "total": N, "limit": L, "offset": O}`; the session-scoped route adds `"session_id"`. 401 is emitted by auth middleware — never construct it in the handler.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Api/Handler/AuditHandlerTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\AuditHandler;
use CoquiBot\Coqui\Storage\AuditLogStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

covers(AuditHandler::class);

function auditHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-audit-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $store = new AuditLogStore($storage->getPdo());

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'handler' => new AuditHandler($store, $storage),
    ];
}

test('GET /api/v1/audit returns the paginated envelope', function (): void {
    $f = auditHandlerFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        for ($i = 0; $i < 3; $i++) {
            $f['storage']->logAudit($sessionId, 'exec', ['n' => $i], 'auto_approved');
        }

        $response = $f['handler']->list(new ServerRequest('GET', '/api/v1/audit'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body)->toHaveKeys(['entries', 'total', 'limit', 'offset']);
        expect($body['entries'])->toHaveCount(3);
        expect($body['total'])->toBe(3);
        expect($body['limit'])->toBe(100);
        expect($body['offset'])->toBe(0);
        expect($body['entries'][0]['arguments'])->toBeArray();
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('total reflects the filtered set, not the returned page', function (): void {
    $f = auditHandlerFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        for ($i = 0; $i < 6; $i++) {
            $f['storage']->logAudit($sessionId, 'exec', ['n' => $i], 'auto_approved');
        }

        $request = (new ServerRequest('GET', '/api/v1/audit'))->withQueryParams(['limit' => '2']);
        $body = json_decode((string) $f['handler']->list($request)->getBody(), true);

        expect($body['entries'])->toHaveCount(2);
        expect($body['total'])->toBe(6);
        expect($body['limit'])->toBe(2);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('filters are applied from query parameters', function (): void {
    $f = auditHandlerFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        $f['storage']->logAudit($sessionId, 'exec', ['n' => 1], 'auto_approved');
        $f['storage']->logAudit($sessionId, 'exec', ['n' => 2], 'blocked');
        $f['storage']->logAudit($sessionId, 'write_file', ['n' => 3], 'auto_approved');

        $byTool = (new ServerRequest('GET', '/api/v1/audit'))->withQueryParams(['tool_name' => 'exec']);
        expect(json_decode((string) $f['handler']->list($byTool)->getBody(), true)['total'])->toBe(2);

        $byAction = (new ServerRequest('GET', '/api/v1/audit'))->withQueryParams(['action' => 'blocked']);
        expect(json_decode((string) $f['handler']->list($byAction)->getBody(), true)['total'])->toBe(1);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('an invalid timestamp returns 400 validation_error', function (): void {
    $f = auditHandlerFixture();

    try {
        $request = (new ServerRequest('GET', '/api/v1/audit'))->withQueryParams(['after' => 'nonsense']);
        $response = $f['handler']->list($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['code'])->toBe('validation_error');
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('session-scoped route echoes the session id and scopes results', function (): void {
    $f = auditHandlerFixture();

    try {
        $a = $f['storage']->createSession('orchestrator', 'test/model');
        $b = $f['storage']->createSession('orchestrator', 'test/model');
        $f['storage']->logAudit($a, 'exec', ['n' => 1], 'auto_approved');
        $f['storage']->logAudit($b, 'exec', ['n' => 2], 'auto_approved');

        $response = $f['handler']->listForSession(new ServerRequest('GET', "/api/v1/sessions/{$a}/audit"), $a);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['session_id'])->toBe($a);
        expect($body['total'])->toBe(1);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('session-scoped route returns 404 for an unknown session', function (): void {
    $f = auditHandlerFixture();

    try {
        $response = $f['handler']->listForSession(new ServerRequest('GET', '/api/v1/sessions/nope/audit'), 'nope');

        expect($response->getStatusCode())->toBe(404);
        expect(json_decode((string) $response->getBody(), true)['code'])->toBe('session_not_found');
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('a session_id query parameter cannot widen a session-scoped request', function (): void {
    $f = auditHandlerFixture();

    try {
        $a = $f['storage']->createSession('orchestrator', 'test/model');
        $b = $f['storage']->createSession('orchestrator', 'test/model');
        $f['storage']->logAudit($a, 'exec', ['n' => 1], 'auto_approved');
        $f['storage']->logAudit($b, 'exec', ['n' => 2], 'auto_approved');

        $request = (new ServerRequest('GET', "/api/v1/sessions/{$a}/audit"))
            ->withQueryParams(['session_id' => $b]);

        $body = json_decode((string) $f['handler']->listForSession($request, $a)->getBody(), true);

        expect($body['session_id'])->toBe($a);
        expect($body['total'])->toBe(1);
        expect($body['entries'][0]['session_id'])->toBe($a);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('audit routes are registered as authenticated, never public', function (): void {
    $source = file_get_contents(dirname(__DIR__, 4) . '/src/Api/Handler/AuditHandler.php') ?: '';

    expect($source)->not->toContain('addPublicRoute');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/AuditHandlerTest.php`
Expected: FAIL — `AuditHandler` not found.

- [ ] **Step 3: Write the handler**

Create `src/Api/Handler/AuditHandler.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Storage\AuditLogQuery;
use CoquiBot\Coqui\Storage\AuditLogStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Audit-log read endpoints.
 *
 * GET /api/v1/audit                  — global, filterable, paginated
 * GET /api/v1/sessions/{id}/audit    — session-scoped convenience
 *
 * Both are normal authenticated routes. The API-key middleware supplies 401;
 * this handler never constructs one.
 */
final readonly class AuditHandler
{
    public function __construct(
        private AuditLogStore $store,
        private SessionStorage $storage,
    ) {}

    public function register(Router $router): void
    {
        $v1 = '/api/v1';

        $router->get($v1 . '/audit', [$this, 'list']);
        $router->get($v1 . '/sessions/{id}/audit', [$this, 'listForSession']);
    }

    /**
     * GET /api/v1/audit?session_id=&tool_name=&action=&after=&before=&limit=&offset=
     */
    public function list(ServerRequestInterface $request): Response
    {
        try {
            $query = AuditLogQuery::fromParams($request->getQueryParams());
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        return Router::jsonResponse($this->envelope($query));
    }

    /**
     * GET /api/v1/sessions/{id}/audit
     */
    public function listForSession(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $params = $request->getQueryParams();

        // The path segment is authoritative — a session_id parameter must not widen scope.
        unset($params['session_id']);

        try {
            $query = AuditLogQuery::fromParams($params);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        $scoped = new AuditLogQuery(
            sessionId: $id,
            toolName: $query->toolName,
            action: $query->action,
            after: $query->after,
            before: $query->before,
            limit: $query->limit,
            offset: $query->offset,
        );

        return Router::jsonResponse(['session_id' => $id] + $this->envelope($scoped));
    }

    /**
     * @return array<string, mixed>
     */
    private function envelope(AuditLogQuery $query): array
    {
        return [
            'entries' => $this->store->query($query),
            'total' => $this->store->count($query),
            'limit' => $query->limit,
            'offset' => $query->offset,
        ];
    }
}
```

- [ ] **Step 4: Register the handler in ApiCommand**

In `src/Command/ApiCommand.php`, alongside the other handler instantiations (near `:311`):

```php
        $auditHandler = new AuditHandler(
            new \CoquiBot\Coqui\Storage\AuditLogStore($storage->getPdo()),
            $storage,
        );
```

Add the `use CoquiBot\Coqui\Api\Handler\AuditHandler;` import, thread `$auditHandler` through the `registerRoutes(...)` call at `:369` and its signature at `:562`, and inside `registerRoutes` add — next to the other `register()` calls near `:710`:

```php
        // Audit log (authenticated; never addPublicRoute)
        $audit->register($router);
```

**Ordering matters:** `/api/v1/sessions/{id}/audit` must be registered before any route that would match `/api/v1/sessions/{id}/{something}` generically. Check the surrounding session routes and place it with them.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Api/Handler/AuditHandlerTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 6: Confirm no new public routes**

Run: `./vendor/bin/pest tests/Unit/Api/PublicRouteMiddlewareStackTest.php`
Expected: PASS — `/api/v1/health` remains the only public route.

Run: `grep -rn "addPublicRoute" src/ | grep -i audit`
Expected: no output.

- [ ] **Step 7: Full suite, analysis, commit**

```bash
composer test && composer analyse
git add src/Api/Handler/AuditHandler.php src/Command/ApiCommand.php tests/Unit/Api/Handler/AuditHandlerTest.php
git commit -m "feat(audit): add authenticated GET /audit and /sessions/{id}/audit endpoints"
```

---

## Task 6: `/audit` REPL command

**Files:**
- Create: `src/Repl/Handler/AuditHandler.php`
- Modify: `src/Repl/ReplCommandCatalog.php`, `src/Repl/SlashCommandRouter.php`, `src/Repl/TabCompletion.php`, `src/Command/RunCommand.php`
- Test: `tests/Unit/Repl/Handler/AuditHandlerTest.php`

**Interfaces:**
- Consumes: `AuditLogStore` (Task 4); `SessionStorage::getPdo()`; `CoquiBot\Coqui\Repl\TimeFormatter::timeSince(string $timestamp): string`.
- Produces: `CoquiBot\Coqui\Repl\Handler\AuditHandler::__construct(SessionStorage $storage)` and `handle(SymfonyStyle $io, string $arg, string $sessionId = ''): void`.

**Argument grammar** (word-style, matching `/loops`, not the `--limit` form sketched in the spec — flag parsing exists nowhere else in the REPL and would be a lone convention):

- `/audit` — most recent entries
- `/audit tool exec`
- `/audit session <id>`
- `/audit action blocked`
- `/audit limit 50`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Repl/Handler/AuditHandlerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Repl\Handler;

use CoquiBot\Coqui\Repl\Handler\AuditHandler;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

covers(AuditHandler::class);

function replAuditIo(): array
{
    $output = new BufferedOutput();

    return [new SymfonyStyle(new ArrayInput([]), $output), $output];
}

test('bare /audit on an empty log renders the empty-state message', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, '', '');

        expect($output->fetch())->toContain('No audit entries');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('/audit renders a table with tool and action columns', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');
        $storage->logAudit($sessionId, 'exec', ['command' => 'echo hello'], 'auto_approved');

        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, '', $sessionId);

        $rendered = $output->fetch();

        expect($rendered)->toContain('exec');
        expect($rendered)->toContain('auto_approved');
        expect($rendered)->toContain('Tool');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('/audit tool <name> filters by tool', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');
        $storage->logAudit($sessionId, 'exec', ['command' => 'findme-exec'], 'auto_approved');
        $storage->logAudit($sessionId, 'write_file', ['path' => 'findme-write'], 'auto_approved');

        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, 'tool write_file', $sessionId);

        $rendered = $output->fetch();

        expect($rendered)->toContain('write_file');
        expect($rendered)->not->toContain('exec ');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('/audit action <name> filters by action', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');
        $storage->logAudit($sessionId, 'exec', ['command' => 'one'], 'auto_approved');
        $storage->logAudit($sessionId, 'exec', ['command' => 'two'], 'blocked');

        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, 'action blocked', $sessionId);

        expect($output->fetch())->toContain('blocked');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('/audit limit <n> bounds the rendered rows', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');
        for ($i = 0; $i < 5; $i++) {
            $storage->logAudit($sessionId, 'exec', ['n' => $i], 'auto_approved');
        }

        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, 'limit 2', $sessionId);

        expect($output->fetch())->toContain('showing 2 of 5');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('/audit reports an unusable filter rather than silently listing everything', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, 'tool', '');

        expect($output->fetch())->toContain('Usage');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Repl/Handler/AuditHandlerTest.php`
Expected: FAIL — handler class not found.

- [ ] **Step 3: Write the REPL handler**

Create `src/Repl/Handler/AuditHandler.php`:

```php
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
```

- [ ] **Step 4: Register the command in the catalog**

In `src/Repl/ReplCommandCatalog.php`, add to the array in `all()`, in the `Context & Inspection` section:

```php
            new ReplCommandSpec('/audit', '/audit [tool|session|action|limit] <value>', 'Browse the audit log of approval decisions and questions.', firstArguments: ['tool', 'session', 'action', 'limit'], section: 'Context & Inspection'),
```

`tests/Unit/Repl/ReplCommandCatalogTest.php` asserts exact help rows. Read it and add the matching `/audit` row assertion in the same style — do not weaken an existing assertion to accommodate the new command.

- [ ] **Step 5: Route the command**

In `src/Repl/SlashCommandRouter.php`: add `use CoquiBot\Coqui\Repl\Handler\AuditHandler;`, add the constructor property `private readonly AuditHandler $audit,`, add the match arm next to `/loops`:

```php
            '/audit' => $this->handleAudit($io, $arg, $sessionId),
```

and the method beside `handleLoops()`:

```php
    private function handleAudit(SymfonyStyle $io, string $arg, string $sessionId): RouteResult
    {
        $this->audit->handle($io, $arg, $sessionId);

        return RouteResult::continue();
    }
```

In `src/Command/RunCommand.php`, inside the `new SlashCommandRouter(...)` call around `:397`, add the named argument:

```php
            audit: new AuditHandler($this->storage),
```

In `src/Repl/TabCompletion.php`, join the shared static-argument arm around `:86`:

```php
            '/config', '/tasks', '/prompt', '/evaluations', '/multiline', '/audit' => $this->completeStaticArguments($spec, $parts),
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Repl/`
Expected: PASS, including the catalog, router, and tab-completion tests.

- [ ] **Step 7: Full suite, analysis, commit**

```bash
composer test && composer analyse
git add src/Repl src/Command/RunCommand.php tests/Unit/Repl/Handler/AuditHandlerTest.php
git commit -m "feat(audit): add /audit REPL command as a core static catalog entry"
```

---

## Task 7: Documentation

**Files:**
- Modify: `docs/API.md`, `docs/COMMANDS.md`

**Every claim must be traceable to code you have read in Tasks 4–6.** Do not document a parameter, response key, or error code you have not seen in the implementation. There is no `config/source.json` in this repo — it was deleted in #162; do not create or reference one.

- [ ] **Step 1: Read the surrounding docs**

Run: `grep -n "loops" docs/API.md | head -20` and `grep -n "/loops" docs/COMMANDS.md | head -10`

Match the existing section structure, heading depth, and table formatting exactly. Do not invent a new documentation style.

- [ ] **Step 2: Document the endpoints in docs/API.md**

Add an "Audit Log" section following the established pattern, covering:

- `GET /api/v1/audit` — parameters `session_id`, `tool_name`, `action`, `after` (inclusive, ISO-8601), `before` (exclusive, ISO-8601), `limit` (1–500, default 100), `offset` (>= 0, default 0).
- `GET /api/v1/sessions/{id}/audit` — same filters except `session_id`, which is taken from the path; a `session_id` parameter is ignored.
- Response envelope: `{"entries": [...], "total": N, "limit": L, "offset": O}`, plus `session_id` on the scoped route.
- Entry fields: `id`, `session_id`, `turn_id`, `tool_name`, `action`, `reason`, `arguments` (decoded JSON object), `created_at`.
- Errors: `400 validation_error` for a malformed `after`/`before`; `404 session_not_found` for an unknown session on the scoped route; `401 unauthorized` without an API key, like every other authenticated route.
- A sentence stating that secrets are redacted at write time, and that the audit log records approval decisions and questions — **not** all tool or API activity. Do not overclaim its coverage.
- A sentence stating there is no export endpoint.

- [ ] **Step 3: Document the command in docs/COMMANDS.md**

Add `/audit` to the REPL command reference in the section matching its catalog `section` (`Context & Inspection`), documenting the five forms from Task 6 and noting it is browse-only.

- [ ] **Step 4: Verify every documented claim**

For each parameter and response key you wrote, confirm it exists:

Run: `grep -n "entries\|total\|limit\|offset" src/Api/Handler/AuditHandler.php`
Run: `grep -n "session_id\|tool_name\|action\|after\|before" src/Storage/AuditLogQuery.php`
Run: `grep -n "'/audit'" src/Repl/ReplCommandCatalog.php src/Repl/SlashCommandRouter.php`

Fix any documented claim not backed by a hit.

- [ ] **Step 5: Commit**

```bash
git add docs/API.md docs/COMMANDS.md
git commit -m "docs: document /audit endpoints and REPL command"
```

---

## Final Verification

- [ ] **Step 1: Full suite**

Run: `composer test`
Expected: all green, total **above 2393**, 1 skipped. Record the actual number.

- [ ] **Step 2: Static analysis**

Run: `composer analyse`
Expected: `[OK]`, file count 382 plus the six new source files.

- [ ] **Step 3: Re-prove the negative control**

Comment out the redaction line in `SessionStorage::logAudit()`, run `composer test`, and confirm it **fails**. Restore and confirm green. A suite that stays green with redaction disabled is decorative and must be fixed.

- [ ] **Step 4: Confirm no public audit routes**

Run: `grep -rn "addPublicRoute" src/`
Expected: only the health route in `ApiCommand.php`.

- [ ] **Step 5: Confirm no export or migration crept in**

Run: `grep -rniE "csv|export|migrat" src/Storage/AuditLogStore.php src/Storage/AuditRedactor.php src/Api/Handler/AuditHandler.php src/Repl/Handler/AuditHandler.php`
Expected: no output.

- [ ] **Step 6: Open the PR**

```bash
git push -u origin feat/audit-log-access
gh pr create --repo AgentCoqui/coqui --title "feat(audit): write-time secret redaction + read access" --body "Implements docs/superpowers/specs/2026-07-15-audit-log-redaction-and-access-design.md"
```

---

## Self-Review Notes

Spec coverage checked section by section:

| Spec requirement | Task |
|---|---|
| `AuditRedactor` L1/L2/L3, recursive | 1 |
| Fail-closed on redact/encode failure | 2 |
| Redact both `arguments` and `reason` | 2 |
| Resolve values at write time, no cached secrets | 1 |
| Wire at all seven construction sites | 3 |
| No legacy migration | Non-goal; absent by design, guarded by Final Verification Step 5 |
| `AuditLogStore` read side, shared PDO | 4 |
| Filters, bounded limit/offset, deterministic order, `turn_id`, decoded JSON, total | 4 |
| New indexes `created_at`, `tool_name` | 4 |
| Two authenticated endpoints, never public | 5 |
| No export / CSV | Non-goal; guarded by Final Verification Step 5 |
| `/audit` core static catalog command, no `/logs` | 6 |
| Negative control test | 2 (Step 6), re-proved in Final Verification |
| Production wiring verified, not just units | 3 |
| Docs: API.md, COMMANDS.md | 7 |

Two deliberate deviations from the spec, both flagged in place:

1. **`/audit limit 50` instead of `/audit --limit 50`.** No flag parsing exists anywhere in the REPL; a lone `--` convention in one command would be the odd one out. Task 6 documents the grammar.
2. **No name cache or `refresh()` on `AuditRedactor`.** The spec asked for a cached, refreshable name set. `CredentialResolver` already re-reads `.env` on every `get()` for hot-reload, so resolving names per call costs the same as one existing lookup, and a `refresh()` nobody calls is dead API. Reasoning recorded in the Boot-Ordering Constraint section.
