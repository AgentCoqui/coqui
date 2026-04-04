<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Storage\EvaluationStore;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;

/**
 * Agent-facing toolkit for the learner role.
 *
 * Provides tools to query poor evaluations and read their reports so the
 * learner agent can synthesize corrective Skills. Only available when the
 * active role is 'learner' (enforced via role frontmatter toolkits field).
 *
 * Tools:
 * - learning_list_poor_evaluations: Find recent evaluations with low scores
 * - learning_read_evaluation: Read a full evaluation report by ID
 */
final class LearningToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly EvaluationStore $evaluationStore,
        private readonly ?SkillLifecycleStore $skillLifecycleStore = null,
    ) {}

    /**
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        return [
            $this->listPoorEvaluationsTool(),
            $this->readEvaluationTool(),
        ];
    }

    public function guidelines(): string
    {
        $stats = $this->evaluationStore->getStats();
        $total = $stats['total'];

        if ($total === 0) {
            return <<<'GUIDELINES'
                <LEARNING-GUIDELINES>
                No evaluations exist yet. Call `done` — there is nothing to learn from.
                </LEARNING-GUIDELINES>
                GUIDELINES;
        }

        $poorCount = ($stats['grade_distribution']['C'] ?? 0)
            + ($stats['grade_distribution']['D'] ?? 0)
            + ($stats['grade_distribution']['F'] ?? 0);

        return <<<GUIDELINES
            <LEARNING-GUIDELINES>
            **{$total}** total evaluations. **{$poorCount}** with grade C/D/F (potential learning targets).
            Averages: completion={$stats['avg_completion']}, hallucination={$stats['avg_hallucination']}, efficiency={$stats['avg_efficiency']}

            Workflow:
            1. Call `learning_list_poor_evaluations` to find recent poor sessions
            2. Read each evaluation report to understand failure patterns
            3. Check existing skills with `skill_list` before creating new ones
            4. Create or update skills with corrective SOPs

            If no poor evaluations are found, call `done` immediately.
            </LEARNING-GUIDELINES>
            GUIDELINES;
    }

    private function listPoorEvaluationsTool(): ToolInterface
    {
        return new Tool(
            name: 'learning_list_poor_evaluations',
            description: 'Find recent evaluations with low scores (grades C, D, or F). Returns evaluation ID, grade, scores, session title, and creation date.',
            parameters: [
                new NumberParameter('limit', 'Maximum number of evaluations to return (default: 10).', required: false),
                new NumberParameter('threshold', 'Maximum overall_score to include (0.0–1.0, default: 0.5). Evaluations scoring below this are returned.', required: false),
                new NumberParameter('since_hours', 'Only include evaluations from the last N hours (default: 168 = 7 days).', required: false),
            ],
            callback: function (array $args): ToolResult {
                $limit = isset($args['limit']) ? (int) $args['limit'] : 10;
                $threshold = isset($args['threshold']) ? (float) $args['threshold'] : 0.5;
                $sinceHours = isset($args['since_hours']) ? (int) $args['since_hours'] : 168;

                $limit = max(1, min(50, $limit));
                $threshold = max(0.0, min(1.0, $threshold));
                $sinceHours = max(1, $sinceHours);

                $evaluations = $this->evaluationStore->getPoorEvaluations($limit, $threshold, $sinceHours);

                if (empty($evaluations)) {
                    return ToolResult::success('No poor evaluations found within the specified time window. Nothing to learn from.');
                }

                $lines = [];
                foreach ($evaluations as $eval) {
                    $lines[] = sprintf(
                        "- **%s** (ID: %s)\n  Grade: %s | Score: %.2f | Completion: %.2f | Hallucination: %.2f | Efficiency: %.2f\n  Session: %s | Date: %s",
                        $eval['overall_grade'],
                        $eval['id'],
                        $eval['overall_grade'],
                        (float) $eval['overall_score'],
                        (float) $eval['score_completion'],
                        (float) $eval['score_hallucination'],
                        (float) $eval['score_efficiency'],
                        $eval['session_title'] ?? '(untitled)',
                        $eval['created_at'] ?? 'unknown',
                    );
                }

                return ToolResult::success(
                    sprintf("Found %d poor evaluation(s):\n\n%s", count($evaluations), implode("\n\n", $lines)),
                );
            },
        );
    }

    private function readEvaluationTool(): ToolInterface
    {
        return new Tool(
            name: 'learning_read_evaluation',
            description: 'Read the full evaluation report for a specific evaluation. Returns the detailed report, scores, grade, and session context.',
            parameters: [
                new StringParameter('evaluation_id', 'The evaluation ID to read.', required: true),
            ],
            callback: function (array $args): ToolResult {
                $id = $args['evaluation_id'] ?? '';

                if ($id === '') {
                    return ToolResult::error('Evaluation ID is required.');
                }

                $eval = $this->evaluationStore->get($id);

                if ($eval === null) {
                    return ToolResult::error(sprintf('Evaluation "%s" not found.', $id));
                }

                $output = sprintf(
                    "## Evaluation Report\n\n"
                    . "**ID:** %s\n"
                    . "**Session:** %s\n"
                    . "**Grade:** %s\n"
                    . "**Overall Score:** %.3f\n"
                    . "**Completion:** %.3f\n"
                    . "**Hallucination Absence:** %.3f\n"
                    . "**Tool Efficiency:** %.3f\n"
                    . "**Model:** %s\n"
                    . "**Date:** %s\n\n"
                    . "---\n\n%s",
                    $eval['id'],
                    $eval['session_id'],
                    $eval['overall_grade'],
                    (float) $eval['overall_score'],
                    (float) $eval['score_completion'],
                    (float) $eval['score_hallucination'],
                    (float) $eval['score_efficiency'],
                    $eval['model'] ?? 'unknown',
                    $eval['created_at'] ?? 'unknown',
                    $eval['report'],
                );

                $metadata = $this->decodeJsonObject($eval['metadata'] ?? null);
                if ($metadata !== null) {
                    $output .= "\n\n---\n\n## Structured Evidence Metadata\n\n";
                    $output .= json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
                }

                $evidenceLinks = $this->skillLifecycleStore?->listEvaluationEvidenceLinks($id) ?? [];
                if ($evidenceLinks !== []) {
                    $lines = array_map(
                        static fn(array $link): string => sprintf(
                            '- [%s] %s%s',
                            $link['evidence_type'],
                            $link['label'],
                            isset($link['evidence_id']) && is_string($link['evidence_id']) && $link['evidence_id'] !== ''
                                ? sprintf(' (%s)', $link['evidence_id'])
                                : '',
                        ),
                        $evidenceLinks,
                    );
                    $output .= "\n\n---\n\n## Evidence Links\n\n" . implode("\n", $lines);
                }

                $skillProvenance = $this->skillLifecycleStore?->listSkillProvenance(evaluationId: $id) ?? [];
                if ($skillProvenance !== []) {
                    $lines = array_map(
                        static fn(array $event): string => sprintf(
                            '- %s: %s via learner task %s',
                            $event['action'],
                            $event['skill_name'],
                            $event['learner_task_id'],
                        ),
                        $skillProvenance,
                    );
                    $output .= "\n\n---\n\n## Skill Provenance\n\n" . implode("\n", $lines);
                }

                return ToolResult::success($output);
            },
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(mixed $value): ?array
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
