<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Contract\CredentialRequirement;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;

/**
 * Decorator that checks credential availability before executing a tool.
 *
 * If any required credentials are missing, returns a ToolResult::error() with
 * structured instructions telling the LLM exactly which credentials to request
 * from the user and how to save them via the credentials tool.
 *
 * The inner tool's execute() is never called when credentials are missing,
 * saving tokens and avoiding confusing error messages.
 *
 * When childMode is enabled, the error message instructs the agent to report
 * the missing credential back to the parent agent instead of suggesting the
 * non-existent credentials tool.
 */
final class CredentialGuardTool implements ToolInterface
{
    /**
     * @param CredentialRequirement[] $requirements
     * @param bool $childMode When true, error messages tell the agent to report back to the parent
     */
    public function __construct(
        private readonly ToolInterface $inner,
        private readonly array $requirements,
        private readonly CredentialResolverInterface $resolver,
        private readonly bool $childMode = false,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function description(): string
    {
        return $this->inner->description();
    }

    public function parameters(): array
    {
        return $this->inner->parameters();
    }

    public function execute(array $input): ToolResult
    {
        $missing = $this->findMissingCredentials();

        if (!empty($missing)) {
            return ToolResult::error($this->buildMissingCredentialsMessage($missing));
        }

        return $this->inner->execute($input);
    }

    public function toFunctionSchema(): array
    {
        return $this->inner->toFunctionSchema();
    }

    /**
     * Find required (non-optional) credentials that are not yet configured.
     *
     * Optional credentials are excluded — they do not block tool execution.
     *
     * @return CredentialRequirement[]
     */
    private function findMissingCredentials(): array
    {
        $missing = [];

        foreach ($this->requirements as $requirement) {
            if (!$requirement->optional && !$this->resolver->has($requirement->name)) {
                $missing[] = $requirement;
            }
        }

        return $missing;
    }

    /**
     * @param CredentialRequirement[] $missing
     */
    private function buildMissingCredentialsMessage(array $missing): string
    {
        $message = "## Missing Credentials\n\n";
        $message .= "This tool requires credentials that are not yet configured.\n\n";

        foreach ($missing as $requirement) {
            $message .= "### `{$requirement->name}`\n";
            $message .= "{$requirement->description}\n\n";
        }

        if ($this->childMode) {
            $message .= "## Cannot Fix Here\n\n";
            $message .= "You are running as a child agent and cannot set credentials directly.\n";
            $message .= "Report this missing credential back to the parent agent via the `done` tool so the user can configure it.";
        } else {
            $message .= "## How to Fix\n\n";
            $message .= "Ask the user for each missing credential, then save it using the credentials tool:\n\n";

            foreach ($missing as $requirement) {
                $message .= "```\n";
                $message .= "credentials(action: \"set\", key: \"{$requirement->name}\", value: \"<value-from-user>\")\n";
                $message .= "```\n\n";
            }

            $message .= "After saving the credential(s), retry this tool call.";
        }

        return $message;
    }
}
