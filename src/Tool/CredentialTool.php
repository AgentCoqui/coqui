<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;

/**
 * Manages credentials stored in a workspace .env file.
 *
 * Provides secure access to API keys and secrets needed by installed SDKs.
 * Values are never exposed to the LLM — only key names are visible.
 * At runtime, generated scripts access credentials via getenv().
 */
final class CredentialTool implements ToolInterface
{
    public function __construct(
        private readonly CredentialResolverInterface $resolver,
    ) {}

    public function name(): string
    {
        return 'credentials';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage API keys and secrets for installed SDK packages.
            
            Credentials are stored in a .env file in the workspace directory and are
            loaded automatically when executing PHP scripts via the php_execute tool.
            
            Available actions:
            - set: Store a credential (key=value pair). The value is saved securely.
            - get: Check if a credential exists. Returns the key name only — values are NEVER shown.
            - list: List all stored credential key names (no values).
            - delete: Remove a credential.
            
            IMPORTANT: Credential values are never returned to you. When writing code that
            needs a credential, always use getenv('KEY_NAME') — never hardcode values.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The credential action to perform',
                values: ['set', 'get', 'list', 'delete'],
                required: true,
            ),
            new StringParameter(
                name: 'key',
                description: 'The credential key name (e.g. CLOUDFLARE_API_TOKEN). Required for set, get, delete.',
                required: false,
            ),
            new StringParameter(
                name: 'value',
                description: 'The credential value. Required for set action only.',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';
        $key = $input['key'] ?? '';
        $value = $input['value'] ?? '';

        return match ($action) {
            'set' => $this->setCredential($key, $value),
            'get' => $this->getCredential($key),
            'list' => $this->listCredentials(),
            'delete' => $this->deleteCredential($key),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function setCredential(string $key, string $value): ToolResult
    {
        if ($key === '') {
            return ToolResult::error('Key name is required for set action.');
        }

        if ($value === '') {
            return ToolResult::error('Value is required for set action.');
        }

        if (!$this->isValidKeyName($key)) {
            return ToolResult::error(
                "Invalid key name: '{$key}'. Use UPPER_SNAKE_CASE (e.g. CLOUDFLARE_API_TOKEN).",
            );
        }

        $this->resolver->set($key, $value);

        return ToolResult::success(
            "Credential '{$key}' has been saved. Use getenv('{$key}') in your PHP code to access it.",
        );
    }

    private function getCredential(string $key): ToolResult
    {
        if ($key === '') {
            return ToolResult::error('Key name is required for get action.');
        }

        if (!$this->resolver->has($key)) {
            return ToolResult::error("Credential '{$key}' not found.");
        }

        return ToolResult::success(
            "Credential '{$key}' exists. Use getenv('{$key}') in your PHP code to access it. "
            . 'The value is not shown for security.',
        );
    }

    private function listCredentials(): ToolResult
    {
        $keys = $this->resolver->keys();

        if (empty($keys)) {
            return ToolResult::success('No credentials stored.');
        }

        $output = "## Stored Credentials\n\n";
        $output .= "| Key | Status |\n";
        $output .= "|-----|--------|\n";

        foreach ($keys as $key) {
            $output .= "| {$key} | ✓ set |\n";
        }

        $output .= "\nUse `getenv('KEY_NAME')` in PHP code to access values.";

        return ToolResult::success($output);
    }

    private function deleteCredential(string $key): ToolResult
    {
        if ($key === '') {
            return ToolResult::error('Key name is required for delete action.');
        }

        if (!$this->resolver->has($key)) {
            return ToolResult::error("Credential '{$key}' not found.");
        }

        $this->resolver->delete($key);

        return ToolResult::success("Credential '{$key}' has been deleted.");
    }

    private function isValidKeyName(string $key): bool
    {
        return (bool) preg_match('/^[A-Z][A-Z0-9_]*$/', $key);
    }

    /**
     * Get the path to the .env file (used by PhpExecuteTool for bootstrapping).
     */
    public function envPath(): string
    {
        return $this->resolver->envPath();
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The credential action to perform',
                            'enum' => ['set', 'get', 'list', 'delete'],
                        ],
                        'key' => [
                            'type' => 'string',
                            'description' => 'The credential key name (e.g. CLOUDFLARE_API_TOKEN)',
                        ],
                        'value' => [
                            'type' => 'string',
                            'description' => 'The credential value. Required for set action only.',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }
}
