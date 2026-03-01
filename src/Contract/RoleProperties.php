<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Value object representing parsed role file frontmatter.
 *
 * Holds name, display name, description, access level, version, and optional
 * model overrides. The instructions (body) are loaded separately via
 * RoleDiscovery::readInstructions() for progressive disclosure.
 */
final readonly class RoleProperties
{
    public function __construct(
        public string $name,
        public string $displayName,
        public string $description,
        public string $path,
        public int $version = 1,
        public string $accessLevel = 'readonly',
        public bool $isBuiltin = false,
        public bool $isSystem = false,
        public bool $editable = true,
        public ?string $model = null,
        public ?string $titleModel = null,
        public ?string $allowedTools = null,
        public ?int $maxIterations = null,
    ) {}

    /**
     * Serialize to array for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'display_name' => $this->displayName,
            'description' => $this->description,
            'version' => $this->version,
            'access_level' => $this->accessLevel,
            'is_builtin' => $this->isBuiltin,
            'is_system' => $this->isSystem,
            'editable' => $this->editable,
            'model' => $this->model,
            'title_model' => $this->titleModel,
            'max_iterations' => $this->maxIterations,
        ];
    }
}
