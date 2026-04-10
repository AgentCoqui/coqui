<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Built-in system role identifiers.
 *
 * Centralizes the role name strings used throughout Coqui's core logic
 * for branching, resolution, and quality automation. User-defined roles
 * from config/roles/ remain free-form strings.
 */
enum SystemRole: string
{
    case Orchestrator = 'orchestrator';
    case Coder = 'coder';
    case Reviewer = 'reviewer';
    case Learner = 'learner';
    case Evaluator = 'evaluator';
    case TitleGenerator = 'title-generator';
    case PlanTodoGenerator = 'plan-todo-generator';
}
