<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\QuestionHandler;
use CoquiBot\Coqui\Question\QuestionPersistence;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\ServerRequest;

/**
 * Build a JSON ServerRequest the way the other handler tests do
 * (see ArtifactHandlerTest): React\Http\Message\ServerRequest with an
 * application/json body.
 *
 * @param array<string, mixed> $body
 */
function questionJsonRequest(string $method, string $path, array $body = []): ServerRequestInterface
{
    return new ServerRequest(
        $method,
        $path,
        ['Content-Type' => 'application/json'],
        json_encode($body) ?: '',
    );
}

test('GET questions lists pending questions for the session', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $persistence->persistAsked($sessionId, sampleRequest(), 'suspending', null);
    $handler = new QuestionHandler($persistence, $storage);

    $response = $handler->list(questionJsonRequest('GET', "/sessions/{$sessionId}/questions"), $sessionId);
    $payload = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect(count($payload['questions']))->toBe(1);
    expect($payload['questions'][0]['id'])->toBe('q1');
    expect($payload['questions'][0]['status'])->toBe('pending');
});

test('POST answer with a valid answer resolves the question', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $persistence->persistAsked($sessionId, sampleRequest(), 'suspending', null);
    $handler = new QuestionHandler($persistence, $storage);

    $response = $handler->answer(
        questionJsonRequest('POST', "/sessions/{$sessionId}/questions/q1/answer", ['selected' => ['pear']]),
        $sessionId,
        'q1',
    );
    $payload = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($payload['answered'])->toBeTrue();
    expect($storage->getQuestion('q1')['status'])->toBe('answered');
});

test('POST answer with an invalid answer returns 422 and stays pending', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $persistence->persistAsked($sessionId, sampleRequest(), 'suspending', null);
    $handler = new QuestionHandler($persistence, $storage);

    $response = $handler->answer(
        questionJsonRequest('POST', "/sessions/{$sessionId}/questions/q1/answer", ['selected' => ['not-an-option']]),
        $sessionId,
        'q1',
    );

    $body = json_decode((string) $response->getBody(), true);
    expect($response->getStatusCode())->toBe(422);
    expect($body)->toHaveKey('code');
    expect($body['code'])->toBe('question_invalid_answer');
    expect($storage->getQuestion('q1')['status'])->toBe('pending');
});

test('POST answer to an already-answered question returns 409', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $persistence->persistAsked($sessionId, sampleRequest(), 'suspending', null);
    $handler = new QuestionHandler($persistence, $storage);
    $handler->answer(questionJsonRequest('POST', '/', ['selected' => ['pear']]), $sessionId, 'q1');

    $second = $handler->answer(questionJsonRequest('POST', '/', ['selected' => ['apple']]), $sessionId, 'q1');

    $body = json_decode((string) $second->getBody(), true);
    expect($second->getStatusCode())->toBe(409);
    expect($body)->toHaveKey('code');
    expect($body['code'])->toBe('conflict');
});

test('POST answer to an unknown question returns 404', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $handler = new QuestionHandler($persistence, $storage);

    $response = $handler->answer(
        questionJsonRequest('POST', '/', ['selected' => ['pear']]),
        $sessionId,
        'does-not-exist',
    );

    $body = json_decode((string) $response->getBody(), true);
    expect($response->getStatusCode())->toBe(404);
    expect($body)->toHaveKey('code');
    expect($body['code'])->toBe('question_not_found');
});

test('POST answer for a question belonging to another session returns 404', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $otherSessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $persistence->persistAsked($sessionId, sampleRequest(), 'suspending', null);
    $handler = new QuestionHandler($persistence, $storage);

    $response = $handler->answer(
        questionJsonRequest('POST', '/', ['selected' => ['pear']]),
        $otherSessionId,
        'q1',
    );

    $body = json_decode((string) $response->getBody(), true);
    expect($response->getStatusCode())->toBe(404);
    expect($body)->toHaveKey('code');
    expect($body['code'])->toBe('question_not_found');
    expect($storage->getQuestion('q1')['status'])->toBe('pending');
});

test('POST answer records a loop-bound question answer without reopening anything', function () {
    $storage = new SessionStorage(':memory:');
    $sessionId = $storage->createSession(modelRole: 'orchestrator', model: '');
    $persistence = new QuestionPersistence($storage);
    $persistence->persistAsked($sessionId, sampleRequest(), 'suspending', null, 'loop-1', 'stage-1');

    $handler = new QuestionHandler($persistence, $storage);

    $response = $handler->answer(
        questionJsonRequest('POST', '/', ['selected' => ['pear']]),
        $sessionId,
        'q1',
    );

    expect($response->getStatusCode())->toBe(200);
    expect($storage->getQuestion('q1')['status'])->toBe('answered');
});
