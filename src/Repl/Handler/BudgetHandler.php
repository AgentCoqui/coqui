<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl\Handler;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Renderer\ContextUsageBar;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles /budget prompt-budget inspection in the REPL.
 */
final readonly class BudgetHandler
{
    public function __construct(
        private AgentRunner $agentRunner,
    ) {}

    public function handle(SymfonyStyle $io, ?string $role = null, ?string $profile = null, ?string $sessionId = null): void
    {
        $snapshot = $this->agentRunner->buildBudgetPreview($role, $profile, $sessionId);
        $data = $snapshot->toArray();
        $toolkitBudget = $data['toolkit_budget'];

        $io->section('Prompt Budget');
        $io->definitionList(
            ['Role' => $data['role']],
            ['Model' => $data['model']],
            ['Tool count' => (string) $data['tool_count']],
            ['Toolkit count' => (string) $data['toolkit_count']],
            ['Prompt tokens' => number_format((int) $data['prompt_tokens'])],
            ['Tool schema tokens' => number_format((int) $data['tool_tokens'])],
            ['Estimated total' => number_format((int) $data['total_tokens'])],
        );

        if ($snapshot->contextWindow !== null) {
            ContextUsageBar::render($io, $snapshot->contextWindow, showLegend: true);
            $io->text(sprintf(
                '<fg=gray>Effective budget:</> %s | <fg=gray>Reserved:</> %s | <fg=gray>Headroom:</> %s',
                number_format($snapshot->contextWindow->effectiveBudget()),
                number_format($snapshot->contextWindow->reservedTokens),
                number_format($snapshot->contextWindow->availableTokens()),
            ));
        }

        $io->newLine();
        $io->definitionList(
            ['Toolkit budget' => sprintf('%s (%s)', number_format((int) $toolkitBudget['budget_tokens']), $toolkitBudget['budget_source'])],
            ['Promotion budget' => sprintf(
                '%s (%d%%, %s)',
                number_format((int) $toolkitBudget['promotion_budget_tokens']),
                (int) $toolkitBudget['promotion_budget_percent'],
                $toolkitBudget['promotion_budget_source'],
            )],
            ['Auto candidates' => sprintf(
                '%d toolkit(s) / %s tokens',
                (int) $toolkitBudget['auto_candidate_count'],
                number_format((int) $toolkitBudget['auto_candidate_tokens']),
            )],
            ['Promoted usage' => number_format((int) $toolkitBudget['used_promotion_budget_tokens'])],
            ['Within budget' => $toolkitBudget['within_budget'] ? 'yes' : 'no'],
            ['Deferred toolkits' => (string) $toolkitBudget['deferred_count']],
        );

        $rows = [];
        foreach (array_slice($data['loading_decisions'], 0, 12) as $decision) {
            $rows[] = [
                $decision['name'],
                $decision['mode'],
                $decision['reason'],
                number_format((int) $decision['tokens']),
                $decision['frequency'] !== null ? (string) $decision['frequency'] : '-',
            ];
        }

        if ($rows !== []) {
            $io->newLine();
            $io->table(['Toolkit', 'Mode', 'Reason', 'Tokens', 'Frequency'], $rows);
        }

        $sectionRows = [];
        foreach (array_slice($data['prompt_sections'], 0, 12) as $section) {
            $sectionRows[] = [
                $section['title'],
                $section['priority'],
                $section['pinned'] ? 'yes' : 'no',
                number_format((int) $section['tokens']),
                $section['rationale'],
            ];
        }

        if ($sectionRows !== []) {
            $io->newLine();
            $io->table(['Prompt Section', 'Priority', 'Pinned', 'Tokens', 'Why Present'], $sectionRows);
        }

        if ($data['deferred_toolkits'] === []) {
            return;
        }

        $deferredRows = [];
        foreach (array_slice($data['deferred_toolkits'], 0, 8) as $toolkit) {
            $deferredRows[] = [
                $toolkit['name'],
                $toolkit['package'],
                $toolkit['description'] !== '' ? $toolkit['description'] : '-',
            ];
        }

        $io->newLine();
        $io->table(['Deferred Toolkit', 'Package', 'Description'], $deferredRows);
    }
}