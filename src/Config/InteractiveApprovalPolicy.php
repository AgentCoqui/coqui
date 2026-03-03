<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Gates tool execution by prompting the user for confirmation.
 *
 * Configured with a map of tool names to gating rules that determine which
 * invocations require interactive approval before the agent can proceed.
 *
 * Rules can be:
 *   - `['*']` — gate every invocation of the tool
 *   - `['action_name']` — gate when `action`/`command` argument matches
 *   - `[{'arg': value}]` — gate when a specific argument has a specific value
 *   - `[{'arg': '*'}]` — gate when a specific argument is present and truthy
 *
 * Catastrophic commands are blocked outright — the user is never prompted.
 * All decisions (approved, denied, blocked) are logged to the audit_log table.
 *
 * Example:
 *   new InteractiveApprovalPolicy($io, [
 *       'composer'     => ['require', 'remove', 'update'],
 *       'exec'         => ['*'],
 *       'git_push'     => ['*'],
 *       'git_commit'   => [['amend' => true]],
 *       'git_checkout' => [['files' => '*']],
 *   ]);
 */
final class InteractiveApprovalPolicy implements ToolExecutionPolicyInterface
{
    /**
     * @param array<string, list<mixed>> $gatedTools Tool name => list of gating rules.
     *                                                Use ['*'] to gate all invocations.
     *                                                Use string values to match action/command arg.
     *                                                Use associative arrays for argument predicates.
     */
    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly array $gatedTools = [],
        private readonly ?CatastrophicBlacklist $blacklist = null,
        private readonly ?SessionStorage $storage = null,
        private readonly ?string $sessionId = null,
        private readonly ?string $turnId = null,
    ) {}

    public function shouldExecute(string $toolName, array $arguments): true|string
    {
        // Check catastrophic blacklist first — always enforced, no prompt
        if ($this->blacklist !== null) {
            $argumentsText = $this->flattenArguments($arguments);
            $blocked = $this->blacklist->matches($argumentsText);
            if ($blocked !== null) {
                $reason = "CATASTROPHIC BLOCK: {$blocked}";
                $this->log($toolName, $arguments, 'blocked', $reason);

                return $reason;
            }
        }

        if (!$this->requiresApproval($toolName, $arguments)) {
            return true;
        }

        $this->io->newLine();
        $this->io->writeln('<fg=yellow>⚠ Approval required</>');
        $this->io->writeln("<fg=gray>Tool:</> <fg=cyan>{$toolName}</>");
        $this->renderArguments($arguments);

        $confirmed = $this->io->confirm('Allow this action?', false);

        if (!$confirmed) {
            $this->log($toolName, $arguments, 'denied', 'User denied');

            return "User denied execution of '{$toolName}'";
        }

        $this->log($toolName, $arguments, 'approved');

        return true;
    }

    /** @param array<string, mixed> $arguments */
    private function requiresApproval(string $toolName, array $arguments): bool
    {
        if (!isset($this->gatedTools[$toolName])) {
            return false;
        }

        $rules = $this->gatedTools[$toolName];

        // Wildcard — gate every invocation
        if ($rules === ['*']) {
            return true;
        }

        foreach ($rules as $rule) {
            // String rule — match against `action` or `command` argument value
            if (is_string($rule)) {
                $action = $arguments['action'] ?? $arguments['command'] ?? null;

                if ($action === null) {
                    // No action field — gate by default if the tool is listed
                    return true;
                }

                if ((string) $action === $rule) {
                    return true;
                }

                continue;
            }

            // Associative array rule — predicate matching on argument values
            // e.g. ['amend' => true], ['force' => true], ['files' => '*']
            if (is_array($rule)) {
                if ($this->matchesPredicate($rule, $arguments)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if tool arguments match a predicate rule.
     *
     * Predicate rules are associative arrays where each key is an argument
     * name and each value is the expected value:
     *   - `'*'` — matches when the argument is present and truthy
     *   - `true`/`false` — matches when the argument is the exact boolean value
     *   - A string — matches when the argument is that exact string value
     *
     * All predicates in the rule must match (AND semantics).
     *
     * @param array<string, mixed> $predicate
     * @param array<string, mixed> $arguments
     */
    private function matchesPredicate(array $predicate, array $arguments): bool
    {
        foreach ($predicate as $argName => $expectedValue) {
            $actualValue = $arguments[$argName] ?? null;

            // Wildcard: gate when argument is present and truthy
            if ($expectedValue === '*') {
                if (empty($actualValue)) {
                    return false;
                }

                continue;
            }

            // Boolean match
            if (is_bool($expectedValue)) {
                if ((bool) $actualValue !== $expectedValue) {
                    return false;
                }

                continue;
            }

            // String match
            if (is_string($expectedValue)) {
                if ((string) ($actualValue ?? '') !== $expectedValue) {
                    return false;
                }

                continue;
            }

            // Unknown predicate type — reject
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function renderArguments(array $arguments): void
    {
        foreach ($arguments as $key => $value) {
            $display = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_string($value) => $this->truncate($value, 120),
                is_numeric($value) => (string) $value,
                is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES) ?: '[...]',
                default => '(complex)',
            };

            $this->io->writeln("<fg=gray>{$key}:</> {$display}");
        }
    }

    private function truncate(string $text, int $maxLength): string
    {
        $text = str_replace(["\n", "\r"], ' ', $text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
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
