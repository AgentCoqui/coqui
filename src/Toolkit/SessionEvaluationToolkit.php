<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CoquiBot\Coqui\Contract\RoleScopedToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Agent-facing toolkit for the evaluator role.
 *
 * Provides tools to introspect past sessions (transcripts, child runs)
 * and record structured evaluation reports. Only registered when the
 * active role is 'evaluator'.
 *
 * Tools:
 * - evaluation_list_sessions: Find unevaluated sessions ready for grading
 * - evaluation_read_transcript: Read a session's conversation history
 * - evaluation_read_child_runs: Read child agent executions for a session
 * - evaluation_save_report: Save a structured evaluation report with scores
 */
final class SessionEvaluationToolkit implements RoleScopedToolkitInterface
{
    private const int MAX_TOOL_RESULT_LENGTH = 500;

    public function roleScope(): string
    {
        return 'evaluator';
    }

    public function __construct(
        private readonly EvaluationStore $evaluationStore,
        private readonly SessionStorage $storage,
        private readonly int $defaultLookbackHours = 24,
        private readonly int $defaultInactivityHours = 3,
    ) {}

    public function tools(): array
    {
        return [
            $this->listSessionsTool(),
            $this->readTranscriptTool(),
            $this->readChildRunsTool(),
            $this->saveReportTool(),
        ];
    }

    public function guidelines(): string
    {
        $stats = $this->evaluationStore->getStats();
        $total = $stats['total'];

        if ($total === 0) {
            return <<<'GUIDELINES'
            <EVALUATION-GUIDELINES>
            You are an **automated session evaluator**. No sessions have been evaluated yet.

            Workflow:
            1. Call `evaluation_list_sessions` to find sessions ready for evaluation
            2. For each session, read transcript and child runs
            3. Grade on: completion (0-1), hallucination absence (0-1), efficiency (0-1)
            4. Save report via `evaluation_save_report`

            If no sessions need evaluation, call `done` immediately.
            </EVALUATION-GUIDELINES>
            GUIDELINES;
        }

        $dist = $stats['grade_distribution'];
        $distLine = sprintf('A:%d B:%d C:%d D:%d F:%d', $dist['A'], $dist['B'], $dist['C'], $dist['D'], $dist['F']);

        return <<<GUIDELINES
        <EVALUATION-GUIDELINES>
        You are an **automated session evaluator**. **{$total}** sessions evaluated so far.
        Averages: completion={$stats['avg_completion']}, hallucination={$stats['avg_hallucination']}, efficiency={$stats['avg_efficiency']}
        Grades: {$distLine}

        Workflow:
        1. Call `evaluation_list_sessions` to find sessions ready for evaluation
        2. For each session, read transcript and child runs
        3. Grade on: completion (0-1), hallucination absence (0-1), efficiency (0-1)
        4. Save report via `evaluation_save_report`

        If no sessions need evaluation, call `done` immediately.
        </EVALUATION-GUIDELINES>
        GUIDELINES;
    }

