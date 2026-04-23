<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Contract\SessionType;

final readonly class SessionTypeRegistry
{
    /**
     * @var array<string, SessionTypeHandlerInterface>
     */
    private array $handlers;

    public function __construct(SessionTypeHandlerInterface ...$handlers)
    {
        $resolvedHandlers = [];
        foreach ($handlers as $handler) {
            $resolvedHandlers[$handler->type()->value] = $handler;
        }

        $this->handlers = $resolvedHandlers;
    }

    public function handlerFor(SessionType $type): SessionTypeHandlerInterface
    {
        $handler = $this->handlers[$type->value] ?? null;
        if ($handler === null) {
            throw new \LogicException(sprintf('No session type handler registered for "%s".', $type->value));
        }

        return $handler;
    }
}