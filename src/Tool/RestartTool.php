<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Tool that allows the agent to trigger a graceful restart of Coqui.
 *
 * When executed, sets a restart flag via the provided callback. The REPL
 * loop checks this flag after the current agent turn completes and exits
 * with RESTART_EXIT_CODE (10), which the launcher script detects and
 * uses to relaunch the process.
 *
 * This tool is gated — it requires user confirmation unless --auto-approve
 * is enabled.
 */
final class RestartTool implements ToolInterface
{
    /** @var \Closure(): void */
    private \Closure $onRestart;

    /**
     * @param \Closure(): void $onRestart Callback that sets the restart flag on RunCommand
     */
    public function __construct(
        \Closure $onRestart,
    ) {
        $this->onRestart = $onRestart;
    }

    public function name(): string
    {
        return 'restart_coqui';
    }

    public function description(): string
    {
        return <<<'DESC'
            Trigger a graceful restart of Coqui. The current agent turn will complete
            normally, then Coqui will shut down and immediately relaunch.

            Use this when:
            - New toolkit packages were installed and need to be discovered
            - Configuration (openclaw.json) was modified and needs to be reloaded
            - You need to recover from an error state or clear in-memory state
            - The user explicitly asks you to restart

            The session will be automatically resumed after restart.
            DESC;
    }

    /**
     * @return \CarmeloSantana\PHPAgents\Tool\Parameter\Parameter[]
     */
    public function parameters(): array
    {
        return [
            new StringParameter(
                name: 'reason',
                description: 'Brief explanation of why a restart is needed',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $reason = isset($input['reason']) && is_string($input['reason'])
            ? $input['reason']
            : 'No reason provided';

        ($this->onRestart)();

        return ToolResult::success(
            "Restart scheduled. Reason: {$reason}. "
            . 'The current turn will complete, then Coqui will restart and resume the session.',
        );
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'reason' => [
                            'type' => 'string',
                            'description' => 'Brief explanation of why a restart is needed',
                        ],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }
}
