<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Prompt-budget inspection endpoint.
 */
final readonly class BudgetHandler
{
    public function __construct(
        private AgentRunner $agentRunner,
    ) {}

    public function get(ServerRequestInterface $request): Response
    {
        try {
            $params = $request->getQueryParams();
            $role = isset($params['role']) && is_string($params['role']) && $params['role'] !== ''
                ? $params['role']
                : null;

            return Router::jsonResponse($this->agentRunner->buildBudgetPreview($role)->toArray());
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to build prompt budget preview: ' . $e->getMessage(),
            );
        }
    }
}