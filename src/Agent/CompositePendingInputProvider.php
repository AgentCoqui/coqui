<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;

/**
 * Composes multiple PendingInputProviderInterface instances into one.
 *
 * Used to chain BudgetExitObserver with existing providers (e.g.
 * DatabasePendingInputProvider for background task input injection).
 */
final class CompositePendingInputProvider implements PendingInputProviderInterface
{
    /** @var PendingInputProviderInterface[] */
    private readonly array $providers;

    public function __construct(PendingInputProviderInterface ...$providers)
    {
        $this->providers = $providers;
    }

    /**
     * @return MessageInterface[]
     */
    public function consumePendingInputs(): array
    {
        $messages = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->consumePendingInputs() as $message) {
                $messages[] = $message;
            }
        }

        return $messages;
    }
}
