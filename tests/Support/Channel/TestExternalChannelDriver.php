<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Support\Channel;

use CoquiBot\Coqui\Channel\Builtin\PlaceholderChannelRuntime;
use CoquiBot\Coqui\Contract\ChannelDriverInterface;
use CoquiBot\Coqui\Contract\ChannelRuntimeInterface;

final class TestExternalChannelDriver implements ChannelDriverInterface
{
    public function driverName(): string
    {
        return 'test-external';
    }

    public function displayName(): string
    {
        return 'Test External';
    }

    public function capabilities(): array
    {
        return ['direct_messages' => true];
    }

    public function validateInstanceConfig(array $instanceConfig): array
    {
        return [];
    }

    public function createRuntime(array $instanceDefinition, array $context = []): ChannelRuntimeInterface
    {
        return new PlaceholderChannelRuntime($this->displayName(), $instanceDefinition);
    }
}