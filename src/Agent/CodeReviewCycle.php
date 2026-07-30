<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Contract\ChildAgentHandoff;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\CodeReviewResult;
use CoquiBot\Coqui\Contract\ReviewHandoffMetadata;
use CoquiBot\Coqui\Contract\ReviewVerdict;
use CoquiBot\Coqui\Contract\SystemRole;
use SplObserver;

/**
 * Orchestrates the code → review → iterate cycle.
 *
 * After a coder agent completes, this class spawns a reviewer child agent
 * to evaluate the output. If the reviewer returns NEEDS_CHANGES, the coder
 * is re-invoked with the feedback. The cycle repeats up to maxRounds.
 *
 * Used by SpawnAgentTool (full iterate loop) and AgentRunner (single pass).
 */
final class CodeReviewCycle
{
    private const int DEFAULT_MAX_ROUNDS = 2;
    private const int REVIEWER_MAX_ITERATIONS = 15;

    public function __construct(
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly ?RoleDiscovery $roleDiscovery = null,
        private readonly ?SplObserver $observer = null,
        private readonly ?ToolExecutorInterface $toolExecutor = null,
        private readonly ?ProviderFactory $providerFactory = null,
        private readonly ?string $activePersona = null,
        private readonly ?string $activePersonaPath = null,
        private readonly ?string $personaIdentityPreamble = null,
    ) {}

    /**
     * Run a full code-review-iterate cycle.
     *
     * @param string $coderOutput        The coder agent's initial output to review.
     * @param string $originalTask       The original task given to the coder.
     * @param ToolkitInterface[] $reviewerToolkits  Toolkits for the reviewer child agent.
     * @param int $maxRounds             Maximum review rounds (each round = one reviewer pass + optional coder re-run).
     * @param ToolkitInterface[] $coderToolkits     Toolkits for re-running the coder (only needed if autoIterate=true).
     * @param bool $autoIterate          If true, re-run coder on NEEDS_CHANGES. If false, return after first review.
     * @param int $coderMaxIterations    Max iterations for coder re-runs.
     */
    public function run(
        string $coderOutput,
        string $originalTask,
        array $reviewerToolkits,
        int $maxRounds = self::DEFAULT_MAX_ROUNDS,
        array $coderToolkits = [],
        bool $autoIterate = true,
        int $coderMaxIterations = 48,
    ): CodeReviewResult {
        $totalTokens = 0;
        $coderIterations = 0;
        $reviewerIterations = 0;
        $currentOutput = $coderOutput;
        $reviewFeedback = '';

        for ($round = 1; $round <= $maxRounds; $round++) {
            // Emit review start event
            $this->emitEvent('child.review_start', [
                'round' => $round,
                'max_rounds' => $maxRounds,
            ]);

            // Run reviewer
            $reviewerResult = $this->runReviewer(
                coderOutput: $currentOutput,
                originalTask: $originalTask,
                toolkits: $reviewerToolkits,
                round: $round,
            );

            $totalTokens += $reviewerResult['tokens'];
            $reviewerIterations += $reviewerResult['iterations'];
            $reviewFeedback = $reviewerResult['feedback'];

            $verdict = ReviewVerdict::fromReviewerOutput($reviewerResult['feedback']);

            // Emit review result event
            $this->emitEvent('child.review_end', [
                'round' => $round,
                'verdict' => $verdict->value,
                'approved' => $verdict->isApproved(),
            ]);

            if ($verdict->isApproved()) {
                return new CodeReviewResult(
                    finalContent: $currentOutput,
                    approved: true,
                    reviewFeedback: $reviewFeedback,
                    roundsUsed: $round,
                    totalTokens: $totalTokens,
                    coderIterations: $coderIterations,
                    reviewerIterations: $reviewerIterations,
                );
            }

            // If not auto-iterating or this is the last round, return with feedback
            if (!$autoIterate || $round >= $maxRounds) {
                break;
            }

            // Re-run coder with review feedback
            $coderResult = $this->runCoderIteration(
                originalTask: $originalTask,
                previousOutput: $currentOutput,
                reviewFeedback: $reviewFeedback,
                toolkits: $coderToolkits,
                maxIterations: $coderMaxIterations,
                round: $round,
            );

            $totalTokens += $coderResult['tokens'];
            $coderIterations += $coderResult['iterations'];
            $currentOutput = $coderResult['output'];
        }

        return new CodeReviewResult(
            finalContent: $currentOutput,
            approved: false,
            reviewFeedback: $reviewFeedback,
            roundsUsed: min($maxRounds, $round),
            totalTokens: $totalTokens,
            coderIterations: $coderIterations,
            reviewerIterations: $reviewerIterations,
        );
    }

