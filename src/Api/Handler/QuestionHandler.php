<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Core authenticated REST surface for structured questions.
 *
 * GET  /api/v1/sessions/{id}/questions                        — list pending questions
 * POST /api/v1/sessions/{id}/questions/{questionId}/answer    — answer a question
 *
 * Both routes are core authenticated — never public. `QuestionResponse::isValidFor`
 * is the single validation authority behind the 422 decision.
 */
final class QuestionHandler
{
    use DecodesRequestBody;

    public function __construct(
        private readonly QuestionPersistence $persistence,
        private readonly SessionStorage $storage,
    ) {}

    /**
     * GET /api/v1/sessions/{id}/questions
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $questions = array_map(
            static fn($record): array => $record->toArray(),
            $this->persistence->pending($id),
        );

        return Router::jsonResponse(['questions' => $questions]);
    }

    /**
     * POST /api/v1/sessions/{id}/questions/{questionId}/answer
     */
    public function answer(ServerRequestInterface $request, string $id, string $questionId): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $record = $this->persistence->find($questionId);
        if ($record === null || $record->sessionId !== $id) {
            return Router::errorResponse(ApiErrorCode::QUESTION_NOT_FOUND, 'Question not found');
        }
        if ($record->status !== 'pending') {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Question already answered');
        }

        $body = $this->decodeJsonObjectOrNull($request) ?? [];
        $answer = QuestionResponse::fromArray($body);

        if (!$answer->isValidFor($record->request)) {
            return Router::errorResponse(ApiErrorCode::QUESTION_INVALID_ANSWER, 'Answer is not valid for this question');
        }

        if (!$this->persistence->persistAnswered($questionId, $id, $record->request, $answer)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Question could not be answered');
        }

        return Router::jsonResponse(['answered' => true]);
    }
}
