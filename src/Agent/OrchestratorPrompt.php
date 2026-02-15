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
        private string $projectRoot,
        private string $availableRoles,
        ?string $promptsDir = null,
    ) {
        $this->loader = new PromptLoader(
            promptsDir: $promptsDir ?? dirname(__DIR__, 2) . '/prompts',
            placeholders: [
                'workspace_path' => $this->workspacePath,
                'project_root' => $this->projectRoot,
                'available_roles' => $this->availableRoles,
            ],
        );
    }

    public function render(): string
    {
        return $this->loader->buildSystemPrompt();
    }
}
