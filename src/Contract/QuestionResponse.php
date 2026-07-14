<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A user's answer to a QuestionRequest.
 *
 * `isValidFor()` is the single validation authority for the whole feature —
 * no other code re-implements answer validation.
 */
final readonly class QuestionResponse
{
    /**
     * @param list<string> $selected Chosen option labels (empty for free-text / Other).
     * @param ?string       $text    Free-text answer, or the "Other" value on a select.
     */
    public function __construct(
        public array $selected = [],
        public ?string $text = null,
    ) {}

    public function isValidFor(QuestionRequest $question): bool
    {
        $labels = $question->optionLabels();
        $hasText = is_string($this->text) && $this->text !== '';

        return match ($question->format) {
            QuestionFormat::FreeText => $hasText && $this->selected === [],
            QuestionFormat::SingleSelect => $this->isValidSingleSelect($labels, $hasText, $question->allowOther),
            QuestionFormat::MultiSelect => $this->isValidMultiSelect($labels, $hasText, $question->allowOther),
        };
    }

    /**
     * @param list<string> $labels
     */
    private function isValidSingleSelect(array $labels, bool $hasText, bool $allowOther): bool
    {
        $otherPath = $allowOther && $hasText && $this->selected === [];
        $optionPath = count($this->selected) === 1
            && in_array($this->selected[0], $labels, true)
            && $this->text === null;

        return $otherPath || $optionPath;
    }

    /**
     * @param list<string> $labels
     */
    private function isValidMultiSelect(array $labels, bool $hasText, bool $allowOther): bool
    {
        foreach ($this->selected as $label) {
            if (!in_array($label, $labels, true)) {
                return false;
            }
        }

        // Text is permitted only as an Other entry, and only when allowOther.
        if ($this->text !== null) {
            return $allowOther && $hasText;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $selected = [];
        foreach ($data['selected'] ?? [] as $label) {
            if (is_string($label)) {
                $selected[] = $label;
            }
        }
        $text = $data['text'] ?? null;

        return new self(
            selected: $selected,
            text: is_string($text) ? $text : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['selected' => $this->selected, 'text' => $this->text];
    }
}
