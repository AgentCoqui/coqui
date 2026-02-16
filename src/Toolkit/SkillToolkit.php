<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\SkillParser;
use CoquiBot\Coqui\Exception\SkillNotFoundException;
use CoquiBot\Coqui\Exception\SkillValidationException;

/**
 * Toolkit providing skill management tools.
 *
 * Three tools: skill_list (browse available skills), skill_read (activate a
 * skill by loading full instructions), skill_create (scaffold a new skill
 * directory with SKILL.md).
 *
 * Follows progressive disclosure — skill_read is the activation mechanism
 * that loads full instructions into agent context.
 */
final class SkillToolkit implements ToolkitInterface
{
    private readonly SkillParser $parser;

    public function __construct(
        private readonly SkillDiscovery $discovery,
    ) {
        $this->parser = new SkillParser();
    }

    /**
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        return [
            $this->buildSkillListTool(),
            $this->buildSkillReadTool(),
            $this->buildSkillCreateTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <SKILL-GUIDELINES>
            Skills are reusable instruction sets that guide your behavior for specific tasks.

            ## Progressive Disclosure
            - At startup, only skill names and descriptions are loaded (lightweight)
            - Use `skill_read` to load full instructions ONLY when a task matches a skill
            - Follow the loaded instructions to complete the user's request

            ## When to Use Skills
            - Check the available skills listed in the system prompt
            - If a user's request matches a skill's description, use `skill_read` to activate it
            - You can combine multiple skills for complex tasks
            - If no skill matches, respond normally

            ## Creating Skills
            - Use `skill_create` to make new skills for recurring patterns
            - Keep SKILL.md under 500 lines — move reference material to separate files
            - Write descriptions that clearly state WHAT the skill does and WHEN to use it

            ## Skill Structure
            Each skill is a directory in .workspace/skills/ containing:
            - SKILL.md (required) — YAML frontmatter + markdown instructions
            - scripts/ (optional) — executable code
            - references/ (optional) — additional documentation
            - assets/ (optional) — templates, data files
            </SKILL-GUIDELINES>
            GUIDELINES;
    }

    private function buildSkillListTool(): Tool
    {
        return new Tool(
            name: 'skill_list',
            description: 'List all available skills with their names and descriptions.',
            parameters: [],
            callback: function (array $args): ToolResult {
                $skills = $this->discovery->discoverAll();

                if (empty($skills)) {
                    return ToolResult::success('No skills installed. Use skill_create to create one.');
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
            },
        );
    }

    private function buildSkillReadTool(): Tool
    {
        return new Tool(
            name: 'skill_read',
            description: 'Activate a skill by loading its full instructions. Use when a task matches a skill\'s description.',
            parameters: [
                new StringParameter('name', 'The skill name to activate (e.g. "say-hello").', required: true),
            ],
            callback: function (array $args): ToolResult {
                $name = $args['name'] ?? '';

                if ($name === '') {
                    return ToolResult::error('Skill name is required. Use skill_list to see available skills.');
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

                return ToolResult::success($body);
            },
        );
    }

    private function buildSkillCreateTool(): Tool
    {
        return new Tool(
            name: 'skill_create',
            description: 'Create a new skill with a SKILL.md file. Skills are saved to .workspace/skills/.',
            parameters: [
                new StringParameter('name', 'Kebab-case skill name (e.g. "code-review"). Must match directory name.', required: true),
                new StringParameter('description', 'What the skill does and when to use it (max 1024 chars).', required: true),
                new StringParameter('instructions', 'The markdown body content — detailed instructions the agent should follow.', required: true),
                new StringParameter('license', 'License name (e.g. "MIT", "Apache-2.0").', required: false),
                new StringParameter('compatibility', 'Environment requirements (max 500 chars).', required: false),
            ],
            callback: function (array $args): ToolResult {
                $name = $args['name'] ?? '';
                $description = $args['description'] ?? '';
                $instructions = $args['instructions'] ?? '';
                $license = $args['license'] ?? null;
                $compatibility = $args['compatibility'] ?? null;

                // Validate name format
                $nameErrors = $this->parser->validateName($name);
                if (!empty($nameErrors)) {
                    return ToolResult::error(
                        'Invalid skill name: ' . implode('; ', $nameErrors),
                    );
                }

                // Validate description length
                if ($description === '') {
                    return ToolResult::error('Description is required.');
                }

                if (strlen($description) > 1024) {
                    return ToolResult::error('Description must be at most 1024 characters.');
                }

                // Validate compatibility length
                if ($compatibility !== null && $compatibility !== '' && strlen($compatibility) > 500) {
                    return ToolResult::error('Compatibility must be at most 500 characters.');
                }

                // Build SKILL.md content
                $content = "---\n";
                $content .= "name: {$name}\n";
                $content .= "description: {$description}\n";

                if ($license !== null && $license !== '') {
                    $content .= "license: {$license}\n";
                }

                if ($compatibility !== null && $compatibility !== '') {
                    $content .= "compatibility: {$compatibility}\n";
                }

                $content .= "---\n\n";
                $content .= $instructions;

                // Ensure trailing newline
                if (!str_ends_with($content, "\n")) {
                    $content .= "\n";
                }

                // Create directory and write file
                $this->discovery->ensureSkillsDir();
                $skillDir = $this->discovery->skillsDir() . '/' . $name;

                if (is_dir($skillDir)) {
                    return ToolResult::error(
                        sprintf('Skill directory "%s" already exists. Choose a different name or delete the existing skill first.', $name),
                    );
                }

                if (!mkdir($skillDir, 0755, true)) {
                    return ToolResult::error(
                        sprintf('Failed to create skill directory: %s', $skillDir),
                    );
                }

                $skillMdPath = $skillDir . '/SKILL.md';
                if (file_put_contents($skillMdPath, $content) === false) {
                    return ToolResult::error(
                        sprintf('Failed to write SKILL.md to: %s', $skillMdPath),
                    );
                }

                // Invalidate cache so the new skill is immediately visible
                $this->discovery->invalidateCache();

                return ToolResult::success(
                    sprintf("Skill \"%s\" created successfully at:\n%s", $name, $skillDir),
                );
            },
        );
    }
}
