<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\RoleToolkitResolver;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitVisibility;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CoquiBot\Coqui\Contract\LoopDefinition;
use CoquiBot\Coqui\Contract\LoopStageResult;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;
use CoquiBot\Coqui\Toolkit\ArtifactToolkit;
use CoquiBot\Coqui\Toolkit\FileSystemToolkit;
use CoquiBot\Coqui\Toolkit\MemoryToolkit;
use CoquiBot\Coqui\Toolkit\CoquiSourceToolkit;
use CoquiBot\Coqui\Toolkit\SkillToolkit;
use CoquiBot\Coqui\Toolkit\SprintToolkit;
use CoquiBot\Coqui\Toolkit\TodoToolkit;
use CoquiBot\Coqui\Toolkit\ShellToolkit;
use CoquiBot\Coqui\Toolkit\WebToolkit;
use SplObserver;

/**
 * Drives synchronous loop execution in the REPL.
 *
 * For each stage, spawns a child agent (like SpawnAgentTool) and runs it in the
 * same process with shared observers. After all stages in an iteration complete,
 * evaluates the termination condition and advances to the next iteration if needed.
 */
final class LoopRunner
{
    public function __construct(
        private readonly LoopExecutor $executor,
        private readonly LoopStore $loopStore,
        private readonly RoleResolver $roleResolver,
        private readonly RoleDiscovery $roleDiscovery,
        private readonly ConfigInterface $config,
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly ?SessionStorage $storage = null,
        private readonly ?ArtifactStore $artifactStore = null,
        private readonly ?TodoStore $todoStore = null,
        private readonly ?ProjectStore $projectStore = null,
        private readonly ?MemoryStore $memoryStore = null,
        private readonly ?SkillDiscovery $skillDiscovery = null,
        private readonly ?ToolkitDiscovery $discovery = null,
        private readonly ?ToolkitVisibilityRegistry $visibilityRegistry = null,
        private readonly ?MountManager $mountManager = null,
        private readonly ?SplObserver $observer = null,
        private readonly bool $unsafeMode = false,
        /** @var list<string> */
        private readonly array $shellAllowedCommands = [],
        /** @var list<string> */
        private readonly array $shellDeniedCommands = [],
    ) {}

    /**
     * Run a loop synchronously from start to completion.
     *
     * @return array{loop_id: string, outcome: IterationOutcome, iterations_completed: int, total_stages_run: int}
     */
    public function run(
        LoopDefinition $definition,
        string $goal,
        ?string $sessionId = null,
    ): array {
        $loopId = $this->executor->startLoop($definition, $goal, $sessionId);

        $this->emitEvent('loop.start', [
            'loop_id' => $loopId,
            'definition' => $definition->name,
            'goal' => $goal,
        ]);

        $totalStagesRun = 0;
        $outcome = IterationOutcome::Continue;

        while ($outcome === IterationOutcome::Continue) {
            $state = $this->loopStore->getCurrentState($loopId);
            if ($state === null) {
                $outcome = IterationOutcome::Failed;
                break;
            }

            $loop = $state['loop'];
            if ($loop['status'] !== 'running') {
                $outcome = match ($loop['status']) {
                    'completed' => IterationOutcome::Complete,
                    'cancelled' => IterationOutcome::LimitReached,
                    default => IterationOutcome::Failed,
                };
                break;
            }

            $iterationNumber = (int) ($state['iteration']['iteration_number'] ?? 0);

            $this->emitEvent('loop.iteration_start', [
                'loop_id' => $loopId,
                'iteration' => $iterationNumber,
            ]);

            // Execute all stages in this iteration
            $stageResult = $this->executor->prepareNextStage($loopId);

            while ($stageResult !== null) {
                $this->emitEvent('loop.stage_start', [
                    'loop_id' => $loopId,
                    'iteration' => $iterationNumber,
                    'stage' => $stageResult->stageIndex,
                    'role' => $stageResult->role,
                ]);

                $result = $this->executeStage($stageResult);
                $totalStagesRun++;

                if ($result['success']) {
                    // Create an artifact for the stage output if we have a session
                    $artifactId = null;
                    if ($this->artifactStore !== null && $sessionId !== null) {
                        $artifactId = $this->artifactStore->create(
                            sessionId: $sessionId,
                            title: sprintf('Loop %s — iter %d, stage %d (%s)', $definition->name, $iterationNumber, $stageResult->stageIndex + 1, $stageResult->role),
                            content: $result['output'],
                            type: 'loop_output',
                            stage: 'final',
                            sprintId: $stageResult->sprintId,
                        );
                    }

                    $this->executor->completeStage(
                        stageId: $stageResult->stageId,
                        result: $result['output'],
                        artifactId: $artifactId,
                    );

                    $this->emitEvent('loop.stage_end', [
                        'loop_id' => $loopId,
                        'iteration' => $iterationNumber,
                        'stage' => $stageResult->stageIndex,
                        'role' => $stageResult->role,
                        'success' => true,
                    ]);
                } else {
                    $this->executor->failStage($stageResult->stageId, $result['output']);

                    $this->emitEvent('loop.stage_end', [
                        'loop_id' => $loopId,
                        'iteration' => $iterationNumber,
                        'stage' => $stageResult->stageIndex,
                        'role' => $stageResult->role,
                        'success' => false,
                        'error' => $result['output'],
                    ]);
                }

                // Get next stage (or null if iteration complete)
                $stageResult = $this->executor->prepareNextStage($loopId);
            }

            $this->emitEvent('loop.iteration_end', [
                'loop_id' => $loopId,
                'iteration' => $iterationNumber,
            ]);

            // Evaluate the iteration — this may advance to next or complete
            $outcome = $this->executor->evaluateIteration($loopId);
        }

        $this->emitEvent('loop.complete', [
            'loop_id' => $loopId,
            'outcome' => $outcome->value,
            'iterations_completed' => (int) ($this->loopStore->getLoop($loopId)['current_iteration'] ?? 0),
            'total_stages_run' => $totalStagesRun,
        ]);

        return [
            'loop_id' => $loopId,
            'outcome' => $outcome,
            'iterations_completed' => (int) ($this->loopStore->getLoop($loopId)['current_iteration'] ?? 0),
            'total_stages_run' => $totalStagesRun,
        ];
    }

