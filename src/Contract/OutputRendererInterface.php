<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Renders agent turn results to an output destination.
 *
 * Implementations handle the presentation concern — the terminal shows
 * colorized text, JSON mode writes structured output, and the null
 * renderer discards everything (for API mode where SSE handles display).
 */
interface OutputRendererInterface
{
    /**
     * Render a completed agent turn result.
     *
     * @param bool $contentStreamed  When true, content was already streamed
     *                               via observer events — skip reprinting it.
     */
    public function render(AgentTurnResult $result, bool $contentStreamed = false): void;

    /**
     * Render an error message outside of a turn context.
     */
    public function renderError(string $message): void;
}
