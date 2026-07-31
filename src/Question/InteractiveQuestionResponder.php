<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Question;

use CoquiBot\Coqui\Contract\QuestionFormat;
use CoquiBot\Coqui\Contract\QuestionRequest;
use CoquiBot\Coqui\Contract\QuestionResponderInterface;
use CoquiBot\Coqui\Contract\QuestionResponse;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders a question synchronously in the REPL via SymfonyStyle.
 *
 * Mirrors InteractiveApprovalPolicy's prompt-and-wait model: the answer is
 * available immediately, so this responder persists the question as asked,
 * collects a valid answer, then persists the answer before returning.
 * `QuestionResponse::isValidFor()` stays the single validation authority —
 * we re-prompt until the collected answer satisfies it (defensive; the UI
 * already constrains the choices).
 */
final class InteractiveQuestionResponder implements QuestionResponderInterface
{
    private const OTHER_LABEL = 'Other…';

    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly QuestionPersistence $persistence,
        private readonly string $sessionId,
        private readonly ?string $turnId = null,
    ) {}

    /**
     * Always returns a validated answer: the REPL prompt resolves synchronously.
     */
    public function ask(QuestionRequest $question): QuestionResponse
    {
        $this->persistence->persistAsked($this->sessionId, $question, 'interactive', $this->turnId);

        $this->io->newLine();
        if ($question->header !== null) {
            $this->io->writeln("<fg=cyan>{$question->header}</>");
        }
        $this->io->writeln("<fg=yellow>{$question->prompt}</>");

        do {
            $answer = $this->collect($question);
        } while (!$answer->isValidFor($question));

        $this->persistence->persistAnswered($question->id, $this->sessionId, $question, $answer, $this->turnId);

        return $answer;
    }

    private function collect(QuestionRequest $question): QuestionResponse
    {
        return match ($question->format) {
            QuestionFormat::FreeText => new QuestionResponse([], $this->askText($question->prompt, $question->suggested->text)),
            QuestionFormat::SingleSelect => $this->collectSingle($question),
            QuestionFormat::MultiSelect => $this->collectMulti($question),
        };
    }

    private function collectSingle(QuestionRequest $question): QuestionResponse
    {
        $labels = $question->optionLabels();
        $choices = $labels;
        if ($question->allowOther) {
            $choices[] = self::OTHER_LABEL;
        }

        $default = $question->suggested->selected[0] ?? ($question->allowOther ? self::OTHER_LABEL : $labels[0]);

        $chosen = (string) $this->io->choice($question->prompt, $choices, $default);
        if ($chosen === self::OTHER_LABEL) {
            return new QuestionResponse([], $this->askText('Your answer', $question->suggested->text));
        }

        return new QuestionResponse([$chosen]);
    }

    private function collectMulti(QuestionRequest $question): QuestionResponse
    {
        $labels = $question->optionLabels();
        $default = $question->suggested->selected === []
            ? null
            : implode(',', $question->suggested->selected);

        /** @var list<string> $picked */
        $picked = (array) $this->io->choice($question->prompt, $labels, $default, true);

        $text = null;
        if ($question->allowOther && $this->io->confirm('Add an "Other" free-text entry?', false)) {
            $text = $this->askText('Other', null);
        }

        return new QuestionResponse(array_map('strval', $picked), $text);
    }

    private function askText(string $prompt, ?string $default): string
    {
        return (string) $this->io->ask($prompt, $default);
    }
}
