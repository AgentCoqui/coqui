<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Machine-readable verdict for a completed loop stage.
 *
 * For gate stages, requirementsMet/qualityPass/findings drive approval. For
 * non-gate producer stages, those are null and only `status` matters.
 */
final readonly class StageVerdict
{
    /**
     * @param list<StageFinding> $findings
     */
    public function __construct(
        public StageStatus $status,
        public ?bool $requirementsMet,
        public ?bool $qualityPass,
        public array $findings,
        public string $rationale,
    ) {}

    /** Gate approval: both verdicts true and no Critical/Important findings. */
    public function isApproved(): bool
    {
        return $this->requirementsMet === true
            && $this->qualityPass === true
            && !$this->hasBlockingFindings();
    }

    public function hasBlockingFindings(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity->blocks()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'requirements_met' => $this->requirementsMet,
            'quality_pass' => $this->qualityPass,
            'findings' => array_map(static fn(StageFinding $f): array => $f->toArray(), $this->findings),
            'rationale' => $this->rationale,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $findings = [];
        foreach ($data['findings'] ?? [] as $raw) {
            if (is_array($raw)) {
                $findings[] = StageFinding::fromArray($raw);
            }
        }

        return new self(
            status: StageStatus::tryFrom((string) ($data['status'] ?? 'done')) ?? StageStatus::Done,
            requirementsMet: array_key_exists('requirements_met', $data) ? self::nullableBool($data['requirements_met']) : null,
            qualityPass: array_key_exists('quality_pass', $data) ? self::nullableBool($data['quality_pass']) : null,
            findings: $findings,
            rationale: (string) ($data['rationale'] ?? ''),
        );
    }

    /**
     * Keyword fallback for gate stages when no utility model is configured.
     */
    public static function gateFromText(string $output): self
    {
        $lower = strtolower($output);
        $approvalSignals = ['approved', 'approve', 'lgtm', 'looks good', 'accepted', 'passes all criteria'];
        $rejectionSignals = ['rejected', 'needs changes', 'needs_changes', 'needs work', 'not approved', 'revisions needed'];

        $approved = false;
        foreach ($approvalSignals as $signal) {
            if (str_contains($lower, $signal)) {
                $approved = true;
                break;
            }
        }
        foreach ($rejectionSignals as $signal) {
            if (str_contains($lower, $signal)) {
                $approved = false;
                break;
            }
        }

        return new self(
            status: $approved ? StageStatus::Done : StageStatus::DoneWithConcerns,
            requirementsMet: $approved,
            qualityPass: $approved,
            findings: [],
            rationale: $approved ? 'Approved (keyword fallback).' : 'Not approved (keyword fallback).',
        );
    }

    /**
     * Non-gate producer verdict from a cheap self-signal parse.
     *
     * @param list<StageFinding> $findings
     */
    public static function producerSelfSignal(string $output, array $findings = []): self
    {
        return new self(
            status: StageStatus::fromProducerSignal($output),
            requirementsMet: null,
            qualityPass: null,
            findings: $findings,
            rationale: 'Producer self-signal.',
        );
    }

    private static function nullableBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value;
    }
}
