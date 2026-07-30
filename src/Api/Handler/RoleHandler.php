<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\PersonaPreferences;
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
        private ?PersonaDiscovery $personaDiscovery = null,
    ) {}

    /**
     * GET /api/v1/config/roles
     *
     * Sources from RoleResolver::toArray() which merges system roles
     * (orchestrator), config roles, and file-based discovered roles.
     */
    public function list(ServerRequestInterface $request): Response
    {
        [$persona, $personaPath, $error] = $this->resolveRequestedPersona($request);
        if ($error instanceof Response) {
            return $error;
        }

        $params = $request->getQueryParams();
        $path = $request->getUri()->getPath();
        $defaultSelectable = $path === '/api/v1/roles';
        $selectableOnly = array_key_exists('selectable', $params)
            ? filter_var($params['selectable'], FILTER_VALIDATE_BOOLEAN)
            : $defaultSelectable;

        $roles = $this->roleResolver->toArray();
        if ($selectableOnly) {
            $selectableNames = $this->roleResolver->selectableRoles();
            $roles = array_filter(
                $roles,
                static fn(array $role): bool => in_array((string) ($role['name'] ?? ''), $selectableNames, true),
            );
        }

        if ($persona !== null) {
            $preferences = $personaPath !== null
                ? PersonaPreferences::fromPersonaPath($personaPath)
                : PersonaPreferences::empty();
            $roles = array_filter(
                $roles,
                static fn(array $role): bool => $preferences->isRoleAllowed((string) ($role['name'] ?? '')),
            );
        }

        $roles = array_values(array_map(
            fn(array $role): array => $this->normalizeRoleRecord($role, $persona, $personaPath),
            $roles,
        ));

        return Router::jsonResponse([
            'roles' => $roles,
            'count' => count($roles),
            'persona' => $persona,
            'selectable_only' => $selectableOnly,
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
        [$persona, $personaPath, $error] = $this->resolveRequestedPersona($request);
        if ($error instanceof Response) {
            return $error;
        }

        // Check if it's a system role (synthesized by RoleResolver)
        if ($this->roleResolver->isSystemRole($name)) {
            $roles = $this->roleResolver->toArray();
            if (isset($roles[$name])) {
                return Router::jsonResponse($this->normalizeRoleRecord($roles[$name], $persona, $personaPath));
            }
        }

        try {
            $properties = $this->roleDiscovery->getRole($name, $personaPath);
            $instructions = $this->roleDiscovery->readInstructions($name, $personaPath);
        } catch (RoleNotFoundException) {
            return Router::errorResponse(ApiErrorCode::ROLE_NOT_FOUND, "Role '{$name}' not found");
        }

        if ($persona !== null) {
            $preferences = $personaPath !== null
                ? PersonaPreferences::fromPersonaPath($personaPath)
                : PersonaPreferences::empty();
            if (!$preferences->isRoleAllowed($name)) {
                return Router::errorResponse(
                    ApiErrorCode::ROLE_NOT_FOUND,
                    sprintf("Role '%s' is not available for persona '%s'", $name, $persona),
                );
            }
        }

        $data = $properties->toArray();
        $data['model'] = $this->roleResolver->resolve($name, $persona);
        $data['instructions'] = $instructions;
        $data['persona'] = $persona;
        $data['persona_override'] = $personaPath !== null && $this->roleDiscovery->getPersonaRole($name, $personaPath) !== null;
        $data['selectable'] = !((bool) ($data['is_template'] ?? false));

        return Router::jsonResponse($data);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?Response}
     */
    private function resolveRequestedPersona(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();
        $persona = isset($params['persona']) ? strtolower(trim((string) $params['persona'])) : null;
        if ($persona === '') {
            $persona = null;
        }

        if ($persona === null) {
            return [null, null, null];
        }

        if ($this->personaDiscovery === null || !$this->personaDiscovery->personaExists($persona)) {
            return [
                $persona,
                null,
                Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown persona "%s". Use GET /api/v1/personas to see available personas.', $persona),
                ),
            ];
        }

        return [$persona, $this->personaDiscovery->getPersonaPath($persona), null];
    }

    /**
     * @param array<string, mixed> $role
     * @return array<string, mixed>
     */
    private function normalizeRoleRecord(array $role, ?string $persona, ?string $personaPath): array
    {
        $name = (string) ($role['name'] ?? '');
        $role['model'] = $this->roleResolver->resolve($name, $persona);
        $role['persona'] = $persona;
        $role['selectable'] = !((bool) ($role['is_template'] ?? false));
        $role['persona_override'] = $personaPath !== null && !$this->roleResolver->isSystemRole($name)
            && $this->roleDiscovery->getPersonaRole($name, $personaPath) !== null;

        return $role;
    }
}
