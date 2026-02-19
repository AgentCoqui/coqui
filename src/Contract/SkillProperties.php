<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Value object representing parsed SKILL.md frontmatter.
 *
 * Holds name, description, path, and optional license/compatibility/metadata
 * fields per the AgentSkills specification.
 */
final readonly class SkillProperties
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $path,
        public ?string $license = null,
        public ?string $compatibility = null,
        public ?string $allowedTools = null,
        public array $metadata = [],
        public bool $isPackageBundled = false,
    ) {}
}
