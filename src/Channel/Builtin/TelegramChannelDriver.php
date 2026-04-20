<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Channel\Builtin;

use CoquiBot\Coqui\Contract\ChannelDriverInterface;
use CoquiBot\Coqui\Contract\ChannelRuntimeInterface;

final class TelegramChannelDriver implements ChannelDriverInterface
{
    public function driverName(): string
    {
        return 'telegram';
    }

    public function displayName(): string
    {
        return 'Telegram';
    }

    public function capabilities(): array
    {
        return [
            'direct_messages' => true,
            'groups' => true,
            'mentions' => true,
            'attachments' => true,
            'reactions' => false,
            'typing' => false,
            'threads' => false,
            'proactive_sends' => true,
            'message_edits' => true,
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