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
     * @param callable(string, string, int, ?array<int, string>, string): AgentTurnResult $executeActor
     * @param null|callable(string, array<string, mixed>): void $notifyLifecycleEvent
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
        ?callable $notifyLifecycleEvent = null,
    ): AgentTurnResult {
        $turnId = $this->storage->createTurn($sessionId, $prompt, $modelString, $turnProcessId);
        $this->storage->addMessage($sessionId, 'user', $prompt, turnId: $turnId);

        $maxRounds = max(1, $maxRounds);
        $round = 1;
        $selection = $this->resolveResponderSelection($prompt, $members, initialRound: true);
        $queue = $selection['responders'];
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
            $this->emitLifecycleEvent($turnProcessId, $notifyLifecycleEvent, 'group_round_start', [
                'round' => $round,
                'responders' => $queue,
                'max_rounds' => $maxRounds,
                'selection_source' => $selection['source'],
                'selection_rationale' => $selection['rationale'],
            ]);

            $nextRound = [];
            $handoffSelections = [];

            foreach ($queue as $actorName) {
                $actorPrompt = $round === 1
                    ? $this->buildInitialActorPrompt($actorName, $members)
                    : $this->buildFollowUpActorPrompt($actorName, $members, $round, $maxRounds);

                $this->emitLifecycleEvent($turnProcessId, $notifyLifecycleEvent, 'group_actor_start', [
                    'round' => $round,
                    'actor_name' => $actorName,
                    'actor_role' => $modelRole,
                ]);

                $segmentResult = $executeActor($actorPrompt, $actorName, $round, $filePaths, $turnId);

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
                        /** @var array{file_path: string, operation: string} $edit */
                        $filePath = $edit['file_path'];
                        $operation = $edit['operation'];

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

                $handoffSelection = $this->resolveResponderSelection($segmentResult->content, $members, $actorName, false);
                $handoffSelections[] = [
                    'actor_name' => $actorName,
                    'source' => $handoffSelection['source'],
                    'responders' => $handoffSelection['responders'],
                    'rationale' => $handoffSelection['rationale'],
                ];

                foreach ($handoffSelection['responders'] as $mentionedActor) {
                    if (!in_array($mentionedActor, $nextRound, true)) {
                        $nextRound[] = $mentionedActor;
                    }
                }

                $this->emitLifecycleEvent($turnProcessId, $notifyLifecycleEvent, 'group_actor_end', [
                    'round' => $round,
                    'actor_name' => $actorName,
                    'actor_role' => $modelRole,
                    'mentioned_next' => $handoffSelection['responders'],
                    'selection_source' => $handoffSelection['source'],
                    'selection_rationale' => $handoffSelection['rationale'],
                ]);
            }

            $nextSelection = $this->combineHandoffSelections($handoffSelections, $nextRound);

            $this->emitLifecycleEvent($turnProcessId, $notifyLifecycleEvent, 'group_round_end', [
                'round' => $round,
                'next_responders' => $nextRound,
                'max_rounds' => $maxRounds,
                'selection_source' => $nextSelection['source'],
                'selection_rationale' => $nextSelection['rationale'],
            ]);

            $queue = $nextRound;
            $selection = $nextSelection;
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
     */
    private function buildInitialActorPrompt(string $actorName, array $members): string
    {
        return sprintf(
            'You are @%s in a group session with members: %s. Respond to the latest user message already in the conversation history. Keep the reply distinct, concise, and collaborative. If another member should continue in this same turn, mention them explicitly with @name. If the whole team should continue, use @everyone or @group.',
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
            'You are @%s continuing the same group turn in a session with members: %s. This is follow-up round %d of %d. Review the latest group messages already in history, add only a useful direct follow-up, avoid repeating earlier points, and mention another member with @name only if they should respond next. Use @everyone or @group only when the whole team should respond.',
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
    private function extractExplicitMemberMentions(string $content, array $members, ?string $excludeActor = null): array
    {
        if ($content === '') {
            return [];
        }

        preg_match_all('/@([A-Za-z0-9._-]+)/', $content, $matches);
        $knownMembers = array_fill_keys($members, true);
        $resolved = [];
        $seen = [];

        foreach ($matches[1] as $mentioned) {
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
     * @param list<string> $members
     * @return array{responders: list<string>, source: string, rationale: string}
     */
    private function resolveResponderSelection(
        string $content,
        array $members,
        ?string $excludeActor = null,
        bool $initialRound = false,
    ): array {
        if ($this->containsBroadcastMention($content)) {
            $responders = $this->broadcastResponders($members, $excludeActor);

            return [
                'responders' => $responders,
                'source' => 'broadcast',
                'rationale' => $excludeActor === null
                    ? 'Broadcast mention @everyone/@group requested all members in stored order.'
                    : sprintf('Broadcast handoff from @%s requested all eligible members in stored order.', $excludeActor),
            ];
        }

        $mentioned = $this->extractExplicitMemberMentions($content, $members, $excludeActor);
        if ($mentioned !== []) {
            return [
                'responders' => $mentioned,
                'source' => $initialRound ? 'direct_mentions' : 'handoff_mentions',
                'rationale' => $excludeActor === null
                    ? sprintf('Explicit member mentions selected the responders in mention order: %s.', $this->formatMembers($mentioned))
                    : sprintf('Direct handoff from @%s selected the next responders in mention order: %s.', $excludeActor, $this->formatMembers($mentioned)),
            ];
        }

        if ($initialRound) {
            return [
                'responders' => $members,
                'source' => 'default_all',
                'rationale' => 'No explicit member mentions were provided, so all group members respond in stored order.',
            ];
        }

        return [
            'responders' => [],
            'source' => 'none',
            'rationale' => 'No member mentions were emitted, so the turn stops after this round.',
        ];
    }

    private function containsBroadcastMention(string $content): bool
    {
        return (bool) preg_match('/@(?:everyone|group)\b/i', $content);
    }

    /**
     * @param list<string> $members
     * @return list<string>
     */
    private function broadcastResponders(array $members, ?string $excludeActor = null): array
    {
        if ($excludeActor === null || $excludeActor === '') {
            return $members;
        }

        return array_values(array_filter(
            $members,
            static fn(string $member): bool => $member !== $excludeActor,
        ));
    }

    /**
     * @param array<int, array{actor_name: string, source: string, responders: list<string>, rationale: string}> $handoffSelections
     * @param list<string> $nextRound
     * @return array{responders: list<string>, source: string, rationale: string}
     */
    private function combineHandoffSelections(array $handoffSelections, array $nextRound): array
    {
        if ($nextRound === []) {
            return [
                'responders' => [],
                'source' => 'none',
                'rationale' => 'No member mentions were emitted, so the turn stops after this round.',
            ];
        }

        foreach ($handoffSelections as $selection) {
            if ($selection['source'] === 'broadcast') {
                return [
                    'responders' => $nextRound,
                    'source' => 'broadcast',
                    'rationale' => sprintf(
                        'Broadcast handoff selected all eligible members in stored order: %s.',
                        $this->formatMembers($nextRound),
                    ),
                ];
            }
        }

        return [
            'responders' => $nextRound,
            'source' => 'handoff_mentions',
            'rationale' => sprintf(
                'Member handoff mentions selected the next responders in order: %s.',
                $this->formatMembers($nextRound),
            ),
        ];
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
    private function emitLifecycleEvent(
        ?string $turnProcessId,
        ?callable $notifyLifecycleEvent,
        string $eventType,
        array $data,
    ): void
    {
        if ($notifyLifecycleEvent !== null) {
            $notifyLifecycleEvent($eventType, $data);
        }

        if ($turnProcessId === null || $turnProcessId === '') {
            return;
        }

        $this->storage->appendTurnEvent($turnProcessId, $eventType, $data);
    }
}
