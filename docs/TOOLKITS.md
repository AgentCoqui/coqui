# Coqui Toolkits

A toolkit is a group of related tools that extend Coqui's capabilities. Toolkits are Composer packages that implement `ToolkitInterface` — they are auto-discovered at boot, their tools are registered with the agent, and their guidelines are injected into the system prompt.

This guide covers everything you need to create, test, and distribute your own toolkits.

## Table of Contents

- [What Is a Toolkit?](#what-is-a-toolkit)
- [Quick Start](#quick-start)
- [Anatomy of a Toolkit](#anatomy-of-a-toolkit)
- [Creating Your First Toolkit](#creating-your-first-toolkit)
- [Adding Tools](#adding-tools)
- [Parameter Types](#parameter-types)
- [Tool Results](#tool-results)
- [Managing Dependencies](#managing-dependencies)
- [Credential Management](#credential-management)
- [Auto-Discovery](#auto-discovery)
- [MCP Toolkits](#mcp-toolkits)
- [Testing](#testing)
- [Best Practices](#best-practices)
- [API Reference](#api-reference)
- [Examples](#examples)

## What Is a Toolkit?

A toolkit is a Composer package that:

1. Implements `ToolkitInterface` from `carmelosantana/php-agents`
2. Declares itself in `composer.json` via `extra.php-agents.toolkits`
3. Returns one or more `Tool` instances from its `tools()` method
4. Provides usage guidelines via its `guidelines()` method

When Coqui boots, it scans all installed packages for toolkit declarations. Discovered toolkits are instantiated, their tools are registered with the orchestrator agent, and their guidelines are appended to the system prompt. This happens automatically — no manual registration needed.

```
┌─────────────────────────────────────────────────────┐
│                   Coqui Boot                        │
│                                                     │
│  1. Scan vendor/composer/installed.json             │
│  2. Find packages with extra.php-agents.toolkits    │
│  3. Instantiate toolkit classes                     │
│  4. Wrap with CredentialGuardToolkit (if needed)     │
│  5. Register tools with OrchestratorAgent           │
│  6. Inject guidelines into system prompt            │
└─────────────────────────────────────────────────────┘
```

## Quick Start

A toolkit is a normal Composer package with `extra.php-agents.toolkits` metadata.
Create the package structure manually, declare the toolkit class in `composer.json`, then install it into the target Coqui workspace with Composer.

## Anatomy of a Toolkit

A toolkit package has this structure:

```
my-toolkit/
├── composer.json          # Package manifest with auto-discovery
├── README.md              # Documentation
└── src/
    └── MyToolkit.php      # Main toolkit class
```

### composer.json

The `extra.php-agents` section is what makes a package a toolkit:

```json
{
    "name": "coquibot/coqui-toolkit-my-toolkit",
    "description": "My awesome toolkit for Coqui",
    "type": "library",
    "license": "MIT",
    "autoload": {
        "psr-4": {
            "CoquiBot\\Toolkits\\MyToolkit\\": "src/"
        }
    },
    "require": {
        "php": "^8.4",
        "carmelosantana/php-agents": "^0.2 || @dev"
    },
    "extra": {
        "php-agents": {
            "toolkits": [
                "CoquiBot\\Toolkits\\MyToolkit\\MyToolkit"
            ]
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

**Required fields in `extra.php-agents`:**

| Field | Type | Description |
|-------|------|-------------|
| `toolkits` | `string[]` | Fully-qualified class names implementing `ToolkitInterface` |

**Optional fields:**

| Field | Type | Description |
|-------|------|-------------|
| `credentials` | `object` | Map of `KEY_NAME` → description for required credentials |
| `description` | `string` | Human-readable description of the toolkit |
| `skills` | `string` | Relative path to a directory containing skill subdirectories |
| `roles` | `string` | Relative path to a directory containing `.md` role files (auto-seeded to workspace on boot) |
| `loops` | `string` | Relative path to a directory containing `.json` loop definitions (auto-seeded to workspace on boot) |
| `gated` | `object` | Map of tool names → gating rules for destructive operation confirmation |

### Toolkit Class

The toolkit class implements two methods:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\MyToolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

final class MyToolkit implements ToolkitInterface
{
    /**
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        return [
            $this->myTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <MY-TOOLKIT-GUIDELINES>
            - Use my_tool when the user asks to do something specific.
            - Keep guidelines concise — every token counts.
            </MY-TOOLKIT-GUIDELINES>
            GUIDELINES;
    }

    private function myTool(): ToolInterface
    {
        return new Tool(
            name: 'my_tool',
            description: 'Does something useful.',
            parameters: [
                new StringParameter('input', 'The input to process'),
            ],
            callback: function (array $input): ToolResult {
                $value = $input['input'] ?? '';

                return ToolResult::success("Processed: {$value}");
            },
        );
    }
}
```

## Creating Your First Toolkit

Create the toolkit package manually:

1. Create the package directory:

```bash
mkdir -p workspace/packages/my-toolkit/src
```

2. Create `composer.json`:

```json
{
    "name": "coquibot/coqui-toolkit-my-toolkit",
    "description": "My custom toolkit",
    "type": "library",
    "license": "MIT",
    "autoload": {
        "psr-4": {
            "CoquiBot\\Toolkits\\MyToolkit\\": "src/"
        }
    },
    "require": {
        "php": "^8.4",
        "carmelosantana/php-agents": "^0.2 || @dev"
    },
    "extra": {
        "php-agents": {
            "toolkits": [
                "CoquiBot\\Toolkits\\MyToolkit\\MyToolkit"
            ]
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

3. Create `src/MyToolkit.php` implementing `ToolkitInterface`

4. Install and activate:

```bash
cd workspace && composer require coquibot/coqui-toolkit-my-toolkit
```

Then restart Coqui for auto-discovery to pick up the new toolkit.

## Adding Tools

Each tool is a `Tool` instance with four components:

| Component | Type | Description |
|-----------|------|-------------|
| `name` | `string` | Unique snake_case identifier (e.g. `fetch_data`) |
| `description` | `string` | What the tool does — the LLM reads this to decide when to use it |
| `parameters` | `Parameter[]` | Typed parameter declarations |
| `callback` | `Closure` | The function that executes when the tool is called |

```php
private function fetchDataTool(): ToolInterface
{
    return new Tool(
        name: 'fetch_data',
        description: 'Fetch data from the external API by query.',
        parameters: [
            new StringParameter(
                'query',
                'The search query to send to the API',
            ),
            new NumberParameter(
                'limit',
                'Maximum number of results (1-100)',
                required: false,
                integer: true,
                minimum: 1,
                maximum: 100,
            ),
        ],
        callback: function (array $input): ToolResult {
            $query = $input['query'] ?? '';

            if ($query === '') {
                return ToolResult::error('Query parameter is required.');
            }

            $limit = (int) ($input['limit'] ?? 10);

            // Your implementation here
            $results = $this->api->search($query, $limit);

            return ToolResult::success(json_encode($results, JSON_PRETTY_PRINT));
        },
    );
}
```

### Tool Naming Conventions

- Use `snake_case` for tool names
- Prefix with a namespace to avoid collisions (e.g. `brave_search`, `weather_lookup`)
- Keep names concise but descriptive
- Tool names must be unique across all installed toolkits

### Tool Descriptions

Write descriptions for the LLM, not humans. The description is the primary signal the LLM uses to decide when to invoke a tool:

```php
// Good — tells the LLM exactly when to use this tool
'Search the web using Brave Search. Returns titles, URLs, and descriptions.'

// Bad — too vague for the LLM to know when to use it
'Searches for things.'
```

## Parameter Types

All parameter classes extend `Parameter` and live in `CarmeloSantana\PHPAgents\Tool\Parameter\`.

### StringParameter

```php
new StringParameter(
    'name',                    // parameter name
    'Description of the param', // description
    required: true,             // default: true
    pattern: null,              // optional regex pattern
    maxLength: null,            // optional max length
    enum: null,                 // optional allowed values (string[])
)
```

### NumberParameter

```php
new NumberParameter(
    'count',
    'Number of results to return',
    required: false,
    integer: true,       // emit "integer" type instead of "number"
    minimum: 1,          // optional minimum value
    maximum: 100,        // optional maximum value
)
```

### BoolParameter

```php
new BoolParameter(
    'verbose',
    'Enable verbose output',
    required: false,
)
```

### EnumParameter

```php
new EnumParameter(
    'format',
    'Output format',
    values: ['json', 'text', 'csv'],  // allowed values
    required: true,
)
```

### ArrayParameter

```php
new ArrayParameter(
    'tags',
    'List of tags to filter by',
    required: false,
    items: new StringParameter('tag', 'A single tag'),  // optional item schema
)
```

### ObjectParameter

```php
new ObjectParameter(
    'options',
    'Configuration options',
    required: false,
    properties: [
        new StringParameter('format', 'Output format'),
        new BoolParameter('verbose', 'Enable verbose output'),
    ],
)
```

### Runtime Validation

php-agents now validates declared parameter constraints before your tool callback runs.
This builds on the schema declarations you were already writing for the model.

What is enforced automatically:
- required parameters must be present
- `StringParameter` can enforce `pattern`, `maxLength`, and `enum`
- `NumberParameter` can enforce `integer`, `minimum`, and `maximum`
- `EnumParameter` enforces membership in the declared values
- `ArrayParameter` validates nested item types
- `ObjectParameter` validates nested required properties and child parameter rules

Example:

```php
new Tool(
    name: 'create_project',
    description: 'Create a project',
    parameters: [
        new StringParameter('slug', 'Lowercase slug', pattern: '/^[a-z-]+$/'),
        new NumberParameter('count', 'Project count', required: false, integer: true, minimum: 1),
    ],
    callback: fn(array $args): ToolResult => ToolResult::success(
        sprintf('Created %s x%d', $args['slug'], $args['count'] ?? 1),
    ),
)
```

This means many manual guard clauses can stay focused on domain rules instead of re-checking
basic type and shape constraints. Keep custom validation inside the callback for business logic,
cross-field invariants, or checks that depend on external state.

## Tool Results

Every tool callback must return a `ToolResult`:

```php
// Success — content is returned to the LLM
return ToolResult::success('Operation completed. Result: ...');

// Error — the LLM sees this as a tool error and can retry or inform the user
return ToolResult::error('Invalid input: query parameter is required.');

// Structured JSON helper — still delivered as string content
return ToolResult::json([
    'id' => $id,
    'status' => 'created',
]);
```

`ToolResult` also supports additive metadata and display hints. These do not change the
provider-facing message format, so existing toolkits remain compatible while Coqui gains
better internal metadata for future UI or routing work.

```php
return ToolResult::success('Done')
    ->withMetadata(['phase' => 'apply'])
    ->withMimeType('text/plain')
    ->withDisplayHint('plain-text');
```

**Guidelines for results:**

- Keep success content concise — the LLM's context window is finite
- Use structured formats (JSON, markdown tables) for complex data
- Include actionable information the LLM can use in its response
- Error messages should explain what went wrong and how to fix it
- Never include secrets, credentials, or sensitive data in results

## Managing Dependencies

### Declaring Dependencies

Add dependencies to the `require` section of your toolkit's `composer.json`:

```json
{
    "require": {
        "php": "^8.4",
        "carmelosantana/php-agents": "^0.2 || @dev",
        "guzzlehttp/guzzle": "^7.0",
        "symfony/http-client": "^7.0"
    }
}
```

### Declaring Dependencies

Add toolkit dependencies directly to the generated package `composer.json`, then run `composer install` in the toolkit package directory.

### Using Packagist

Coqui has a built-in `packagist` tool for discovering packages before adding them:

```
packagist(action: "search", query: "http client")
packagist(action: "details", package: "guzzlehttp/guzzle")
packagist(action: "advisories", package: "guzzlehttp/guzzle")
```

**Recommended workflow:** search → details → advisories → add to toolkit dependencies.

### Dependency Guidelines

- Use caret `^` version constraints for stability
- Prefer PSR interfaces over concrete implementations
- Never depend on full frameworks (Laravel, Symfony framework-bundle, etc.)
- Keep dependencies minimal — each one is a maintenance burden
- Check for security advisories before adding a package

## Credential Management

Many toolkits require API keys or secrets. Coqui has a first-class credential system that handles this automatically.

### Declaring Credentials

Add credential requirements to `composer.json`:

```json
{
    "extra": {
        "php-agents": {
            "toolkits": ["CoquiBot\\MyApi\\MyApiToolkit"],
            "credentials": {
                "MY_API_KEY": "API key for MyService — get one at https://myservice.com/keys",
                "MY_API_SECRET": "API secret — found in your MyService dashboard"
            }
        }
    }
}
```

### How Credential Guards Work

When a toolkit declares credentials:

1. **`ToolkitDiscovery`** reads the credential declarations from `composer.json`
2. **`CredentialGuardToolkit`** wraps the toolkit — each tool gets a `CredentialGuardTool` decorator
3. When a tool is called with **missing credentials**, the guard intercepts and returns a structured error:
   - Lists missing credential names and descriptions
   - Provides the exact `credentials(action: "set", ...)` call syntax
   - The inner tool is never invoked
4. The LLM asks the user for the credential, saves it via the `credentials` tool
5. **`CredentialResolver::set()`** persists to `.env` AND calls `putenv()` for hot-reload
6. The next tool call succeeds immediately — no restart needed

### Implementing Credential Support

Use lazy resolution in your toolkit so hot-reload works:

```php
final class MyApiToolkit implements ToolkitInterface
{
    private readonly string $apiKey;

    public function __construct(
        string $apiKey = '',
    ) {
        $this->apiKey = $apiKey;
    }

    /**
     * Factory method for ToolkitDiscovery.
     */
    public static function fromEnv(): self
    {
        $apiKey = getenv('MY_API_KEY');

        return new self(apiKey: $apiKey !== false ? $apiKey : '');
    }

    /**
     * Resolve the API key lazily.
     *
     * Checks constructor value first, then process environment.
     * This enables hot-reload: after CredentialTool saves a key
     * via putenv(), the next call picks it up without restarting.
     */
    private function resolveApiKey(): string
    {
        if ($this->apiKey !== '') {
            return $this->apiKey;
        }

        $env = getenv('MY_API_KEY');

        return $env !== false ? $env : '';
    }

    // ... tools use $this->resolveApiKey() ...
}
```

**Key points:**

- The `fromEnv()` static factory is detected by `ToolkitDiscovery` (Strategy 1)
- Lazy resolution means credentials saved at runtime are picked up immediately
- The `CredentialGuardTool` handles the missing-credential UX — your toolkit does NOT need error messages for missing keys
- Never expose credential values in tool results

## Auto-Discovery

### How It Works

`ToolkitDiscovery` scans installed packages at boot using three strategies (in order):

| # | Strategy | When Used |
|---|----------|-----------|
| 1 | `static fromEnv(): self` | Toolkit reads config from environment (preferred for credential-based toolkits) |
| 2 | No-arg constructor | No required constructor params (simplest toolkits) |
| 3 | First param is `string` | Receives the workspace path (for file-aware toolkits) |

### Discovery Sources

Toolkits are discovered from two locations:

1. **Project vendor:** `vendor/composer/installed.json` — packages installed in the main project
2. **Workspace vendor:** `workspace/vendor/composer/installed.json` — packages installed by the bot at runtime

### The Registry

Discovered toolkits are persisted in `workspace/toolkits.json`:

```json
{
    "coquibot/coqui-toolkit-brave-search": [
        "CoquiBot\\Toolkits\\BraveSearch\\BraveSearchToolkit"
    ],
    "coquibot/coqui-toolkit-hello": [
        "CoquiBot\\Toolkits\\HelloToolkit\\HelloToolkit"
    ]
}
```

This registry is rebuilt on every boot from the actual installed packages.

### Triggering Discovery

- **Boot:** `discoverAll()` runs automatically
- **After `composer require`:** `discover($packageName)` runs for the new package
- **After `composer remove`:** `unregister($packageName)` removes the entry
- **Restart:** use `restart_coqui` to trigger a full re-boot with fresh discovery

## MCP Toolkits

MCP (Model Context Protocol) support illustrates the split between what Coqui core ships and what an optional toolkit adds on top.

**Core ships the MCP engine by default** — no toolkit required. `src/Mcp/*` (namespace `CoquiBot\Coqui\Mcp\*`) provides the client, stdio transport, JSON-RPC handling, `.workspace/mcp.json` config management, and the shared `McpManagementService` used across every surface. Per-server MCP tools are exposed to the agent as ordinary candidate toolkits (`McpServerToolkit`), so they participate in Coqui's normal budget-gated loading model — **deferred by default**, just like any other toolkit. The full HTTP API under `/api/v1/mcp` (add, remove, update, connect, test, status, tools, search) is also core and works standalone. Internal toolkits and functions can depend on the MCP runtime directly via `CoquiBot\Coqui\Mcp\McpRuntime`.

**The `coquibot/coqui-toolkit-mcp-client` package is optional** and adds only the interactive management surface on top of core's engine:

- The `mcp` agent tool (list/add/update/remove/enable/disable/promote/demote/auto/connect/disconnect/refresh/status/tools/search/test/auth)
- The `/mcp` REPL command and its help text
- Browser-based OAuth (implements `CoquiBot\Coqui\Contract\McpOAuthInterface` and registers itself with core's `McpRuntime`)

Without the toolkit installed, MCP servers can still be fully managed through the HTTP API or by editing `.workspace/mcp.json` directly — every management action core exposes works standalone, except OAuth, which requires the toolkit's implementation of `McpOAuthInterface`. This is a useful reference pattern for any toolkit author who wants to build an optional UX layer (tool + REPL command) on top of a capability that a host application already ships natively.

## Testing

Use [Pest 3.x](https://pestphp.com/) for testing toolkits.

### Basic Test Structure

```php
<?php

declare(strict_types=1);

use CoquiBot\Toolkits\MyToolkit\MyToolkit;

test('toolkit provides expected tools', function () {
    $toolkit = new MyToolkit();
    $tools = $toolkit->tools();

    expect($tools)->toHaveCount(2);

    $names = array_map(fn($t) => $t->name(), $tools);
    expect($names)->toBe(['my_search', 'my_fetch']);
});

test('guidelines contain XML tags', function () {
    $toolkit = new MyToolkit();

    expect($toolkit->guidelines())->toContain('<MY-TOOLKIT-GUIDELINES>');
});

test('search tool returns results', function () {
    $toolkit = new MyToolkit(apiKey: 'test-key');
    $tool = $toolkit->tools()[0];

    $result = $tool->execute(['query' => 'test query']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('results');
});

test('search tool rejects empty query', function () {
    $toolkit = new MyToolkit();
    $tool = $toolkit->tools()[0];

    $result = $tool->execute(['query' => '']);

    expect($result->status->value)->toBe('error');
});

test('tools produce valid function schemas', function () {
    $toolkit = new MyToolkit();

    foreach ($toolkit->tools() as $tool) {
        $schema = $tool->toFunctionSchema();

        expect($schema['type'])->toBe('function');
        expect($schema['function']['name'])->toBe($tool->name());
        expect($schema['function']['parameters']['type'])->toBe('object');
    }
});
```

### Mocking HTTP Clients

For toolkits that make HTTP requests, mock the client in tests:

```php
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

test('handles API response', function () {
    $mockResponse = new MockResponse(json_encode([
        'results' => [['title' => 'Test', 'url' => 'https://example.com']],
    ]));

    $httpClient = new MockHttpClient($mockResponse);
    $toolkit = new MyApiToolkit(apiKey: 'test', httpClient: $httpClient);

    $result = $toolkit->tools()[0]->execute(['query' => 'test']);

    expect($result->status->value)->toBe('success');
});
```

### Running Tests

```bash
# Run all tests
./vendor/bin/pest

# Run specific test file
./vendor/bin/pest tests/Unit/MyToolkitTest.php

# With static analysis
./vendor/bin/phpstan analyse
```

## Best Practices

### Code Style

- Mark toolkit classes `final` — composition over inheritance
- Use `readonly` wherever state shouldn't change
- Start every file with `declare(strict_types=1)`
- Follow PER-CS 2.0 coding style
- One class per file

### Tool Design

- **One purpose per tool.** A tool should do one thing well.
- **Validate early.** Check inputs at the top of the callback and return `ToolResult::error()` immediately.
- **Return structured data.** Use JSON or markdown for complex results.
- **Keep output concise.** Truncate large outputs to prevent context overflow.
- **Handle errors gracefully.** Catch exceptions and return descriptive `ToolResult::error()` messages.

### Guidelines

- Wrap guidelines in XML-style tags: `<MY-TOOLKIT-GUIDELINES>...</MY-TOOLKIT-GUIDELINES>`
- Keep them concise — every token reduces available context
- Tell the LLM **when** to use each tool, not just how
- Include any constraints or important behaviors

### Security

- Never hardcode secrets — use environment variables
- Never expose credential values in `ToolResult` content
- Validate all external input in tool callbacks
- Use `resolveApiKey()` with lazy `getenv()` lookup for hot-reload support
- Sanitize output that might contain user-controlled data

### Dependencies

- Minimize external dependencies — prefer PHP built-ins
- Use PSR interfaces where they exist (PSR-7, PSR-17, PSR-18)
- Never depend on full frameworks
- Use caret `^` version constraints

## API Reference

### ToolkitInterface

```php
interface ToolkitInterface
{
    /** @return ToolInterface[] */
    public function tools(): array;

    /** Usage guidelines injected into the system prompt. */
    public function guidelines(): string;
}
```

### ToolInterface

```php
interface ToolInterface
{
    /** Unique snake_case name. */
    public function name(): string;

    /** Human-readable description for the LLM. */
    public function description(): string;

    /** @return Parameter[] */
    public function parameters(): array;

    /** Execute the tool with given input. */
    public function execute(array $input): ToolResult;

    /** OpenAI-compatible function schema. */
    public function toFunctionSchema(): array;
}
```

### Tool (concrete class)

```php
final class Tool implements ToolInterface
{
    public function __construct(
        string $name,
        string $description,
        array $parameters,
        Closure $callback,          // fn(array $input): ToolResult
        int $maxTries = 3,
    );
}
```

### ToolResult

```php
final readonly class ToolResult
{
    public ToolResultStatus $status;  // Success | Error | Timeout
    public string $content;
    public ?string $callId;

    public static function success(string $content): self;
    public static function error(string $message): self;
    public function withCallId(string $callId): self;
}
```

### Parameter (abstract base)

```php
abstract readonly class Parameter
{
    public function __construct(
        public string $name,
        public string $description,
        public bool $required = true,
    );

    abstract public function toSchema(): array;
}
```

### composer.json `extra.php-agents` Schema

```json
{
    "extra": {
        "php-agents": {
            "toolkits": ["Vendor\\Package\\ClassName"],
            "credentials": {
                "KEY_NAME": "Human-readable description of the credential"
            },
            "description": "Optional toolkit description",
            "skills": "skills",
            "roles": "config/roles",
            "loops": "config/loops"
        }
    }
}
```

Roles and loops declared by packages are auto-seeded into the workspace (`workspace/roles/`, `workspace/loops/`) on every boot. Existing workspace files are never overwritten — workspace always takes priority. This uses the same copy-if-not-exists semantics as built-in role/loop seeding.

## Examples

### Hello Toolkit

The simplest possible toolkit — two tools, no dependencies, no credentials.

**Location:** [`examples/hello-toolkit/`](../examples/hello-toolkit/)

```php
final class HelloToolkit implements ToolkitInterface
{
    public function tools(): array
    {
        return [
            $this->helloWorldTool(),
            $this->helloTimeTool(),
        ];
    }

    // hello_world — returns a greeting
    // hello_time — returns current time
}
```

See the [Hello Toolkit README](../examples/hello-toolkit/README.md) for full details.

### Brave Search Toolkit

A production toolkit with credentials, HTTP client, and auto-discovery.

**Package:** `coquibot/coqui-toolkit-brave-search`

Key patterns demonstrated:
- `fromEnv()` factory method for discovery
- `resolveApiKey()` lazy credential resolution
- Credential declarations in `composer.json`
- Symfony HTTP client for API requests
- Structured JSON output formatting
- Error handling with HTTP status codes

See the [Brave Search README](https://github.com/coquibot/coqui-toolkit-brave-search) for full details.

### MCP Management Toolkit

An optional toolkit that layers a management UX (agent tool + REPL command + OAuth) on top of a capability the host application (Coqui core) already ships natively — see [MCP Toolkits](#mcp-toolkits) above for the full split.

**Package:** `coquibot/coqui-toolkit-mcp-client`

Key patterns demonstrated:
- Consuming a shared runtime object passed through discovery context (`mcp_runtime`) instead of owning the engine itself
- Registering an implementation of a core-defined contract (`McpOAuthInterface`) back into the host's runtime
- Clean no-op behavior when the expected runtime/context is absent (older or misconfigured core)
- Self-registering REPL command (see [docs/TOOLKIT-EXTENSIBILITY.md](TOOLKIT-EXTENSIBILITY.md))

See the [MCP Toolkit README](https://github.com/AgentCoqui/coqui-toolkit-mcp-client) for full details.

### Weather Toolkit

A toolkit with an HTTP dependency but no credentials (uses free APIs).

**Location:** `workspace/packages/weather-toolkit/`

Key patterns demonstrated:
- Optional constructor dependency injection (`?Client $http`)
- No `fromEnv()` needed (no credentials)
- Multiple data source fallbacks (IP geolocation + ZIP code)
- Complex data transformation in tool callback
