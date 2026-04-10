<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Exception\RoleNotFoundException;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Role read-only endpoints.
 *
 * GET    /api/v1/config/roles          — list all roles with metadata
 * GET    /api/v1/config/roles/{name}   — get role detail + instructions
 *
 * Mutating operations (create, update, delete) are REPL-only.
 */
final readonly class RoleHandler
{
    public function __construct(
        private RoleDiscovery $roleDiscovery,
        private RoleResolver $roleResolver,
    ) {}

    /**
     * GET /api/v1/config/roles
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
     * GET /api/v1/config/roles/{name}
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
}
