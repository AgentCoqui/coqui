<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Verdict from an automated code review pass.
 *
 * The reviewer agent outputs a structured verdict marker at the end of its
 * response. This enum parses that marker from free-form reviewer text.
 */
enum ReviewVerdict: string
{
    case Approved = 'approved';
    case NeedsChanges = 'needs_changes';
    case Error = 'error';

    /**
     * Parse a verdict from the reviewer agent's output text.
     *
     * Scans for `VERDICT: APPROVED` or `VERDICT: NEEDS_CHANGES` markers.
     * If no marker is found, defaults to NeedsChanges (conservative — always
     * surface feedback rather than silently approving).
     */
    public static function fromReviewerOutput(string $output): self
    {
        // Normalize: strip markdown formatting around verdict markers
        $normalized = preg_replace('/[*_`~]/', '', $output) ?? $output;

        // Match the last VERDICT marker in the output (reviewer may mention
        // verdict format earlier in explanatory text)
        if (preg_match_all('/VERDICT\s*:\s*(APPROVED|NEEDS_CHANGES)/i', $normalized, $matches)) {
            $lastMatch = end($matches[1]);

            return match (strtoupper($lastMatch)) {
                'APPROVED' => self::Approved,
                'NEEDS_CHANGES' => self::NeedsChanges,
                default => self::NeedsChanges,
            };
        }

        // No marker found — conservative default
        return self::NeedsChanges;
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }
}
