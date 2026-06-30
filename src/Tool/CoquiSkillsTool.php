<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\SkillParser;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Exception\SkillNotFoundException;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use CoquiBot\ModManager\Installer\SkillInstaller;

/**
 * Unified tool for browsing and managing local skills.
 *
 * Absorbs the old SkillToolkit (list, read, create, update) and adds
 * lifecycle management from SkillInstaller (disable, enable, remove).
 * Always loaded — never budget-gated or deferred.
 *
 * Follows progressive disclosure — the `read` action is the activation
 * mechanism that loads full instructions into agent context.
 */
final class CoquiSkillsTool
{
    private readonly SkillParser $parser;

    public function __construct(
        private readonly SkillDiscovery $discovery,
        private readonly ?SkillInstaller $installer = null,
        private readonly ?SkillLifecycleStore $lifecycleStore = null,
        private readonly ?string $sessionId = null,
        private readonly ?string $turnId = null,
        private readonly ?string $agentRole = null,
    ) {
        $this->parser = new SkillParser();
    }

    public function tool(): ToolInterface
    {
        return new Tool(
            name: 'coqui_skills',
            description: 'Browse and manage local skills. '
                . 'Actions: list (show all available skills), '
                . 'read (activate a skill by loading its full instructions — progressive disclosure), '
                . 'create (scaffold a new skill with SKILL.md), '
                . 'update (modify an existing skill\'s description or instructions), '
                . 'disable (deactivate a skill without removing), '
                . 'enable (reactivate a disabled skill), '
                . 'remove (uninstall a skill).',
            parameters: [
                new EnumParameter(
                    'action',
                    'The operation to perform',
                    ['list', 'read', 'create', 'update', 'disable', 'enable', 'remove'],
                ),
                new StringParameter('name', 'Skill name in kebab-case (e.g. "code-review").', required: false),
                new StringParameter('description', 'Skill description (for create/update).', required: false),
                new StringParameter('instructions', 'Markdown instructions body (for create/update).', required: false),
                new BoolParameter('append', 'If true, append instructions instead of replacing (for update). Default: false.', required: false),
                new StringParameter('license', 'License name for create (e.g. "MIT").', required: false),
                new StringParameter('compatibility', 'Environment requirements for create (max 500 chars).', required: false),
                new BoolParameter('purge', 'Permanently delete when removing (default: false — just disables).', required: false),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? 'list');

        try {
            return match ($action) {
                'list' => $this->listSkills(),
                'read' => $this->readSkill($input),
                'create' => $this->createSkill($input),
                'update' => $this->updateSkill($input),
                'disable' => $this->disableSkill($input),
                'enable' => $this->enableSkill($input),
                'remove' => $this->removeSkill($input),
                default => ToolResult::error("Unknown action: '{$action}'. Valid: list, read, create, update, disable, enable, remove"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    // ── List ─────────────────────────────────────────────────────────

    private function listSkills(): ToolResult
    {
        $skills = $this->discovery->discoverAll();

        if (empty($skills)) {
            return ToolResult::success(
                "No skills installed. Use `coqui_skills(action: \"create\", ...)` to create one, "
                . "or `/mods search <query>` to discover community skills.",
            );
        }

        $lines = [];
        foreach ($skills as $skill) {
            $lines[] = sprintf(
                "- **%s**: %s\n  Path: %s",
                $skill->name,
                $skill->description,
                $skill->path,
            );
        }

        return ToolResult::success(
            sprintf("Found %d skill(s):\n\n%s", count($skills), implode("\n\n", $lines)),
        );
    }

    // ── Read (progressive disclosure) ────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function readSkill(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Skill name is required. Use coqui_skills(action: "list") to see available skills.');
        }

        try {
            $body = $this->discovery->readBody($name);
        } catch (SkillNotFoundException $e) {
            return ToolResult::error($e->getMessage());
        }

        if (trim($body) === '') {
            return ToolResult::success(
                sprintf('Skill "%s" has no instructions body. It may only have metadata.', $name),
            );
        }

        $this->recordUsage($name, 'read', 'coqui_skills');

        return ToolResult::success($body);
    }

    // ── Create ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function createSkill(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        $description = (string) ($input['description'] ?? '');
        $instructions = (string) ($input['instructions'] ?? '');
        $license = isset($input['license']) && $input['license'] !== '' ? (string) $input['license'] : null;
        $compatibility = isset($input['compatibility']) && $input['compatibility'] !== '' ? (string) $input['compatibility'] : null;

        $nameErrors = $this->parser->validateName($name);
        if (!empty($nameErrors)) {
            return ToolResult::error('Invalid skill name: ' . implode('; ', $nameErrors));
        }

        if ($description === '') {
            return ToolResult::error('Description is required.');
        }

        if (strlen($description) > 1024) {
            return ToolResult::error('Description must be at most 1024 characters.');
        }

        if ($compatibility !== null && strlen($compatibility) > 500) {
            return ToolResult::error('Compatibility must be at most 500 characters.');
        }

        // Build SKILL.md content
        $content = "---\n";
        $content .= "name: {$name}\n";
        $content .= "description: {$description}\n";

        if ($license !== null) {
            $content .= "license: {$license}\n";
        }

        if ($compatibility !== null) {
            $content .= "compatibility: {$compatibility}\n";
        }

        $content .= "---\n\n";
        $content .= $instructions;

        if (!str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        $this->discovery->ensureSkillsDir();
        $skillDir = $this->discovery->skillsDir() . '/' . $name;

        if (is_dir($skillDir)) {
            return ToolResult::error(
                sprintf('Skill directory "%s" already exists. Choose a different name or delete the existing skill first.', $name),
            );
        }

        if (!mkdir($skillDir, CoquiDefaults::DIRECTORY_MODE, true)) {
            return ToolResult::error(sprintf('Failed to create skill directory: %s', $skillDir));
        }

        $skillMdPath = $skillDir . '/SKILL.md';
        if (file_put_contents($skillMdPath, $content) === false) {
            return ToolResult::error(sprintf('Failed to write SKILL.md to: %s', $skillMdPath));
        }

        $this->discovery->invalidateCache();

        $this->recordUsage($name, 'create', 'coqui_skills', [
            'description' => $description,
            'license' => $license,
            'compatibility' => $compatibility,
        ]);

        return ToolResult::success(sprintf("Skill \"%s\" created successfully at:\n%s", $name, $skillDir));
    }

    // ── Update ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function updateSkill(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        $description = isset($input['description']) && $input['description'] !== '' ? (string) $input['description'] : null;
        $instructions = isset($input['instructions']) ? (string) $input['instructions'] : null;
        $append = (bool) ($input['append'] ?? false);

        if ($name === '') {
            return ToolResult::error('Skill name is required.');
        }

        if ($description === null && $instructions === null) {
            return ToolResult::error('At least one of description or instructions must be provided.');
        }

        if (!$this->discovery->skillExists($name)) {
            return ToolResult::error(
                sprintf('Skill "%s" not found. Use coqui_skills(action: "list") to see available skills.', $name),
            );
        }

        $skill = $this->discovery->getSkill($name);

        if ($skill->isPackageBundled) {
            return ToolResult::error(
                sprintf('Skill "%s" is package-bundled and cannot be updated. Create a workspace copy instead.', $name),
            );
        }

        if ($description !== null && strlen($description) > 1024) {
            return ToolResult::error('Description must be at most 1024 characters.');
        }

        $existingBody = $this->discovery->readBody($name);
        $newDescription = $description ?? $skill->description;
        $newBody = $existingBody;

        if ($instructions !== null) {
            $newBody = $append
                ? $existingBody . "\n\n" . $instructions
                : $instructions;
        }

        // Rebuild SKILL.md content
        $content = "---\n";
        $content .= "name: {$name}\n";
        $content .= "description: {$newDescription}\n";

        if ($skill->license !== null && $skill->license !== '') {
            $content .= "license: {$skill->license}\n";
        }

        if ($skill->compatibility !== null && $skill->compatibility !== '') {
            $content .= "compatibility: {$skill->compatibility}\n";
        }

        $content .= "---\n\n";
        $content .= $newBody;

        if (!str_ends_with($content, "\n")) {
            $content .= "\n";
        }

        $skillMdPath = $skill->path . '/SKILL.md';
        if (file_put_contents($skillMdPath, $content) === false) {
            return ToolResult::error(sprintf('Failed to write SKILL.md: %s', $skillMdPath));
        }

        $this->discovery->invalidateCache();

        $this->recordUsage($name, 'update', 'coqui_skills', [
            'append' => $append,
            'description_changed' => $description !== null,
            'instructions_changed' => $instructions !== null,
        ]);

        $mode = $append ? 'appended to' : 'replaced';

        return ToolResult::success(sprintf('Skill "%s" updated successfully. Instructions %s.', $name, $mode));
    }

    // ── Lifecycle ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function disableSkill(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required for disable.');
        }

        if ($this->installer === null) {
            return ToolResult::error('Skill lifecycle management is not available (mod manager toolkit not loaded).');
        }

        $message = $this->installer->disable($name);

        return ToolResult::success($message);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function enableSkill(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required for enable.');
        }

        if ($this->installer === null) {
            return ToolResult::error('Skill lifecycle management is not available (mod manager toolkit not loaded).');
        }

        $message = $this->installer->enable($name);

        return ToolResult::success($message);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function removeSkill(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required for remove.');
        }

        if ($this->installer === null) {
            return ToolResult::error('Skill lifecycle management is not available (mod manager toolkit not loaded).');
        }

        $purge = (bool) ($input['purge'] ?? false);
        $message = $this->installer->remove($name, $purge);

        return ToolResult::success($message);
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed>|null $metadata
     */
    private function recordUsage(string $skillName, string $action, string $sourceTool, ?array $metadata = null): void
    {
        $this->lifecycleStore?->recordSkillUsage(
            skillName: $skillName,
            action: $action,
            sourceTool: $sourceTool,
            sessionId: $this->sessionId,
            turnId: $this->turnId,
            agentRole: $this->agentRole,
            metadata: $metadata,
        );
    }
}
