<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

enum PromptSectionPriority: string
{
    case Critical = 'critical';
    case Workflow = 'workflow';
    case Volatile = 'volatile';

    public function isPinned(): bool
    {
        return $this !== self::Volatile;
    }

    public function isDeferrable(): bool
    {
        return $this === self::Volatile;
    }
}