    /**
     * Run a single reviewer pass against the coder's output.
     *
     * @param ToolkitInterface[] $toolkits
     * @return array{feedback: string, tokens: int, iterations: int}
     */
    private function runReviewer(
        string $coderOutput,
        string $originalTask,
        array $toolkits,
        int $round,
    ): array {
        $reviewerModelString = $this->roleResolver->resolve(SystemRole::Reviewer->value, $this->activePersona);

        try {
            $factory = $this->providerFactory ?? new ProviderFactory($this->config);
            $provider = $factory->create($reviewerModelString);
        } catch (\Throwable $e) {
            return [
                'feedback' => "Error creating reviewer provider: {$e->getMessage()}",
                'tokens' => 0,
                'iterations' => 0,
            ];
        }

        $prompt = $this->buildReviewerPrompt($coderOutput, $originalTask, $round);
        $handoff = ChildAgentHandoff::fromInput(
            task: $prompt,
            metadata: (new ReviewHandoffMetadata(
                phase: 'review',
                round: $round,
                sourceRole: SystemRole::Coder->value,
                autoIterate: false,
            ))->toArray(),
            intent: 'code_review',
            workflowPhase: 'review',
        );

        $child = new ChildAgent(
            provider: $provider,
            role: SystemRole::Reviewer->value,
            taskInstructions: $handoff,
            toolkits: $toolkits,
            maxIterations: self::REVIEWER_MAX_ITERATIONS,
            roleDiscovery: $this->roleDiscovery,
            toolExecutor: $this->toolExecutor,
            personaIdentityPreamble: $this->personaIdentityPreamble,
            activePersonaPath: $this->activePersonaPath,
        );

        if ($this->observer !== null) {
            $child->attach($this->observer);
        }

        try {
            $output = $child->run(new UserMessage($handoff->userPrompt()));

            return [
                'feedback' => $output->content,
                'tokens' => $output->usage !== null ? $output->usage->totalTokens : 0,
                'iterations' => $output->iterations,
            ];
        } catch (\Throwable $e) {
            return [
                'feedback' => "Reviewer error: {$e->getMessage()}",
                'tokens' => 0,
                'iterations' => 0,
            ];
        }
    }

    /**
     * Re-run the coder with review feedback injected as context.
     *
     * @param ToolkitInterface[] $toolkits
     * @return array{output: string, tokens: int, iterations: int}
     */
    private function runCoderIteration(
        string $originalTask,
        string $previousOutput,
        string $reviewFeedback,
        array $toolkits,
        int $maxIterations,
        int $round,
    ): array {
        $coderModelString = $this->roleResolver->resolve(SystemRole::Coder->value, $this->activePersona);

        try {
            $factory = $this->providerFactory ?? new ProviderFactory($this->config);
            $provider = $factory->create($coderModelString);
        } catch (\Throwable $e) {
            return [
                'output' => $previousOutput,
                'tokens' => 0,
                'iterations' => 0,
            ];
        }

        $prompt = $this->buildCoderIterationPrompt($originalTask, $reviewFeedback, $round);
        $handoff = ChildAgentHandoff::fromInput(
            task: $prompt,
            metadata: (new ReviewHandoffMetadata(
                phase: 'rework',
                round: $round,
                sourceRole: SystemRole::Reviewer->value,
                autoIterate: true,
            ))->toArray(),
            intent: 'code_rework',
            workflowPhase: 'rework',
        );

        $child = new ChildAgent(
            provider: $provider,
            role: SystemRole::Coder->value,
            taskInstructions: $handoff,
            toolkits: $toolkits,
            maxIterations: $maxIterations,
            roleDiscovery: $this->roleDiscovery,
            toolExecutor: $this->toolExecutor,
            personaIdentityPreamble: $this->personaIdentityPreamble,
            activePersonaPath: $this->activePersonaPath,
        );

        if ($this->observer !== null) {
            $child->attach($this->observer);
        }

        try {
            $output = $child->run(new UserMessage($handoff->userPrompt()));

            return [
                'output' => $output->content,
                'tokens' => $output->usage !== null ? $output->usage->totalTokens : 0,
                'iterations' => $output->iterations,
            ];
        } catch (\Throwable $e) {
            return [
                'output' => $previousOutput,
                'tokens' => 0,
                'iterations' => 0,
            ];
        }
    }

    private function buildReviewerPrompt(
        string $coderOutput,
        string $originalTask,
        int $round,
    ): string {
        $prompt = <<<PROMPT
            ## Automated Code Review (Round {$round})

            Review the coder agent's work against the original task requirements.

            ### Original Task
            {$originalTask}

            ### Coder's Output
            {$coderOutput}
            PROMPT;

        $prompt .= <<<'PROMPT'


            ### Review Instructions

            1. Read the coder's output and any files they modified
            2. Verify the implementation matches the original task requirements
            3. Check for correctness, security issues, and code quality
            4. If tests exist for the modified code, note whether they should pass

            ### Required Output Format

            End your review with exactly one of these verdict markers on its own line:
            - `VERDICT: APPROVED` — if the implementation is correct and complete
            - `VERDICT: NEEDS_CHANGES` — if issues need to be fixed

            If NEEDS_CHANGES, list specific actionable items the coder must address.
            PROMPT;

        return $prompt;
    }

    private function buildCoderIterationPrompt(
        string $originalTask,
        string $reviewFeedback,
        int $round,
    ): string {
        return <<<PROMPT
            ## Code Review Iteration (Round {$round})

            Your previous implementation was reviewed and needs changes.

            ### Original Task
            {$originalTask}

            ### Reviewer Feedback
            {$reviewFeedback}

            ### Instructions

            Address ALL of the reviewer's feedback. Focus on the specific issues identified.
            Read the relevant files to understand the current state, then make the necessary changes.
            PROMPT;
    }

    private function emitEvent(string $event, mixed $data): void
    {
        if (!is_object($this->observer) || !method_exists($this->observer, 'handleEvent')) {
            return;
        }

        call_user_func([$this->observer, 'handleEvent'], $event, $data);
    }
}
