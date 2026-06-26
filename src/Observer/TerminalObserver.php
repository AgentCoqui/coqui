<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Observer;

use CarmeloSantana\PHPAgents\Contract\AgentInterface;
use CarmeloSantana\PHPAgents\Tool\DoneTool;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Renderer\StreamingMarkdownBuffer;
use CoquiBot\Coqui\Support\ImagePreviewService;
use CoquiBot\Coqui\Support\ImagePreviewState;
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
    private bool $statusLineVisible = false;
    private ?string $currentActorName = null;
    private readonly StreamingMarkdownBuffer $markdownBuffer;
    private readonly ImagePreviewState $imagePreviewState;
    private ?AnimatedTickCallback $tickCallback = null;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly ?ImagePreviewService $imagePreviewService = null,
    ) {
        $this->imagePreviewState = new ImagePreviewState();
        $this->markdownBuffer = new StreamingMarkdownBuffer(
            fn(string $rendered) => $this->output->write($rendered),
            $this->imagePreviewService,
            $this->imagePreviewState,
        );
    }

    /**
     * Set the animated tick callback to delegate status line rendering.
     *
     * When set, showStatusLine() updates the callback's context instead of
     * rendering its own static line, and clearStatusLine() delegates to it.
     */
    public function setTickCallback(AnimatedTickCallback $callback): void
    {
        $this->tickCallback = $callback;
    }

    public function setActorContext(?string $actorName, ?string $actorRole): void
    {
        $this->currentActorName = is_string($actorName) && $actorName !== '' ? $actorName : null;
    }

    public function update(SplSubject $subject): void
    {
        // Handle loop events from transient SplSubject
        if (method_exists($subject, 'getEventName') && method_exists($subject, 'getEventData')) {
            /** @var callable(): string $getEventName */
            $getEventName = [$subject, 'getEventName'];
            /** @var callable(): mixed $getEventData */
            $getEventData = [$subject, 'getEventData'];

            $this->handleEvent($getEventName(), $getEventData());
            return;
        }

        if (!$subject instanceof AgentInterface) {
            return;
        }

        // Access the last event via reflection or a getter if available
        // AbstractAgent exposes lastEvent() and lastEventData()
        if (!method_exists($subject, 'lastEvent') || !method_exists($subject, 'lastEventData')) {
            return;
        }

        /** @var callable(): string $lastEvent */
        $lastEvent = [$subject, 'lastEvent'];
        /** @var callable(): mixed $lastEventData */
        $lastEventData = [$subject, 'lastEventData'];

        $event = $lastEvent();
        $data = $lastEventData();

        $this->handleEvent($event, $data);
    }

    public function handleEvent(string $event, mixed $data): void
    {
        $indent = str_repeat('  ', $this->indentLevel);

        // Clear the in-place status line before writing any new output.
        // Text streaming and reasoning are excluded — they write inline
        // and the status line is already cleared before their first chunk.
        // Status updates drive the spinner directly without clearing first.
        if (!in_array($event, ['agent.text_delta', 'agent.reasoning', 'agent.status'], true)) {
            $this->clearStatusLine();
        }

        match ($event) {
            'agent.start' => (function () use ($indent): void {
                $this->hasStreamedText = false;
                $this->hasStreamedReasoning = false;
                $this->imagePreviewState->reset();
                $this->markdownBuffer->reset();
                $this->output->writeln(sprintf('%s<fg=cyan>▶ Agent started</>%s', $indent, $this->actorDisplaySuffix()));
                $this->showStatusLine();
            })(),

            'agent.iteration' => (function () use ($indent, $data): void {
                if (is_array($data)) {
                    $this->syncActorContextFromData($data);
                }

                $number = is_array($data) ? ($data['number'] ?? '?') : $data;
                if ($this->hasStreamedReasoning) {
                    $this->output->writeln('');
                    $this->hasStreamedReasoning = false;
                }
                $this->output->writeln(sprintf('%s<fg=gray>  ⟳ Iteration %s</>%s', $indent, (string) $number, $this->actorDisplaySuffix()));
                $this->showStatusLine();
            })(),

            'agent.reasoning' => $this->handleReasoningDelta($data),

            'agent.text_delta' => $this->handleTextDelta($data),

            'agent.tool_call' => $this->handleToolCall($data, $indent),

            'agent.batch_start' => $this->handleBatchStart($data, $indent),

            'agent.batch_end' => null,

            'agent.tool_result' => $this->handleToolResult($data, $indent),

            'agent.done' => $this->handleDone($data, $indent),

            'agent.error' => $this->output->writeln("{$indent}<fg=red>✗ Error: {$data}</>"),

            'agent.warning' => $this->output->writeln("{$indent}<fg=yellow>⚠ {$data}</>"),

            'agent.budget_warning' => $this->handleBudgetWarning($data, $indent),

            'agent.empty_response' => $this->handleEmptyResponse($data, $indent),

            'agent.summary' => $this->handleSummary($data, $indent),

            'agent.memory_extraction' => $this->handleMemoryExtraction($data, $indent),

            'agent.notification' => $this->handleNotification($data, $indent),

            'agent.status' => $this->handleStatus($data),

            'group_round_start' => $this->handleGroupRoundStart($data, $indent),

            'group_round_end' => $this->handleGroupRoundEnd($data, $indent),

            'child.start' => $this->handleChildStart($data, $indent),

            'child.end' => $this->handleChildEnd($indent),

            'child.review_start' => $this->handleReviewStart($data, $indent),

            'child.review_end' => $this->handleReviewEnd($data, $indent),

            'loop.start' => $this->handleLoopStart($data, $indent),
            'loop.iteration_start' => $this->handleLoopIterationStart($data, $indent),
            'loop.stage_start' => $this->handleLoopStageStart($data, $indent),
            'loop.stage_end' => $this->handleLoopStageEnd($data, $indent),
            'loop.iteration_end' => $this->handleLoopIterationEnd($data, $indent),
            'loop.complete' => $this->handleLoopComplete($data, $indent),

            default => null,
        };
    }

    private function handleEmptyResponse(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }

        $attempt = is_numeric($data['attempt'] ?? null) ? (int) $data['attempt'] : 0;
        $maxRetries = is_numeric($data['maxRetries'] ?? null) ? (int) $data['maxRetries'] : 0;
        $detail = ($data['hasReasoning'] ?? false) === true ? ' (reasoning only)' : '';

        if ($this->hasStreamedReasoning) {
            $this->output->writeln('');
            $this->hasStreamedReasoning = false;
        }

        $this->output->writeln(sprintf(
            '%s<fg=gray>  ⛭ empty response from model%s — retrying (%d/%d)</>',
            $indent,
            $detail,
            min($attempt, $maxRetries),
            $maxRetries,
        ));
    }

    private function handleReasoningDelta(mixed $data): void
    {
        if (is_array($data)) {
            $this->syncActorContextFromData($data);
            $data = is_string($data['content'] ?? null) ? $data['content'] : '';
        }

        if (!is_string($data) || $data === '') {
            return;
        }

        if (!$this->hasStreamedReasoning) {
            if ($this->tickCallback !== null) {
                $this->tickCallback->suspend();
            } else {
                $this->clearStatusLine();
            }
            $this->hasStreamedReasoning = true;
            $this->output->write('<fg=gray>  ⛭' . $this->actorPlainSuffix() . ' </>');
        }

        $this->output->write('<fg=gray>' . $data . '</>');
    }

    private function handleBatchStart(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }

        $count = $data['count'] ?? 0;
        $tools = $data['tools'] ?? [];
        $toolList = implode(', ', $tools);
        $this->output->writeln("{$indent}<fg=magenta>⚡ Running {$count} tools concurrently: {$toolList}</>");
        $this->showStatusLine("{$count} tools concurrently");
    }

    private function handleToolCall(mixed $data, string $indent): void
    {
        if (is_array($data)) {
            $this->syncActorContextFromData($data);
            $data = $data['tool_call'] ?? null;
        }

        if (!$data instanceof ToolCall) {
            return;
        }

        // When the done tool fires, flush any remaining buffered content
        // immediately so single-line responses appear before the done
        // confirmation rather than waiting for handleDone().
        if ($data->name === DoneTool::NAME) {
            if ($this->hasStreamedReasoning) {
                $this->output->writeln('');
                $this->hasStreamedReasoning = false;
            }
            if ($this->hasStreamedText) {
                $this->markdownBuffer->flush();
                $this->output->writeln('');
                $this->hasStreamedText = false;
            }
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
        $this->output->writeln(sprintf(
            '%s<fg=gray>  ▸ Using%s:</> <fg=yellow>%s</><fg=gray>(%s)</>',
            $indent,
            $this->actorPlainSuffix(),
            $data->name,
            $args,
        ));
        $this->showStatusLine($data->name);
    }

    private function handleToolResult(mixed $data, string $indent): void
    {
        if (is_array($data)) {
            $this->syncActorContextFromData($data);
            $data = $data['tool_result'] ?? null;
        }

        if (!$data instanceof ToolResult) {
            return;
        }

        $status = $data->status->value;
        $color = $status === 'success' ? 'green' : 'red';
        $icon = $status === 'success' ? '✓' : '✗';

        $imageResult = $this->buildImageToolResultDisplay($data);
        if ($imageResult !== null) {
            $this->output->writeln(sprintf(
                '%s    <fg=%s>%s</> <fg=gray>%s%s</>',
                $indent,
                $color,
                $icon,
                $imageResult['summary'],
                $this->actorPlainSuffix(),
            ));
            $this->output->writeln("{$indent}      <fg=gray>Path:</> {$imageResult['path']}");

            if (is_string($imageResult['preview']) && trim($imageResult['preview']) !== '') {
                $this->output->writeln("{$indent}      <fg=gray>Preview:</>");
                $this->writeIndentedBlock($imageResult['preview'], $indent . '      ');
            }

            $this->showStatusLine();

            return;
        }

        // Truncate content for display
        $content = $this->truncateToolResultContent($data->content);

        $this->output->writeln(sprintf(
            '%s    <fg=%s>%s</> <fg=gray>%s%s</>',
            $indent,
            $color,
            $icon,
            $content,
            $this->actorPlainSuffix(),
        ));
        $this->showStatusLine();
    }

    private function handleTextDelta(mixed $data): void
    {
        if (is_array($data)) {
            $this->syncActorContextFromData($data);
            $data = is_string($data['content'] ?? null) ? $data['content'] : '';
        }

        if (!is_string($data) || $data === '') {
            return;
        }

        // Clear the reasoning line before the first text chunk so the
        // response starts on a new line, visually separated from the thinking.
        if ($this->hasStreamedReasoning) {
            $this->output->writeln('');
            $this->hasStreamedReasoning = false;
        }

        // Suspend the spinner before the first text chunk so the periodic
        // timer stops redrawing it while text is streaming.
        if (!$this->hasStreamedText) {
            if ($this->tickCallback !== null) {
                $this->tickCallback->suspend();
            } else {
                $this->clearStatusLine();
            }
        }

        $this->hasStreamedText = true;
        $this->markdownBuffer->feed($data);
    }

    private function handleDone(mixed $data, string $indent): void
    {
        if (is_array($data)) {
            $this->syncActorContextFromData($data);
        }

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
            $this->output->writeln(sprintf(
                '%s<fg=green>✓ Done</>%s <fg=gray>%s</>',
                $indent,
                $this->actorDisplaySuffix(),
                $preview,
            ));
        } else {
            $this->output->writeln(sprintf('%s<fg=green>✓ Done</>%s', $indent, $this->actorDisplaySuffix()));
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

    private function handleBudgetWarning(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }

        $usagePercent = $data['usagePercent'] ?? 0;
        $wrapUpIterations = $data['wrapUpIterations'] ?? 2;
        $this->output->writeln(
            "{$indent}<fg=yellow;options=bold>⚠ Context budget {$usagePercent}% consumed — "
            . "{$wrapUpIterations} wrap-up iteration(s) before exit</>",
        );
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
            "{$indent}<fg=yellow>❇ Conversation summarized{$auto}: {$count} messages compressed, {$saved} tokens saved</>",
        );
        $this->showStatusLine();
    }

    private function handleMemoryExtraction(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }

        $count = (int) ($data['memories_saved'] ?? 0);
        $source = (string) ($data['source'] ?? 'unknown');

        if ($count === 0) {
            return;
        }

        $this->output->writeln(
            "{$indent}<fg=yellow>✱ Memory extraction ({$source}): {$count} " . ($count === 1 ? 'memory' : 'memories') . ' saved</>',
        );
        $this->showStatusLine();
    }

    private function handleNotification(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }

        $count = (int) ($data['count'] ?? 0);
        $source = (string) ($data['source'] ?? 'turn_inject');

        if ($count === 0) {
            return;
        }

        $label = $count === 1 ? 'notification' : 'notifications';
        $this->output->writeln(
            "{$indent}<fg=yellow>☀︎ {$count} {$label} injected ({$source})</>",
        );
        $this->showStatusLine();
    }

    private function handleStatus(mixed $data): void
    {
        if (!is_array($data)) {
            return;
        }

        $label = (string) ($data['label'] ?? '');
        $this->showStatusLine($label);
    }

    private function handleGroupRoundStart(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }

        $round = (int) ($data['round'] ?? 1);
        $maxRounds = (int) ($data['max_rounds'] ?? 1);
        $responders = is_array($data['responders'] ?? null)
            ? array_values(array_filter($data['responders'], is_string(...)))
            : [];
        $rationale = is_string($data['selection_rationale'] ?? null)
            ? trim($data['selection_rationale'])
            : '';
        $responderLabel = $responders === []
            ? 'no responders selected'
            : implode(', ', array_map(static fn(string $responder): string => '@' . $responder, $responders));

        $this->output->writeln(sprintf(
            '%s<fg=magenta>◉ Group round %d/%d</> <fg=gray>→ %s</>',
            $indent,
            $round,
            $maxRounds,
            $responderLabel,
        ));

        if ($rationale !== '') {
            $this->output->writeln(sprintf('%s  <fg=gray>↳ %s</>', $indent, $rationale));
        }
    }

    private function handleGroupRoundEnd(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }

        $nextResponders = is_array($data['next_responders'] ?? null)
            ? array_values(array_filter($data['next_responders'], is_string(...)))
            : [];

        if ($nextResponders === []) {
            return;
        }

        $this->output->writeln(sprintf(
            '%s  <fg=gray>Next up: %s</>',
            $indent,
            implode(', ', array_map(static fn(string $responder): string => '@' . $responder, $nextResponders)),
        ));
    }

    /**
     * @return array{summary: string, path: string, preview: string|null}|null
     */
    private function buildImageToolResultDisplay(ToolResult $data): ?array
    {
        if ($data->status->value !== 'success' || $this->imagePreviewService === null) {
            return null;
        }

        $payload = json_decode($data->content, true);
        if (!is_array($payload)) {
            return null;
        }

        foreach ($this->candidateImagePaths($payload) as $path) {
            if (!$this->imagePreviewService->canPreviewPath($path)) {
                continue;
            }

            try {
                $resolvedPath = $this->imagePreviewService->resolvePath($path);
            } catch (\RuntimeException) {
                continue;
            }

            $preview = null;
            if (!$this->imagePreviewState->hasRenderedPreview()) {
                $preview = $this->extractEmbeddedPreview($payload);

                if ($preview === null) {
                    try {
                        $previewPayload = $this->imagePreviewService->preview($path);
                        $resolvedPath = $previewPayload['path'];
                        $preview = is_string($previewPayload['preview'] ?? null) && trim($previewPayload['preview']) !== ''
                            ? $previewPayload['preview']
                            : null;
                    } catch (\RuntimeException) {
                        $preview = null;
                    }
                }

                if ($preview !== null && !$this->imagePreviewState->consume()) {
                    $preview = null;
                }
            }

            return [
                'summary' => $this->buildImageSummary($payload, $resolvedPath),
                'path' => $resolvedPath,
                'preview' => $preview,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function candidateImagePaths(array $payload): array
    {
        $paths = [];

        foreach (['path', 'saved_path', 'image_path'] as $key) {
            if (is_string($payload[$key] ?? null) && trim($payload[$key]) !== '') {
                $paths[] = trim($payload[$key]);
            }
        }

        $record = $payload['record'] ?? null;
        if (is_array($record) && is_string($record['path'] ?? null) && trim($record['path']) !== '') {
            $paths[] = trim($record['path']);
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildImageSummary(array $payload, string $path): string
    {
        $message = $payload['message'] ?? null;
        if (is_string($message) && trim($message) !== '') {
            return preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);
        }

        $name = basename($path);

        if (isset($payload['session'], $payload['page_id'])) {
            return 'Image captured: ' . $name;
        }

        return 'Image ready: ' . $name;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractEmbeddedPreview(array $payload): ?string
    {
        if (($payload['preview_format'] ?? null) !== 'ansi_blocks') {
            return null;
        }

        $preview = $payload['preview'] ?? null;
        if (!is_string($preview) || trim($preview) === '') {
            return null;
        }

        return $preview;
    }

    private function truncateToolResultContent(string $content): string
    {
        if (strlen($content) > 100) {
            $content = substr($content, 0, 97) . '...';
        }

        return str_replace(["\n", "\r"], ' ', $content);
    }

    private function writeIndentedBlock(string $content, string $prefix): void
    {
        $lines = preg_split("/\r\n|\n|\r/", rtrim($content, "\r\n")) ?: [$content];

        foreach ($lines as $line) {
            $this->output->writeln($prefix . $line);
        }
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

    private function handleLoopStart(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }
        $def = $data['definition'] ?? '?';
        $goal = $data['goal'] ?? '';
        $goalShort = mb_strlen($goal) > 80 ? mb_substr($goal, 0, 77) . '...' : $goal;
        $this->output->writeln('');
        $this->output->writeln("{$indent}<fg=magenta>🔄 Loop started:</> <fg=white;options=bold>{$def}</>");
        $this->output->writeln("{$indent}   <fg=gray>{$goalShort}</>");
    }

    private function handleLoopIterationStart(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }
        $iter = $data['iteration'] ?? '?';
        $this->output->writeln("{$indent}<fg=magenta>  ⟳ Iteration {$iter}</>");
    }

    private function handleLoopStageStart(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }
        $role = $data['role'] ?? '?';
        $stage = $data['stage_index'] ?? '?';
        $this->output->writeln("{$indent}<fg=cyan>    ▶ Stage {$stage}: {$role}</>");
    }

    private function handleLoopStageEnd(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }
        $role = $data['role'] ?? '?';
        $success = ($data['success'] ?? false) === true;
        $icon = $success ? '<fg=green>✓</>' : '<fg=red>✗</>';
        $this->output->writeln("{$indent}    {$icon} <fg=gray>{$role} complete</>");
    }

    private function handleLoopIterationEnd(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }
        $outcome = $data['outcome'] ?? '?';
        $color = match ($outcome) {
            'Complete' => 'green',
            'Continue' => 'yellow',
            'Failed' => 'red',
            default => 'gray',
        };
        $this->output->writeln("{$indent}  <fg={$color}>  ⟶ Iteration outcome: {$outcome}</>");
    }

    private function handleLoopComplete(mixed $data, string $indent): void
    {
        if (!is_array($data)) {
            return;
        }
        $outcome = $data['outcome'] ?? '?';
        $iterations = $data['iterations_completed'] ?? '?';
        $icon = $outcome === 'Complete' ? '✅' : ($outcome === 'Failed' ? '❌' : '⊘');
        $this->output->writeln('');
        $this->output->writeln("{$indent}<fg=magenta>{$icon} Loop finished:</> {$outcome} after {$iterations} iteration(s)");
    }

    /**
     * Show the status line at the bottom of the terminal output.
     *
     * Uses carriage return + clear to maintain a single in-place line.
     */
    private function showStatusLine(string $context = ''): void
    {
        $label = $context !== '' ? $context : 'Working';
        if ($this->currentActorName !== null) {
            $label .= sprintf(' (@%s)', $this->currentActorName);
        }

        if ($this->tickCallback !== null) {
            $this->tickCallback->resume();
            $this->tickCallback->setContext($label);
            // Force immediate redraw to eliminate gap between clearStatusLine and next timer tick
            $this->tickCallback->tick();
            return;
        }
        // \r moves to column 0, \033[K clears to end of line
        $this->output->write("\r\033[K  <fg=gray>{$label}...</> <fg=#666666>(press ESC to cancel)</>");
        $this->statusLineVisible = true;
    }

    /**
     * Clear the status line if it is currently visible.
     */
    private function clearStatusLine(): void
    {
        if ($this->tickCallback !== null) {
            $this->tickCallback->clearStatusLine();
            return;
        }
        if (!$this->statusLineVisible) {
            return;
        }
        $this->output->write("\r\033[K");
        $this->statusLineVisible = false;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncActorContextFromData(array $data): void
    {
        $this->setActorContext(
            is_string($data['actor_name'] ?? null) ? $data['actor_name'] : null,
            is_string($data['actor_role'] ?? null) ? $data['actor_role'] : null,
        );
    }

    private function actorDisplaySuffix(): string
    {
        return $this->currentActorName !== null
            ? sprintf(' <fg=gray>(@%s)</>', $this->currentActorName)
            : '';
    }

    private function actorPlainSuffix(): string
    {
        return $this->currentActorName !== null
            ? sprintf(' (@%s)', $this->currentActorName)
            : '';
    }
}
