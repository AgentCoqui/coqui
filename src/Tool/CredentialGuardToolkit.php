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
 */
final class CredentialGuardToolkit implements ToolkitInterface
{
    /**
     * @param CredentialRequirement[] $requirements
     */
    public function __construct(
        private readonly ToolkitInterface $inner,
        private readonly array $requirements,
        private readonly CredentialResolverInterface $resolver,
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

    private function buildCredentialStatus(): string
    {
        $allConfigured = true;
        $statusLines = [];

        foreach ($this->requirements as $requirement) {
            $hasCredential = $this->resolver->has($requirement->name);

            if (!$hasCredential) {
                $allConfigured = false;
            }

            $status = $hasCredential ? 'configured' : 'MISSING';
            $icon = $hasCredential ? '✓' : '✗';
            $statusLines[] = "  - {$icon} `{$requirement->name}`: {$status} — {$requirement->description}";
        }

        if (empty($this->requirements)) {
            return '';
        }

        $block = "<CREDENTIAL-STATUS>\n";

        if ($allConfigured) {
            $block .= "All required credentials are configured.\n";
        } else {
            $block .= "Some credentials are MISSING. Use the `credentials` tool to set them before using these tools.\n";
        }

        $block .= implode("\n", $statusLines) . "\n";
        $block .= "</CREDENTIAL-STATUS>";

        return $block;
    }
}
