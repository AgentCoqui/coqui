<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CoquiBot\Coqui\Contract\StageFinding;
use CoquiBot\Coqui\Contract\StageStatus;
use CoquiBot\Coqui\Contract\StageVerdict;

/**
 * Single-shot LLM gate evaluator for a loop's reviewer (gate) stage.
 *
 * Judges the gate stage's output against the goal and acceptance criteria and
 * emits a structured StageVerdict (requirements_met + quality_pass + severity
 * findings). Mirrors GoalEvaluator: one provider->chat() call, no tool use,
 * catches all errors, degrades to a keyword verdict on unparseable output.
 */
final readonly class StageGateEvaluator
{
    public function __construct(
        private ProviderInterface $provider,
    ) {}

    /**
     * @param list<string> $priorStageSummaries
     */
    public function judge(
        string $goal,
        ?string $acceptanceCriteria,
        string $gateStageOutput,
        array $priorStageSummaries = [],
    ): StageVerdict {
        try {
            $systemPrompt = <<<'SYSTEM'
            You are a strict quality gate for an automated multi-role loop.
            You judge whether the work meets the goal and the acceptance criteria — you judge the
            work itself, not the worker's self-report.

            Respond with a SINGLE JSON object and nothing else:
            {
              "requirements_met": true|false,
              "quality_pass": true|false,
              "findings": [{"severity": "critical|important|minor", "summary": "...", "location": "optional"}],
              "rationale": "1-3 sentences"
            }

            requirements_met = the goal and acceptance criteria are fully satisfied.
            quality_pass = the work is correct, complete, and free of quality defects that matter.
            Use "critical"/"important" for issues that must block approval; "minor" for nits.
            Be strict: partial or unverified work is requirements_met=false.
            SYSTEM;

            $userPrompt = "## Goal\n{$goal}\n\n";
            if ($acceptanceCriteria !== null && $acceptanceCriteria !== '') {
                $userPrompt .= "## Acceptance Criteria\n{$acceptanceCriteria}\n\n";
            }
            if ($priorStageSummaries !== []) {
                $userPrompt .= "## Prior Stage Outputs\n" . implode("\n\n", $priorStageSummaries) . "\n\n";
            }
            $userPrompt .= "## Reviewer (Gate) Stage Output\n{$gateStageOutput}\n\n";
            $userPrompt .= 'Return the JSON verdict now.';

            $response = $this->provider->chat([
                new SystemMessage($systemPrompt),
                new UserMessage($userPrompt),
            ]);

            return $this->parse($response->content);
        } catch (\Throwable) {
            return new StageVerdict(
                status: StageStatus::DoneWithConcerns,
                requirementsMet: false,
                qualityPass: false,
                findings: [],
                rationale: 'Gate evaluation failed due to an internal error; treating as not approved.',
            );
        }
    }

    private function parse(string $content): StageVerdict
    {
        $json = $this->extractJson($content);
        if ($json === null) {
            return StageVerdict::gateFromText($content);
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !array_key_exists('requirements_met', $data)) {
            return StageVerdict::gateFromText($content);
        }

        $findings = [];
        foreach ($data['findings'] ?? [] as $raw) {
            if (is_array($raw)) {
                $findings[] = StageFinding::fromArray($raw);
            }
        }

        $requirementsMet = (bool) $data['requirements_met'];
        $qualityPass = (bool) ($data['quality_pass'] ?? false);

        return new StageVerdict(
            status: ($requirementsMet && $qualityPass) ? StageStatus::Done : StageStatus::DoneWithConcerns,
            requirementsMet: $requirementsMet,
            qualityPass: $qualityPass,
            findings: $findings,
            rationale: (string) ($data['rationale'] ?? ''),
        );
    }

    /**
     * Extract the first JSON object from raw model output — handles a fenced
     * ```json block or a bare object embedded in prose.
     */
    private function extractJson(string $content): ?string
    {
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $m) === 1) {
            return $m[1];
        }
        $start = strpos($content, '{');
        $end = strrpos($content, '}');
        if ($start !== false && $end !== false && $end > $start) {
            return substr($content, $start, $end - $start + 1);
        }

        return null;
    }
}
