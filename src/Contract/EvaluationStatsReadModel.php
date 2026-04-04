<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

final readonly class EvaluationStatsReadModel
{
    /**
     * @param array<string, int> $gradeDistribution
     */
    public function __construct(
        public int $total,
        public float $avgCompletion,
        public float $avgHallucination,
        public float $avgEfficiency,
        public float $avgOverall,
        public array $gradeDistribution,
    ) {}

    /**
     * @param array{total: int, avg_completion: float, avg_hallucination: float, avg_efficiency: float, avg_overall: float, grade_distribution: array<string, int>} $stats
     */
    public static function fromArray(array $stats): self
    {
        return new self(
            total: $stats['total'],
            avgCompletion: $stats['avg_completion'],
            avgHallucination: $stats['avg_hallucination'],
            avgEfficiency: $stats['avg_efficiency'],
            avgOverall: $stats['avg_overall'],
            gradeDistribution: $stats['grade_distribution'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total,
            'avg_completion' => $this->avgCompletion,
            'avg_hallucination' => $this->avgHallucination,
            'avg_efficiency' => $this->avgEfficiency,
            'avg_overall' => $this->avgOverall,
            'grade_distribution' => $this->gradeDistribution,
        ];
    }
}