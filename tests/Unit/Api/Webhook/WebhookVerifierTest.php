<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Webhook\GenericWebhookVerifier;
use CoquiBot\Coqui\Api\Webhook\GithubWebhookVerifier;
use CoquiBot\Coqui\Api\Webhook\SlackWebhookVerifier;
use CoquiBot\Coqui\Api\Webhook\WebhookVerifierRegistry;

// --- GitHub Verifier ---

test('github verifier accepts valid signature', function () {
    $verifier = new GithubWebhookVerifier();
    $payload = '{"action":"opened"}';
    $secret = 'test-secret-123';

    $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    $result = $verifier->verify($payload, $secret, [
        'x-hub-signature-256' => $signature,
    ]);

    expect($result)->toBeTrue();
});

test('github verifier rejects invalid signature', function () {
    $verifier = new GithubWebhookVerifier();

    $result = $verifier->verify('payload', 'secret', [
        'x-hub-signature-256' => 'sha256=invalid',
    ]);

    expect($result)->toBeFalse();
});

test('github verifier rejects missing signature header', function () {
    $verifier = new GithubWebhookVerifier();

    $result = $verifier->verify('payload', 'secret', []);

    expect($result)->toBeFalse();
});

test('github verifier returns correct source type', function () {
    expect((new GithubWebhookVerifier())->sourceType())->toBe('github');
});

// --- Slack Verifier ---

test('slack verifier accepts valid signature with current timestamp', function () {
    $verifier = new SlackWebhookVerifier();
    $payload = 'token=abcdef&team_id=T123';
    $secret = 'slack-signing-secret';
    $timestamp = (string) time();

    $baseString = "v0:{$timestamp}:{$payload}";
    $signature = 'v0=' . hash_hmac('sha256', $baseString, $secret);

    $result = $verifier->verify($payload, $secret, [
        'x-slack-request-timestamp' => $timestamp,
        'x-slack-signature' => $signature,
    ]);

    expect($result)->toBeTrue();
});

test('slack verifier rejects expired timestamp', function () {
    $verifier = new SlackWebhookVerifier();
    $payload = 'test';
    $secret = 'secret';
    $timestamp = (string) (time() - 600); // 10 minutes ago

    $baseString = "v0:{$timestamp}:{$payload}";
    $signature = 'v0=' . hash_hmac('sha256', $baseString, $secret);

    $result = $verifier->verify($payload, $secret, [
        'x-slack-request-timestamp' => $timestamp,
        'x-slack-signature' => $signature,
    ]);

    expect($result)->toBeFalse();
});

test('slack verifier rejects missing headers', function () {
    $verifier = new SlackWebhookVerifier();

    expect($verifier->verify('payload', 'secret', []))->toBeFalse();
    expect($verifier->verify('payload', 'secret', ['x-slack-request-timestamp' => (string) time()]))->toBeFalse();
    expect($verifier->verify('payload', 'secret', ['x-slack-signature' => 'v0=abc']))->toBeFalse();
});

test('slack verifier returns correct source type', function () {
    expect((new SlackWebhookVerifier())->sourceType())->toBe('slack');
});

// --- Generic Verifier ---

test('generic verifier accepts x-webhook-signature header', function () {
    $verifier = new GenericWebhookVerifier();
    $payload = '{"event":"test"}';
    $secret = 'generic-secret';

    $signature = hash_hmac('sha256', $payload, $secret);

    $result = $verifier->verify($payload, $secret, [
        'x-webhook-signature' => $signature,
    ]);

    expect($result)->toBeTrue();
});

test('generic verifier accepts sha256= prefixed signature', function () {
    $verifier = new GenericWebhookVerifier();
    $payload = '{"event":"test"}';
    $secret = 'generic-secret';

    $signature = 'sha256=' . hash_hmac('sha256', $payload, $secret);

    $result = $verifier->verify($payload, $secret, [
        'x-webhook-signature' => $signature,
    ]);

    expect($result)->toBeTrue();
});

test('generic verifier accepts x-signature header', function () {
    $verifier = new GenericWebhookVerifier();
    $payload = 'data';
    $secret = 'sec';

    $signature = hash_hmac('sha256', $payload, $secret);

    $result = $verifier->verify($payload, $secret, [
        'x-signature' => $signature,
    ]);

    expect($result)->toBeTrue();
});

test('generic verifier accepts authorization bearer', function () {
    $verifier = new GenericWebhookVerifier();
    $payload = 'data';
    $secret = 'sec';

    $signature = hash_hmac('sha256', $payload, $secret);

    $result = $verifier->verify($payload, $secret, [
        'authorization' => 'Bearer ' . $signature,
    ]);

    expect($result)->toBeTrue();
});

test('generic verifier with timestamp includes it in signed payload', function () {
    $verifier = new GenericWebhookVerifier();
    $payload = 'data';
    $secret = 'sec';
    $timestamp = (string) time();

    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    $result = $verifier->verify($payload, $secret, [
        'x-webhook-signature' => $signature,
        'x-webhook-timestamp' => $timestamp,
    ]);

    expect($result)->toBeTrue();
});

test('generic verifier rejects expired timestamp', function () {
    $verifier = new GenericWebhookVerifier();
    $payload = 'data';
    $secret = 'sec';
    $timestamp = (string) (time() - 600); // 10 minutes ago

    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

    $result = $verifier->verify($payload, $secret, [
        'x-webhook-signature' => $signature,
        'x-webhook-timestamp' => $timestamp,
    ]);

    expect($result)->toBeFalse();
});

test('generic verifier rejects invalid signature', function () {
    $verifier = new GenericWebhookVerifier();

    $result = $verifier->verify('payload', 'secret', [
        'x-webhook-signature' => 'invalid',
    ]);

    expect($result)->toBeFalse();
});

test('generic verifier rejects empty headers', function () {
    $verifier = new GenericWebhookVerifier();

    $result = $verifier->verify('payload', 'secret', []);

    expect($result)->toBeFalse();
});

test('generic verifier returns correct source type', function () {
    expect((new GenericWebhookVerifier())->sourceType())->toBe('generic');
});

// --- Verifier Registry ---

test('registry returns correct verifier by source type', function () {
    $registry = new WebhookVerifierRegistry();

    expect($registry->get('github'))->toBeInstanceOf(GithubWebhookVerifier::class);
    expect($registry->get('slack'))->toBeInstanceOf(SlackWebhookVerifier::class);
    expect($registry->get('generic'))->toBeInstanceOf(GenericWebhookVerifier::class);
});

test('registry falls back to generic for unknown source', function () {
    $registry = new WebhookVerifierRegistry();

    expect($registry->get('unknown'))->toBeInstanceOf(GenericWebhookVerifier::class);
});
