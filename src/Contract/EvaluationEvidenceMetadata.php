<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Structured provenance stored alongside evaluation reports.
 */
final readonly class EvaluationEvidenceMetadata
{
    /**
     * @param list<string> $evidenceSources
     * @param list<string> $childRunIds
     */
    public function __construct(
        public string $sessionId,
        public string $sessionTitle,
        public array $evidenceSources,
        public array $childRunIds,
        public int $childRunCount,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'session_id' => $this->sessionId,
            'session_title' => $this->sessionTitle,
            'evidence_sources' => $this->evidenceSources,
            'child_run_ids' => $this->childRunIds,
            'child_run_count' => $this->childRunCount,
        ];
    }
}