<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Webhook;

use CoquiBot\Coqui\Contract\WebhookVerifierInterface;

/**
 * Registry that maps source types to their verifier implementations.
 */
final class WebhookVerifierRegistry
{
    /** @var array<string, WebhookVerifierInterface> */
    private array $verifiers = [];

    public function __construct()
    {
        $this->register(new GithubWebhookVerifier());
        $this->register(new SlackWebhookVerifier());
        $this->register(new GenericWebhookVerifier());
    }

    public function register(WebhookVerifierInterface $verifier): void
    {
        $this->verifiers[$verifier->sourceType()] = $verifier;
    }

    public function get(string $sourceType): WebhookVerifierInterface
    {
        return $this->verifiers[$sourceType]
            ?? $this->verifiers['generic']
            ?? throw new \InvalidArgumentException("No verifier for source type: {$sourceType}");
    }

    /**
     * @return list<string>
     */
    public function supportedTypes(): array
    {
        return array_keys($this->verifiers);
    }
}
