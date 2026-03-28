<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Observer;

use CarmeloSantana\PHPAgents\Contract\AgentInterface;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Renderer\StreamingMarkdownBuffer;
use SplObserver;
use SplSubject;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Observes agent events and streams output to the terminal.
 *
 * Listens for events from AbstractAgent: agent.start, agent.iteration,
 * agent.tool_call, agent.tool_result, agent.done, agent.error.
 */
final class TerminalObserver implements SplObserver
{
    private int $indentLevel = 0;
    private bool $hasStreamedText = false;
    private bool $hasStreamedReasoning = false;
    private readonly StreamingMarkdownBuffer $markdownBuffer;

    public function __construct(
        private readonly OutputInterface $output,
    ) {
        $this->markdownBuffer = new StreamingMarkdownBuffer(
            fn(string $rendered) => $this->output->write($rendered),
        );
    }

    public function update(SplSubject $subject): void
    {
        if (!$subject instanceof AgentInterface) {
            return;
        }

        // Access the last event via reflection or a getter if available
        // AbstractAgent exposes lastEvent() and lastEventData()
        if (!method_exists($subject, 'lastEvent') || !method_exists($subject, 'lastEventData')) {
            return;
        }

        $event = $subject->lastEvent();
        $data = $subject->lastEventData();

        $this->handleEvent($event, $data);
    }

    public function handleEvent(string $event, mixed $data): void
    {
        $indent = str_repeat('  ', $this->indentLevel);

        match ($event) {
            'agent.start' => (function () use ($indent): void {
                $this->hasStreamedText = false;
                $this->hasStreamedReasoning = false;
                $this->markdownBuffer->reset();
                $this->output->writeln("{$indent}<fg=cyan>▶ Agent started</>");
            })(),

            'agent.iteration' => (function () use ($indent, $data): void {
                if ($this->hasStreamedReasoning) {
                    $this->output->writeln('');
                    $this->hasStreamedReasoning = false;
                }
                $this->output->writeln("{$indent}<fg=gray>  ⟳ Iteration {$data}</>");
            })(),

            'agent.reasoning' => $this->handleReasoningDelta($data),

            'agent.text_delta' => $this->handleTextDelta($data),

            'agent.tool_call' => $this->handleToolCall($data, $indent),

            'agent.tool_result' => $this->handleToolResult($data, $indent),

            'agent.done' => $this->handleDone($data, $indent),

            'agent.error' => $this->output->writeln("{$indent}<fg=red>✗ Error: {$data}</>"),

            'agent.warning' => $this->output->writeln("{$indent}<fg=yellow>⚠ {$data}</>"),

            'agent.summary' => $this->handleSummary($data, $indent),

            'child.start' => $this->handleChildStart($data, $indent),

            'child.end' => $this->handleChildEnd($indent),

            'child.review_start' => $this->handleReviewStart($data, $indent),

            'child.review_end' => $this->handleReviewEnd($data, $indent),

            default => null,
        };
    }

    private function handleReasoningDelta(mixed $data): void
    {
        if (!is_string($data) || $data === '') {
            return;
        }

        if (!$this->hasStreamedReasoning) {
            $this->hasStreamedReasoning = true;
            $this->output->write('<fg=gray>  ⛭ </>');
        }

        $this->output->write('<fg=gray>' . $data . '</>');
    }

    private function handleToolCall(mixed $data, string $indent): void
    {
        if (!$data instanceof ToolCall) {
            return;
        }

        // Close any open reasoning or text line before the tool call display.
        if ($this->hasStreamedReasoning) {
            $this->output->writeln('');
            $this->hasStreamedReasoning = false;
        }

        // If text was being streamed, flush and add a newline to separate
        // the streamed content from the tool call display.
        if ($this->hasStreamedText) {
            $this->markdownBuffer->flush();
            $this->output->writeln('');
            $this->hasStreamedText = false;
        }

        $args = $this->formatArguments($data->arguments);
        $this->output->writeln("{$indent}<fg=gray>  ▸ Using:</> <fg=yellow>{$data->name}</><fg=gray>({$args})</>");
    }

    private function handleToolResult(mixed $data, string $indent): void
    {
        if (!$data instanceof ToolResult) {
            return;
        }

        $status = $data->status->value;
        $color = $status === 'success' ? 'green' : 'red';
        $icon = $status === 'success' ? '✓' : '✗';

        // Truncate content for display
        $content = $data->content;
        if (strlen($content) > 100) {
            $content = substr($content, 0, 97) . '...';
        }
        $content = str_replace(["\n", "\r"], ' ', $content);

        $this->output->writeln("{$indent}    <fg={$color}>{$icon}</> <fg=gray>{$content}</>");
    }

