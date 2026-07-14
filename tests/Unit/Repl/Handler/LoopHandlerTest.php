<?php

declare(strict_types=1);

namespace Tests\Unit\Repl\Handler;

use CoquiBot\Coqui\Repl\Handler\LoopHandler;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

covers(LoopHandler::class);

describe('LoopHandler (text portal)', function (): void {
    test('constructor no longer accepts a terminalState parameter', function (): void {
        $params = (new \ReflectionMethod(LoopHandler::class, '__construct'))->getParameters();
        $names = array_map(fn (\ReflectionParameter $p): string => $p->getName(), $params);

        expect($names)->not->toContain('terminalState');
    });

    test('does not reference the removed fullscreen TUI screens', function (): void {
        $source = (string) file_get_contents((new \ReflectionClass(LoopHandler::class))->getFileName());

        expect($source)->not->toContain('LoopDashboardScreen');
        expect($source)->not->toContain('ScreenRunner');
        expect($source)->not->toContain('CoquiBot\\Coqui\\Tui');
    });

    test('bare /loops renders the text path with an empty store', function (): void {
        $dbPath = tempnam(sys_get_temp_dir(), 'loop-portal-') . '.db';
        $storage = new SessionStorage($dbPath);
        $handler = new LoopHandler($storage, null, null);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $handler->handle($io, '', '');

        expect($output->fetch())->toContain('No loops found');

        @unlink($dbPath);
    });

    test('/loops renders the blocked glyph for a blocked loop', function (): void {
        $dbPath = tempnam(sys_get_temp_dir(), 'loop-portal-') . '.db';
        $storage = new SessionStorage($dbPath);

        $store = new LoopStore($storage->getPdo());
        $loopId = $store->createLoop('harness', 'Ship the widget', []);
        $store->updateLoopStatus($loopId, 'blocked');

        $handler = new LoopHandler($storage, null, null);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $handler->handle($io, '', '');

        expect($output->fetch())->toContain('⚑');

        @unlink($dbPath);
    });

    test('/loops status shows the escalation reason for a blocked loop', function (): void {
        $dbPath = tempnam(sys_get_temp_dir(), 'loop-portal-') . '.db';
        $storage = new SessionStorage($dbPath);

        $store = new LoopStore($storage->getPdo());
        $loopId = $store->createLoop('harness', 'Ship the widget', []);
        $store->updateLoopMetadata($loopId, ['escalation' => ['reason' => 'Reviewer rejected 3 rounds']]);
        $store->updateLoopStatus($loopId, 'blocked');

        $handler = new LoopHandler($storage, null, null);
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $handler->handle($io, "status {$loopId}", '');

        $rendered = $output->fetch();
        expect($rendered)->toContain('Blocked: Reviewer rejected 3 rounds');
        expect($rendered)->toContain('loop_control(action: "retry"');

        @unlink($dbPath);
    });
});
