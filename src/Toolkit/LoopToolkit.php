<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Storage\LoopStore;

/**
 * Agent-facing tools for managing automated loop workflows.
 *
 * Enables the agent to start, monitor, pause, resume, and cancel loops.
 * Loops are pre-defined multi-role iteration cycles that execute autonomously.
 */
final readonly class LoopToolkit implements ToolkitInterface
{
    public function __construct(
        private LoopStore $loopStore,
        private LoopDiscovery $loopDiscovery,
    ) {}

    public function tools(): array
    {
        return [
            $this->loopStartTool(),
            $this->loopListTool(),
            $this->loopStatusTool(),
            $this->loopPauseTool(),
            $this->loopResumeTool(),
            $this->loopStopTool(),
            $this->loopDefinitionsTool(),
        ];
    }

    public function guidelines(): string
    {
        $activeCount = $this->loopStore->countActive();
        $definitions = $this->loopDiscovery->availableLoops();
        $defList = count($definitions) > 0 ? implode(', ', $definitions) : '(none)';

        $statusLine = $activeCount > 0
            ? "Active loops: {$activeCount}"
            : 'No active loops';

        // Show active loop details
        $activeDetails = '';
        if ($activeCount > 0) {
            $running = $this->loopStore->listLoops('running');
            $lines = [];
            foreach (array_slice($running, 0, 5) as $loop) {
                $lines[] = sprintf(
                    '- **%s** (iter %d, stage %d) — %s',
                    $loop['definition_name'],
                    $loop['current_iteration'],
                    $loop['current_stage'],
                    mb_substr($loop['goal'], 0, 80),
                );
            }
            $activeDetails = "\n\n### Active Loops\n" . implode("\n", $lines);
        }

        return <<<GUIDELINES
        ## Loop Workflows

        Loops are automated multi-role iteration cycles. Each loop definition specifies roles,
        prompts, and termination conditions. Loops execute autonomously — each iteration runs
        all roles in sequence, then evaluates whether to continue.

        **Status:** {$statusLine}
        **Available definitions:** {$defList}
        {$activeDetails}

        ### Usage
        - Start a loop: `loop_start(definition: "harness", goal: "Build feature X")`
        - Monitor: `loop_status(id: "...")` or `loop_list()`
        - Pause/resume: `loop_pause(id: "...")` / `loop_resume(id: "...")`
        - Cancel: `loop_stop(id: "...")`
        GUIDELINES;
    }

    private function loopStartTool(): Tool
    {
        return new Tool(
            name: 'loop_start',
            description: 'Start a new automated loop workflow from a named definition. The loop runs multiple roles in sequence per iteration, evaluating termination conditions between cycles.',
            parameters: [
                new StringParameter(
                    name: 'definition',
                    description: 'Name of the loop definition to use (e.g. "harness", "research")',
                    required: true,
                ),
                new StringParameter(
                    name: 'goal',
                    description: 'The goal or task for the loop to accomplish',
                    required: true,
                ),
                new NumberParameter(
                    name: 'max_iterations',
                    description: 'Override the maximum number of iterations (default: from definition)',
                    required: false,
                ),
            ],
            callback: function (array $input): ToolResult {
                $defName = (string) ($input['definition'] ?? '');
                $goal = (string) ($input['goal'] ?? '');

                if ($defName === '' || $goal === '') {
                    return ToolResult::error('Both "definition" and "goal" are required');
                }

                if (!$this->loopDiscovery->exists($defName)) {
                    $available = implode(', ', $this->loopDiscovery->availableLoops());
                    return ToolResult::error("Loop definition \"{$defName}\" not found. Available: {$available}");
                }

                // Return the definition details — actual execution is handled by the REPL/API layer
                $definition = $this->loopDiscovery->get($defName);

                return ToolResult::success(json_encode([
                    'action' => 'start_loop',
                    'definition' => $defName,
                    'goal' => $goal,
                    'max_iterations' => $input['max_iterations'] ?? null,
                    'roles' => array_map(fn($r) => $r->role, $definition->roles),
                    'termination' => $definition->terminationCondition->type->value,
                    'message' => "Loop \"{$defName}\" is ready to start. The loop orchestrator will execute this autonomously.",
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            },
        );
    }

    private function loopListTool(): Tool
    {
        return new Tool(
            name: 'loop_list',
            description: 'List loop workflow instances, optionally filtered by status.',
            parameters: [
                new EnumParameter(
                    name: 'status',
                    description: 'Filter by loop status',
                    values: ['running', 'paused', 'completed', 'failed', 'cancelled'],
                    required: false,
                ),
            ],
            callback: function (array $input): ToolResult {
                $status = isset($input['status']) ? (string) $input['status'] : null;
                $loops = $this->loopStore->listLoops($status);

                if ($loops === []) {
                    return ToolResult::success('No loops found' . ($status !== null ? " with status \"{$status}\"" : ''));
                }

                $lines = [];
                foreach ($loops as $loop) {
                    $lines[] = sprintf(
                        '- **%s** [%s] iter %d/stage %d — %s (started %s)',
                        $loop['id'],
                        $loop['status'],
                        $loop['current_iteration'],
                        $loop['current_stage'],
                        mb_substr($loop['goal'], 0, 80),
                        $loop['started_at'],
                    );
                }

                return ToolResult::success(implode("\n", $lines));
            },
        );
    }

    private function loopStatusTool(): Tool
    {
        return new Tool(
            name: 'loop_status',
            description: 'Get detailed status of a loop including current iteration, stage results, and progress.',
            parameters: [
                new StringParameter(
                    name: 'id',
                    description: 'Loop ID',
                    required: true,
                ),
            ],
            callback: function (array $input): ToolResult {
                $id = (string) ($input['id'] ?? '');
                $state = $this->loopStore->getCurrentState($id);

                if ($state === null) {
                    return ToolResult::error("Loop \"{$id}\" not found");
                }

                $loop = $state['loop'];
                $iteration = $state['iteration'];
                $stages = $state['stages'];

                $output = [
                    'id' => $loop['id'],
                    'definition' => $loop['definition_name'],
                    'status' => $loop['status'],
                    'goal' => $loop['goal'],
                    'current_iteration' => $loop['current_iteration'],
                    'max_iterations' => $loop['max_iterations'],
                    'started_at' => $loop['started_at'],
                    'completed_at' => $loop['completed_at'],
                ];

                if ($iteration !== null) {
                    $output['current_iteration_status'] = $iteration['status'];
                    $output['stages'] = array_map(fn(array $s) => [
                        'index' => $s['stage_index'],
                        'role' => $s['role'],
                        'status' => $s['status'],
                        'summary' => $s['result_summary'] !== null ? mb_substr($s['result_summary'], 0, 200) : null,
                    ], $stages);
                }

                return ToolResult::success(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            },
        );
    }

    private function loopPauseTool(): Tool
    {
        return new Tool(
            name: 'loop_pause',
            description: 'Pause a running loop after the current stage completes.',
            parameters: [
                new StringParameter(name: 'id', description: 'Loop ID', required: true),
            ],
            callback: function (array $input): ToolResult {
                $id = (string) ($input['id'] ?? '');
                $loop = $this->loopStore->getLoop($id);

                if ($loop === null) {
                    return ToolResult::error("Loop \"{$id}\" not found");
                }

                if ($loop['status'] !== 'running') {
                    return ToolResult::error("Cannot pause loop — current status is \"{$loop['status']}\"");
                }

                $this->loopStore->updateLoopStatus($id, 'paused');
                return ToolResult::success("Loop \"{$id}\" paused. Use loop_resume to continue.");
            },
        );
    }

    private function loopResumeTool(): Tool
    {
        return new Tool(
            name: 'loop_resume',
            description: 'Resume a paused loop.',
            parameters: [
                new StringParameter(name: 'id', description: 'Loop ID', required: true),
            ],
            callback: function (array $input): ToolResult {
                $id = (string) ($input['id'] ?? '');
                $loop = $this->loopStore->getLoop($id);

                if ($loop === null) {
                    return ToolResult::error("Loop \"{$id}\" not found");
                }

                if ($loop['status'] !== 'paused') {
                    return ToolResult::error("Cannot resume loop — current status is \"{$loop['status']}\"");
                }

                $this->loopStore->updateLoopStatus($id, 'running');
                return ToolResult::success("Loop \"{$id}\" resumed.");
            },
        );
    }

    private function loopStopTool(): Tool
    {
        return new Tool(
            name: 'loop_stop',
            description: 'Cancel a running or paused loop.',
            parameters: [
                new StringParameter(name: 'id', description: 'Loop ID', required: true),
            ],
            callback: function (array $input): ToolResult {
                $id = (string) ($input['id'] ?? '');
                $loop = $this->loopStore->getLoop($id);

                if ($loop === null) {
                    return ToolResult::error("Loop \"{$id}\" not found");
                }

                if (!in_array($loop['status'], ['running', 'paused'], true)) {
                    return ToolResult::error("Cannot stop loop — current status is \"{$loop['status']}\"");
                }

                $this->loopStore->updateLoopStatus($id, 'cancelled');
                return ToolResult::success("Loop \"{$id}\" cancelled.");
            },
        );
    }

    private function loopDefinitionsTool(): Tool
    {
        return new Tool(
            name: 'loop_definitions',
            description: 'List available loop workflow definitions with descriptions.',
            parameters: [],
            callback: function (array $input): ToolResult {
                $definitions = $this->loopDiscovery->discoverAll();

                if ($definitions === []) {
                    return ToolResult::success('No loop definitions found in workspace/loops/');
                }

                $lines = [];
                foreach ($definitions as $def) {
                    $roles = implode(' → ', array_map(fn($r) => $r->role, $def->roles));
                    $termination = $def->terminationCondition->type->value;
                    $lines[] = sprintf(
                        "**%s** — %s\n  Roles: %s | Termination: %s",
                        $def->name,
                        $def->description,
                        $roles,
                        $termination,
                    );
                }

                return ToolResult::success(implode("\n\n", $lines));
            },
        );
    }
}
