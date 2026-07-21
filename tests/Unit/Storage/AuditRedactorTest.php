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

test('a throwing keys() propagates instead of silently disabling L1', function (): void {
    $redactor = new AuditRedactor(throwingCredentials(throwOn: 'keys'));

    // Failing closed means the caller sees the failure. Swallowing it would return
    // the raw secret while looking identical to a healthy redaction.
    expect(fn (): array => $redactor->redact(['command' => 'echo supersecretvalue123']))
        ->toThrow(RuntimeException::class, 'resolver unavailable');

    expect(fn (): ?string => $redactor->redactScalar('echo supersecretvalue123'))
        ->toThrow(RuntimeException::class, 'resolver unavailable');
});

test('a throwing get() propagates rather than skipping that one secret', function (): void {
    $redactor = new AuditRedactor(throwingCredentials(throwOn: 'get'));

    expect(fn (): array => $redactor->redact(['command' => 'echo supersecretvalue123']))
        ->toThrow(RuntimeException::class, 'resolver unavailable');
});

test('a PCRE failure over-redacts the whole string instead of keeping key material', function (): void {
    $redactor = new AuditRedactor();

    // ~1MB PEM block. The lazy `.*?` in the PRIVATE KEY pattern exhausts
    // pcre.backtrack_limit (1_000_000), so preg_replace() returns null.
    $huge = "-----BEGIN RSA PRIVATE KEY-----\nSUPERSECRETKEYMATERIAL\n"
        . str_repeat("QUJDREVGR0g=\n", intdiv(1024 * 1024, 13))
        . "-----END RSA PRIVATE KEY-----";

    expect(strlen($huge))->toBeGreaterThan(1024 * 1024);

    $result = $redactor->redact(['command' => $huge]);

    expect($result['command'])->not->toContain('SUPERSECRETKEYMATERIAL');
    expect($result['command'])->not->toContain('BEGIN RSA PRIVATE KEY');
    expect($result['command'])->toBe('[REDACTED]');
});

test('a smaller PEM block stays within the backtrack limit and redacts normally', function (): void {
    $redactor = new AuditRedactor();

    $small = "prefix -----BEGIN RSA PRIVATE KEY-----\nSUPERSECRETKEYMATERIAL\n"
        . str_repeat("QUJDREVGR0g=\n", intdiv(500 * 1024, 13))
        . "-----END RSA PRIVATE KEY----- suffix";

    $result = $redactor->redact(['command' => $small]);

    expect($result['command'])->not->toContain('SUPERSECRETKEYMATERIAL');
    // Under the limit the pattern matches precisely, so surrounding text survives.
    expect($result['command'])->toBe('prefix [REDACTED] suffix');
});

test('L1 redacts secrets appearing in array KEYS, not just values', function (): void {
    $redactor = new AuditRedactor(fakeCredentials(['GITHUB_TOKEN' => 'supersecretvalue123']));

    $result = $redactor->redact(['env' => ['supersecretvalue123' => 'value']]);

    expect(array_keys($result['env']))->toBe(['[REDACTED]']);
    expect(json_encode($result))->not->toContain('supersecretvalue123');
});

test('L3 patterns apply to array keys too', function (): void {
    $redactor = new AuditRedactor();

    $result = $redactor->redact(['ghp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => 'v']);

    expect(json_encode($result))->not->toContain('ghp_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
});

test('two keys redacting to the same placeholder both survive', function (): void {
    // Deliberately avoid SENSITIVE_KEY_FRAGMENTS in the names, so this test isolates
    // key-collision handling rather than also tripping L2 on the values.
    $credentials = fakeCredentials(['A' => 'alpha-value-one', 'B' => 'beta-value-two']);
    $redactor = new AuditRedactor($credentials);

    $result = $redactor->redact([
        'env' => ['alpha-value-one' => 'one', 'beta-value-two' => 'two'],
    ]);

    // Collision must not silently drop a row.
    expect($result['env'])->toHaveCount(2);
    expect(array_values($result['env']))->toBe(['one', 'two']);
    expect(array_keys($result['env']))->toBe(['[REDACTED]', '[REDACTED]#2']);
});