    // ──────────────────────────────────────────────
    //  Private: Stage Execution
    // ──────────────────────────────────────────────

    /**
     * Execute a single stage by spawning a child agent.
     *
     * @return array{success: bool, output: string}
     */
    private function executeStage(LoopStageResult $stageResult): array
    {
        $role = $stageResult->role;
        $modelString = $this->roleResolver->resolve($role);

        try {
            $factory = new ProviderFactory($this->config);
            $provider = $factory->create($modelString);
        } catch (\Throwable $e) {
            return ['success' => false, 'output' => "Error creating provider for {$role}: {$e->getMessage()}"];
        }

        $toolkits = $this->buildToolkits($role, $stageResult->sessionId);
        $maxIterations = $stageResult->maxIterations ?? $this->resolveMaxIterations($role);

        $child = new ChildAgent(
            provider: $provider,
            role: $role,
            taskInstructions: $stageResult->prompt,
            toolkits: $toolkits,
            maxIterations: $maxIterations,
            roleDiscovery: $this->roleDiscovery,
        );

        if ($this->observer !== null) {
            $child->attach($this->observer);
        }

        try {
            $output = $child->run(new UserMessage($stageResult->prompt));

            return ['success' => true, 'output' => $output->content];
        } catch (\Throwable $e) {
            return ['success' => false, 'output' => "Stage execution error ({$role}): {$e->getMessage()}"];
        }
    }

