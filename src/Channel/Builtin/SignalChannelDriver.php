<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Channel\Builtin;

use CoquiBot\Coqui\Contract\ChannelDriverInterface;
use CoquiBot\Coqui\Contract\ChannelRuntimeInterface;

final class SignalChannelDriver implements ChannelDriverInterface
{
    public function driverName(): string
    {
        return 'signal';
    }

    public function displayName(): string
    {
        return 'Signal';
    }

    public function capabilities(): array
    {
        return [
            'direct_messages' => true,
            'groups' => true,
            'mentions' => false,
            'attachments' => true,
            'reactions' => true,
            'typing' => true,
            'threads' => false,
            'proactive_sends' => true,
            'message_edits' => false,
        ];
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