    private function listSessionsTool(): ToolInterface
    {
        return new Tool(
            name: 'evaluation_list_sessions',
            description: 'Find completed sessions that have not been evaluated yet. Returns sessions that are inactive (no recent activity) and have enough turns to be worth evaluating.',
            parameters: [
                new NumberParameter(
                    'lookback_hours',
                    'How far back to search for sessions (default: ' . $this->defaultLookbackHours . ' hours)',
                    required: false,
                ),
                new NumberParameter(
                    'min_turns',
                    'Minimum number of turns for a session to be worth evaluating (default: 2)',
                    required: false,
                ),
            ],
            callback: function (array $args): ToolResult {
                $lookbackHours = isset($args['lookback_hours']) ? max(1, (int) $args['lookback_hours']) : $this->defaultLookbackHours;
                $minTurns = isset($args['min_turns']) ? max(1, (int) $args['min_turns']) : 2;

                $sessions = $this->evaluationStore->getUnevaluatedSessions(
                    lookbackHours: $lookbackHours,
                    inactivityHours: $this->defaultInactivityHours,
                    minTurns: $minTurns,
                    limit: 20,
                );

                if ($sessions === []) {
                    return ToolResult::success('No unevaluated sessions found within the lookback window.');
                }

                $result = array_map(fn(array $s) => [
                    'id' => $s['id'],
                    'title' => $s['title'] ?? '(untitled)',
                    'model_role' => $s['model_role'],
                    'model' => $s['model'],
                    'turn_count' => (int) $s['turn_count'],
                    'token_count' => (int) $s['token_count'],
                    'last_activity' => $s['updated_at'],
                    'created_at' => $s['created_at'],
                ], $sessions);

                return ToolResult::success(json_encode([
                    'count' => count($result),
                    'sessions' => $result,
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    private function readTranscriptTool(): ToolInterface
    {
        return new Tool(
            name: 'evaluation_read_transcript',
            description: 'Read a session\'s full conversation transcript including user prompts, assistant responses, and tool call summaries. Use this to evaluate the quality of the agent\'s responses.',
            parameters: [
                new StringParameter('session_id', 'Session ID to read', required: true),
                new BoolParameter('include_tool_calls', 'Include tool call details in the transcript (default: true)', required: false),
                new NumberParameter('max_messages', 'Maximum messages to return (default: 200)', required: false),
            ],
            callback: function (array $args): ToolResult {
                $sessionId = trim($args['session_id'] ?? '');
                if ($sessionId === '') {
                    return ToolResult::error('session_id is required.');
                }

                $session = $this->storage->getSession($sessionId);
                if ($session === null) {
                    return ToolResult::error("Session not found: {$sessionId}");
                }

                $includeToolCalls = ($args['include_tool_calls'] ?? true) !== false;
                $maxMessages = isset($args['max_messages']) ? max(1, min(500, (int) $args['max_messages'])) : 200;

                $messages = $this->storage->getMessages($sessionId);
                $turns = $this->storage->getTurns($sessionId);

                // Build turn metadata index
                $turnMeta = [];
                foreach ($turns as $turn) {
                    $turnMeta[$turn['id']] = [
                        'turn_number' => $turn['turn_number'],
                        'total_tokens' => $turn['total_tokens'],
                        'iterations' => $turn['iterations'],
                        'duration_ms' => $turn['duration_ms'],
                        'tools_used' => $turn['tools_used'],
                        'child_agent_count' => $turn['child_agent_count'],
                    ];
                }

                $transcript = [];
                $count = 0;
                foreach ($messages as $msg) {
                    if ($count >= $maxMessages) {
                        break;
                    }

                    $entry = [
                        'role' => $msg['role'],
                        'content' => $msg['content'],
                    ];

                    // Truncate long content for context efficiency
                    if ($entry['content'] !== null && mb_strlen($entry['content']) > 3000) {
                        $entry['content'] = mb_substr($entry['content'], 0, 3000) . "\n... [truncated, " . mb_strlen($msg['content']) . ' chars total]';
                    }

                    if ($includeToolCalls && !empty($msg['tool_calls'])) {
                        $toolCalls = json_decode($msg['tool_calls'], true);
                        if (is_array($toolCalls)) {
                            $entry['tool_calls'] = array_map(function (array $tc) {
                                $summary = [
                                    'name' => $tc['function']['name'] ?? $tc['name'] ?? 'unknown',
                                ];
                                $argStr = $tc['function']['arguments'] ?? '';
                                if (is_string($argStr) && mb_strlen($argStr) > self::MAX_TOOL_RESULT_LENGTH) {
                                    $argStr = mb_substr($argStr, 0, self::MAX_TOOL_RESULT_LENGTH) . '...';
                                }
                                $summary['arguments'] = $argStr;
                                return $summary;
                            }, $toolCalls);
                        }
                    }

                    // Tool results are often very long — truncate aggressively
                    if ($msg['role'] === 'tool' && $entry['content'] !== null && mb_strlen($entry['content']) > self::MAX_TOOL_RESULT_LENGTH) {
                        $entry['content'] = mb_substr($entry['content'], 0, self::MAX_TOOL_RESULT_LENGTH) . '... [truncated]';
                    }

                    // Attach turn metadata to user messages
                    if ($msg['role'] === 'user' && isset($msg['turn_id'], $turnMeta[$msg['turn_id']])) {
                        $entry['turn_meta'] = $turnMeta[$msg['turn_id']];
                    }

                    $transcript[] = $entry;
                    $count++;
                }

                return ToolResult::success(json_encode([
                    'session_id' => $sessionId,
                    'title' => $session['title'] ?? '(untitled)',
                    'model' => $session['model'],
                    'message_count' => count($transcript),
                    'total_messages' => count($messages),
                    'turn_count' => count($turns),
                    'transcript' => $transcript,
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    private function readChildRunsTool(): ToolInterface
    {
        return new Tool(
            name: 'evaluation_read_child_runs',
            description: 'Read child agent executions (coder, reviewer, explorer, etc.) for a session. Useful for evaluating how the bot delegated and handled complex tasks.',
            parameters: [
                new StringParameter('session_id', 'Session ID to read child runs for', required: true),
            ],
            callback: function (array $args): ToolResult {
                $sessionId = trim($args['session_id'] ?? '');
                if ($sessionId === '') {
                    return ToolResult::error('session_id is required.');
                }

                $childRuns = $this->storage->getChildRuns($sessionId);

                if ($childRuns === []) {
                    return ToolResult::success(json_encode([
                        'session_id' => $sessionId,
                        'count' => 0,
                        'child_runs' => [],
                        'note' => 'No child agent runs found for this session.',
                    ], JSON_UNESCAPED_SLASHES) ?: '{}');
                }

                $result = array_map(function (array $run) {
                    $prompt = $run['prompt'] ?? '';
                    $resultText = $run['result'] ?? '';

                    // Truncate for context efficiency
                    if (mb_strlen($prompt) > 1000) {
                        $prompt = mb_substr($prompt, 0, 1000) . '... [truncated]';
                    }
                    if (mb_strlen($resultText) > 2000) {
                        $resultText = mb_substr($resultText, 0, 2000) . '... [truncated]';
                    }

                    return [
                        'role' => $run['agent_role'],
                        'model' => $run['model'],
                        'parent_iteration' => $run['parent_iteration'],
                        'prompt' => $prompt,
                        'result' => $resultText,
                        'token_count' => (int) $run['token_count'],
                        'created_at' => $run['created_at'],
                    ];
                }, $childRuns);

                return ToolResult::success(json_encode([
                    'session_id' => $sessionId,
                    'count' => count($result),
                    'child_runs' => $result,
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    private function saveReportTool(): ToolInterface
    {
        return new Tool(
            name: 'evaluation_save_report',
            description: 'Save a structured evaluation report for a session. Scores must be between 0.0 and 1.0. The overall score is computed as a weighted average: completion (40%), hallucination absence (40%), efficiency (20%).',
            parameters: [
                new StringParameter('session_id', 'Session ID being evaluated', required: true),
                new EnumParameter('overall_grade', 'Letter grade for the session', ['A', 'B', 'C', 'D', 'F'], required: true),
                new NumberParameter('score_completion', 'Task completion score (0.0 = failed, 1.0 = fully completed)', required: true),
                new NumberParameter('score_hallucination', 'Hallucination absence score (0.0 = entirely fabricated, 1.0 = no hallucinations)', required: true),
                new NumberParameter('score_efficiency', 'Tool usage efficiency score (0.0 = wasted all iterations, 1.0 = optimal)', required: true),
                new StringParameter('report', 'Full markdown evaluation report with evidence and findings', required: true),
            ],
            callback: function (array $args): ToolResult {
                $sessionId = trim($args['session_id'] ?? '');
                $grade = trim($args['overall_grade'] ?? '');
                $report = trim($args['report'] ?? '');

                if ($sessionId === '' || $grade === '' || $report === '') {
                    return ToolResult::error('session_id, overall_grade, and report are required.');
                }

                $validGrades = ['A', 'B', 'C', 'D', 'F'];
                if (!in_array($grade, $validGrades, true)) {
                    return ToolResult::error('overall_grade must be one of: ' . implode(', ', $validGrades));
                }

                $scoreCompletion = $this->clampScore((float) ($args['score_completion'] ?? 0));
                $scoreHallucination = $this->clampScore((float) ($args['score_hallucination'] ?? 0));
                $scoreEfficiency = $this->clampScore((float) ($args['score_efficiency'] ?? 0));

                // Weighted composite: completion 40%, hallucination 40%, efficiency 20%
                $overallScore = round(
                    ($scoreCompletion * 0.4) + ($scoreHallucination * 0.4) + ($scoreEfficiency * 0.2),
                    3,
                );

                // Verify session exists
                $session = $this->storage->getSession($sessionId);
                if ($session === null) {
                    return ToolResult::error("Session not found: {$sessionId}");
                }

                $id = $this->evaluationStore->create(
                    sessionId: $sessionId,
                    overallGrade: $grade,
                    scoreCompletion: $scoreCompletion,
                    scoreHallucination: $scoreHallucination,
                    scoreEfficiency: $scoreEfficiency,
                    overallScore: $overallScore,
                    report: $report,
                );

                return ToolResult::success(json_encode([
                    'id' => $id,
                    'session_id' => $sessionId,
                    'session_title' => $session['title'] ?? '(untitled)',
                    'overall_grade' => $grade,
                    'overall_score' => $overallScore,
                    'scores' => [
                        'completion' => $scoreCompletion,
                        'hallucination' => $scoreHallucination,
                        'efficiency' => $scoreEfficiency,
                    ],
                    'message' => "Evaluation saved successfully. Grade: {$grade} (score: {$overallScore})",
                ], JSON_UNESCAPED_SLASHES) ?: '{}');
            },
        );
    }

    /**
     * Clamp a score to the valid 0.0–1.0 range.
     */
    private function clampScore(float $score): float
    {
        return round(max(0.0, min(1.0, $score)), 3);
    }
}
