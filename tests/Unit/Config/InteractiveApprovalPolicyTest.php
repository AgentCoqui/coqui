<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\InteractiveApprovalPolicy;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

function createIo(): SymfonyStyle
{
    return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
}

test('ungated tool is allowed without prompt', function () {
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        gatedTools: ['composer' => ['require']],
    );

    expect($policy->shouldExecute('git_status', []))->toBeTrue();
});

test('wildcard rule gates all invocations', function () {
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        gatedTools: ['git_push' => ['*']],
    );

    // We can't easily test the interactive prompt, but we can verify
    // the method doesn't return true (it would prompt, which we can't
    // simulate in unit tests). Instead we test requiresApproval via reflection.
    $reflection = new ReflectionMethod($policy, 'requiresApproval');

    expect($reflection->invoke($policy, 'git_push', ['remote' => 'origin']))->toBeTrue();
    expect($reflection->invoke($policy, 'git_status', []))->toBeFalse();
});

test('string rule matches action argument', function () {
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        gatedTools: ['git_branch' => ['delete']],
    );

    $reflection = new ReflectionMethod($policy, 'requiresApproval');

    expect($reflection->invoke($policy, 'git_branch', ['action' => 'delete', 'name' => 'old-branch']))->toBeTrue();
    expect($reflection->invoke($policy, 'git_branch', ['action' => 'create', 'name' => 'new-branch']))->toBeFalse();
    expect($reflection->invoke($policy, 'git_branch', ['action' => 'list']))->toBeFalse();
});

test('string rule matches command argument', function () {
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        gatedTools: ['composer' => ['require', 'remove']],
    );

    $reflection = new ReflectionMethod($policy, 'requiresApproval');

    expect($reflection->invoke($policy, 'composer', ['command' => 'require', 'package' => 'foo/bar']))->toBeTrue();
    expect($reflection->invoke($policy, 'composer', ['command' => 'remove', 'package' => 'foo/bar']))->toBeTrue();
    expect($reflection->invoke($policy, 'composer', ['command' => 'show']))->toBeFalse();
});

test('string rule gates when no action argument present', function () {
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        gatedTools: ['git_branch' => ['delete']],
    );

    $reflection = new ReflectionMethod($policy, 'requiresApproval');

    // Tool is listed but no action argument — should gate by default
    expect($reflection->invoke($policy, 'git_branch', ['name' => 'test']))->toBeTrue();
});

test('predicate rule matches boolean argument', function () {
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        gatedTools: ['git_commit' => [['amend' => true]]],
    );

    $reflection = new ReflectionMethod($policy, 'requiresApproval');

    expect($reflection->invoke($policy, 'git_commit', ['message' => 'fix', 'amend' => true]))->toBeTrue();
    expect($reflection->invoke($policy, 'git_commit', ['message' => 'fix', 'amend' => false]))->toBeFalse();
    expect($reflection->invoke($policy, 'git_commit', ['message' => 'fix']))->toBeFalse();
});

test('predicate rule with presence wildcard', function () {
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        gatedTools: ['git_checkout' => [['files' => '*']]],
    );

    $reflection = new ReflectionMethod($policy, 'requiresApproval');

    expect($reflection->invoke($policy, 'git_checkout', ['target' => 'main', 'files' => 'src/Foo.php']))->toBeTrue();
    expect($reflection->invoke($policy, 'git_checkout', ['target' => 'main']))->toBeFalse();
    expect($reflection->invoke($policy, 'git_checkout', ['target' => 'main', 'files' => '']))->toBeFalse();
});

test('predicate rule with string value match', function () {
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        gatedTools: ['my_tool' => [['mode' => 'destructive']]],
    );

    $reflection = new ReflectionMethod($policy, 'requiresApproval');

    expect($reflection->invoke($policy, 'my_tool', ['mode' => 'destructive']))->toBeTrue();
    expect($reflection->invoke($policy, 'my_tool', ['mode' => 'safe']))->toBeFalse();
});

test('multiple predicate keys use AND semantics', function () {
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        gatedTools: ['my_tool' => [['force' => true, 'mode' => 'delete']]],
    );

    $reflection = new ReflectionMethod($policy, 'requiresApproval');

    // Both match — should gate
    expect($reflection->invoke($policy, 'my_tool', ['force' => true, 'mode' => 'delete']))->toBeTrue();
    // Only one matches — should not gate
    expect($reflection->invoke($policy, 'my_tool', ['force' => true, 'mode' => 'create']))->toBeFalse();
    expect($reflection->invoke($policy, 'my_tool', ['force' => false, 'mode' => 'delete']))->toBeFalse();
});

