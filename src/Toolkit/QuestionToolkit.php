<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\ArrayParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\ObjectParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionOption;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\QuestionUnansweredException;
use CoquiBot\Coqui\Support\IdGenerator;

/**
 * Exposes the single `ask_user` tool. Context-agnostic: it never knows whether
 * it runs in a REPL, an API turn, or a loop — the runtime wires the responder,
 * exactly as it wires the execution policy. All answer validation lives in
 * QuestionRequest construction and QuestionResponse::isValidFor(); this toolkit
 * only shapes input, delegates, and serializes the outcome.
 */
final class QuestionToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly QuestionResponderInterface $responder,
        private readonly string $idPrefix = 'q',
    ) {}

    /**
     * @return list<ToolInterface>
     */
    public function tools(): array
    {
        return [$this->askUserTool()];
    }

    public function guidelines(): string
    {
        return <<<GUIDELINES
        <ASK-USER-GUIDELINES>
        Use `ask_user` to ask the user ONE structured question and get a validated answer.
        - `format`: `single_select` (pick one), `multi_select` (pick zero or more), or `free_text`.
        - Provide `options` for selects (objects `{"label": "...", "description": "..."}` or bare strings). Set `allow_other: true` to let the user type a value not in the list.
        - ALWAYS provide `suggested` — your best-guess default — as `{"selected": ["label"]}` for selects or `{"text": "..."}` for free-text. It pre-selects a default and is auto-taken in non-interactive `default` mode.
        - Yes/no is a two-option `single_select`. Ask sequentially if you need multiple answers (one question per call).
        - In an autonomous loop with `on_question: block`, calling `ask_user` halts the stage until an operator answers; the loop shows as `blocked`. If the result begins with `QUESTION_BLOCKED`, STOP immediately and take no further action.
        </ASK-USER-GUIDELINES>
        GUIDELINES;
    }

    private function askUserTool(): ToolInterface
    {
        return new Tool(
            name: 'ask_user',
            description: 'Ask the user ONE structured question (single-select, multi-select, or free-text) and receive a validated answer. Always include a suggested default.',
            parameters: [
                new StringParameter('prompt', 'The question to ask the user.', required: true),
                new EnumParameter('format', 'Answer shape.', ['single_select', 'multi_select', 'free_text'], required: true),
                new ArrayParameter('options', 'Choices for selects: objects {"label","description"?} or bare strings. Omit for free_text.', required: false),
                new ObjectParameter('suggested', 'Best-guess default: {"selected":["label"]} for selects or {"text":"..."} for free-text. REQUIRED.', required: false),
                new StringParameter('header', 'Short chip label for the question.', required: false),
                new BoolParameter('allow_other', 'Selects only: allow a free-text "Other" answer.', required: false),
            ],
            callback: fn(array $input): ToolResult => $this->handle($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function handle(array $input): ToolResult
    {
        $prompt = trim((string) ($input['prompt'] ?? ''));
        if ($prompt === '') {
            return ToolResult::error('ask_user: "prompt" is required.');
        }

        $format = QuestionFormat::tryFrom((string) ($input['format'] ?? ''));
        if ($format === null) {
            return ToolResult::error('ask_user: "format" must be single_select, multi_select, or free_text.');
        }

        $options = $this->parseOptions($input['options'] ?? null);
        if ($format->isSelect() && $options === []) {
            return ToolResult::error('ask_user: select formats require a non-empty "options" array.');
        }
        if (!$format->isSelect() && $options !== []) {
            return ToolResult::error('ask_user: free_text must not include "options".');
        }

        $suggestedRaw = $this->decodeJsonObject($input['suggested'] ?? null);
        if ($suggestedRaw === null) {
            return ToolResult::error('ask_user: "suggested" is required and must be a JSON object.');
        }
        $suggested = QuestionResponse::fromArray($suggestedRaw);

        try {
            $request = new QuestionRequest(
                id: $this->idPrefix . '_' . IdGenerator::hex(6),
                prompt: $prompt,
                format: $format,
                options: $options,
                allowOther: (bool) ($input['allow_other'] ?? false),
                suggested: $suggested,
                header: isset($input['header']) && is_string($input['header']) && $input['header'] !== ''
                    ? $input['header']
                    : null,
            );
        } catch (\InvalidArgumentException $e) {
            return ToolResult::error('ask_user: ' . $e->getMessage() . '.');
        }

        try {
            $answer = $this->responder->ask($request);
        } catch (QuestionUnansweredException $e) {
            return ToolResult::error('ask_user: No answer received (' . $e->getMessage() . ').');
        }

        // A null return means the question was escalated to an operator and no
        // synchronous answer is available (loop `block` mode). The stage keeps
        // running until the turn ends, so the agent must take NO further action:
        // the stage is re-run from the start once the operator answers, and any
        // work done now is discarded. Emit a hard STOP sentinel.
        if ($answer === null) {
            return ToolResult::success(
                'QUESTION_BLOCKED: STOP IMMEDIATELY. Your question has been escalated to the operator and this '
                . 'loop stage is now BLOCKED awaiting their answer. Do NOT call any more tools, write any files, '
                . 'or take any further action. End your turn now with no further output. This stage will be '
                . "re-run from the start with the operator's answer once they respond; anything you do now is discarded.",
            );
        }

        return ToolResult::json([
            'answered' => true,
            'selected' => $answer->selected,
            'text' => $answer->text,
        ]);
    }

    /**
     * Tolerant of a native array (most providers) and a JSON string (some
     * providers stringify structured arguments). Bare strings become option
     * labels; objects with a `label` become full options.
     *
     * @return list<QuestionOption>
     */
    private function parseOptions(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $options = [];
        foreach ($decoded as $entry) {
            if (is_string($entry) && $entry !== '') {
                $options[] = new QuestionOption($entry);
            } elseif (is_array($entry) && isset($entry['label'])) {
                $options[] = QuestionOption::fromArray($entry);
            }
        }

        return $options;
    }

    /**
     * Tolerant of a native object (associative array) and a JSON string.
     *
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }
}
