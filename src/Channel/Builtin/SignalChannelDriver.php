<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Channel\Builtin;

use CoquiBot\Coqui\Contract\ChannelDriverInterface;
use CoquiBot\Coqui\Contract\ChannelRuntimeInterface;
use CoquiBot\Coqui\Storage\ChannelStore;

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
        $settings = is_array($instanceConfig['settings'] ?? null) ? $instanceConfig['settings'] : [];
        $errors = [];

        $account = $settings['account'] ?? null;
        if (!is_string($account) || trim($account) === '') {
            $errors[] = 'signal settings.account is required';
        }

        if (array_key_exists('binary', $settings) && (!is_string($settings['binary']) || trim((string) $settings['binary']) === '')) {
            $errors[] = 'signal settings.binary must be a non-empty string when provided';
        }

        if (array_key_exists('ignoreAttachments', $settings) && !is_bool($settings['ignoreAttachments'])) {
            $errors[] = 'signal settings.ignoreAttachments must be a boolean';
        }

        if (array_key_exists('sendReadReceipts', $settings) && !is_bool($settings['sendReadReceipts'])) {
            $errors[] = 'signal settings.sendReadReceipts must be a boolean';
        }

        if (array_key_exists('receiveMode', $settings)) {
            $receiveMode = $settings['receiveMode'];
            if (!is_string($receiveMode) || trim($receiveMode) !== 'on-start') {
                $errors[] = 'signal settings.receiveMode currently only supports: on-start';
            }
        }

        return $errors;
    }

    public function createRuntime(array $instanceDefinition, array $context = []): ChannelRuntimeInterface
    {
        $store = $context['channelStore'] ?? null;
        $channelInstanceId = $context['channelInstanceId'] ?? null;
        $workspacePath = $context['workspacePath'] ?? null;

        if (!$store instanceof ChannelStore || !is_string($channelInstanceId) || $channelInstanceId === '' || !is_string($workspacePath) || $workspacePath === '') {
            return new PlaceholderChannelRuntime($this->displayName(), $instanceDefinition);
        }

        return new SignalCliChannelRuntime(
            instanceDefinition: $instanceDefinition,
            channelStore: $store,
            channelInstanceId: $channelInstanceId,
            workspacePath: $workspacePath,
        );
    }
}