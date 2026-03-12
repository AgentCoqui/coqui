<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ConfigValidator;

beforeEach(function () {
    $this->validator = new ConfigValidator();
});

test('valid config passes validation', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'maxIterations' => 25,
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->toBeEmpty();
});

test('missing primary model fails validation', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => [],
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('model.primary');
});

test('invalid model format fails validation', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'no-slash-in-name'],
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('model.primary');
});

test('valid model formats pass', function (string $model) {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => $model],
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->toBeEmpty();
})->with([
    'openai/gpt-4o',
    'anthropic/claude-sonnet-4-20250514',
    'ollama/llama3.2:latest',
    'openrouter/anthropic/claude-3.5-sonnet',
    'xai/grok-3',
]);

test('roles with invalid model format fail validation', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'roles' => [
                    'orchestrator' => 'invalid-no-slash',
                ],
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('roles.orchestrator');
});

test('valid roles pass', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'roles' => [
                    'orchestrator' => 'openai/gpt-4o',
                    'coder' => 'anthropic/claude-sonnet-4-20250514',
                ],
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->toBeEmpty();
});

test('fallbacks must be array of strings', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => [
                    'primary' => 'openai/gpt-4o',
                    'fallbacks' => 'not-an-array',
                ],
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('fallback');
});

test('valid fallbacks pass', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => [
                    'primary' => 'openai/gpt-4o',
                    'fallbacks' => [
                        'anthropic/claude-sonnet-4-20250514',
                        'ollama/llama3.2:latest',
                    ],
                ],
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->toBeEmpty();
});

test('provider baseUrl must be valid URL', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
            ],
        ],
        'models' => [
            'providers' => [
                'openai' => [
                    'baseUrl' => 'not-a-url',
                ],
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('URL');
});

test('maxIterations must be non-negative integer', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'maxIterations' => -5,
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('maxIterations');
});

test('maxIterations zero is valid (unlimited)', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'maxIterations' => 0,
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->toBeEmpty();
});

test('blacklist patterns must be valid regex', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'blacklist' => ['/[invalid-regex/'],
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('blacklist');
});

test('empty config has only primary model error', function () {
    $errors = $this->validator->validate([]);

    // Should have an error about missing primary model
    expect($errors)->not->toBeEmpty();
});

test('minimal valid config', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/llama3.2'],
            ],
        ],
    ];

    $errors = $this->validator->validate($data);

    expect($errors)->toBeEmpty();
});
