<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Provider;

use Symfony\Contracts\HttpClient\ChunkInterface;

/**
 * Simple ChunkInterface value object backed by React stream data.
 *
 * Used by ReactResponseStream to yield chunks compatible with
 * SseStreamParser and Symfony's streaming contract.
 */
final readonly class ReactHttpChunk implements ChunkInterface
{
    public function __construct(
        private string $content,
        private bool $isFirst = false,
        private bool $isLast = false,
        private int $offset = 0,
        private ?string $error = null,
    ) {}

    public function isTimeout(): bool
    {
        return false;
    }

    public function isFirst(): bool
    {
        return $this->isFirst;
    }

    public function isLast(): bool
    {
        return $this->isLast;
    }

    /**
     * @return array<string, string>|null
     */
    public function getInformationalStatus(): ?array
    {
        return null;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getOffset(): int
    {
        return $this->offset;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
