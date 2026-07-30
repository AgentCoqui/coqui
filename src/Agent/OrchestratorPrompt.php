<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CoquiBot\Coqui\Prompt\PromptLoader;

/**
 * System prompt template for the OrchestratorAgent.
 *
 * Delegates to PromptLoader which composes the prompt from markdown
 * files in the prompts/ directory. Placeholders are substituted at
 * render time.
 */
final readonly class OrchestratorPrompt
{
    private PromptLoader $loader;

    public function __construct(
        private string $workspacePath,
        private string $availableRoles,
        private string $availableSkills = '',
        private string $storageMap = '',
        private string $timeSinceLastMessage = 'New session',
        ?string $promptsDir = null,
        /** @var list<string> Tool prompt slugs to exclude from the system prompt. */
        private array $excludeToolPromptSlugs = [],
        private ?string $personaPath = null,
    ) {
        $this->loader = new PromptLoader(
            promptsDir: $promptsDir ?? dirname(__DIR__, 2) . '/prompts',
            placeholders: [
                'workspace_path' => $this->workspacePath,
                'available_roles' => $this->availableRoles,
                'available_skills' => $this->availableSkills,
                'storage_map' => $this->storageMap,
                'current_datetime' => date('Y-m-d H:i:s (T)'),
                'time_since_last_message' => $this->timeSinceLastMessage,
            ],
            workspacePath: $this->workspacePath,
            excludeToolPromptSlugs: $this->excludeToolPromptSlugs,
            personaPath: $this->personaPath,
        );
    }

    public function render(): string
    {
        return $this->loader->buildSystemPrompt();
    }

    /**
     * Return just the soul section (core identity, personality).
     *
     * Returns null if no soul.md exists in any tier.
     */
    public function renderSoul(): ?string
    {
        return $this->loader->buildSoulContent();
    }

    /**
     * Return the backstory section (identity context, continuity markers).
     *
     * Returns null if no backstory.md exists in any tier.
     */
    public function renderBackstory(): ?string
    {
        return $this->loader->buildBackstoryContent();
    }

    /**
     * Return the context section (supplementary persona notes).
     *
     * Returns null if no context/*.md exists in the active persona.
     */
    public function renderContext(): ?string
    {
        return $this->loader->buildContextContent();
    }

    /**
     * Return the body sections (base + tools + security + done).
     */
    public function renderBody(): string
    {
        return $this->loader->buildBodyContent();
    }

    /**
     * @return array<int, array{id: string, title: string, content: string, source: string}>
     */
    public function renderSections(): array
    {
        return $this->loader->buildSystemPromptSections();
    }
}