    private function handleTextDelta(mixed $data): void
    {
        if (!is_string($data) || $data === '') {
            return;
        }

        // Close the reasoning line before the first text chunk so the
        // response starts on a new line, visually separated from the thinking.
        if ($this->hasStreamedReasoning) {
            $this->output->writeln('');
            $this->hasStreamedReasoning = false;
        }

        $this->hasStreamedText = true;
        $this->markdownBuffer->feed($data);
    }

    private function handleDone(mixed $data, string $indent): void
    {
        if ($this->hasStreamedReasoning) {
            $this->output->writeln('');
            $this->hasStreamedReasoning = false;
        }

        if ($this->hasStreamedText) {
            $this->markdownBuffer->flush();
            $this->output->writeln('');
            $this->hasStreamedText = false;
            return;
        }

        if (is_array($data) && isset($data['response'])) {
            $preview = substr((string) $data['response'], 0, 50);
            if (strlen((string) $data['response']) > 50) {
                $preview .= '...';
            }
            $this->output->writeln("{$indent}<fg=green>✓ Done</> <fg=gray>{$preview}</>");
        } else {
            $this->output->writeln("{$indent}<fg=green>✓ Done</>");
        }
    }

    private function handleChildStart(mixed $data, string $indent): void
    {
        $role = is_array($data) && isset($data['role']) ? $data['role'] : 'child';
        $this->output->writeln("{$indent}<fg=blue>[{$role}]</> <fg=cyan>Spawning child agent...</>");
        $this->indentLevel++;
    }

    private function handleChildEnd(string $indent): void
    {
        $this->indentLevel = max(0, $this->indentLevel - 1);
        $newIndent = str_repeat('  ', $this->indentLevel);
        $this->output->writeln("{$newIndent}<fg=blue>└─</> <fg=gray>Child agent completed</>");
    }

    private function handleReviewStart(mixed $data, string $indent): void
    {
        $round = is_array($data) ? ($data['round'] ?? 1) : 1;
        $maxRounds = is_array($data) ? ($data['max_rounds'] ?? 1) : 1;
        $this->output->writeln("{$indent}<fg=magenta>⚖ Code Review</> <fg=gray>Round {$round}/{$maxRounds}</>");
        $this->indentLevel++;
    }

    private function handleReviewEnd(mixed $data, string $indent): void
    {
        $this->indentLevel = max(0, $this->indentLevel - 1);
        $newIndent = str_repeat('  ', $this->indentLevel);
        $approved = is_array($data) && ($data['approved'] ?? false);
        if ($approved) {
            $this->output->writeln("{$newIndent}<fg=green>✓ APPROVED</>");
        } else {
            $verdict = is_array($data) ? ($data['verdict'] ?? 'needs_changes') : 'needs_changes';
            $this->output->writeln("{$newIndent}<fg=yellow>⟳ {$verdict}</>");
        }
    }

    private function handleSummary(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }

        $saved = number_format($data['tokens_saved'] ?? 0);
        $count = $data['messages_summarized'] ?? 0;
        $auto = ($data['auto'] ?? false) ? ' (auto)' : '';

        $this->output->writeln(
            "{$indent}<fg=yellow>📋 Conversation summarized{$auto}: {$count} messages compressed, {$saved} tokens saved</>",
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function formatArguments(array $arguments): string
    {
        $parts = [];

        foreach ($arguments as $key => $value) {
            if (is_string($value)) {
                $display = strlen($value) > 40 ? substr($value, 0, 37) . '...' : $value;
                $display = str_replace(["\n", "\r"], ' ', $display);
                $parts[] = "{$key}: \"{$display}\"";
            } elseif (is_bool($value)) {
                $parts[] = "{$key}: " . ($value ? 'true' : 'false');
            } elseif (is_numeric($value)) {
                $parts[] = "{$key}: {$value}";
            } elseif (is_array($value)) {
                $parts[] = "{$key}: [...]";
            }
        }

        return implode(', ', $parts);
    }

    public function increaseIndent(): void
    {
        $this->indentLevel++;
    }

    public function decreaseIndent(): void
    {
        $this->indentLevel = max(0, $this->indentLevel - 1);
    }
}
