<?php

declare(strict_types=1);

use CoquiBot\Coqui\Command\RunCommand;
use CoquiBot\Coqui\Repl\NotificationPresenter;
use CoquiBot\Coqui\Storage\NotificationStore;
use CoquiBot\Coqui\Storage\SessionStorage;

test('run command keeps the readline prompt neutral even when unread notifications exist', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-run-command-prompt-' . bin2hex(random_bytes(8)) . '.db';

    try {
        $storage = new SessionStorage($dbPath);
        $sessionId = $storage->createSession('orchestrator', 'test/model');
        $store = new NotificationStore($storage->getPdo());
        $store->create(
            sessionId: $sessionId,
            class: 'informational',
            kind: 'task.completed',
            title: 'Build finished',
        );

        $command = new RunCommand();

        $setSessionId = function (string $value): void {
            $this->sessionId = $value;
        };
        \Closure::bind($setSessionId, $command, RunCommand::class)($sessionId);

        $buildPrompt = function (NotificationPresenter $presenter, ?NotificationStore $notificationStore): string {
            return $this->buildReadlinePrompt($presenter, $notificationStore);
        };

        $prompt = \Closure::bind($buildPrompt, $command, RunCommand::class)(new NotificationPresenter(), $store);

        expect($prompt)->toBe(' › ');
    } finally {
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
    }
});

test('run command syncs active project after agent turns without putting the project in the readline prompt', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Command/RunCommand.php');

    expect($source)
        ->toContain('buildReadlinePrompt(')
        ->not->toContain("sprintf(' [%s] › ', \$this->activeProjectSlug)");

    preg_match_all(
        '/\\$shutdownGuard\\(\\$shutdownStty\\);\R+\s+\\$this->restoreActiveProject\\(\\);/',
        $source,
        $matches,
    );

    expect($matches[0])->toHaveCount(2);
});

test('run command exposes group membership in the user prompt context line', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-run-command-group-prompt-' . bin2hex(random_bytes(8)) . '.db';

    try {
        $storage = new SessionStorage($dbPath);
        $sessionId = $storage->createGroupSession('orchestrator', 'test/model', ['alex-hormozi', 'trinity'], 3);
        $command = new RunCommand();

        $setState = function (SessionStorage $storage, string $sessionId): void {
            $this->storage = $storage;
            $this->sessionId = $sessionId;
        };
        \Closure::bind($setState, $command, RunCommand::class)($storage, $sessionId);

        $buildContext = function (): array {
            return $this->buildUserPromptContextParts();
        };

        $context = \Closure::bind($buildContext, $command, RunCommand::class)();

        expect($context)->toBe(['group session with @alex-hormozi, @trinity']);
    } finally {
        if (file_exists($dbPath)) {
            unlink($dbPath);
        }
    }
});