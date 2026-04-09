<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Credential management endpoints.
 *
 * GET    /api/v1/credentials       — list credential keys (values hidden)
 * POST   /api/v1/credentials       — set a credential
 * DELETE /api/v1/credentials/{key} — delete a credential
 */
final readonly class CredentialHandler
{
    public function __construct(
        private CredentialResolverInterface $credentialResolver,
        private ?ToolkitDiscovery $toolkitDiscovery = null,
    ) {}

    /**
     * GET /api/v1/credentials — list all credential keys.
     *
     * Values are never exposed — only existence is returned.
     */
    public function list(ServerRequestInterface $request): Response
    {
        $keys = $this->credentialResolver->keys();

        $credentials = array_map(
            fn(string $key): array => [
                'key' => $key,
                'is_set' => true,
            ],
            $keys,
        );

        return Router::jsonResponse([
            'credentials' => $credentials,
            'count' => count($credentials),
        ]);
    }

    /**
     * POST /api/v1/credentials  { "key": "NAME", "value": "secret" }
     */
    public function set(ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);

        if (
            !is_array($body)
            || !isset($body['key'])
            || !isset($body['value'])
            || trim((string) $body['key']) === ''
        ) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Missing required fields: "key" and "value"');
        }

        $key = strtoupper(trim((string) $body['key']));
        $value = (string) $body['value'];

        // Validate key format (uppercase, underscores, digits)
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
            return Router::errorResponse(ApiErrorCode::INVALID_FORMAT, 'Invalid key format. Use UPPER_SNAKE_CASE (e.g. MY_API_KEY)');
        }

        $this->credentialResolver->set($key, $value);

        return Router::jsonResponse([
            'key' => $key,
            'set' => true,
        ], 201);
    }

    /**
     * DELETE /api/v1/credentials/{key}
     */
    public function delete(ServerRequestInterface $request, string $key): Response
    {
        $key = strtoupper($key);

        if (!$this->credentialResolver->has($key)) {
            return Router::errorResponse(ApiErrorCode::CREDENTIAL_NOT_FOUND, 'Credential not found');
        }

        $this->credentialResolver->delete($key);

        return Router::jsonResponse([
            'key' => $key,
            'deleted' => true,
        ]);
    }

    /**
     * GET /api/v1/credentials/requirements — list all credential requirements from installed packages.
     *
     * Returns credential metadata (name, description, optional) merged with
     * current set-status so clients see the full picture in one call.
     */
    public function requirements(ServerRequestInterface $request): Response
    {
        if ($this->toolkitDiscovery === null) {
            return Router::jsonResponse([
                'requirements' => [],
                'count' => 0,
            ]);
        }

        $allRequirements = $this->toolkitDiscovery->collectAllCredentialRequirements();

        $requirements = [];
        foreach ($allRequirements as $requirement) {
            $requirements[] = [
                'key' => $requirement->name,
                'description' => $requirement->description,
                'optional' => $requirement->optional,
                'is_set' => $this->credentialResolver->has($requirement->name),
            ];
        }

        return Router::jsonResponse([
            'requirements' => $requirements,
            'count' => count($requirements),
        ]);
    }
}
