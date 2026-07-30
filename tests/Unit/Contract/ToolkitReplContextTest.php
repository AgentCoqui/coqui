<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use CoquiBot\Coqui\Contract\ToolkitReplContext;
use CoquiBot\Coqui\Observer\AnimatedTickCallback;
use CoquiBot\Coqui\Repl\InterruptiblePrompt;
use CoquiBot\Coqui\Support\ToolkitDatabaseFactory;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

covers(ToolkitReplContext::class);

describe('ToolkitReplContext (text portal)', function (): void {
    test('no longer exposes fullscreen screen-hosting methods', function (): void {
        expect(method_exists(ToolkitReplContext::class, 'runScreen'))->toBeFalse();
        expect(method_exists(ToolkitReplContext::class, 'isInteractiveTerminal'))->toBeFalse();
    });

    test('constructor no longer accepts a screenHost parameter', function (): void {
        $params = (new \ReflectionMethod(ToolkitReplContext::class, '__construct'))->getParameters();
        $names = array_map(fn (\ReflectionParameter $p): string => $p->getName(), $params);

        expect($names)->not->toContain('screenHost');
    });

    test('retains the text-portal services: spinner and database factory', function (): void {
        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $context = new ToolkitReplContext(
            io: $io,
            prompt: new InterruptiblePrompt($io),
            workspacePath: sys_get_temp_dir(),
            activePersona: null,
            sessionId: 'session-1',
            output: $output,
            databaseFactory: new ToolkitDatabaseFactory(sys_get_temp_dir()),
        );

        expect($context->createSpinner('work'))->toBeInstanceOf(AnimatedTickCallback::class);
        expect($context->io)->toBe($io);
        expect($context->sessionId)->toBe('session-1');
    });
});