    /**
     * Build toolkits for a child agent based on its role's access level.
     *
     * Mirrors the pattern from SpawnAgentTool::buildToolkits().
     * Intentionally excludes LoopToolkit, BackgroundTaskToolkit, ScheduleToolkit,
     * and WebhookToolkit to prevent nested loops and uncontrolled spawning.
     *
     * @return ToolkitInterface[]
     */
    private function buildToolkits(string $role, ?string $sessionId = null): array
    {
        $accessLevel = $this->resolveAccessLevel($role);

        $mountPaths = match ($accessLevel) {
            'full' => $this->mountManager?->allowedPaths() ?? [],
            default => $this->mountManager?->allowedPathsReadOnly() ?? [],
        };

        $toolkits = match ($accessLevel) {
            'full' => [
                new FileSystemToolkit(workspacePath: $this->workspacePath, allowedPaths: $mountPaths),
                new ShellToolkit(
                    workDir: $this->projectRoot,
                    allowedCommands: $this->unsafeMode ? [] : $this->shellAllowedCommands,
                    deniedCommands: $this->unsafeMode ? [] : $this->shellDeniedCommands,
                    timeout: 60,
                    unsafe: $this->unsafeMode,
                ),
                new WebToolkit(),
                new CoquiSourceToolkit(projectRoot: $this->projectRoot),
            ],

            'readonly-shell' => [
                new FileSystemToolkit(workspacePath: $this->workspacePath, readOnly: true, allowedPaths: $mountPaths),
                new ShellToolkit(
                    workDir: $this->projectRoot,
                    allowedCommands: ['grep', 'find', 'cat', 'head', 'tail', 'wc', 'ls', 'sort', 'uniq', 'sed', 'awk', 'diff'],
                    timeout: 60,
                ),
                new CoquiSourceToolkit(projectRoot: $this->projectRoot),
            ],

            'readonly' => [
                new FileSystemToolkit(workspacePath: $this->workspacePath, readOnly: true, allowedPaths: $mountPaths),
                new CoquiSourceToolkit(projectRoot: $this->projectRoot),
            ],

            default => [],
        };

        // Memory toolkit for full-access roles
        if ($this->memoryStore !== null && $accessLevel === 'full') {
            $toolkits[] = new MemoryToolkit($this->memoryStore);
        }

        // Skills toolkit for non-minimal roles
        if ($this->skillDiscovery !== null && $accessLevel !== 'minimal') {
            $toolkits[] = new SkillToolkit($this->skillDiscovery);
        }

        // Artifact toolkit — bind to parent session so stage agents can see parent artifacts
        if ($this->artifactStore !== null && $this->storage !== null) {
            $toolkits[] = new ArtifactToolkit(
                $this->artifactStore,
                $sessionId,
                readOnly: $accessLevel !== 'full',
            );
        }

        // Todo toolkit — bind to parent session for shared task tracking
        if ($this->todoStore !== null && $this->storage !== null) {
            $toolkits[] = new TodoToolkit(
                $this->todoStore,
                $sessionId,
                $role,
                $accessLevel,
            );
        }

        // Sprint toolkit — bind to parent session for project coordination
        if ($this->projectStore !== null && $this->storage !== null) {
            $toolkits[] = new SprintToolkit(
                $this->projectStore,
                $this->todoStore,
                sessionId: $sessionId,
                workspacePath: $this->workspacePath,
            );
        }

        // Auto-discovered toolkits from installed packages
        if ($this->discovery !== null && $accessLevel !== 'minimal') {
            foreach ($this->discovery->instantiateRegisteredGrouped(childMode: true) as $entry) {
                $toolkit = $entry['toolkit'];

                if ($this->visibilityRegistry !== null) {
                    $vis = $this->visibilityRegistry->getPackageVisibility($entry['package']);
                    if ($vis === ToolkitVisibility::Disabled) {
                        continue;
                    }
                }

                $toolkits[] = $toolkit;
            }
        }

        return $toolkits;
    }

    /**
     * Resolve the access level for a role from its frontmatter.
     */
    private function resolveAccessLevel(string $role): string
    {
        try {
            $properties = $this->roleDiscovery->getRole($role);
            return $properties->accessLevel ?? 'full';
        } catch (\Throwable) {
            return 'full';
        }
    }

    /**
     * Resolve the max iterations for a role from its frontmatter or config defaults.
     */
    private function resolveMaxIterations(string $role): int
    {
        try {
            $properties = $this->roleDiscovery->getRole($role);
            if ($properties->maxIterations !== null && $properties->maxIterations > 0) {
                return $properties->maxIterations;
            }
        } catch (\Throwable) {
            // Fall through to config default
        }

        $configMax = $this->config->get('agents.defaults.maxIterations');
        if (is_int($configMax) && $configMax > 0) {
            return $configMax;
        }

        return 48;
    }

    /**
     * Emit an observer event if an observer is attached.
     *
     * @param array<string, mixed> $data
     */
    private function emitEvent(string $event, array $data): void
    {
        if ($this->observer === null) {
            return;
        }

        // Use a transient SplSubject to emit the event
        $subject = new class ($event, $data) implements \SplSubject {
            /** @var list<SplObserver> */
            private array $observers = [];

            /**
             * @param array<string, mixed> $data
             */
            public function __construct(
                public readonly string $event,
                public readonly array $data,
            ) {}

            public function attach(\SplObserver $observer): void
            {
                $this->observers[] = $observer;
            }

            public function detach(\SplObserver $observer): void
            {
                $this->observers = array_filter($this->observers, fn(\SplObserver $o) => $o !== $observer);
            }

            public function notify(): void
            {
                foreach ($this->observers as $observer) {
                    $observer->update($this);
                }
            }

            public function getEventName(): string
            {
                return $this->event;
            }

            /**
             * @return array<string, mixed>
             */
            public function getEventData(): array
            {
                return $this->data;
            }
        };

        $subject->attach($this->observer);
        $subject->notify();
    }
}
