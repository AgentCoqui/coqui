<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Transport driver contract for built-in and external channel integrations.
 */
interface ChannelDriverInterface
{
    public function driverName(): string;

    public function displayName(): string;

    /**
     * @return array<string, bool>
     */
    public function capabilities(): array;

    /**
     * @param array<string, mixed> $instanceConfig
     * @return string[]
     */
    public function validateInstanceConfig(array $instanceConfig): array;

    /**
     * @param array<string, mixed> $instanceDefinition
     * @param array<string, mixed> $context
     */
    public function createRuntime(array $instanceDefinition, array $context = []): ChannelRuntimeInterface;
}