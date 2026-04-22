<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\ProfilePreferences;
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
        private ?ProfileDiscovery $profileDiscovery = null,
    ) {}

    /**
     * GET /api/v1/config/roles
     *
     * Sources from RoleResolver::toArray() which merges system roles
     * (orchestrator), config roles, and file-based discovered roles.
     */
    public function list(ServerRequestInterface $request): Response
    {
        [$profile, $profilePath, $error] = $this->resolveRequestedProfile($request);
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

        if ($profile !== null) {
            $preferences = $profilePath !== null
                ? ProfilePreferences::fromProfilePath($profilePath)
                : ProfilePreferences::empty();
            $roles = array_filter(
                $roles,
                static fn(array $role): bool => $preferences->isRoleAllowed((string) ($role['name'] ?? '')),
            );
        }

        $roles = array_values(array_map(
            fn(array $role): array => $this->normalizeRoleRecord($role, $profile, $profilePath),
            $roles,
        ));

        return Router::jsonResponse([
            'roles' => $roles,
            'count' => count($roles),
            'profile' => $profile,
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
        [$profile, $profilePath, $error] = $this->resolveRequestedProfile($request);
        if ($error instanceof Response) {
            return $error;
        }

        // Check if it's a system role (synthesized by RoleResolver)
        if ($this->roleResolver->isSystemRole($name)) {
            $roles = $this->roleResolver->toArray();
            if (isset($roles[$name])) {
                return Router::jsonResponse($this->normalizeRoleRecord($roles[$name], $profile, $profilePath));
            }
        }

        try {
            $properties = $this->roleDiscovery->getRole($name, $profilePath);
            $instructions = $this->roleDiscovery->readInstructions($name, $profilePath);
        } catch (RoleNotFoundException) {
            return Router::errorResponse(ApiErrorCode::ROLE_NOT_FOUND, "Role '{$name}' not found");
        }

        if ($profile !== null) {
            $preferences = $profilePath !== null
                ? ProfilePreferences::fromProfilePath($profilePath)
                : ProfilePreferences::empty();
            if (!$preferences->isRoleAllowed($name)) {
                return Router::errorResponse(
                    ApiErrorCode::ROLE_NOT_FOUND,
                    sprintf("Role '%s' is not available for profile '%s'", $name, $profile),
                );
            }
        }

        $data = $properties->toArray();
        $data['model'] = $this->roleResolver->resolve($name, $profile);
        $data['instructions'] = $instructions;
        $data['profile'] = $profile;
        $data['profile_override'] = $profilePath !== null && $this->roleDiscovery->getProfileRole($name, $profilePath) !== null;
        $data['selectable'] = !((bool) ($data['is_template'] ?? false));

        return Router::jsonResponse($data);
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?Response}
     */
    private function resolveRequestedProfile(ServerRequestInterface $request): array
    {
        $params = $request->getQueryParams();
        $profile = isset($params['profile']) ? strtolower(trim((string) $params['profile'])) : null;
        if ($profile === '') {
            $profile = null;
        }

        if ($profile === null) {
            return [null, null, null];
        }

        if ($this->profileDiscovery === null || !$this->profileDiscovery->profileExists($profile)) {
            return [
                $profile,
                null,
                Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown profile "%s". Use GET /api/v1/profiles to see available profiles.', $profile),
                ),
            ];
        }

        return [$profile, $this->profileDiscovery->getProfilePath($profile), null];
    }

    /**
     * @param array<string, mixed> $role
     * @return array<string, mixed>
     */
    private function normalizeRoleRecord(array $role, ?string $profile, ?string $profilePath): array
    {
        $name = (string) ($role['name'] ?? '');
        $role['model'] = $this->roleResolver->resolve($name, $profile);
        $role['profile'] = $profile;
        $role['selectable'] = !((bool) ($role['is_template'] ?? false));
        $role['profile_override'] = $profilePath !== null && !$this->roleResolver->isSystemRole($name)
            && $this->roleDiscovery->getProfileRole($name, $profilePath) !== null;

        return $role;
    }
}
