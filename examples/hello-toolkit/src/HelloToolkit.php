<?php

declare(strict_types=1);

namespace CoquiBot\HelloToolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

/**
 * A minimal reference toolkit demonstrating how to create Coqui toolkits.
 *
 * This toolkit provides two simple tools:
 * - hello_world: Returns a personalized greeting
 * - hello_time: Returns the current server time
 *
 * Use this as a template when building your own toolkits. Key patterns:
 * - Implement ToolkitInterface with tools() and guidelines()
 * - Each tool is built via a private method returning a Tool instance
 * - Parameters use typed parameter classes (StringParameter, NumberParameter, etc.)
 * - Callbacks return ToolResult::success() or ToolResult::error()
 * - Declare the toolkit in composer.json extra.php-agents.toolkits for auto-discovery
 */
final class HelloToolkit implements ToolkitInterface
{
    /**
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        return [
            $this->helloWorldTool(),
            $this->helloTimeTool(),
        ];
    }

    /**
     * Usage guidelines injected into the LLM's system prompt.
     *
     * Wrap guidelines in XML-style tags for clear boundaries.
     * Keep them concise — every token counts against the context window.
     */
    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <HELLO-TOOLKIT-GUIDELINES>
            - Use hello_world to greet the user. Optional parameter: name.
            - Use hello_time to report the current server time in any format.
            </HELLO-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }

    /**
     * Build the hello_world tool.
     *
     * Each tool should be constructed in its own private method for readability.
     * The Tool class takes: name, description, parameters array, and a callback.
     */
    private function helloWorldTool(): ToolInterface
    {
        return new Tool(
            name: 'hello_world',
            description: 'Return a friendly greeting. Optionally personalize it with a name.',
            parameters: [
                new StringParameter(
                    'name',
                    'The name to greet (default: "World")',
                    required: false,
                ),
            ],
            callback: function (array $input): ToolResult {
                $name = trim((string) ($input['name'] ?? 'World'));

                if ($name === '') {
                    $name = 'World';
                }

                return ToolResult::success("Hello, {$name}! 👋");
            },
        );
    }

    /**
     * Build the hello_time tool.
     *
     * Demonstrates using a parameter with a default value and
     * returning dynamic data (current time).
     */
    private function helloTimeTool(): ToolInterface
    {
        return new Tool(
            name: 'hello_time',
            description: 'Return the current server date and time.',
            parameters: [
                new StringParameter(
                    'format',
                    'PHP date format string (default: "c" for ISO 8601). Examples: "Y-m-d", "H:i:s", "l, F j, Y"',
                    required: false,
                ),
            ],
            callback: function (array $input): ToolResult {
                $format = trim((string) ($input['format'] ?? 'c'));

                if ($format === '') {
                    $format = 'c';
                }

                $time = date($format);

                return ToolResult::success("Current time: {$time}");
            },
        );
    }
}
