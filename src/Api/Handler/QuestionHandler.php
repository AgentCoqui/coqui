<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\QuestionAnswerReopener;
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
        private readonly ?QuestionAnswerReopener $reopener = null,
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
            return Router::jsonResponse(['error' => 'Question not found'], 404);
        }
        if ($record->status !== 'pending') {
            return Router::jsonResponse(['error' => 'Question already answered'], 409);
        }

        $body = $this->decodeJsonObjectOrNull($request) ?? [];
        $answer = QuestionResponse::fromArray($body);

        if (!$answer->isValidFor($record->request)) {
            return Router::jsonResponse(['error' => 'Answer is not valid for this question'], 422);
        }

        if (!$this->persistence->persistAnswered($questionId, $id, $record->request, $answer)) {
            return Router::jsonResponse(['error' => 'Question could not be answered'], 409);
        }

        if ($record->loopId !== null && $this->reopener !== null) {
            $this->reopener->reopen($record->loopId, $record->stageId, $record->request, $answer);
        }

        return Router::jsonResponse(['answered' => true]);
    }
}
