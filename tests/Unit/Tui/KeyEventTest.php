<?php

declare(strict_types=1);

namespace Tests\Unit\Tui;

use CoquiBot\Coqui\Tui\KeyEvent;

covers(KeyEvent::class);

describe('KeyEvent', function (): void {
    test('arrow up from ANSI escape', function (): void {
        $key = KeyEvent::fromBytes("\e[A");
        expect($key->type)->toBe(KeyEvent::ARROW_UP);
    });

    test('arrow down from ANSI escape', function (): void {
        $key = KeyEvent::fromBytes("\e[B");
        expect($key->type)->toBe(KeyEvent::ARROW_DOWN);
    });

    test('arrow right from ANSI escape', function (): void {
        $key = KeyEvent::fromBytes("\e[C");
        expect($key->type)->toBe(KeyEvent::ARROW_RIGHT);
    });

    test('arrow left from ANSI escape', function (): void {
        $key = KeyEvent::fromBytes("\e[D");
        expect($key->type)->toBe(KeyEvent::ARROW_LEFT);
    });

    test('enter from line feed', function (): void {
        $key = KeyEvent::fromBytes(chr(10));
        expect($key->type)->toBe(KeyEvent::ENTER);
    });

    test('enter from carriage return', function (): void {
        $key = KeyEvent::fromBytes(chr(13));
        expect($key->type)->toBe(KeyEvent::ENTER);
    });

    test('escape from bare escape byte', function (): void {
        $key = KeyEvent::fromBytes("\e");
        expect($key->type)->toBe(KeyEvent::ESC);
    });

    test('backspace from byte 127', function (): void {
        $key = KeyEvent::fromBytes(chr(127));
        expect($key->type)->toBe(KeyEvent::BACKSPACE);
    });

    test('backspace from byte 8', function (): void {
        $key = KeyEvent::fromBytes(chr(8));
        expect($key->type)->toBe(KeyEvent::BACKSPACE);
    });

    test('delete from ANSI delete sequence', function (): void {
        $key = KeyEvent::fromBytes("\e[3~");
        expect($key->type)->toBe(KeyEvent::DELETE);
    });

    test('tab from byte 9', function (): void {
        $key = KeyEvent::fromBytes(chr(9));
        expect($key->type)->toBe(KeyEvent::TAB);
    });

    test('regular character', function (): void {
        $key = KeyEvent::fromBytes('a');
        expect($key->type)->toBe(KeyEvent::CHAR);
        expect($key->char)->toBe('a');
    });

    test('space character', function (): void {
        $key = KeyEvent::fromBytes(' ');
        expect($key->type)->toBe(KeyEvent::CHAR);
        expect($key->char)->toBe(' ');
    });

    test('empty string returns unknown', function (): void {
        $key = KeyEvent::fromBytes('');
        expect($key->type)->toBe(KeyEvent::UNKNOWN);
    });

    test('unknown ANSI sequence returns unknown', function (): void {
        $key = KeyEvent::fromBytes("\e[99~");
        expect($key->type)->toBe(KeyEvent::UNKNOWN);
    });

    test('char is null for non-character keys', function (): void {
        $key = KeyEvent::fromBytes("\e[A");
        expect($key->char)->toBeNull();
    });
});
