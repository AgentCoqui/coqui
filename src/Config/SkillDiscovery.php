<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CoquiBot\Coqui\Contract\SkillProperties;
use CoquiBot\Coqui\Exception\SkillNotFoundException;
use CoquiBot\Coqui\Exception\SkillParseException;

/**
 * Boot-time skill discovery.
 *
 * Scans workspace/skills/ for directories containing SKILL.md, parses
 * metadata only (progressive disclosure). Also scans package-bundled skill
 * directories declared via extra.php-agents.skills in composer.json.
 * Workspace skills override package-bundled skills with the same name.
 *
 * Provides name resolution, body loading for activation, prompt summary
 * generation, and cache invalidation for newly created skills.
 */
final class SkillDiscovery
{
    private readonly string $skillsDir;
    private readonly SkillParser $parser;

    /** @var SkillProperties[]|null Cached discovery results */
    private ?array $discovered = null;

    /**
     * @param string[] $packageSkillDirs Additional directories from toolkit packages to scan for skills
     */
    public function __construct(
        string $workspacePath,
        private readonly array $packageSkillDirs = [],
    ) {
        $this->skillsDir = PathHelper::trimTrailingSlash($workspacePath) . '/skills';
        $this->parser = new SkillParser();
    }

    /**
     * Scan the skills directory and package-bundled directories for all valid skills.
     *
     * Only reads frontmatter (progressive disclosure). Silently skips
     * directories that lack a valid SKILL.md. Workspace skills override
     * package-bundled skills with the same name.
     *
     * @return SkillProperties[]
     */
    public function discoverAll(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $this->discovered = [];

        // First: scan package-bundled skill directories (lower priority)
        $packageSkills = $this->scanDirectories($this->packageSkillDirs, isPackageBundled: true);
        foreach ($packageSkills as $skill) {
            $this->discovered[] = $skill;
        }

        // Second: scan workspace skills directory (higher priority — overrides package skills)
        $workspaceSkills = $this->scanDirectories(
            is_dir($this->skillsDir) ? [$this->skillsDir] : [],
            isPackageBundled: false,
        );

        foreach ($workspaceSkills as $skill) {
            // Override any package-bundled skill with the same name
            $this->discovered = array_filter(
                $this->discovered,
                fn(SkillProperties $existing) => $existing->name !== $skill->name,
            );
            $this->discovered = array_values($this->discovered);
            $this->discovered[] = $skill;
        }

        return $this->discovered;
    }

    /**
     * Scan multiple directories for skill subdirectories containing SKILL.md.
     *
     * @param string[] $directories Directories to scan
     * @return SkillProperties[]
     */
    private function scanDirectories(array $directories, bool $isPackageBundled): array
    {
        $skills = [];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $entries = scandir($dir);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $skillDir = $dir . '/' . $entry;
                if (!is_dir($skillDir)) {
                    continue;
                }

                try {
                    $properties = $this->parser->readProperties($skillDir);

                    if ($isPackageBundled) {
                        // Reconstruct with isPackageBundled flag set
                        $properties = new SkillProperties(
                            name: $properties->name,
                            description: $properties->description,
                            path: $properties->path,
                            license: $properties->license,
                            compatibility: $properties->compatibility,
                            allowedTools: $properties->allowedTools,
                            metadata: $properties->metadata,
                            isPackageBundled: true,
                        );
                    }

                    $skills[] = $properties;
                } catch (SkillParseException) {
                    // Not a valid skill — silently skip
                    continue;
                }
            }
        }

        return $skills;
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
