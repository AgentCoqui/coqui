<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Contract\QuestionRecord;
use CoquiBot\Coqui\Contract\QuestionResponse;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Core authenticated REST surface for structured questions.
 *
 * GET  /api/v1/sessions/{id}/questions                        — list pending questions
 * POST /api/v1/sessions/{id}/questions/{questionId}/answer    — answer a question by id
 * POST /api/v1/sessions/{id}/turns/{turnId}/answer            — answer a turn's pending question
 *
 * All routes are core authenticated — never public, and never behind the optional
 * `questions` profile: {@see submitTurnAnswer} is the Core answer path a client
 * reaches after an SSE `question` frame (schema/sse-question.json). Both answer
 * paths share one record mechanism ({@see recordAnswer} → persistAnswered), so
 * there is a single write path. `QuestionResponse::isValidFor` is the single
 * validation authority behind the 422 decision.
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

        return $this->recordAnswer($request, $id, $record, ApiErrorCode::QUESTION_INVALID_ANSWER);
    }

    /**
     * POST /api/v1/sessions/{id}/turns/{turnId}/answer
     *
     * The Core answer path: a client that received an SSE `question` frame answers
     * the turn's blocking question here, without the optional `questions` profile.
     * Resolves the turn's question, then shares {@see recordAnswer} — the same
     * validate-and-persist write path as {@see answer}.
     *
     * Errors: no question for the turn ⇒ 404 `not_found`; already answered ⇒ 409
     * `conflict`; an invalid answer ⇒ 422 `validation_error`.
     */
    public function submitTurnAnswer(ServerRequestInterface $request, string $id, string $turnId): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $record = $this->persistence->findByTurn($id, $turnId);
        if ($record === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'No question is pending for this turn');
        }
        if ($record->status !== 'pending') {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Question already answered');
        }

        // The 422 keeps the `validation_error` code with an explicit status override
        // (validation_error otherwise maps to 400), matching submitTurnAnswer's
        // documented error coverage.
        return $this->recordAnswer($request, $id, $record, ApiErrorCode::VALIDATION_ERROR, 422);
    }

    /**
     * Validate the request body against a resolved pending question and persist the
     * answer. The SINGLE write path shared by both {@see answer} and
     * {@see submitTurnAnswer}; callers own only the resolution + status branching,
     * and supply the code (and optional HTTP status override) for an invalid answer.
     */
    private function recordAnswer(
        ServerRequestInterface $request,
        string $sessionId,
        QuestionRecord $record,
        ApiErrorCode $invalidCode,
        ?int $invalidStatus = null,
    ): Response {
        $body = $this->decodeJsonObjectOrNull($request) ?? [];
        $answer = QuestionResponse::fromArray($body);

        if (!$answer->isValidFor($record->request)) {
            return Router::errorResponse($invalidCode, 'Answer is not valid for this question', status: $invalidStatus);
        }

        if (!$this->persistence->persistAnswered($record->id, $sessionId, $record->request, $answer)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Question could not be answered');
        }

        return Router::jsonResponse(['answered' => true]);
    }
}
