<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Support\JsonHelper;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Api\ApiHealthCheck;
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
        private ?LoopExecutor $executor = null,
        private ?string $sessionId = null,
        private ?\Closure $healthCheck = null,
        private ?string $expectedWorkspacePath = null,
    ) {}

    public function tools(): array
    {
        return [
            $this->loopStartTool(),
            $this->loopListTool(),
            $this->loopStatusTool(),
            $this->loopControlTool(),
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
        - With parameters: `loop_start(definition: "research", goal: "Investigate auth", parameters: "{\"output_format\": \"comparison matrix\"}")`
        - Reuse a project: `loop_start(definition: "harness", goal: "Fix bugs", project_slug: "my-app")`
        - Monitor: `loop_status(id: "...")` or `loop_list()`
        - Pause: `loop_control(action: "pause", id: "...")` or `loop_control(action: "pause", id: "all")`
        - Resume: `loop_control(action: "resume", id: "...")`
        - Cancel: `loop_control(action: "stop", id: "...")`
        GUIDELINES;
    }

    private function loopStartTool(): Tool
    {
        return new Tool(
            name: 'loop_start',
            description: 'Start a new automated loop workflow from a named definition. The loop runs multiple roles in sequence per iteration, evaluating termination conditions between cycles. Use the goal to describe the subject matter; parameters should tune the definition rather than identify the loop. Loops can reuse an existing project (by ID or slug) instead of auto-creating a new one.',
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
                new StringParameter(
                    name: 'parameters',
                    description: 'JSON object of template parameter values (e.g. {"output_format": "comparison matrix", "language": "PHP"}). These substitute {{variable}} placeholders in the loop\'s role prompts.',
                    required: false,
                ),
                new StringParameter(
                    name: 'project_id',
                    description: 'Reuse an existing project by ID. The loop will scope all work to this project instead of creating a new one. Mutually exclusive with project_slug.',
                    required: false,
                ),
                new StringParameter(
                    name: 'project_slug',
                    description: 'Reuse an existing project by slug. The loop will scope all work to this project instead of creating a new one. Mutually exclusive with project_id.',
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

                $rawDefinition = $this->loopDiscovery->getRawDefinition($defName);

                // Parse template parameters if provided
                $parameters = [];
                if (isset($input['parameters']) && $input['parameters'] !== '') {
                    $decoded = json_decode((string) $input['parameters'], true);
                    if (!is_array($decoded)) {
                        return ToolResult::error('The "parameters" field must be a valid JSON object (e.g. {"output_format": "report"})');
                    }
                    $parameters = array_map('strval', $decoded);
                }

                $maxIterations = null;
                if (isset($input['max_iterations']) && $input['max_iterations'] !== '') {
                    $maxIterations = (int) $input['max_iterations'];
                    if ($maxIterations < 1) {
                        return ToolResult::error('The "max_iterations" field must be greater than 0');
                    }
                }

                // Use LoopExecutor to actually start the loop when available
                if ($this->executor !== null) {
                    // Verify API server is reachable before creating the loop —
                    // loops depend on LoopManager (API) to advance stages via background tasks.
                    $health = ($this->healthCheck ?? fn(): array => ApiHealthCheck::check(
                        expectedWorkspacePath: $this->expectedWorkspacePath,
                        requireTaskManager: true,
                        requireLoopManager: true,
                    ))();
                    if (!$health['ok']) {
                        return ToolResult::error($health['error'] ?? 'Cannot reach the API server required for loop execution.');
                    }

                    try {
                        // Resolve project input
                        $projectId = isset($input['project_id']) && $input['project_id'] !== ''
                            ? (string) $input['project_id']
                            : null;
                        $projectSlug = isset($input['project_slug']) && $input['project_slug'] !== ''
                            ? (string) $input['project_slug']
                            : null;
                        if ($projectId !== null && $projectSlug !== null) {
                            return ToolResult::error('Specify either "project_id" or "project_slug", not both');
                        }

                        $loopId = $this->executor->startLoop(
                            rawDefinition: $rawDefinition,
                            goal: $goal,
                            sessionId: $this->sessionId,
                            parameters: $parameters,
                            projectId: $projectId,
                            projectSlug: $projectSlug,
                            maxIterationsOverride: $maxIterations,
                        );

                        // Parse the definition for display (doesn't need substitution for metadata)
                        $definition = $this->loopDiscovery->get($defName);

                        return ToolResult::json([
                            'loop_id' => $loopId,
                            'definition' => $defName,
                            'goal' => $goal,
                            'max_iterations' => $maxIterations,
                            'parameters' => $parameters !== [] ? $parameters : null,
                            'roles' => array_map(fn($r) => $r->role, $definition->roles),
                            'termination' => $definition->terminationCondition->type->value,
                            'message' => "Loop \"{$defName}\" started successfully with ID {$loopId}. Stages will execute as background tasks via the API server. Use loop_status(id: \"{$loopId}\") to monitor progress.",
                        ]);
                    } catch (\Throwable $e) {
                        return ToolResult::error(sprintf('Failed to start loop: %s', $e->getMessage()));
                    }
                }

                // Fallback: return definition details when executor is not available
                $definition = $this->loopDiscovery->get($defName);
                return ToolResult::json([
                    'action' => 'start_loop',
                    'definition' => $defName,
                    'goal' => $goal,
                    'max_iterations' => $maxIterations,
                    'roles' => array_map(fn($r) => $r->role, $definition->roles),
                    'termination' => $definition->terminationCondition->type->value,
                    'message' => "Loop \"{$defName}\" is ready to start. Stages will execute as background tasks via the API server.",
                ]);
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
                    'metadata' => JsonHelper::decodeJsonObject($loop['metadata'] ?? null),
                ];

                if ($iteration !== null) {
                    $output['current_iteration_status'] = $iteration['status'];
                    $output['stages'] = array_map(fn(array $s) => [
                        'index' => $s['stage_index'],
                        'role' => $s['role'],
                        'status' => $s['status'],
                        'summary' => $s['result_summary'] !== null ? mb_substr($s['result_summary'], 0, 200) : null,
                        'metadata' => JsonHelper::decodeJsonObject($s['metadata'] ?? null),
                    ], $stages);
                }

                return ToolResult::json($output);
            },
        );
    }

    private function loopControlTool(): Tool
    {
        return new Tool(
            name: 'loop_control',
            description: 'Pause, resume, or cancel a loop. Pass id="all" to apply to all matching loops at once.',
            parameters: [
                new EnumParameter(
                    name: 'action',
                    description: 'The control action to perform',
                    values: ['pause', 'resume', 'stop'],
                    required: true,
                ),
                new StringParameter(name: 'id', description: 'Loop ID or "all"', required: true),
            ],
            callback: function (array $input): ToolResult {
                $action = (string) ($input['action'] ?? '');
                $id = (string) ($input['id'] ?? '');

                if ($action === '' || $id === '') {
                    return ToolResult::error('Both "action" and "id" are required.');
                }

                return match ($action) {
                    'pause' => $this->executePause($id),
                    'resume' => $this->executeResume($id),
                    'stop' => $this->executeStop($id),
                    default => ToolResult::error("Unknown action: {$action}"),
                };
            },
        );
    }

    private function executePause(string $id): ToolResult
    {
        if (strtolower($id) === 'all') {
            $running = $this->loopStore->listLoops('running');
            if ($running === []) {
                return ToolResult::success('No running loops to pause.');
            }

            foreach ($running as $loop) {
                $this->loopStore->updateLoopStatus((string) $loop['id'], 'paused');
            }

            return ToolResult::success(sprintf('Paused %d loop(s).', count($running)));
        }

        $loop = $this->loopStore->getLoop($id);

        if ($loop === null) {
            return ToolResult::error("Loop \"{$id}\" not found");
        }

        if ($loop['status'] !== 'running') {
            return ToolResult::error("Cannot pause loop — current status is \"{$loop['status']}\"");
        }

        $this->loopStore->updateLoopStatus($id, 'paused');
        return ToolResult::success("Loop \"{$id}\" paused. Use loop_control(action: \"resume\") to continue.");
    }

    private function executeResume(string $id): ToolResult
    {
        if (strtolower($id) === 'all') {
            $paused = $this->loopStore->listLoops('paused');
            if ($paused === []) {
                return ToolResult::success('No paused loops to resume.');
            }

            foreach ($paused as $loop) {
                $this->loopStore->updateLoopStatus((string) $loop['id'], 'running');
            }

            return ToolResult::success(sprintf('Resumed %d loop(s).', count($paused)));
        }

        $loop = $this->loopStore->getLoop($id);

        if ($loop === null) {
            return ToolResult::error("Loop \"{$id}\" not found");
        }

        if ($loop['status'] !== 'paused') {
            return ToolResult::error("Cannot resume loop — current status is \"{$loop['status']}\"");
        }

        $this->loopStore->updateLoopStatus($id, 'running');
        return ToolResult::success("Loop \"{$id}\" resumed.");
    }

    private function executeStop(string $id): ToolResult
    {
        if (strtolower($id) === 'all') {
            $active = array_merge(
                $this->loopStore->listLoops('running'),
                $this->loopStore->listLoops('paused'),
            );

            if ($active === []) {
                return ToolResult::success('No active loops to cancel.');
            }

            foreach ($active as $loop) {
                $this->loopStore->updateLoopStatus((string) $loop['id'], 'cancelled');
            }

            return ToolResult::success(sprintf('Cancelled %d loop(s).', count($active)));
        }

        $loop = $this->loopStore->getLoop($id);

        if ($loop === null) {
            return ToolResult::error("Loop \"{$id}\" not found");
        }

        if (!in_array($loop['status'], ['running', 'paused'], true)) {
            return ToolResult::error("Cannot stop loop — current status is \"{$loop['status']}\"");
        }

        $this->loopStore->updateLoopStatus($id, 'cancelled');
        return ToolResult::success("Loop \"{$id}\" cancelled.");
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
                    $paramInfo = '';
                    if ($def->parameters !== []) {
                        $paramNames = array_map(fn($p) => $p->required ? $p->name : $p->name . '?', $def->parameters);
                        $paramInfo = ' | Parameters: ' . implode(', ', $paramNames);
                    }
                    $lines[] = sprintf(
                        "**%s** — %s\n  Roles: %s | Termination: %s%s",
                        $def->name,
                        $def->description,
                        $roles,
                        $termination,
                        $paramInfo,
                    );
                }

                return ToolResult::success(implode("\n\n", $lines));
            },
        );
    }
}
