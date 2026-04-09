<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Reads pending user input from the task_inputs table.
 *
 * Used by background task processes to receive mid-run input
 * injected via the API (POST /api/v1/tasks/{id}/input).
 *
 * Each call to consumePendingInputs() atomically consumes all
 * unconsumed inputs and converts them to UserMessage objects.
 */
final class DatabasePendingInputProvider implements PendingInputProviderInterface
{
    public function __construct(
        private readonly SessionStorage $storage,
        private readonly string $taskId,
    ) {}

    /**
     * @return \CarmeloSantana\PHPAgents\Contract\MessageInterface[]
     */
    public function consumePendingInputs(): array
    {
        try {
            $inputs = $this->storage->consumeTaskInputs($this->taskId);
        } catch (\Throwable) {
            return [];
        }

        return array_map(
            fn(string $content): UserMessage => new UserMessage($content),
            $inputs,
        );
    }
}
