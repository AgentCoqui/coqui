<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\CursorPage;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\PersonaPreferences;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Exception\RequestBodyException;
use CoquiBot\Coqui\Exception\RoleNotFoundException;
use CoquiBot\Coqui\Export\RoleProducer;
use CoquiBot\Coqui\Storage\ObjectVersionStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Role endpoints.
 *
 * GET    /api/v1/config/roles          — list all roles with metadata
 * GET    /api/v1/config/roles/{name}   — get role detail + instructions
 * PUT    /api/v1/roles/{name}          — create (If-None-Match: *) or update (If-Match: <version>) a role
 *
 * Create/update flow through PUT with optimistic-concurrency preconditions.
 * Delete stays REPL-only.
 */
final readonly class RoleHandler
{
    use DecodesRequestBody;

    /**
     * Object type key used for role version counters in ObjectVersionStore.
     */
    private const string ROLE_OBJECT_TYPE = 'role';

    public function __construct(
        private RoleDiscovery $roleDiscovery,
        private RoleResolver $roleResolver,
        private ?PersonaDiscovery $personaDiscovery = null,
        private ?ObjectVersionStore $objectVersions = null,
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

        // Declared default sort: name ascending. The role name is the stable,
        // unique cursor key.
        usort($roles, static fn(array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        $page = CursorPage::build(
            $roles,
            CursorPage::limitFrom($params['limit'] ?? null),
            static fn(array $role): string => (string) ($role['name'] ?? ''),
            CursorPage::decode(isset($params['cursor']) ? (string) $params['cursor'] : null),
        );

        return Router::jsonResponse([
            ...$page,
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
        $data['version'] = $this->roleVersion($name);
        $data['model'] = $this->roleResolver->resolve($name, $persona);
        $data['instructions'] = $instructions;
        $data['persona'] = $persona;
        $data['persona_override'] = $personaPath !== null && $this->roleDiscovery->getPersonaRole($name, $personaPath) !== null;
        $data['selectable'] = !((bool) ($data['is_template'] ?? false));

        return Router::jsonResponse($data);
    }

    /**
     * PUT /api/v1/roles/{name}
     *
     * The single write path for a role, branching on the CAP 0.5.0
     * optimistic-concurrency preconditions:
     *
     *  - `If-None-Match: *`      — create; 409 conflict if it already exists.
     *  - `If-Match: <version>`   — update; 409 version_conflict on a mismatch,
     *                              404 role_not_found if it does not exist.
     *  - neither header          — 409 conflict; a precondition is mandatory.
     *
     * The authoring body is strict (role.put.json): the server-owned `version`
     * lives in ObjectVersionStore, never in the on-disk file, so a body carrying
     * `version`/`id` is a 422 validation_error. System/reserved role names and
     * built-in role files are not writable (409 role_reserved / role_builtin).
     */
    public function put(ServerRequestInterface $request, string $name): Response
    {
        if (!$this->roleDiscovery->isValidRoleName($name)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid role name', ['name' => $name], 422);
        }

        if ($this->roleResolver->isSystemRole($name) || $this->roleDiscovery->isReservedName($name)) {
            return Router::errorResponse(
                ApiErrorCode::ROLE_RESERVED,
                sprintf('Role "%s" is system-managed and cannot be written.', $name),
                ['name' => $name],
                409,
            );
        }

        try {
            $body = $this->decodeAuthoringBody(
                $request,
                ['name', 'access_level'],
                ['model', 'toolkits', 'max_iterations', 'gate', 'instructions'],
            );
        } catch (RequestBodyException $e) {
            return Router::errorResponse($e->errorCode, $e->getMessage(), $e->details, $e->status);
        }

        $precondition = $this->readPrecondition($request);
        $current = $this->objectVersions?->current(self::ROLE_OBJECT_TYPE, $name) ?? 0;
        $exists = $this->roleDiscovery->roleExists($name);

        // A built-in role file (seeded from config/roles) is not user-writable.
        if ($exists && $this->isBuiltinRole($name)) {
            return Router::errorResponse(
                ApiErrorCode::ROLE_BUILTIN,
                sprintf('Role "%s" is built-in and cannot be modified.', $name),
                ['name' => $name],
                409,
            );
        }

        if ($precondition->isCreate) {
            if ($exists || $current !== 0) {
                return Router::errorResponse(
                    ApiErrorCode::CONFLICT,
                    sprintf('Role "%s" already exists.', $name),
                    ['name' => $name],
                    409,
                );
            }

            $saved = $this->persistRole($name, $body);
            if ($saved instanceof Response) {
                return $saved;
            }

            $this->objectVersions?->create(self::ROLE_OBJECT_TYPE, $name);

            return Router::jsonResponse($this->servedRoleWire($name), 201);
        }

        if ($precondition->expectedVersion !== null) {
            if (!$exists) {
                return Router::errorResponse(
                    ApiErrorCode::ROLE_NOT_FOUND,
                    sprintf("Role '%s' not found", $name),
                    ['name' => $name],
                    404,
                );
            }

            $currentVersion = max(1, $current);
            if ($precondition->expectedVersion !== $currentVersion) {
                return Router::errorResponse(
                    ApiErrorCode::VERSION_CONFLICT,
                    sprintf('Role "%s" has changed; expected version %d.', $name, $currentVersion),
                    ['expected_version' => $precondition->expectedVersion, 'current_version' => $currentVersion],
                    409,
                );
            }

            $saved = $this->persistRole($name, $body);
            if ($saved instanceof Response) {
                return $saved;
            }

            $this->objectVersions?->bump(self::ROLE_OBJECT_TYPE, $name);

            return Router::jsonResponse($this->servedRoleWire($name));
        }

        return Router::errorResponse(
            ApiErrorCode::CONFLICT,
            'A precondition is required: use If-None-Match: * to create or If-Match: <version> to update.',
            ['reason' => 'precondition_required'],
            409,
        );
    }

    /**
     * The served role wire: the strict role.json producer output plus the
     * server-assigned `version` from ObjectVersionStore (the file version, if
     * any, is not authoritative under CAP 0.5.0).
     *
     * @return array<string, mixed>
     */
    public function servedRoleWire(string $name): array
    {
        $wire = RoleProducer::toWire($this->roleDiscovery->getRole($name));
        $wire['version'] = $this->roleVersion($name);

        return $wire;
    }

    /**
     * Persist an authoring body to disk, or a 422 Response on a structural error.
     *
     * @param array<string, mixed> $body
     */
    private function persistRole(string $name, array $body): Response|true
    {
        try {
            $this->roleDiscovery->saveRole($name, $body);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage(), ['name' => $name], 422);
        }

        return true;
    }

    /**
     * Current role version, defaulting to 1 for a role that has never been
     * written through the versioned API.
     */
    private function roleVersion(string $name): int
    {
        $current = $this->objectVersions?->current(self::ROLE_OBJECT_TYPE, $name) ?? 0;

        return max(1, $current);
    }

    /**
     * Whether the named role exists as a built-in role file.
     */
    private function isBuiltinRole(string $name): bool
    {
        try {
            return $this->roleDiscovery->getRole($name)->isBuiltin;
        } catch (RoleNotFoundException) {
            return false;
        }
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
        $role['version'] = $this->roleVersion($name);
        $role['model'] = $this->roleResolver->resolve($name, $persona);
        $role['persona'] = $persona;
        $role['selectable'] = !((bool) ($role['is_template'] ?? false));
        $role['persona_override'] = $personaPath !== null && !$this->roleResolver->isSystemRole($name)
            && $this->roleDiscovery->getPersonaRole($name, $personaPath) !== null;

        return $role;
    }
}
