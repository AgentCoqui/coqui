<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\EvaluationStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

final readonly class EvaluationHandler
{
    public function __construct(
        private EvaluationStore $store,
    ) {}

    public function list(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $grade = $this->resolveGrade($params['grade'] ?? null);

        if (($params['grade'] ?? null) !== null && $grade === null) {
            return Router::errorResponse(
                ApiErrorCode::INVALID_FORMAT,
                'Invalid grade filter. Use one of: A, B, C, D, F.',
            );
        }

        $sessionId = isset($params['session_id']) && is_string($params['session_id']) && $params['session_id'] !== ''
            ? $params['session_id']
            : null;
        $limit = isset($params['limit']) && is_scalar($params['limit'])
            ? max(1, min(100, (int) $params['limit']))
            : 50;

        $evaluations = $this->store->listReadModels($grade, $sessionId, $limit);

        return Router::jsonResponse([
            'count' => count($evaluations),
            'filters' => [
                'grade' => $grade,
                'session_id' => $sessionId,
                'limit' => $limit,
            ],
            'evaluations' => array_map(
                static fn($evaluation): array => $evaluation->toSummaryArray(),
                $evaluations,
            ),
        ]);
    }

    public function get(ServerRequestInterface $request, string $id): Response
    {
        $evaluation = $this->store->getReadModel($id);
        if ($evaluation === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Evaluation not found');
        }

        return Router::jsonResponse($evaluation->toArray());
    }

    public function stats(ServerRequestInterface $request): Response
    {
        return Router::jsonResponse($this->store->getStatsReadModel()->toArray());
    }

    private function resolveGrade(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $grade = strtoupper(trim($value));

        return in_array($grade, ['A', 'B', 'C', 'D', 'F'], true) ? $grade : null;
    }
}