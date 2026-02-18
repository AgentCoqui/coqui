<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Exception\RoleNotFoundException;
use CoquiBot\Coqui\Exception\RoleParseException;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Role CRUD endpoints.
 *
 * GET    /api/config/roles          — list all roles with metadata
 * GET    /api/config/roles/{name}   — get role detail + instructions
 * POST   /api/config/roles          — create a new custom role
 * PATCH  /api/config/roles/{name}   — update a role
 * DELETE /api/config/roles/{name}   — delete a custom role
 */
final readonly class RoleHandler
{
    public function __construct(
        private RoleDiscovery $roleDiscovery,
        private RoleResolver $roleResolver,
    ) {}

    /**
     * GET /api/config/roles
     *
     * Sources from RoleResolver::toArray() which merges system roles
     * (orchestrator), config roles, and file-based discovered roles.
     */
    public function list(ServerRequestInterface $request): Response
    {
        $roles = $this->roleResolver->toArray();

        return Router::jsonResponse([
            'roles' => array_values($roles),
            'count' => count($roles),
        ]);
    }

    /**
     * GET /api/config/roles/{name}
     *
     * System roles (orchestrator) return metadata with editable=false.
     * Non-system roles include full instructions.
     */
    public function get(ServerRequestInterface $request, string $name): Response
    {
        // Check if it's a system role (synthesized by RoleResolver)
        if ($this->roleResolver->isSystemRole($name)) {
            $roles = $this->roleResolver->toArray();
            if (isset($roles[$name])) {
                return Router::jsonResponse($roles[$name]);
            }
        }

        try {
            $properties = $this->roleDiscovery->getRole($name);
            $instructions = $this->roleDiscovery->readInstructions($name);
        } catch (RoleNotFoundException) {
            return Router::errorResponse(ApiErrorCode::ROLE_NOT_FOUND, "Role '{$name}' not found");
        }

        $data = $properties->toArray();
        $data['model'] = $this->roleResolver->resolve($name);
        $data['instructions'] = $instructions;

        return Router::jsonResponse($data);
    }

    /**
     * POST /api/config/roles
     *
     * Body: { "name": "...", "display_name": "...", "description": "...",
     *         "access_level": "full|readonly|minimal", "instructions": "..." }
     */
    public function create(ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $name = trim((string) ($body['name'] ?? ''));
        $instructions = trim((string) ($body['instructions'] ?? ''));

        if ($name === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Field "name" is required');
        }
        if ($instructions === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Field "instructions" is required');
        }

        // Block reserved role names
        if ($this->roleDiscovery->isReservedName($name)) {
            return Router::errorResponse(ApiErrorCode::ROLE_RESERVED, "Role name '{$name}' is reserved and cannot be created");
        }

        if ($this->roleDiscovery->roleExists($name)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, "Role '{$name}' already exists");
        }

        $frontmatter = [
            'name' => $name,
            'display_name' => trim((string) ($body['display_name'] ?? ucfirst($name))),
            'description' => trim((string) ($body['description'] ?? '')),
            'access_level' => trim((string) ($body['access_level'] ?? 'readonly')),
        ];

        if (isset($body['model']) && is_string($body['model']) && $body['model'] !== '') {
            $frontmatter['model'] = $body['model'];
        }

        try {
            $properties = $this->roleDiscovery->createRole($frontmatter, $instructions);
        } catch (RoleParseException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        $data = $properties->toArray();
        $data['instructions'] = $instructions;

        return Router::jsonResponse($data, 201);
    }

    /**
     * PATCH /api/config/roles/{name}
     *
     * Body: partial update — any of { "display_name", "description",
     *       "access_level", "model", "instructions" }
     */
    public function update(ServerRequestInterface $request, string $name): Response
    {
        // Block updates to system roles
        if ($this->roleResolver->isSystemRole($name)) {
            return Router::errorResponse(ApiErrorCode::ROLE_BUILTIN, "System role '{$name}' cannot be modified");
        }

        try {
            $existing = $this->roleDiscovery->getRole($name);
        } catch (RoleNotFoundException) {
            return Router::errorResponse(ApiErrorCode::ROLE_NOT_FOUND, "Role '{$name}' not found");
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        // Merge existing frontmatter with updates
        $frontmatter = [
            'name' => $name,
            'display_name' => trim((string) ($body['display_name'] ?? $existing->displayName)),
            'description' => trim((string) ($body['description'] ?? $existing->description)),
            'access_level' => trim((string) ($body['access_level'] ?? $existing->accessLevel)),
            'is_builtin' => $existing->isBuiltin,
            'version' => $existing->version,
        ];

        if (isset($body['model']) && is_string($body['model'])) {
            $frontmatter['model'] = $body['model'];
        } elseif ($existing->model !== null) {
            $frontmatter['model'] = $existing->model;
        }

        // Use new instructions or keep existing
        $instructions = isset($body['instructions']) && is_string($body['instructions'])
            ? trim($body['instructions'])
            : $this->roleDiscovery->readInstructions($name);

        if ($instructions === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Instructions cannot be empty');
        }

        try {
            $properties = $this->roleDiscovery->updateRole($name, $frontmatter, $instructions);
        } catch (RoleParseException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        $data = $properties->toArray();
        $data['instructions'] = $instructions;

        return Router::jsonResponse($data);
    }

    /**
     * DELETE /api/config/roles/{name}
     */
    public function delete(ServerRequestInterface $request, string $name): Response
    {
        // Block deletion of system roles
        if ($this->roleResolver->isSystemRole($name)) {
            return Router::errorResponse(ApiErrorCode::ROLE_BUILTIN, "System role '{$name}' cannot be deleted");
        }

        try {
            $properties = $this->roleDiscovery->getRole($name);
        } catch (RoleNotFoundException) {
            return Router::errorResponse(ApiErrorCode::ROLE_NOT_FOUND, "Role '{$name}' not found");
        }

        if ($properties->isBuiltin) {
            return Router::errorResponse(ApiErrorCode::ROLE_BUILTIN, 'Cannot delete built-in roles');
        }

        $this->roleDiscovery->deleteRole($name);

        return Router::jsonResponse(['deleted' => true, 'name' => $name]);
    }
}
