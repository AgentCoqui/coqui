<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Agent\AgentRunner;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
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
        private AgentRunner $agentRunner,
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
            $preview = $this->agentRunner->buildPromptPreview($role);

            return Router::jsonResponse([
                'prompt'            => $preview['prompt'],
                'tool_count'        => $preview['tool_count'],
                'toolkit_count'     => $preview['toolkit_count'],
                'prompt_tokens'     => $preview['prompt_tokens'],
                'tool_tokens'       => $preview['tool_tokens'],
                'total_tokens'      => $preview['total_tokens'],
                'toolkit_breakdown' => $preview['toolkit_breakdown'],
                'budget'            => $preview['budget_snapshot'],
            ]);
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to build system prompt: ' . $e->getMessage(),
            );
        }
    }
}
