<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Tool\StubTool;

/**
 * Wraps a toolkit with stub schemas for all its tools.
 *
 * Guidelines pass through unchanged so the LLM still receives usage
 * instructions for the toolkit. The tools() method returns StubTool
 * wrappers instead of real tools so the LLM only sees minimal schemas.
 *
 * The caller (OrchestratorAgent::addToolkit) must register the real tools
 * via realTools() into the BM25 ToolRegistry before adding this wrapper to
 * the parent agent — otherwise tool_search will return stub descriptions.
 */
final class StubToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly ToolkitInterface $inner,
    ) {}

    /**
     * Returns StubTool wrappers — minimal schema for LLM context.
     *
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        return array_map(
            fn(ToolInterface $tool) => new StubTool($tool),
            $this->inner->tools(),
        );
    }

    public function guidelines(): string
    {
        return $this->inner->guidelines();
    }

    /**
     * Returns the underlying real tools for BM25 registry registration.
     *
     * @return ToolInterface[]
     */
    public function realTools(): array
    {
        return $this->inner->tools();
    }
}
