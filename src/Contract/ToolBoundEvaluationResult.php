<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Result of a direct tool execution evaluation.
 *
 * Returned by ToolBoundEvaluator after executing a tool and comparing
 * its numeric output against the configured threshold using the specified operator.
 */
final readonly class ToolBoundEvaluationResult
{
    public function __construct(
        public bool $met,
        public float $actualValue,
        public string $operator,
        public float $threshold,
        public ?string $error = null,
    ) {}
}
