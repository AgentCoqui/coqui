<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Built-in system role identifiers.
 *
 * Centralizes the role name strings used throughout Coqui's core logic
 * for branching and resolution. User-defined roles from config/roles/
 * remain free-form strings.
 */
enum SystemRole: string
{
    case Orchestrator = 'orchestrator';
    case Coder = 'coder';
    case Reviewer = 'reviewer';
    case TitleGenerator = 'title-generator';
}
