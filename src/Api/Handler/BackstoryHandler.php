<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Backstory\BackstoryInspectionService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Backstory inspection endpoint.
 */
final readonly class BackstoryHandler
{
    public function __construct(
        private BackstoryInspectionService $inspectionService,
    ) {}

    public function get(ServerRequestInterface $request): Response
    {
        try {
            $params = $request->getQueryParams();
            $profile = isset($params['profile']) && is_string($params['profile']) && $params['profile'] !== ''
                ? $params['profile']
                : null;

            return Router::jsonResponse($this->inspectionService->inspect($profile));
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\Throwable $e) {
            return Router::errorResponse(
                ApiErrorCode::INTERNAL_ERROR,
                'Failed to inspect backstory: ' . $e->getMessage(),
            );
        }
    }
}