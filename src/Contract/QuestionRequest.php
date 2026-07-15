<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * An agent's structured question to the user. One question per ask_user call (v1).
 */
final readonly class QuestionRequest
{
    /**
     * @param list<QuestionOption> $options  Required for selects, empty for free-text.
     * @param QuestionResponse      $suggested The agent's best-guess default (must be valid for this request).
     */
    public function __construct(
        public string $id,
        public string $prompt,
        public QuestionFormat $format,
        public array $options,
        public bool $allowOther,
        public QuestionResponse $suggested,
        public ?string $header = null,
    ) {
        if ($id === '') {
            throw new \InvalidArgumentException('QuestionRequest id must not be empty');
        }
        if ($prompt === '') {
            throw new \InvalidArgumentException('QuestionRequest prompt must not be empty');
        }
        if ($format->isSelect() && $options === []) {
            throw new \InvalidArgumentException('Select questions require at least one option');
        }
        if (!$format->isSelect() && $options !== []) {
            throw new \InvalidArgumentException('Free-text questions must not carry options');
        }
        if (!$suggested->isValidFor($this)) {
            throw new \InvalidArgumentException('QuestionRequest suggested answer is not valid for the question');
        }
    }

    /**
     * @return list<string>
     */
    public function optionLabels(): array
    {
        return array_map(static fn(QuestionOption $o): string => $o->label, $this->options);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $options = [];
        foreach ($data['options'] ?? [] as $opt) {
            if (is_array($opt)) {
                $options[] = QuestionOption::fromArray($opt);
            }
        }
        $header = $data['header'] ?? null;

        return new self(
            id: (string) ($data['id'] ?? ''),
            prompt: (string) ($data['prompt'] ?? ''),
            format: QuestionFormat::from((string) ($data['format'] ?? '')),
            options: $options,
            allowOther: (bool) ($data['allow_other'] ?? false),
            suggested: QuestionResponse::fromArray(is_array($data['suggested'] ?? null) ? $data['suggested'] : []),
            header: is_string($header) && $header !== '' ? $header : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'format' => $this->format->value,
            'options' => array_map(static fn(QuestionOption $o): array => $o->toArray(), $this->options),
            'allow_other' => $this->allowOther,
            'suggested' => $this->suggested->toArray(),
            'header' => $this->header,
        ];
    }
}
