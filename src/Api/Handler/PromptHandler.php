<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Support\PromptInspectionService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * System prompt inspection endpoint.
 *
 * GET /api/v1/server/prompt — return the fully constructed system prompt
 *   along with tool and toolkit counts for the current boot configuration.
 */
final readonly class PromptHandler
{
    public function __construct(
        private PromptInspectionService $inspectionService,
    ) {}

    /**
     * GET /api/v1/server/prompt
     *
     * Returns the system prompt text the agent would receive on its next turn,
     * plus metadata about how many tools and toolkits are active.
     */
    public function get(ServerRequestInterface $request): Response
    {
        try {
            $params = $request->getQueryParams();
            $role = isset($params['role']) && is_string($params['role']) && $params['role'] !== ''
                ? $params['role']
                : null;
            $persona = isset($params['persona']) && is_string($params['persona']) && $params['persona'] !== ''
                ? $params['persona']
                : null;
            $sessionId = isset($params['session_id']) && is_string($params['session_id']) && $params['session_id'] !== ''
                ? $params['session_id']
                : null;

            return Router::jsonResponse($this->inspectionService->inspect($role, $persona, $sessionId));
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to build system prompt: ' . $e->getMessage(),
            );
        }
    }
}
