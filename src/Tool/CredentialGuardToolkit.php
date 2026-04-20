<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Contract\CredentialRequirement;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;

/**
 * Decorator that wraps a toolkit's tools with credential guards.
 *
 * Each tool returned by the inner toolkit is wrapped in a CredentialGuardTool
 * that checks credential availability before execution. The guidelines are
 * augmented with credential status information.
 *
 * When childMode is enabled, error messages and guidelines are adjusted to
 * reflect that the agent cannot set credentials directly — it should report
 * missing credentials back to the parent agent.
 */
final class CredentialGuardToolkit implements ToolkitInterface
{
    /**
     * @param CredentialRequirement[] $requirements
     * @param bool $childMode When true, wraps tools with child-aware error messages
     */
    public function __construct(
        private readonly ToolkitInterface $inner,
        private readonly array $requirements,
        private readonly CredentialResolverInterface $resolver,
        private readonly bool $childMode = false,
    ) {}

    /**
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        return array_map(
            fn(ToolInterface $tool): ToolInterface => new CredentialGuardTool(
                inner: $tool,
                requirements: $this->requirements,
                resolver: $this->resolver,
                childMode: $this->childMode,
            ),
            $this->inner->tools(),
        );
    }

    public function guidelines(): string
    {
        $innerGuidelines = $this->inner->guidelines();
        $credentialStatus = $this->buildCredentialStatus();

        return $innerGuidelines . "\n" . $credentialStatus;
    }

    /**
     * Returns the FQCN of the wrapped toolkit for token breakdown matching.
     */
    public function innerClass(): string
    {
        return $this->inner::class;
    }

    /**
     * Returns the inner toolkit instance.
     *
     * Used by discovery to check if the wrapped toolkit implements
     * additional capability interfaces (e.g. ReplCommandProvider).
     */
    public function innerToolkit(): ToolkitInterface
    {
        return $this->inner;
    }

    private function buildCredentialStatus(): string
    {
        $allConfigured = true;
        $statusLines = [];

        foreach ($this->requirements as $requirement) {
            $hasCredential = $this->resolver->has($requirement->name);

            if (!$hasCredential && !$requirement->optional) {
                $allConfigured = false;
            }

            $status = $hasCredential ? 'configured' : ($requirement->optional ? 'not set (optional)' : 'MISSING');
            $icon = $hasCredential ? '✓' : ($requirement->optional ? '○' : '✗');
            $statusLines[] = "  - {$icon} `{$requirement->name}`: {$status} — {$requirement->description}";
        }

        if (empty($this->requirements)) {
            return '';
        }

        $block = "<CREDENTIAL-STATUS>\n";

        if ($allConfigured) {
            $block .= "All required credentials are configured.\n";
        } elseif ($this->childMode) {
            $block .= "Some credentials are MISSING. You cannot set credentials directly — report missing credentials back to the parent agent.\n";
        } else {
            $block .= "Some credentials are MISSING. Use the `credentials` tool to set them before using these tools.\n";
        }

        $block .= implode("\n", $statusLines) . "\n";
        $block .= "</CREDENTIAL-STATUS>";

        return $block;
    }
}
