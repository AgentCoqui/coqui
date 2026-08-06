<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ConfigValidator;

function validator(): ConfigValidator
{
    return new ConfigValidator();
}

test('valid config passes validation', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'maxIterations' => 25,
            ],
        ],
    ];

    $errors = validator()->validate($data);

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

    $errors = validator()->validate($data);

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

    $errors = validator()->validate($data);

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

    $errors = validator()->validate($data);

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

    $errors = validator()->validate($data);

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

    $errors = validator()->validate($data);

    expect($errors)->toBeEmpty();
});

test('role toolkit budget overrides pass validation', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'roles' => [
                    'coder' => [
                        'model' => 'anthropic/claude-sonnet-4-20250514',
                        'toolkitTokenBudget' => 6000,
                        'toolkitPromotionBudgetPercent' => 45,
                    ],
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->toBeEmpty();
});

test('role toolkit budget overrides fail validation when invalid', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'roles' => [
                    'coder' => [
                        'toolkitTokenBudget' => 0,
                        'toolkitPromotionBudgetPercent' => 120,
                    ],
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->toHaveCount(2);
    expect($errors[0])->toContain('toolkitTokenBudget');
    expect($errors[1])->toContain('toolkitPromotionBudgetPercent');
});

test('default persona must be a non-empty string when configured', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'persona' => '',
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('agents.defaults.persona');
});

test('default persona accepts a valid string', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'persona' => 'caelum',
            ],
        ],
    ];

    $errors = validator()->validate($data);

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

    $errors = validator()->validate($data);

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

    $errors = validator()->validate($data);

    expect($errors)->toBeEmpty();
});

test('valid image model config passes', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => [
                    'primary' => 'openai/gpt-4o',
                    'imageModel' => 'openai/gpt-image-1.5',
                    'imageFallbacks' => [
                        'ollama/x/z-image-turbo:latest',
                    ],
                ],
            ],
        ],
        'images' => [
            'providers' => [
                'openai' => [
                    'model' => 'gpt-image-1.5',
                    'baseUrl' => 'https://api.openai.com/v1',
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->toBeEmpty();
});

test('conversation history prompt flag must be boolean', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'context' => [
                    'conversationHistoryInSystemPrompt' => 'yes',
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->toContain('agents.defaults.context.conversationHistoryInSystemPrompt must be a boolean');
});

test('conversation history prompt flag accepts boolean', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'context' => [
                    'conversationHistoryInSystemPrompt' => true,
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->toBeEmpty();
});

test('auto summarize context settings accept valid values', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'context' => [
                    'autoSummarizeMode' => 'turn',
                    'autoSummarizeThreshold' => 64,
                    'autoSummarizeTurnThreshold' => 12,
                    'autoSummarizeKeepRecent' => 10,
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->toBeEmpty();
});

test('auto summarize mode must be a supported string', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'context' => [
                    'autoSummarizeMode' => 'always',
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect(implode("\n", $errors))->toContain('agents.defaults.context.autoSummarizeMode');
});

test('auto summarize threshold must be numeric and within supported range', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'context' => [
                    'autoSummarizeThreshold' => 120,
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect(implode("\n", $errors))->toContain('agents.defaults.context.autoSummarizeThreshold');
});

test('auto summarize turn threshold must be an integer greater than zero', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'context' => [
                    'autoSummarizeTurnThreshold' => 0,
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect(implode("\n", $errors))->toContain('agents.defaults.context.autoSummarizeTurnThreshold');
});

test('auto summarize keep recent must stay within the supported range', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'context' => [
                    'autoSummarizeKeepRecent' => 25,
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect(implode("\n", $errors))->toContain('agents.defaults.context.autoSummarizeKeepRecent');
});

test('valid MCP stdio policy passes validation', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'mcp' => [
                    'allowedStdioCommands' => [
                        ['npx', '-y', '@modelcontextprotocol/server-github'],
                    ],
                    'deniedStdioCommands' => [
                        ['uvx', 'mcp-server-fetch'],
                    ],
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->toBeEmpty();
});

test('invalid MCP stdio policy fails validation', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'mcp' => [
                    'allowedStdioCommands' => [
                        'npx -y @modelcontextprotocol/server-github',
                        ['', ''],
                    ],
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->not->toBeEmpty();
    expect(implode("\n", $errors))->toContain('agents.defaults.mcp.allowedStdioCommands');
});

test('invalid image model format fails validation', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => [
                    'primary' => 'openai/gpt-4o',
                    'imageModel' => 'gpt-image-1.5',
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->not->toBeEmpty();
    expect(implode(' | ', $errors))->toContain('agents.defaults.model.imageModel');
});

test('invalid image provider baseUrl fails validation', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => [
                    'primary' => 'openai/gpt-4o',
                ],
            ],
        ],
        'images' => [
            'providers' => [
                'ollama' => [
                    'baseUrl' => 'not-a-url',
                ],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->not->toBeEmpty();
    expect(implode(' | ', $errors))->toContain('images.providers.ollama.baseUrl');
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

    $errors = validator()->validate($data);

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

    $errors = validator()->validate($data);

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

    $errors = validator()->validate($data);

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

    $errors = validator()->validate($data);

    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('blacklist');
});

test('empty config has only primary model error', function () {
    $errors = validator()->validate([]);

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

    $errors = validator()->validate($data);

    expect($errors)->toBeEmpty();
});

// ---------------------------------------------------------------
// Edit history validation
// ---------------------------------------------------------------

test('valid editHistory config passes', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'editHistory' => ['retentionDays' => 14],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->toBeEmpty();
});

test('editHistory.retentionDays must be positive integer', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'editHistory' => ['retentionDays' => 0],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->not->toBeEmpty();
    expect($errors)->toContain('agents.defaults.editHistory.retentionDays must be a positive integer');
});

test('editHistory.retentionDays rejects negative values', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'editHistory' => ['retentionDays' => -5],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->not->toBeEmpty();
});

test('editHistory must be an object', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
                'editHistory' => 'invalid',
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->toContain('agents.defaults.editHistory must be an object');
});

test('missing editHistory is valid', function () {
    $data = [
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'openai/gpt-4o'],
            ],
        ],
    ];

    $errors = validator()->validate($data);

    expect($errors)->toBeEmpty();
});

