<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CoquiBot\Coqui\Contract\SkillProperties;
use CoquiBot\Coqui\Exception\SkillNotFoundException;
use CoquiBot\Coqui\Exception\SkillParseException;

/**
 * Boot-time skill discovery.
 *
 * Scans .workspace/skills/ for directories containing SKILL.md, parses
 * metadata only (progressive disclosure). Provides name resolution, body
 * loading for activation, prompt summary generation, and cache invalidation
 * for newly created skills.
 */
final class SkillDiscovery
{
    private readonly string $skillsDir;
    private readonly SkillParser $parser;

    /** @var SkillProperties[]|null Cached discovery results */
    private ?array $discovered = null;

    public function __construct(
        string $workspacePath,
    ) {
        $this->skillsDir = rtrim($workspacePath, '/') . '/skills';
        $this->parser = new SkillParser();
    }

    /**
     * Scan the skills directory and return all valid skills.
     *
     * Only reads frontmatter (progressive disclosure). Silently skips
     * directories that lack a valid SKILL.md.
     *
     * @return SkillProperties[]
     */
    public function discoverAll(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $this->discovered = [];

        if (!is_dir($this->skillsDir)) {
            return $this->discovered;
        }

        $entries = scandir($this->skillsDir);
        if ($entries === false) {
            return $this->discovered;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $skillDir = $this->skillsDir . '/' . $entry;
            if (!is_dir($skillDir)) {
                continue;
            }

            try {
                $properties = $this->parser->readProperties($skillDir);
                $this->discovered[] = $properties;
            } catch (SkillParseException) {
                // Not a valid skill — silently skip
                continue;
            }
        }

        return $this->discovered;
    }

    /**
     * Resolve a skill name to its properties.
     *
     * @throws SkillNotFoundException If the skill name is not found.
     */
    public function getSkill(string $name): SkillProperties
    {
        foreach ($this->discoverAll() as $skill) {
            if ($skill->name === $name) {
                return $skill;
            }
        }

        throw SkillNotFoundException::forName($name);
    }

    /**
     * Return the full markdown body for a named skill.
     *
     * This is the "activation" mechanism — when the agent decides to use a skill.
     *
     * @throws SkillNotFoundException If the skill name is not found.
     */
    public function readBody(string $name): string
    {
        $skill = $this->getSkill($name);

        return $this->parser->readBody($skill->path);
    }

    /**
     * Check if a skill directory exists with a valid SKILL.md.
     */
    public function skillExists(string $name): bool
    {
        try {
            $this->getSkill($name);
            return true;
        } catch (SkillNotFoundException) {
            return false;
        }
    }

    /**
     * Return the absolute path to the skills directory.
     */
    public function skillsDir(): string
    {
        return $this->skillsDir;
    }

    /**
     * Generate the <available-skills> XML block for system prompt injection.
     *
     * Returns "No skills installed." if no skills are discovered.
     */
    public function buildPromptSummary(): string
    {
        $skills = $this->discoverAll();

        if (empty($skills)) {
            return 'No skills installed.';
        }

        $xml = "<available-skills>\n";

        foreach ($skills as $skill) {
            $xml .= "<skill>\n";
            $xml .= "<name>{$skill->name}</name>\n";
            $xml .= "<description>{$skill->description}</description>\n";
            $xml .= "</skill>\n";
        }

        $xml .= '</available-skills>';

        return $xml;
    }

    /**
     * Create the skills directory if it doesn't exist.
     *
     * Called by BootManager during initialization.
     */
    public function ensureSkillsDir(): void
    {
        if (!is_dir($this->skillsDir)) {
            mkdir($this->skillsDir, 0755, true);
        }
    }

    /**
     * Clear cached discovery results.
     *
     * Called after skill_create so new skills are visible immediately.
     */
    public function invalidateCache(): void
    {
        $this->discovered = null;
    }
}
