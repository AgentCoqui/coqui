<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Agent-facing tool that searches the ToolRegistry for relevant tools.
 *
 * Instead of loading every tool's full JSON Schema into the context window on
 * every request (which can exceed 100K tokens with 100+ tools), the agent uses
 * this tool to discover what is available. It then calls the specific tool it
 * needs — and only that tool's schema enters the context.
 *
 * This provides a ~85–94% reduction in tool-related token usage, equivalent to
 * the "Tool Search Tool" pattern described by Anthropic (Nov 2025).
 *
 * The search is BM25 keyword-based using the ToolRegistry — fast, deterministic,
 * and free of external dependencies. Results include tool name + one-line
 * description only (no schema), so even listing many results is cheap.
 *
 * Usage pattern:
 *   1. Agent calls tool_search with a keyword like "file" or "git"
 *   2. Results show which tools are available in that category
 *   3. Agent calls the specific tool it identified
 *
 * This tool itself is exempt from the maxTools cap (it is always loaded).
 */
final class ToolSearchTool implements ToolInterface
{
    public function __construct(
        private readonly ToolRegistry $registry,
    ) {}

    public function name(): string
    {
        return 'tool_search';
    }

    public function description(): string
    {
        return <<<'DESC'
            Search the full tool library by keyword.

            Use this when you need a capability but are not sure of the exact tool name.
            Returns a list of matching tool names and one-line descriptions.

            Examples:
            - query "file" → find file reading, writing, listing tools
            - query "git commit" → find version control tools
            - query "composer install" → find package management tools
            - query "browser navigate" → find web browsing tools

            After finding a relevant tool name, call it directly. The search results
            show names only — the actual tool parameters will be available when you call it.
            DESC;
    }

    /**
     * @return \CarmeloSantana\PHPAgents\Contract\ParameterInterface[]
     */
    public function parameters(): array
    {
        return [
            new StringParameter(
                name: 'query',
                description: 'Keywords describing the capability you need (e.g. "write file", "run shell command", "search web").',
                required: true,
            ),
            new NumberParameter(
                name: 'limit',
                description: 'Maximum number of results to return (default 8, max 20).',
                required: false,
            ),
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));
        if ($query === '') {
            return ToolResult::error('query is required and must not be empty.');
        }

        $limit = min(20, max(1, (int) ($arguments['limit'] ?? 8)));

        $results = $this->registry->search($query, $limit);

        if (empty($results)) {
            $total = $this->registry->count();
            return ToolResult::success(
                "No tools matched \"{$query}\". The registry contains {$total} tools. Try broader keywords."
            );
        }

        $lines = [];
        foreach ($results as $result) {
            // Truncate long descriptions to keep output compact
            $desc = $result['description'];
            if (strlen($desc) > 120) {
                $desc = substr($desc, 0, 117) . '...';
            }
            $lines[] = "- **{$result['name']}**: {$desc}";
        }

        $count = count($results);
        $total = $this->registry->count();
        $header = "Found {$count} tools matching \"{$query}\" (of {$total} total):";

        return ToolResult::success($header . "\n" . implode("\n", $lines));
    }

    public function toFunctionSchema(): array
    {
        $schema = [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                    'required' => [],
                ],
            ],
        ];

        foreach ($this->parameters() as $parameter) {
            $schema['function']['parameters']['properties'][$parameter->name] = $parameter->toSchema();
            if ($parameter->required) {
                $schema['function']['parameters']['required'][] = $parameter->name;
            }
        }

        return $schema;
    }
}