test('mixed rules use OR semantics', function () {
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        gatedTools: ['git_branch' => ['delete', ['force' => true]]],
    );

    $reflection = new ReflectionMethod($policy, 'requiresApproval');

    // String rule matches
    expect($reflection->invoke($policy, 'git_branch', ['action' => 'delete', 'name' => 'old']))->toBeTrue();
    // Predicate rule matches
    expect($reflection->invoke($policy, 'git_branch', ['action' => 'create', 'force' => true]))->toBeTrue();
    // Neither matches
    expect($reflection->invoke($policy, 'git_branch', ['action' => 'list']))->toBeFalse();
});

test('tool not in gated map is never gated', function () {
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        gatedTools: ['git_push' => ['*']],
    );

    $reflection = new ReflectionMethod($policy, 'requiresApproval');

    expect($reflection->invoke($policy, 'git_diff', ['scope' => 'working']))->toBeFalse();
    expect($reflection->invoke($policy, 'git_log', []))->toBeFalse();
    expect($reflection->invoke($policy, 'git_status', []))->toBeFalse();
});

// ---------------------------------------------------------------
// Blacklist scoping — only command-execution tools are checked
// ---------------------------------------------------------------

test('blacklist blocks exec tool with shutdown command', function () {
    $blacklist = new CatastrophicBlacklist();
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        blacklist: $blacklist,
    );

    $result = $policy->shouldExecute('exec', ['command' => 'shutdown -h now']);
    expect($result)->toBeString();
    expect($result)->toContain('CATASTROPHIC BLOCK');
});

test('blacklist blocks php_execute with shutdown in code', function () {
    $blacklist = new CatastrophicBlacklist();
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        blacklist: $blacklist,
    );

    $result = $policy->shouldExecute('php_execute', ['code' => "exec('shutdown -h now');"]);
    expect($result)->toBeString();
    expect($result)->toContain('CATASTROPHIC BLOCK');
});

test('blacklist does NOT block write_file with shutdown in content', function () {
    $blacklist = new CatastrophicBlacklist();
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        blacklist: $blacklist,
    );

    $result = $policy->shouldExecute('write_file', [
        'path' => 'plan.md',
        'content' => 'The server will shutdown gracefully when signaled.',
    ]);
    expect($result)->toBeTrue();
});

test('blacklist does NOT block replace_in_file with shutdown in content', function () {
    $blacklist = new CatastrophicBlacklist();
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        blacklist: $blacklist,
    );

    $result = $policy->shouldExecute('replace_in_file', [
        'path' => 'timer.md',
        'old_content' => 'shutdown the timer',
        'new_content' => 'stop the timer',
    ]);
    expect($result)->toBeTrue();
});

test('blacklist does NOT block memory_save with shutdown in content', function () {
    $blacklist = new CatastrophicBlacklist();
    $policy = new InteractiveApprovalPolicy(
        io: createIo(),
        blacklist: $blacklist,
    );

    $result = $policy->shouldExecute('memory_save', [
        'content' => 'User prefers graceful shutdown handling.',
        'area' => 'preferences',
    ]);
    expect($result)->toBeTrue();
});

// ---------------------------------------------------------------
// AutoApprovalPolicy — same blacklist scoping
// ---------------------------------------------------------------

test('auto-approval blocks exec with shutdown', function () {
    $blacklist = new CatastrophicBlacklist();
    $policy = new AutoApprovalPolicy(blacklist: $blacklist);

    $result = $policy->shouldExecute('exec', ['command' => 'shutdown -h now']);
    expect($result)->toBeString();
    expect($result)->toContain('CATASTROPHIC BLOCK');
});

test('auto-approval allows write_file with shutdown in content', function () {
    $blacklist = new CatastrophicBlacklist();
    $policy = new AutoApprovalPolicy(blacklist: $blacklist);

    $result = $policy->shouldExecute('write_file', [
        'path' => 'docs/architecture.md',
        'content' => 'The graceful shutdown handler ensures all connections are closed.',
    ]);
    expect($result)->toBeTrue();
});

test('auto-approval allows batch_replace with reboot in content', function () {
    $blacklist = new CatastrophicBlacklist();
    $policy = new AutoApprovalPolicy(blacklist: $blacklist);

    $result = $policy->shouldExecute('batch_replace', [
        'glob' => '*.md',
        'replacements' => [
            ['search' => 'reboot the system', 'replace' => 'restart the system'],
        ],
    ]);
    expect($result)->toBeTrue();
});
