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

    expect($rows)->toContain(['/loops [filter|action]', 'Inspect loops and definitions. Start|pause|resume|stop actions are advanced automation controls.']);
    expect($rows)->toContain(['/schedules [action]', 'Inspect schedules or run status|enable|disable|delete|trigger for operator control.']);
    expect($rows)->toContain(['/webhooks [action]', 'Inspect webhook subscriptions or run status|deliveries|enable|disable|delete|rotate for operator control.']);
    expect($rows)->toContain(['/profile [name|default|reset]', 'Show or switch the active profile, set a default with default <name|none>, or clear it.']);
    expect($rows)->toContain(['/image [action]', 'Generate and manage workspace images through the image toolkit. Actions: generate, list, search, get, tag, config.']);
    expect($rows)->toContain(['/quit', 'Exit Coqui. Aliases: /exit, /q.']);
});