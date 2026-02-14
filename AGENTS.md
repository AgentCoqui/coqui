# Agents.md — Coqui Project Guidelines

## Credential System Architecture

Coqui provides first-class credential management for toolkit packages. The system ensures the LLM never wastes tokens figuring out credential names or storage — everything is declarative and enforced automatically.

### How It Works

1. **Toolkit packages declare credentials** in `composer.json` via `extra.php-agents.credentials` — a map of `KEY_NAME` → `description`.
2. **`CredentialResolver`** manages the workspace `.env` file and process environment. Calling `set()` persists the value AND calls `putenv()` for immediate availability (hot-reload).
3. **`CredentialGuardToolkit`** wraps discovered toolkits whose packages declare credential requirements. Each tool is wrapped in a `CredentialGuardTool` decorator.
4. **`CredentialGuardTool`** intercepts `execute()` — if any required credential is missing, it returns a structured `ToolResult::error()` with exact key names, descriptions, and the precise `credentials` tool call syntax. The inner tool is never invoked.
5. **After the user provides the key**, the LLM calls `credentials(action: "set", key: "EXACT_NAME", value: "...")` → `CredentialTool` → `CredentialResolver::set()` → `.env` + `putenv()`. The next tool call succeeds immediately — no restart needed.

### Key Source Files

| File | Purpose |
|------|---------|  
| `src/Contract/CredentialRequirement.php` | Value object: credential name + description |
| `src/Contract/CredentialResolverInterface.php` | Interface for get/set/delete/has with hot-reload |
| `src/Config/CredentialResolver.php` | Implementation: reads workspace `.env` lazily, `set()` calls `putenv()` |
| `src/Tool/CredentialGuardTool.php` | `ToolInterface` decorator — intercepts execution when credentials missing |
| `src/Tool/CredentialGuardToolkit.php` | `ToolkitInterface` decorator — wraps all tools + appends credential status to guidelines |
| `src/Tool/CredentialTool.php` | LLM-facing CRUD tool for credentials (delegates to `CredentialResolver`) |

### For Toolkit Authors

Add credential declarations to your `composer.json`:

```json
{
    "extra": {
        "php-agents": {
            "toolkits": ["Acme\\MyToolkit\\MyToolkit"],
            "credentials": {
                "MY_API_KEY": "API key for MyService — get one at https://myservice.com/keys"
            }
        }
    }
}
```

Use lazy resolution in your toolkit so hot-reload works:

```php
private function resolveApiKey(): string
{
    if ($this->apiKey !== '') {
        return $this->apiKey;
    }
    $env = getenv('MY_API_KEY');
    return $env !== false ? $env : '';
}
```

The `CredentialGuardTool` handles the missing-credential UX — your toolkit does not need to produce its own "key not configured" errors.



## Language & Runtime

- **PHP 8.4** — use all modern features including readonly properties, enums, fibers, typed class constants, intersection types, `#[\Override]`, DNF types, property hooks, asymmetric visibility.
- **Strict types** — every PHP file starts with `declare(strict_types=1);`.
- **No large frameworks** — no Laravel, Symfony (as a framework), Laminas, etc. Individual Symfony or PSR-compliant *components* are acceptable (e.g. `symfony/http-client`, `symfony/console`).
- **Core dependency** — `carmelosantana/php-agents` provides agents, toolkits, providers, and the tool-use loop.

## Composer & Dependencies

### Rules

1. **Composer is the only package manager.** All dependencies are managed via `composer.json`.
2. **Minimize dependencies.** Before adding a package, justify it — prefer PHP built-ins and SPL.
3. **PSR standards first.** When a PSR exists for a concern (logging, HTTP, caching), depend on the PSR interface, not a concrete implementation.
4. **No framework coupling.** Never require a package that pulls in a full framework as a transitive dependency.
5. **Version constraints.** Use caret `^` constraints (e.g. `^7.0`) for stability. Pin exact versions only when required.
6. **Autoloading.** PSR-4 only. Map the root namespace to `src/`.

## Code Style & Formatting

### General

- **PER-CS 2.0** (PHP Evolving Recommendation Coding Style) — the successor to PSR-12.
- 4-space indentation, no tabs.
- Unix line endings (`LF`).
- One class per file. Filename matches class name.
- Trailing commas in multi-line arrays, parameters, and arguments.
- Don't use `---` in README or documentation to seperate sections.

### Naming

| Element | Convention | Example |
|---------|-----------|---------|
| Classes | PascalCase | `VideoProcessor` |
| Interfaces | PascalCase + `Interface` suffix | `ProviderInterface` |
| Enums | PascalCase | `Role`, `FinishReason` |
| Methods | camelCase | `getConfig()` |
| Properties | camelCase | `$maxTokens` |
| Constants | UPPER_SNAKE | `MAX_RETRIES` |
| Functions | camelCase | `buildPrompt()` |
| Variables | camelCase | `$outputPath` |
| Namespaces | PascalCase | `CoquiBot\Coqui` |

### Type Declarations

