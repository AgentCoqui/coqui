<?php

declare(strict_types=1);

use CoquiBot\Coqui\Repl\ReplCommandCatalog;

test('repl command catalog exposes top-level aliases without nested pseudo commands', function (): void {
    $commands = ReplCommandCatalog::topLevelCommands();

    expect($commands)->toContain('/quit');
    expect($commands)->toContain('/exit');
    expect($commands)->toContain('/q');
    expect($commands)->not->toContain('/space skills');
    expect($commands)->not->toContain('/space toolkits');
});

test('repl command catalog help rows surface critical command variants', function (): void {
    $rows = ReplCommandCatalog::helpRows();

    expect($rows)->toContain(['/loops [filter|action]', 'List loops, filter by status, or run start <definition> <goal>, definitions, status|pause|resume|stop <id|all>.']);
    expect($rows)->toContain(['/profile [name|default|reset]', 'Show or switch the active profile, set a default with default <name|none>, or clear it.']);
    expect($rows)->toContain(['/quit', 'Exit Coqui. Aliases: /exit, /q.']);
});