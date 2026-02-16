<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Auto-approves all tool executions except those matching catastrophic patterns.
 *
 * Used with the --auto-approve CLI flag for power users who don't want
 * to confirm every tool invocation. Catastrophic commands (rm -rf /, shutdown,
 * fork bombs, etc.) are still blocked regardless.
 *
 * All decisions are logged to the audit_log table for accountability.
 */
final class AutoApprovalPolicy implements ToolExecutionPolicyInterface
{
    public function __construct(
        private readonly CatastrophicBlacklist $blacklist,
        private readonly ?SessionStorage $storage = null,
        private readonly ?string $sessionId = null,
        private readonly ?string $turnId = null,
    ) {}

    public function shouldExecute(string $toolName, array $arguments): true|string
    {
        // Build a single string from all arguments for pattern matching
        $argumentsText = $this->flattenArguments($arguments);

        // Check catastrophic blacklist — always enforced
        $blocked = $this->blacklist->matches($argumentsText);
        if ($blocked !== null) {
            $reason = "CATASTROPHIC BLOCK: {$blocked}";

            $this->log($toolName, $arguments, 'blocked', $reason);

            return $reason;
        }

        // Auto-approve and log
        $this->log($toolName, $arguments, 'auto_approved');

        return true;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function log(
        string $toolName,
        array $arguments,
        string $action,
        ?string $reason = null,
    ): void {
        if ($this->storage === null || $this->sessionId === null) {
            return;
        }

        $this->storage->logAudit(
            sessionId: $this->sessionId,
            toolName: $toolName,
            arguments: $arguments,
            action: $action,
            reason: $reason,
            turnId: $this->turnId,
        );
    }

    /**
     * Flatten all argument values into a single string for pattern matching.
     *
     * @param array<string, mixed> $arguments
     */
    private function flattenArguments(array $arguments): string
    {
        $parts = [];

        foreach ($arguments as $value) {
            if (is_string($value)) {
                $parts[] = $value;
            } elseif (is_array($value)) {
                $parts[] = json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
            } elseif (is_scalar($value)) {
                $parts[] = (string) $value;
            }
        }

        return implode(' ', $parts);
    }
}
