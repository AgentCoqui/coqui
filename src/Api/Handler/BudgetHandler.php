<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Budget\BudgetBreakdownProducer;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Prompt-budget inspection endpoint.
 */
final readonly class BudgetHandler
{
    public function __construct(
        private AgentRunner $agentRunner,
        private SessionStorage $storage,
    ) {}

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

            return Router::jsonResponse($this->agentRunner->buildBudgetPreview($role, $persona, $sessionId)->toArray());
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to build prompt budget preview: ' . $e->getMessage(),
            );
        }
    }

    /**
     * GET /api/v1/sessions/{id}/budget
     *
     * Session-scoped, typed budget breakdown (schema/budget-breakdown.json):
     * which system-prompt sections were included, their token cost, and what was
     * shed. The session's role/persona are resolved from the session row; the
     * session-model precedence is applied inside {@see AgentRunner::buildBudgetPreview()}.
     */
    public function session(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);
        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        try {
            $role = isset($session['model_role']) && is_string($session['model_role']) && $session['model_role'] !== ''
                ? $session['model_role']
                : null;
            $persona = isset($session['persona_id']) && is_string($session['persona_id']) && $session['persona_id'] !== ''
                ? $session['persona_id']
                : null;

            $snapshot = $this->agentRunner->buildBudgetPreview($role, $persona, $id);

            return Router::jsonResponse((new BudgetBreakdownProducer())->toWire($snapshot));
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to build budget breakdown: ' . $e->getMessage(),
            );
        }
    }
}