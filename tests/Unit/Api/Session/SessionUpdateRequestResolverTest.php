<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Session\SessionUpdateRequest;
use CoquiBot\Coqui\Api\Session\SessionUpdateRequestResolver;
use React\Http\Message\Response;

test('resolver rejects an empty patch body with a 422 validation error', function () {
    $result = (new SessionUpdateRequestResolver())->resolve([]);

    expect($result)->toBeInstanceOf(Response::class);
    /** @var Response $result */
    expect($result->getStatusCode())->toBe(422);
    $body = json_decode((string) $result->getBody(), true);
    expect($body['code'])->toBe('validation_error');
    expect($body['details'])->toBeArray();
});

test('resolver rejects an old model_role field as an unknown key', function () {
    $result = (new SessionUpdateRequestResolver())->resolve(['model_role' => 'coder']);

    expect($result)->toBeInstanceOf(Response::class);
    /** @var Response $result */
    expect($result->getStatusCode())->toBe(422);
    $body = json_decode((string) $result->getBody(), true);
    expect($body['code'])->toBe('validation_error');
    expect($body['details']['unexpected_fields'] ?? null)->toBe(['model_role']);
});

test('resolver treats model:null as a clear (updatesModel true, model null)', function () {
    $result = (new SessionUpdateRequestResolver())->resolve(['model' => null]);

    expect($result)->toBeInstanceOf(SessionUpdateRequest::class);
    /** @var SessionUpdateRequest $result */
    expect($result->updatesModel)->toBeTrue();
    expect($result->model)->toBeNull();
});

test('resolver reads a concrete model id', function () {
    $result = (new SessionUpdateRequestResolver())->resolve(['model' => 'anthropic/claude-sonnet-4']);

    expect($result)->toBeInstanceOf(SessionUpdateRequest::class);
    /** @var SessionUpdateRequest $result */
    expect($result->updatesModel)->toBeTrue();
    expect($result->model)->toBe('anthropic/claude-sonnet-4');
});

test('resolver reads a workspace value', function () {
    $result = (new SessionUpdateRequestResolver())->resolve(['workspace' => '/work/x']);

    expect($result)->toBeInstanceOf(SessionUpdateRequest::class);
    /** @var SessionUpdateRequest $result */
    expect($result->updatesWorkspace)->toBeTrue();
    expect($result->workspace)->toBe('/work/x');
});

test('resolver leaves an omitted model untouched (updatesModel false)', function () {
    $result = (new SessionUpdateRequestResolver())->resolve(['title' => 'hello']);

    expect($result)->toBeInstanceOf(SessionUpdateRequest::class);
    /** @var SessionUpdateRequest $result */
    expect($result->updatesModel)->toBeFalse();
    expect($result->updatesTitle)->toBeTrue();
    expect($result->title)->toBe('hello');
});

test('resolver rejects a status outside the closed enum', function () {
    $result = (new SessionUpdateRequestResolver())->resolve(['status' => 'paused']);

    expect($result)->toBeInstanceOf(Response::class);
    /** @var Response $result */
    expect($result->getStatusCode())->toBe(422);
    expect(json_decode((string) $result->getBody(), true)['code'])->toBe('validation_error');
});

test('resolver reads pinned as a boolean', function () {
    $result = (new SessionUpdateRequestResolver())->resolve(['pinned' => true]);

    expect($result)->toBeInstanceOf(SessionUpdateRequest::class);
    /** @var SessionUpdateRequest $result */
    expect($result->updatesPinned)->toBeTrue();
    expect($result->pinned)->toBeTrue();
});
