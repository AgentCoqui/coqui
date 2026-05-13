<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\Coqui\Repl\ReplCommandCatalog;
use CoquiBot\Coqui\Repl\ToolkitCommandCandidate;

test('repl command catalog exposes top-level aliases without nested pseudo commands', function (): void {
    $commands = ReplCommandCatalog::topLevelCommands();

    expect($commands)->toContain('/quit');
    expect($commands)->toContain('/exit');
    expect($commands)->toContain('/q');
    expect($commands)->not->toContain('/toolkits enable');
    expect($commands)->not->toContain('/loops start');
});

test('repl command catalog help rows surface critical command variants', function (): void {
    $rows = ReplCommandCatalog::helpRows();

    expect($rows)->toContain(['/loops [filter|action]', 'Inspect loops and definitions. Start|pause|resume|stop actions are advanced automation controls.']);
    expect($rows)->toContain(['/schedules [action]', 'Inspect schedules or run status|enable|disable|delete|trigger for operator control.']);
    expect($rows)->toContain(['/webhooks [action]', 'Inspect webhook subscriptions or run status|deliveries|enable|disable|delete|rotate for operator control.']);
    expect($rows)->toContain(['/group [action]', 'Inspect or manage session-based group chats.']);
    expect($rows)->toContain(['/profile [name|default|reset]', 'Show or switch the active profile, set a default with default <name|none>, or clear it.']);
    expect($rows)->toContain(['/quit', 'Exit Coqui. Aliases: /exit, /q.']);
});

test('repl command catalog registration keeps first toolkit command and reports collisions', function (): void {
    $imageHandler = new class implements ToolkitCommandHandler
    {
        public function commandName(): string
        {
            return 'image';
        }

        public function subcommands(): array
        {
            return ['generate'];
        }

        public function usage(): string
        {
            return '/image [action]';
        }

        public function description(): string
        {
            return 'Generate images.';
        }

        public function handle(\CoquiBot\Coqui\Contract\ToolkitReplContext $context, string $arg): void
        {
        }
    };

    $duplicateImageHandler = new class implements ToolkitCommandHandler
    {
        public function commandName(): string
        {
            return 'image';
        }

        public function subcommands(): array
        {
            return ['delete'];
        }

        public function usage(): string
        {
            return '/image delete <id>';
        }

        public function description(): string
        {
            return 'Delete images.';
        }

        public function handle(\CoquiBot\Coqui\Contract\ToolkitReplContext $context, string $arg): void
        {
        }
    };

    $coreCollisionHandler = new class implements ToolkitCommandHandler
    {
        public function commandName(): string
        {
            return 'help';
        }

        public function subcommands(): array
        {
            return [];
        }

        public function usage(): string
        {
            return '/help toolkit';
        }

        public function description(): string
        {
            return 'Toolkit help override.';
        }

        public function handle(\CoquiBot\Coqui\Contract\ToolkitReplContext $context, string $arg): void
        {
        }
    };

    try {
        $report = ReplCommandCatalog::registerToolkitHandlers([
            new ToolkitCommandCandidate('vendor/first-images', $imageHandler),
            new ToolkitCommandCandidate('vendor/second-images', $duplicateImageHandler),
            new ToolkitCommandCandidate('vendor/help-override', $coreCollisionHandler),
        ]);

        expect($report->acceptedHandlers)->toHaveCount(1);
        expect($report->acceptedHandlers[0])->toBe($imageHandler);
        expect($report->acceptedSpecs)->toHaveCount(1);
        expect($report->acceptedSpecs[0]->name)->toBe('/image');
        expect($report->acceptedSpecs[0]->firstArguments)->toBe(['generate', 'help']);
        expect($report->collisions)->toHaveCount(2);
        expect($report->collisions[0]->reason)->toBe('toolkit');
        expect($report->collisions[0]->winnerPackage)->toBe('vendor/first-images');
        expect($report->collisions[0]->skippedPackage)->toBe('vendor/second-images');
        expect($report->collisions[1]->reason)->toBe('core');
        expect($report->collisions[1]->winnerPackage)->toBe('coquibot/coqui');
        expect($report->collisions[1]->skippedPackage)->toBe('vendor/help-override');
        expect(ReplCommandCatalog::topLevelCommands())->toContain('/image');
        expect(ReplCommandCatalog::find('/image')?->description)->toBe('Generate images.');
    } finally {
        ReplCommandCatalog::clearToolkitHandlers();
    }
});