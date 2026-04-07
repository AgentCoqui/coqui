<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Observer;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use SplObserver;
use SplSubject;

/**
 * Listens for `agent.budget_warning` events and queues a workflow-aware
 * wrap-up instruction via `PendingInputProviderInterface`.
 *
 * This bridges php-agents' mechanical budget detection with Coqui's
 * domain-specific context (todos, artifacts, sprints). The queued message
 * is consumed by `AbstractAgent::run()` at the top of the next iteration.
 */
final class BudgetExitObserver implements SplObserver, PendingInputProviderInterface
{
    /** @var MessageInterface[] */
    private array $pendingMessages = [];

    /**
     * @param ?\Closure(): string $workflowContextBuilder Returns workflow state (todos, artifacts, sprints)
     */
    public function __construct(
        private readonly ?\Closure $workflowContextBuilder = null,
    ) {}

    public function update(SplSubject $subject): void
    {
        if (!method_exists($subject, 'lastEvent') || !method_exists($subject, 'lastEventData')) {
            return;
        }

        if ($subject->lastEvent() !== 'agent.budget_warning') {
            return;
        }

        $data = $subject->lastEventData();
        if (!is_array($data)) {
            return;
        }

        $usagePercent = $data['usagePercent'] ?? 0;
        $wrapUpIterations = $data['wrapUpIterations'] ?? 2;

        $workflowContext = '';
        if ($this->workflowContextBuilder !== null) {
            try {
                $workflowContext = ($this->workflowContextBuilder)();
            } catch (\Throwable) {
                // Non-fatal — proceed without workflow context
            }
        }

        $message = $this->buildWrapUpInstruction(
            (float) $usagePercent,
            (int) $wrapUpIterations,
            $workflowContext,
        );

        $this->pendingMessages[] = new UserMessage($message);
    }

    /**
     * @return MessageInterface[]
     */
    public function consumePendingInputs(): array
    {
        $messages = $this->pendingMessages;
        $this->pendingMessages = [];

        return $messages;
    }

    private function buildWrapUpInstruction(float $usagePercent, int $wrapUpIterations, string $workflowContext): string
    {
        $parts = [
            sprintf(
                '[BUDGET WARNING] Context window is %.0f%% consumed. You have %d iteration(s) remaining before forced exit.',
                $usagePercent,
                $wrapUpIterations,
            ),
            '',
            'Summarize your progress immediately:',
            '- What you completed',
            '- What remains incomplete',
            '- Exact next steps needed to continue',
        ];

        if ($workflowContext !== '') {
            $parts[] = '';
            $parts[] = 'Current workflow state:';
            $parts[] = $workflowContext;
        }

        $parts[] = '';
        $parts[] = 'Call done(response: "...") with this summary immediately.';

        return implode("\n", $parts);
    }
}
