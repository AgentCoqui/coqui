<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Support\Agent;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Contract\ToolkitLoadingKeyProvider;

final class CompositeBudgetChildToolkit implements ToolkitInterface, ToolkitLoadingKeyProvider
{
    public function __construct(
        private readonly string $name,
    ) {}

    public function toolkitLoadingKey(): string
    {
        return 'CompositeChild:' . $this->name;
    }

    public function tools(): array
    {
        return [new Tool(
            name: 'composite_child_' . $this->name,
            description: 'Composite child tool ' . $this->name,
            parameters: [],
            callback: static fn(array $input): ToolResult => ToolResult::success('ok'),
        )];
    }

    public function guidelines(): string
    {
        return sprintf('Composite child toolkit %s with server-scoped loading.', $this->name);
    }
}