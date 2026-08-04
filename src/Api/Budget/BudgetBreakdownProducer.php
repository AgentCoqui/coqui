<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Budget;

use CoquiBot\Coqui\Contract\PromptBudgetSnapshot;

/**
 * Projects a {@see PromptBudgetSnapshot} onto the CAP budget-observability wire
 * shape (schema/budget-breakdown.json): which system-prompt sections entered the
 * composed prompt, their token cost, and what was shed.
 *
 * The producer is strict — the returned array conforms to budget-breakdown.json
 * exactly (additionalProperties:false throughout).
 */
final readonly class BudgetBreakdownProducer
{
    /**
     * Conservative positive fallback when the snapshot carries no usable context
     * window (the schema requires model_context_window >= 1).
     */
    private const int DEFAULT_CONTEXT_WINDOW = 8192;

    /**
     * @return array{
     *     sections: list<array{name: string, included: bool, estimated_tokens: int, priority: int, shed_reason: string|null}>,
     *     total_estimated_tokens: int,
     *     model_context_window: int
     * }
     */
    public function toWire(PromptBudgetSnapshot $snapshot): array
    {
        $sections = [];
        $totalIncludedTokens = 0;

        foreach ($snapshot->promptSections as $section) {
            $included = $section['included'];
            $tokens = max(0, $section['tokens']);

            $title = trim($section['title']);
            $id = trim($section['id']);
            // name is required with minLength 1: prefer the human title, fall back
            // to the stable id, then a generic placeholder so it is never empty.
            $name = $title !== '' ? $title : ($id !== '' ? $id : 'section');

            if ($included) {
                $totalIncludedTokens += $tokens;
                $shedReason = null;
            } else {
                $decision = trim($section['decision']);
                $shedReason = $decision !== '' ? $decision : 'over_budget';
            }

            $sections[] = [
                'name' => $name,
                'included' => $included,
                'estimated_tokens' => $tokens,
                // The snapshot carries priority as a closed-set string; the wire
                // shape is a non-negative integer shed-rank.
                'priority' => max(0, (int) $section['priority']),
                'shed_reason' => $shedReason,
            ];
        }

        // model_context_window MUST be a positive integer so cost is legible
        // against capacity; fall back when the snapshot carries no usable window.
        $context = $snapshot->contextWindow;
        $window = $context !== null && $context->maxTokens >= 1
            ? $context->maxTokens
            : self::DEFAULT_CONTEXT_WINDOW;

        return [
            'sections' => $sections,
            'total_estimated_tokens' => $totalIncludedTokens,
            'model_context_window' => $window,
        ];
    }
}
