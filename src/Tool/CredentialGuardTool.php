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
 */
final class CredentialGuardTool implements ToolInterface
{
    /**
     * @param CredentialRequirement[] $requirements
     */
    public function __construct(
        private readonly ToolInterface $inner,
        private readonly array $requirements,
        private readonly CredentialResolverInterface $resolver,
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

        $message .= "## How to Fix\n\n";
        $message .= "Ask the user for each missing credential, then save it using the credentials tool:\n\n";

        foreach ($missing as $requirement) {
            $message .= "```\n";
            $message .= "credentials(action: \"set\", key: \"{$requirement->name}\", value: \"<value-from-user>\")\n";
            $message .= "```\n\n";
        }

        $message .= "After saving the credential(s), retry this tool call.";

        return $message;
    }
}
