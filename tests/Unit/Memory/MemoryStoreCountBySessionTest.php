<?php

declare(strict_types=1);

use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemoryEntry;

test('countBySession counts only memories written in that session', function () {
    $store = new MemoryStore(':memory:');
    $store->save(new MemoryEntry(content: 'a', sessionId: 'sess-1'));
    $store->save(new MemoryEntry(content: 'b', sessionId: 'sess-1'));
    $store->save(new MemoryEntry(content: 'c', sessionId: 'sess-2'));

    expect($store->countBySession('sess-1'))->toBe(2);
    expect($store->countBySession('sess-2'))->toBe(1);
    expect($store->countBySession('sess-none'))->toBe(0);
});
