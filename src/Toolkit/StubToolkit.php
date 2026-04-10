<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Tool\StubTool;

/**
 * Wraps a toolkit with stub schemas for all its tools.
 *
 * Deferred toolkits have zero prompt footprint — guidelines return empty
 * and tools() returns StubTool wrappers with minimal schemas. The LLM
 * discovers deferred toolkit capabilities via tool_search only.
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

    /**
     * Returns empty string — deferred toolkits have zero prompt footprint.
     *
     * The LLM discovers deferred toolkit capabilities via tool_search.
     * A brief description is included in the # DEFERRED TOOLKITS hint
     * section instead of full guidelines.
     */
    public function guidelines(): string
    {
        return '';
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

    /**
     * Returns the FQCN of the wrapped toolkit for token breakdown matching.
     */
    public function innerClass(): string
    {
        return $this->inner::class;
    }
}
