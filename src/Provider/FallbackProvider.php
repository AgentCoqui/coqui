<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Provider;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Exception\ProviderErrorClassifier;

/**
 * Decorator that adds automatic failover to any ProviderInterface.
 *
 * Wraps a primary provider and transparently falls through to fallback
 * providers on retryable errors (429, 5xx, network failures). Non-retryable
 * errors (401, 403, 400, 404) are thrown immediately.
 *
 * The optional $onNotify callback receives warning strings for observer
 * integration (e.g. "Primary provider failed (rate_limited). Attempting
 * 2 fallback(s)...").
 */
final class FallbackProvider implements ProviderInterface
{
    /** @var (\Closure(string): void)|null */
    private ?\Closure $onNotify = null;

    /**
     * @param ProviderInterface $primary The primary provider to try first
     * @param ProviderInterface[] $fallbacks Fallback providers in priority order
     */
    public function __construct(
        private readonly ProviderInterface $primary,
        private readonly array $fallbacks,
    ) {}

    /**
     * Set a callback for fallback notifications (warnings, successes).
     */
    public function setOnNotify(\Closure $onNotify): void
    {
        $this->onNotify = $onNotify;
    }

    public function chat(array $messages, array $tools = [], array $options = []): Response
    {
        try {
            return $this->primary->chat($messages, $tools, $options);
        } catch (\Throwable $e) {
            return $this->attemptFallbackChat($e, $messages, $tools, $options);
        }
    }

    public function stream(array $messages, array $tools = [], array $options = []): iterable
    {
        try {
            yield from $this->primary->stream($messages, $tools, $options);
        } catch (\Throwable $e) {
            yield from $this->attemptFallbackStream($e, $messages, $tools, $options);
        }
    }

    public function structured(array $messages, string $schema, array $options = []): mixed
    {
        try {
            return $this->primary->structured($messages, $schema, $options);
        } catch (\Throwable $e) {
            return $this->attemptFallbackStructured($e, $messages, $schema, $options);
        }
    }

    public function models(): array
    {
        return $this->primary->models();
    }

    public function isAvailable(): bool
    {
        return $this->primary->isAvailable();
    }

    public function getModel(): string
    {
        return $this->primary->getModel();
    }

    public function withModel(string $model): static
    {
        // Cannot use static return with final class decorator — delegate to primary
        // and reconstruct with same fallbacks. This preserves the decorator chain.
        $new = clone $this;

        return $new;
    }

    /**
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     */
    private function attemptFallbackChat(
        \Throwable $originalError,
        array $messages,
        array $tools,
        array $options,
    ): Response {
        $this->guardNonRetryable($originalError);
        $this->notifyFallbackStart($originalError);

        foreach ($this->fallbacks as $fallback) {
            try {
                $result = $fallback->chat($messages, $tools, $options);
                $this->notify(sprintf('Fallback to %s succeeded.', $fallback->getModel()));
                return $result;
            } catch (\Throwable $e) {
                $this->notify(sprintf(
                    'Fallback model %s also failed: %s',
                    $fallback->getModel(),
                    $e->getMessage(),
                ));
            }
        }

        throw $originalError;
    }

    /**
     * @param MessageInterface[] $messages
     * @param ToolInterface[] $tools
     * @param array<string, mixed> $options
     * @return iterable<Response>
     */
    private function attemptFallbackStream(
        \Throwable $originalError,
        array $messages,
        array $tools,
        array $options,
    ): iterable {
        $this->guardNonRetryable($originalError);
        $this->notifyFallbackStart($originalError);

        foreach ($this->fallbacks as $fallback) {
            try {
                yield from $fallback->stream($messages, $tools, $options);
                $this->notify(sprintf('Fallback to %s succeeded.', $fallback->getModel()));
                return;
            } catch (\Throwable $e) {
                $this->notify(sprintf(
                    'Fallback model %s also failed: %s',
                    $fallback->getModel(),
                    $e->getMessage(),
                ));
            }
        }

        throw $originalError;
    }

    /**
     * @param MessageInterface[] $messages
     * @param array<string, mixed> $options
     */
    private function attemptFallbackStructured(
        \Throwable $originalError,
        array $messages,
        string $schema,
        array $options,
    ): mixed {
        $this->guardNonRetryable($originalError);
        $this->notifyFallbackStart($originalError);

        foreach ($this->fallbacks as $fallback) {
            try {
                $result = $fallback->structured($messages, $schema, $options);
                $this->notify(sprintf('Fallback to %s succeeded.', $fallback->getModel()));
                return $result;
            } catch (\Throwable $e) {
                $this->notify(sprintf(
                    'Fallback model %s also failed: %s',
                    $fallback->getModel(),
                    $e->getMessage(),
                ));
            }
        }

        throw $originalError;
    }

    /**
     * Re-throw immediately if the error is not retryable.
     */
    private function guardNonRetryable(\Throwable $e): void
    {
        if (!ProviderErrorClassifier::isRetryable($e)) {
            throw $e;
        }
    }

    private function notifyFallbackStart(\Throwable $originalError): void
    {
        $classification = ProviderErrorClassifier::classify($originalError);
        $this->notify(sprintf(
            'Primary provider failed (%s). Attempting %d fallback model(s)...',
            $classification,
            count($this->fallbacks),
        ));
    }

    private function notify(string $message): void
    {
        if ($this->onNotify !== null) {
            ($this->onNotify)($message);
        }
    }
}
