<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\ToolResult;

/**
 * Wraps a real tool with a minimal stub schema for the LLM.
 *
 * The LLM sees a truncated description prefixed with [STUB] and no parameter
 * definitions. When called, execution is forwarded to the real underlying tool.
 *
 * The real tool must remain in the BM25 ToolRegistry with its full description
 * so that tool_search returns useful information about it.
 */
final class StubTool implements ToolInterface
{
    private const int DESCRIPTION_MAX = 150;

    public function __construct(
        private readonly ToolInterface $inner,
    ) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    public function description(): string
    {
        $desc = $this->inner->description();

        if (strlen($desc) > self::DESCRIPTION_MAX) {
            $desc = substr($desc, 0, self::DESCRIPTION_MAX - 3) . '...';
        }

        return '[STUB] ' . $desc
            . ' Use tool_search("' . $this->inner->name() . '") for full parameter details.';
    }

    /** @return array<never> */
    public function parameters(): array
    {
        return [];
    }

    public function execute(array $input): ToolResult
    {
        return $this->inner->execute($input);
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => $this->name(),
                'description' => $this->description(),
                'parameters'  => [
                    'type'                 => 'object',
                    'properties'           => new \stdClass(),
                    'additionalProperties' => true,
                ],
            ],
        ];
    }

    public function inner(): ToolInterface
    {
        return $this->inner;
    }
}
