<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Support\Agent;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Contract\CompositeToolkitProvider;

final class CompositeBudgetTestToolkit implements ToolkitInterface, CompositeToolkitProvider
{
    public function tools(): array
    {
        return [new Tool(
            name: 'composite_budget_manage',
            description: 'Composite management tool.',
            parameters: [],
            callback: static fn(array $input): ToolResult => ToolResult::success('ok'),
        )];
    }

    public function guidelines(): string
    {
        return 'Composite budget test toolkit management surface.';
    }

    public function childToolkits(): array
    {
        return [
            new CompositeBudgetChildToolkit('alpha'),
            new CompositeBudgetChildToolkit('beta'),
        ];
    }
}