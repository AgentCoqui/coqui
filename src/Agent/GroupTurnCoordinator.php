<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Contract\DeferredWorkQueue;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Coordinates a single stored turn across multiple profiled responders.
 *
 * Group sessions remain a single session and a single top-level turn. This
 * coordinator chooses which member profiles speak, executes them sequentially,
 * and aggregates their results into one durable turn payload.
 */
final readonly class GroupTurnCoordinator
{
    public function __construct(
        private SessionStorage $storage,
    ) {}

    /**
     * @param list<string> $members
     * @param string[]|null $filePaths
     * @param callable(string, string, int, ?array): AgentTurnResult $executeActor
     */
    public function run(
        string $sessionId,
        string $prompt,
        string $modelString,
        string $modelRole,
        array $members,
        int $maxRounds,
        ?string $turnProcessId,
        ?array $filePaths,
        callable $executeActor,
    ): AgentTurnResult {
        $turnId = $this->storage->createTurn($sessionId, $prompt, $modelString, $turnProcessId);
        $this->storage->addMessage($sessionId, 'user', $prompt, turnId: $turnId);

        $maxRounds = max(1, $maxRounds);
        $round = 1;
        $queue = $this->resolveInitialResponders($prompt, $members);
        $deferredWork = new DeferredWorkQueue();
        $actorResponses = [];
        $toolsUsed = [];
        $promptTokens = 0;
        $completionTokens = 0;
        $totalTokens = 0;
        $iterations = 0;
        $childAgentCount = 0;
        $restartRequested = false;
        $iterationLimitReached = false;
        $budgetExhausted = false;
        $contextUsage = null;
        $backgroundTasks = null;
        $reviewApproved = null;
        $reviewFeedbackParts = [];
        $fileEdits = [];
        $startedAt = hrtime(true);

        while ($queue !== [] && $round <= $maxRounds) {
            $this->appendLifecycleEvent($turnProcessId, 'group_round_start', [
                'round' => $round,
                'responders' => $queue,
                'max_rounds' => $maxRounds,
            ]);

            $nextRound = [];

            foreach ($queue as $actorName) {
                $actorPrompt = $round === 1
                    ? $this->buildInitialActorPrompt($actorName, $members)
                    : $this->buildFollowUpActorPrompt($actorName, $members, $round, $maxRounds);

                $this->appendLifecycleEvent($turnProcessId, 'group_actor_start', [
                    'round' => $round,
                    'actor_name' => $actorName,
                    'actor_role' => $modelRole,
                ]);

                $segmentResult = $executeActor($actorPrompt, $actorName, $round, $filePaths);

                if ($segmentResult->deferredWork !== null && !$segmentResult->deferredWork->isEmpty()) {
                    $queueToProcess = $segmentResult->deferredWork;
                    $deferredWork->enqueue(static function () use ($queueToProcess): void {
                        $queueToProcess->process();
                    });
                }

                if ($segmentResult->error !== null) {
                    $errorResult = new AgentTurnResult(
                        content: $this->renderCombinedContent($actorResponses),
                        iterations: $iterations,
                        promptTokens: $promptTokens,
                        completionTokens: $completionTokens,
                        totalTokens: $totalTokens,
                        durationMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
                        toolsUsed: array_keys($toolsUsed),
                        childAgentCount: $childAgentCount,
                        restartRequested: $restartRequested,
                        iterationLimitReached: $iterationLimitReached,
                        budgetExhausted: $budgetExhausted,
                        contextUsage: $contextUsage,
                        fileEdits: $fileEdits === [] ? null : array_values($fileEdits),
                        actorResponses: $actorResponses === [] ? null : $actorResponses,
                        error: $segmentResult->error,
                        reviewFeedback: $reviewFeedbackParts === [] ? null : implode("\n\n", $reviewFeedbackParts),
                        reviewApproved: $reviewApproved,
                        deferredWork: $deferredWork,
                        backgroundTasks: $backgroundTasks,
                    );

                    $this->finalizeTurn($turnId, $errorResult);

                    return $errorResult;
                }

                $promptTokens += $segmentResult->promptTokens;
                $completionTokens += $segmentResult->completionTokens;
                $totalTokens += $segmentResult->totalTokens;
                $iterations += $segmentResult->iterations;
                $childAgentCount += $segmentResult->childAgentCount;
                $restartRequested = $restartRequested || $segmentResult->restartRequested;
                $iterationLimitReached = $iterationLimitReached || $segmentResult->iterationLimitReached;
                $budgetExhausted = $budgetExhausted || $segmentResult->budgetExhausted;
                $contextUsage = $segmentResult->contextUsage ?? $contextUsage;
                $backgroundTasks = $segmentResult->backgroundTasks ?? $backgroundTasks;

                foreach ($segmentResult->toolsUsed as $toolName) {
                    $toolsUsed[$toolName] = true;
                }

                if ($segmentResult->reviewFeedback !== null && $segmentResult->reviewFeedback !== '') {
                    $reviewFeedbackParts[] = sprintf('@%s: %s', $actorName, $segmentResult->reviewFeedback);
                }

                if ($segmentResult->reviewApproved !== null) {
                    $reviewApproved = $reviewApproved === null
                        ? $segmentResult->reviewApproved
                        : ($reviewApproved && $segmentResult->reviewApproved);
                }

                if (is_array($segmentResult->fileEdits)) {
                    foreach ($segmentResult->fileEdits as $edit) {
                        $filePath = is_string($edit['file_path'] ?? null) ? $edit['file_path'] : null;
                        $operation = is_string($edit['operation'] ?? null) ? $edit['operation'] : null;
                        if ($filePath === null || $operation === null) {
                            continue;
                        }

                        $fileEdits[$filePath . '|' . $operation] = [
                            'file_path' => $filePath,
                            'operation' => $operation,
                        ];
                    }
                }

                $content = trim($segmentResult->content);
                if ($content !== '') {
                    $actorResponses[] = [
                        'actor_name' => $actorName,
                        'actor_role' => $modelRole,
                        'content' => $segmentResult->content,
                        'round' => $round,
                    ];
                }

                foreach ($this->extractMentionedMembers($segmentResult->content, $members, $actorName) as $mentionedActor) {
                    if (!in_array($mentionedActor, $nextRound, true)) {
                        $nextRound[] = $mentionedActor;
                    }
                }

                $this->appendLifecycleEvent($turnProcessId, 'group_actor_end', [
                    'round' => $round,
                    'actor_name' => $actorName,
                    'actor_role' => $modelRole,
                    'mentioned_next' => $nextRound,
                ]);
            }

            $this->appendLifecycleEvent($turnProcessId, 'group_round_end', [
                'round' => $round,
                'next_responders' => $nextRound,
                'max_rounds' => $maxRounds,
            ]);

            $queue = $nextRound;
            $round++;
        }

        $turnResult = new AgentTurnResult(
            content: $this->renderCombinedContent($actorResponses),
            iterations: $iterations,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            durationMs: (int) ((hrtime(true) - $startedAt) / 1_000_000),
            toolsUsed: array_keys($toolsUsed),
            childAgentCount: $childAgentCount,
            restartRequested: $restartRequested,
            iterationLimitReached: $iterationLimitReached,
            budgetExhausted: $budgetExhausted,
            contextUsage: $contextUsage,
            fileEdits: $fileEdits === [] ? null : array_values($fileEdits),
            actorResponses: $actorResponses === [] ? null : $actorResponses,
            reviewFeedback: $reviewFeedbackParts === [] ? null : implode("\n\n", $reviewFeedbackParts),
            reviewApproved: $reviewApproved,
            deferredWork: $deferredWork,
            backgroundTasks: $backgroundTasks,
        );

        $this->finalizeTurn($turnId, $turnResult);

        return $turnResult;
    }

    /**
     * @param list<string> $members
     * @return list<string>
     */
    private function resolveInitialResponders(string $prompt, array $members): array
    {
        $mentioned = $this->extractMentionedMembers($prompt, $members);

        return $mentioned !== [] ? $mentioned : $members;
    }

    /**
     * @param list<string> $members
     */
    private function buildInitialActorPrompt(string $actorName, array $members): string
    {
        return sprintf(
            'You are @%s in a group session with members: %s. Respond to the latest user message already in the conversation history. Keep the reply distinct, concise, and collaborative. If another member should continue in this same turn, mention them explicitly with @name.',
            $actorName,
            $this->formatMembers($members),
        );
    }

    /**
     * @param list<string> $members
     */
    private function buildFollowUpActorPrompt(string $actorName, array $members, int $round, int $maxRounds): string
    {
        return sprintf(
            'You are @%s continuing the same group turn in a session with members: %s. This is follow-up round %d of %d. Review the latest group messages already in history, add only a useful direct follow-up, avoid repeating earlier points, and mention another member with @name only if they should respond next.',
            $actorName,
            $this->formatMembers($members),
            $round,
            $maxRounds,
        );
    }

    /**
     * @param list<string> $members
     * @return list<string>
     */
    private function extractMentionedMembers(string $content, array $members, ?string $excludeActor = null): array
    {
        if ($content === '') {
            return [];
        }

        preg_match_all('/@([A-Za-z0-9._-]+)/', $content, $matches);
        $knownMembers = array_fill_keys($members, true);
        $resolved = [];
        $seen = [];

        foreach ($matches[1] ?? [] as $mentioned) {
            $candidate = strtolower((string) $mentioned);
            if (!isset($knownMembers[$candidate]) || $candidate === $excludeActor || isset($seen[$candidate])) {
                continue;
            }

            $seen[$candidate] = true;
            $resolved[] = $candidate;
        }

        return $resolved;
    }

    /**
     * @param list<array{actor_name: string, actor_role: string, content: string, round: int}> $actorResponses
     */
    private function renderCombinedContent(array $actorResponses): string
    {
        return implode("\n\n", array_map(
            static fn(array $response): string => sprintf('@%s: %s', $response['actor_name'], trim($response['content'])),
            $actorResponses,
        ));
    }

    /**
     * @param list<string> $members
     */
    private function formatMembers(array $members): string
    {
        return implode(', ', array_map(static fn(string $member): string => '@' . $member, $members));
    }

    private function finalizeTurn(string $turnId, AgentTurnResult $turnResult): void
    {
        if ($turnResult->error !== null) {
            $this->storage->completeTurn(
                turnId: $turnId,
                responseText: 'Error: ' . $turnResult->error,
                promptTokens: 0,
                completionTokens: 0,
                totalTokens: 0,
                iterations: 0,
                durationMs: $turnResult->durationMs,
                toolsUsed: '[]',
                childAgentCount: 0,
            );
        } else {
            $this->storage->completeTurn(
                turnId: $turnId,
                responseText: $turnResult->content,
                promptTokens: $turnResult->promptTokens,
                completionTokens: $turnResult->completionTokens,
                totalTokens: $turnResult->totalTokens,
                iterations: $turnResult->iterations,
                durationMs: $turnResult->durationMs,
                toolsUsed: json_encode($turnResult->toolsUsed, JSON_UNESCAPED_SLASHES) ?: '[]',
                childAgentCount: $turnResult->childAgentCount,
            );
        }

        $this->storage->storeTurnResultPayload($turnId, $turnResult->toArray());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function appendLifecycleEvent(?string $turnProcessId, string $eventType, array $data): void
    {
        if ($turnProcessId === null || $turnProcessId === '') {
            return;
        }

        $this->storage->appendTurnEvent($turnProcessId, $eventType, $data);
    }
}
