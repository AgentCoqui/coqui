<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

final readonly class PromptSection
{
    public function __construct(
        public string $id,
        public string $title,
        public string $content,
        public PromptSectionPriority $priority,
        public string $rationale,
        public string $decision,
        public string $group = 'instructions',
        public ?string $source = null,
        public bool $included = true,
    ) {}

    /**
     * @return array{id: string, title: string, group: string, priority: string, pinned: bool, deferrable: bool, included: bool, decision: string, rationale: string, source: string|null, tokens: int}
     */
    public function toTelemetryArray(int $tokens): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'group' => $this->group,
            'priority' => $this->priority->value,
            'pinned' => $this->priority->isPinned(),
            'deferrable' => $this->priority->isDeferrable(),
            'included' => $this->included,
            'decision' => $this->decision,
            'rationale' => $this->rationale,
            'source' => $this->source,
            'tokens' => $tokens,
        ];
    }
}