<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\CursorPage;

/**
 * @return callable(array<string, mixed>): string
 */
function cursorItemKey(): callable
{
    return static fn(array $item): string => (string) $item['id'];
}

/**
 * @return list<array{id: string}>
 */
function cursorItems(): array
{
    return [
        ['id' => 'a'],
        ['id' => 'b'],
        ['id' => 'c'],
        ['id' => 'd'],
        ['id' => 'e'],
    ];
}

test('build truncates to the limit and yields a resumable non-null cursor', function () {
    $page = CursorPage::build(cursorItems(), 2, cursorItemKey());

    expect($page)->toHaveKeys(['data', 'next_cursor']);
    expect($page['data'])->toHaveCount(2);
    expect(array_column($page['data'], 'id'))->toBe(['a', 'b']);
    expect($page['next_cursor'])->not->toBeNull();
});

test('a decoded cursor resumes strictly after the last item of the previous page', function () {
    $first = CursorPage::build(cursorItems(), 2, cursorItemKey());
    $afterKey = CursorPage::decode($first['next_cursor']);
    expect($afterKey)->toBe('b');

    $second = CursorPage::build(cursorItems(), 2, cursorItemKey(), $afterKey);
    expect(array_column($second['data'], 'id'))->toBe(['c', 'd']);
    expect($second['next_cursor'])->not->toBeNull();
});

test('the final page carries a null cursor', function () {
    $afterFour = CursorPage::build(cursorItems(), 4, cursorItemKey())['next_cursor'];

    $last = CursorPage::build(cursorItems(), 2, cursorItemKey(), CursorPage::decode($afterFour));
    expect(array_column($last['data'], 'id'))->toBe(['e']);
    expect($last['next_cursor'])->toBeNull();
});

test('a page that exactly consumes every item has a null cursor', function () {
    $page = CursorPage::build(cursorItems(), 5, cursorItemKey());

    expect($page['data'])->toHaveCount(5);
    expect($page['next_cursor'])->toBeNull();
});

test('encode(decode(x)) round-trips an opaque cursor token', function () {
    $token = CursorPage::encode('updated_at=2026-08-04T00:00:00Z|id=sess_xyz');

    expect(CursorPage::decode($token))->toBe('updated_at=2026-08-04T00:00:00Z|id=sess_xyz');
    expect(CursorPage::encode(CursorPage::decode($token)))->toBe($token);
});

test('a stale cursor whose item is gone yields an empty page, never re-serving the head', function () {
    $page = CursorPage::build(cursorItems(), 2, cursorItemKey(), 'nonexistent-key');

    expect($page['data'])->toBe([]);
    expect($page['next_cursor'])->toBeNull();
});

test('limitFrom clamps to the max page size and defaults sensibly', function () {
    expect(CursorPage::limitFrom(null))->toBe(CursorPage::DEFAULT_LIMIT);
    expect(CursorPage::limitFrom('10'))->toBe(10);
    expect(CursorPage::limitFrom(500))->toBe(CursorPage::MAX_LIMIT);
    expect(CursorPage::limitFrom('0'))->toBe(CursorPage::DEFAULT_LIMIT);
    expect(CursorPage::limitFrom('not-a-number'))->toBe(CursorPage::DEFAULT_LIMIT);
});
