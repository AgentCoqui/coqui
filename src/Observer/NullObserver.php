<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Observer;

use SplObserver;
use SplSubject;

/**
 * No-op observer that discards all agent events.
 *
 * Used in headless and API modes where event rendering is handled
 * elsewhere (SSE stream) or not needed at all.
 */
final class NullObserver implements SplObserver
{
    public function update(SplSubject $subject): void
    {
        // Intentionally empty — discard all events.
    }
}
