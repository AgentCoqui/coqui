<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A typed reviewer verdict (CAP 0.5.0 verdict.json).
 *
 * Approval is decided by the composed rule: a gate is approved only when the
 * requirements are met, quality passes, and no finding is severe enough to
 * block (Critical or Important). Minor findings are surfaced but never block.
 */
final readonly class Verdict
{
    /**
     * @param list<StageFinding> $findings
     */
    private function __construct(
        public bool $requirementsMet,
        public bool $qualityPass,
        public array $findings,
    ) {}

    /**
     * @param array<array-key, StageFinding> $findings
     */
    public static function fromFindings(bool $requirementsMet, bool $qualityPass, array $findings): self
    {
        return new self($requirementsMet, $qualityPass, array_values($findings));
    }

    /**
     * The composed approval rule (CORE-7): both flags must hold and no finding
     * may be of a blocking severity (Critical or Important).
     */
    public function isApproved(): bool
    {
        if (!$this->requirementsMet || !$this->qualityPass) {
            return false;
        }

        foreach ($this->findings as $finding) {
            if ($finding->severity->blocks()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Strict verdict.json wire shape. Severities are emitted as the closed-set
     * enum names (Critical|Important|Minor). Empty findings serialize as `[]`,
     * which the schema's `findings` array accepts.
     *
     * @return array{requirements_met: bool, quality_pass: bool, findings: list<array{severity: string, summary: string}>}
     */
    public function toWire(): array
    {
        return [
            'requirements_met' => $this->requirementsMet,
            'quality_pass' => $this->qualityPass,
            'findings' => array_map(
                static fn(StageFinding $finding): array => [
                    'severity' => $finding->severity->name,
                    'summary' => $finding->summary,
                ],
                $this->findings,
            ),
        ];
    }
}
