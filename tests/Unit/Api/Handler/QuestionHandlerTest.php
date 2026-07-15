<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\QuestionHandler;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\ServerRequest;

/**
 * Unit coverage for QuestionHandler BRANCH ORDERING.
 *
 * The end-to-end status/code triples (question_not_found / conflict /
 * question_invalid_answer through the router) are owned by
 * tests/Integration/Api/QuestionHandlerTest.php. This file complements it by
 * exercising the branch decisions the integration test never reaches:
 *
 *  - the SessionAccess gate short-circuits with `session_not_found` BEFORE any
 *    question lookup, so the two 404 branches emit DISTINCT codes;
 *  - that gate runs even when a matching pending question exists (ordering),
 *    and does not mutate the row.
 */
function handlerJsonRequest(string $method, array $body = []): ServerRequestInterface
{
    return new ServerRequest(
        $method,
        '/',
        ['Content-Type' => 'application/json'],
        json_encode($body) ?: '',
    );
}

test('answer distinguishes the session 404 from the question 404 via distinct codes', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $handler = new QuestionHandler($persistence, $storage);

    // Unknown session id: the SessionAccess gate answers first.
    $sessionMiss = $handler->answer(
        handlerJsonRequest('POST', ['selected' => ['pear']]),
        'no-such-session',
        'q1',
    );
    $sessionBody = json_decode((string) $sessionMiss->getBody(), true);

    // Valid session, but the question does not exist: the question lookup answers.
    $questionMiss = $handler->answer(
        handlerJsonRequest('POST', ['selected' => ['pear']]),
        $sessionId,
        'q1',
    );
    $questionBody = json_decode((string) $questionMiss->getBody(), true);

    expect($sessionMiss->getStatusCode())->toBe(404);
    expect($questionMiss->getStatusCode())->toBe(404);
    // Both are 404, but the handler branches to two different machine codes.
    expect($sessionBody['code'])->toBe('session_not_found');
    expect($questionBody['code'])->toBe('question_not_found');
    expect($sessionBody['code'])->not->toBe($questionBody['code']);
});

test('the session gate precedes the question lookup and leaves the row pending', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    // A real, answerable pending question exists under a valid session...
    $persistence->persistAsked($sessionId, sampleRequest(), 'suspending', null);
    $handler = new QuestionHandler($persistence, $storage);

    // ...but the request targets an unknown session id. Ordering guarantees the
    // session gate wins: the response is session_not_found, NOT question_not_found,
    // even though question 'q1' is pending and would otherwise be answerable.
    $response = $handler->answer(
        handlerJsonRequest('POST', ['selected' => ['pear']]),
        'unknown-session',
        'q1',
    );
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(404);
    expect($body['code'])->toBe('session_not_found');
    // The gate short-circuits with no side effects: q1 is untouched.
    expect($storage->getQuestion('q1')['status'])->toBe('pending');
});

test('list applies the same session gate and returns session_not_found for an unknown session', function () {
    $storage = new SessionStorage(':memory:');
    $persistence = new QuestionPersistence($storage);
    $handler = new QuestionHandler($persistence, $storage);

    $response = $handler->list(handlerJsonRequest('GET'), 'ghost-session');
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(404);
    expect($body['code'])->toBe('session_not_found');
});
