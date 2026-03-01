<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer;

use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Contract\OutputRendererInterface;

/**
 * Discards all output.
 *
 * Used in API mode where the SseObserver handles real-time streaming
 * and the caller inspects AgentTurnResult directly.
 */
final class NullRenderer implements OutputRendererInterface
{
    public function render(AgentTurnResult $result, bool $contentStreamed = false): void
    {
        // Intentionally empty — output handled elsewhere.
    }

    public function renderError(string $message): void
    {
        // Intentionally empty.
    }
}
