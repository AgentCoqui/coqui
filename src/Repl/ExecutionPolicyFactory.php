<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CoquiBot\Coqui\Config\AutoApprovalPolicy;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Config\InteractiveApprovalPolicy;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Builds execution policies for agent turns.
 *
 * Encapsulates the decision between AutoApprovalPolicy and InteractiveApprovalPolicy,
 * and merges hardcoded gated tools with package-declared gated tools.
 */
final class ExecutionPolicyFactory
{
    /** Tool names that require user confirmation in interactive mode. */
    private const GATED_TOOLS = [
        'artifact_bulk_delete' => ['*'],
        'artifact_delete' => ['*'],
        'batch_replace' => ['*'],
        'composer' => ['add', 'remove', 'update', 'install'],
        'exec' => ['*'],
        'loop_stop' => ['*'],
        'move' => ['*'],
        'php_execute' => ['*'],
        'project_delete' => ['*'],
        'restart_coqui' => ['*'],
        'schedule_delete' => ['*'],
    ];

    public function __construct(
        private readonly CatastrophicBlacklist $blacklist,
        private readonly SessionStorage $storage,
        private readonly ToolkitDiscovery $discovery,
    ) {}

    /**
     * Build an execution policy for headless (non-interactive) mode.
     *
     * Only auto-approve is available — interactive prompts require a TTY.
     *
     * @throws \LogicException When auto-approve is false (interactive mode requires SymfonyStyle).
     */
    public function build(
        string $sessionId,
        bool $autoApprove,
        ?string $turnId = null,
    ): ToolExecutionPolicyInterface {
        if ($autoApprove) {
            return new AutoApprovalPolicy(
                blacklist: $this->blacklist,
                storage: $this->storage,
                sessionId: $sessionId,
                turnId: $turnId,
            );
        }

        throw new \LogicException(
            'buildExecutionPolicy() for interactive mode must be called from the REPL context. '
            . 'Use buildInteractive() which accepts SymfonyStyle.',
        );
    }

    /**
     * Build an execution policy for REPL (interactive) mode.
     *
     * Returns AutoApprovalPolicy when auto-approve is enabled, otherwise
     * InteractiveApprovalPolicy with merged gated tool rules.
     */
    public function buildInteractive(
        string $sessionId,
        SymfonyStyle $io,
        bool $autoApprove,
        ?string $turnId = null,
    ): ToolExecutionPolicyInterface {
        if ($autoApprove) {
            return new AutoApprovalPolicy(
                blacklist: $this->blacklist,
                storage: $this->storage,
                sessionId: $sessionId,
                turnId: $turnId,
            );
        }

        return new InteractiveApprovalPolicy(
            io: $io,
            gatedTools: $this->mergeGatedTools(),
            blacklist: $this->blacklist,
            storage: $this->storage,
            sessionId: $sessionId,
            turnId: $turnId,
        );
    }

    /**
     * Merge hardcoded gated tools with package-declared gated tools.
     *
     * Packages declare gated operations in composer.json via extra.php-agents.gated.
     * These are merged with the hardcoded GATED_TOOLS constant so that both
     * core and toolkit-declared gates are enforced.
     *
     * @return array<string, list<mixed>>
     */
    private function mergeGatedTools(): array
    {
        $discoveredGated = $this->discovery->collectAllGatedTools();

        if (empty($discoveredGated)) {
            return self::GATED_TOOLS;
        }

        $merged = self::GATED_TOOLS;

        foreach ($discoveredGated as $toolName => $rules) {
            if (!isset($merged[$toolName])) {
                $merged[$toolName] = $rules;
            } else {
                if ($rules === ['*'] || $merged[$toolName] === ['*']) {
                    $merged[$toolName] = ['*'];
                } else {
                    $merged[$toolName] = array_merge($merged[$toolName], $rules);
                }
            }
        }

        return $merged;
    }
}