- All parameters, return types, and properties **must** have type declarations.
- Use `mixed` only as a last resort.
- Use union types (`string|int`) when appropriate.
- Use `?Type` for nullable, only when `null` is a meaningful value.
- Use `void` for methods that return nothing.
- Never use `@var`, `@param`, `@return` PHPDoc when the native type is sufficient.

```php
declare(strict_types=1);

namespace Acme\Project;

final readonly class Config
{
    public function __construct(
        private string $name,
        private int $maxRetries = 3,
        private ?string $apiKey = null,
    ) {}

    public function name(): string
    {
        return $this->name;
    }
}
```

## Design Principles

1. **Composition over inheritance.** Prefer interfaces + constructor injection. Use `abstract` classes sparingly.
2. **Final by default.** Mark classes `final` unless explicitly designed for extension.
3. **Readonly by default.** Use `readonly` classes and properties when state shouldn't change after construction.
4. **Immutability.** Return new instances rather than mutating. Use `clone` / `with*()` methods.
5. **Enums over constants.** Use backed enums (`string` or `int`) instead of class constants for fixed sets.
6. **Constructor promotion.** Use promoted properties for DTOs and value objects.
7. **Early returns.** Reduce nesting with guard clauses.
8. **No magic.** Avoid `__get`, `__set`, `__call` unless implementing a well-defined pattern (ArrayAccess, etc.).
9. **No `static` state.** Avoid static methods for anything that holds mutable state. Static factory methods are fine.
10. **No `null` abuse.** Use the Null Object pattern or throw exceptions rather than returning `null` to indicate failure.

## Error Handling

- Throw specific exceptions — never `throw new \Exception()`.
- Create domain exceptions that extend `\RuntimeException` or `\LogicException`.
- Catch only exceptions you can meaningfully handle.
- Use `finally` for cleanup.
- Never silence errors with `@`.

```php
final class ConfigNotFoundException extends \RuntimeException
{
    public static function forPath(string $path): self
    {
        return new self(sprintf('Config file not found: %s', $path));
    }
}
```

## Testing

- **Pest 3.x** is the test runner.
- Tests live in `tests/Unit/` and `tests/Integration/`.
- Test file naming: `*Test.php` (e.g. `ConfigTest.php`).
- Use architecture tests to enforce interface compliance.
- Mock external services — never hit real APIs in unit tests.
- Run tests with `composer test` or `./vendor/bin/pest`.

```php
test('config loads from valid JSON', function () {
    $config = Config::fromFile(__DIR__ . '/fixtures/valid.json');

    expect($config->name())->toBe('test-agent');
    expect($config->maxRetries())->toBe(3);
});
```

## Git & Workflow

- One concern per commit.
- Never commit `vendor/`, `.env`, or IDE config.
- `.gitignore` must include: `vendor/`, `.env`, `*.cache`, `.phpunit.result.cache`, `.workspace/`.

### Key Source Files

| File | Purpose |
|------|---------|
| `src/Config/CatastrophicBlacklist.php` | Hardcoded + configurable always-on safety patterns |
| `src/Config/ScriptSanitizer.php` | Static analysis of generated PHP (respects `--unsafe`) |
| `src/Config/AutoApprovalPolicy.php` | Auto-approves tools except catastrophic commands |
| `src/Config/InteractiveApprovalPolicy.php` | Interactive user confirmation with audit logging |
| `src/Config/WorkspaceComposerManager.php` | Manages `.workspace/composer.json` lifecycle |
| `src/Config/ToolkitDiscovery.php` | Boot-time discovery of toolkit packages; wraps toolkits with credential guards |
| `src/Config/CredentialResolver.php` | Workspace `.env` management with hot-reload via `putenv()` |
| `src/Storage/SessionStorage.php` | Sessions, messages, and audit log persistence |

## Documentation

- **README.md** — installation, quick start, usage examples.
- **PHPDoc** — only for complex logic, generics (`@template`), or where native types are insufficient.
- Inline comments explain *why*, not *what*.
- Keep a `CHANGELOG.md` for versioned releases.

## Security

- Never hardcode secrets. Use environment variables or `.env` files.
- Validate and sanitize all external input.
- Use `filter_var()` with appropriate filters.
- Escape output based on context (HTML, SQL, shell).
- Use parameterized queries — never concatenate SQL.

### Safety Architecture

Coqui enforces a layered safety model. All layers are always active unless explicitly relaxed by the user via CLI flags — and even then, the catastrophic blacklist cannot be bypassed.

1. **ScriptSanitizer** — static analysis of generated PHP code. Blocks `eval`, `exec`, `system`, `passthru`, and other dangerous functions. Skipped in `--unsafe` mode.
2. **CatastrophicBlacklist** — hardcoded regex patterns that **always** block destructive commands (e.g. `rm -rf /`, `shutdown`, `mkfs`, fork bombs, credential exfiltration). Cannot be disabled. Additional patterns can be loaded from `agents.defaults.blacklist` in `openclaw.json`.
3. **InteractiveApprovalPolicy** — gated tools require user confirmation. Replaced by `AutoApprovalPolicy` when `--auto-approve` is passed.
4. **Audit logging** — every tool execution decision (`approved`, `denied`, `blocked`) is logged to the `audit_log` table in the session database with tool name, arguments, action, and reason.

