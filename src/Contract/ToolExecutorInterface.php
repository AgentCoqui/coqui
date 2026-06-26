<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Executes a tool by name with the given arguments.
 *
 * Implementations resolve tools from registered toolkits and call execute() directly.
 * Used by BackgroundToolExecutor for direct tool execution (e.g. the task_run path).
 */
interface ToolExecutorInterface
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function execute(string $toolName, array $arguments): ToolResult;
}
