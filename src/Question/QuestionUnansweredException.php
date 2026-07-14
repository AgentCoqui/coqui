<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

/**
 * A suspended API question ended without an answer (cancel or timeout).
 * Caught at the ask_user tool boundary and surfaced as a terminal ToolResult.
 */
final class QuestionUnansweredException extends \RuntimeException
{
}