When adding new tools or modifying safety checks:

- Never remove patterns from `CatastrophicBlacklist::HARDCODED_PATTERNS`.
- Always log decisions through `SessionStorage::logAudit()` in approval policies.
- The catastrophic blacklist check must run **before** any user prompt or auto-approval.
- New dangerous operations should be added to the `gatedTools` array in `RunCommand`.

### Workspace Composer Isolation

The `.workspace/` directory contains its own `composer.json` managed by the bot. This separates bot-installed dependencies from the host project:

- `WorkspaceComposerManager` initializes the workspace Composer project on boot.
- `ComposerTool` defaults to the `workspace` target. The bot can also target `project` when explicitly requested.
- The workspace autoloader is loaded at boot via `WorkspaceComposerManager::loadAutoloader()`.
- Toolkit discovery (`ToolkitDiscovery::discoverAll()`) scans both the project and workspace `installed.json` files on every boot.

This means agents can install packages into `.workspace/` without touching the host `composer.json`.

## Performance

- Prefer generators (`yield`) for large data sets.
- Use `SplFixedArray`, `SplPriorityQueue`, and other SPL data structures when appropriate.
- Avoid `file_get_contents()` for HTTP — use a proper HTTP client.
- Profile before optimizing. Don't guess.

## Contributing Agents & Toolkits

We encourage contributions of new agents, tools, and toolkits. Coqui's power grows with every package the community builds.

### Creating a Toolkit Package

1. Create a Composer package that implements `ToolkitInterface` from `carmelosantana/php-agents`.
2. Add `extra.php-agents.toolkits` to your `composer.json` for auto-discovery.
3. Users install your package with `composer require` and Coqui picks it up automatically.

```php
<?php

declare(strict_types=1);

namespace Acme\MyToolkit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

final class MyToolkit implements ToolkitInterface
{
    public function tools(): array
    {
        return [
            new Tool(
                name: 'my_tool',
                description: 'Does something useful',
                parameters: [
                    new StringParameter('input', 'The input to process', required: true),
                ],
                callback: fn(array $args): ToolResult => ToolResult::success('Result: ' . $args['input']),
            ),
        ];
    }

    public function guidelines(): string
    {
        return 'Use my_tool when the user asks to process input.';
    }
}
```

### Adding a New Tool to Coqui

Follow the patterns in `src/Tool/`. Each tool:
- Extends or wraps `Tool` from php-agents
- Defines typed parameters (`StringParameter`, `NumberParameter`, `BooleanParameter`, `EnumParameter`)
- Returns `ToolResult::success()` or `ToolResult::error()`
- Is registered in `OrchestratorAgent::tools()` or via a `ToolkitInterface`

### Adding a New Child Agent Role

Roles are defined in `ChildAgent::instructions()` and mapped to models in `openclaw.json` under `agents.defaults.roles`. To add a new role:
1. Add a case in `ChildAgent::instructions()` with a tailored system prompt
2. Map the role to a model in `openclaw.json`
3. Add the role to `SpawnAgentTool`'s enum parameter

## Quick Reference: PHP 8.4 Features to Use

| Feature | Use Case |
|---------|----------|
| Property hooks | Computed/validated properties without boilerplate getters |
| `new` without parentheses | `new Foo` instead of `new Foo()` when no args |
| Asymmetric visibility | `public private(set)` for read-public, write-private |
| `#[\Deprecated]` attribute | Mark methods for removal with IDE + tooling support |
| `array_find()`, `array_any()`, `array_all()` | Cleaner array filtering and checking |
| `Mb\trim()`, `ltrim()`, `rtrim()` | Multibyte string trimming |
| Lazy objects | `ReflectionClass::newLazyProxy()` for deferred initialization |
| `Dom\HTMLDocument` | Spec-compliant HTML5 parsing (replaces DOMDocument hacks) |

## Database (SQLite)

For single-user applications, SQLite is the preferred storage engine. No server, no config, zero-dependency.

### Guidelines

- Use `ext-pdo_sqlite` for database access.
- Enable WAL mode for better concurrent read performance: `PRAGMA journal_mode=WAL;`
- Enable foreign keys: `PRAGMA foreign_keys=ON;`
- Auto-create tables on first use — no migration tooling needed.
- Store the `.db` file in a `data/` directory. Gitignore the file, keep a `.gitkeep`.
- Use parameterized queries exclusively — never concatenate SQL.
- Use `TEXT` for IDs (UUID-style), `INTEGER` for auto-increment, `TEXT` for timestamps (ISO 8601).

```php
$db = new \PDO('sqlite:data/app.db');
$db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode=WAL');
$db->exec('PRAGMA foreign_keys=ON');
```
