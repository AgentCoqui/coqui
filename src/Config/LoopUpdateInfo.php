<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Value object describing a pending loop definition update.
 */
final readonly class LoopUpdateInfo
{
    public function __construct(
        public string $loopName,
        public bool $hasBuiltinUpdate,
        public bool $isUserModified,
    ) {}
}
