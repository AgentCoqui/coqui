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
| `src/Tool/RestartTool.php` | Agent-facing tool to trigger graceful restart; sets flag via closure, gated by execution policy |
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

1. **Workspace sandboxing** — file writes via `FilesystemToolkit` are sandboxed to the workspace directory. `ComposerTool` targets the workspace only — it cannot modify the project's `composer.json`. `PhpExecuteTool` runs with `cwd` set to the workspace and `open_basedir` restrictions that prevent writes outside workspace boundaries. The project root is read-only, accessible through `ProjectSourceToolkit` and shell commands.
2. **ScriptSanitizer** — static analysis of generated PHP code. Blocks `eval`, `exec`, `system`, `passthru`, and other dangerous functions. Skipped in `--unsafe` mode.
3. **CatastrophicBlacklist** — hardcoded regex patterns that **always** block destructive commands (e.g. `rm -rf /`, `shutdown`, `mkfs`, fork bombs, credential exfiltration). Cannot be disabled. Additional patterns can be loaded from `agents.defaults.blacklist` in `openclaw.json`.
4. **InteractiveApprovalPolicy** — gated tools require user confirmation. Replaced by `AutoApprovalPolicy` when `--auto-approve` is passed.
5. **Audit logging** — every tool execution decision (`approved`, `denied`, `blocked`) is logged to the `audit_log` table in the session database with tool name, arguments, action, and reason.

When adding new tools or modifying safety checks:

- Never remove patterns from `CatastrophicBlacklist::HARDCODED_PATTERNS`.
- Always log decisions through `SessionStorage::logAudit()` in approval policies.
- The catastrophic blacklist check must run **before** any user prompt or auto-approval.
- New dangerous operations should be added to the `gatedTools` array in `RunCommand`.

### Restart Architecture

Coqui supports graceful restarts and automatic crash recovery via a three-layer system:

1. **`bin/coqui-launcher`** — Bash wrapper script that runs `bin/coqui` in a loop. On exit code `0` (clean quit), the launcher stops. On exit code `10` (restart requested), it immediately relaunches. On any other exit code (crash), it relaunches up to 3 consecutive times before giving up.

2. **`/restart` REPL command** — User types `/restart` in the REPL, which causes `RunCommand` to return `RESTART_EXIT_CODE` (10). The launcher detects this and relaunches the process.

3. **`restart_coqui` tool** — The LLM agent can trigger a restart via a tool call. The tool sets a `restartRequested` flag on `RunCommand` via a closure callback. After the current agent turn completes, the REPL loop checks the flag and exits with code 10. This tool is gated — it requires user confirmation unless `--auto-approve` is enabled.

When adding restart triggers:

- Always use exit code `10` (`RunCommand::RESTART_EXIT_CODE`) for intentional restarts.
- The `restartRequested` flag is checked *after* `runAgent()` completes — the agent finishes its current turn gracefully.
- The launcher's crash counter resets on intentional restarts (code 10); only consecutive unintentional crashes count toward the 3-attempt limit.

### Workspace Composer Isolation

The `.workspace/` directory contains its own `composer.json` managed by the bot. This separates bot-installed dependencies from the host project:

- `WorkspaceComposerManager` initializes the workspace Composer project on boot.
- `ComposerTool` always targets the workspace. It cannot modify the project's `composer.json` — the `target: 'project'` option has been removed as a security measure.
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

## Source Map Maintenance

The file `config/source.json` is the structured codebase map that Coqui uses to understand its own source code. It is loaded by the `project_source_map` tool and injected into agent context.

### When to Update

- **Adding a new source file** — add an entry with path, FQCN, layer, description, and key methods.
- **Renaming or moving a file** — update the path and FQCN in the existing entry.
- **Significantly changing a file's purpose or API** — update the description and methods list.
- **Removing a file** — remove its entry from the `files` array.
- **Adding a new external dependency that agents interact with** — add it to `externalDependencies`.

### Entry Format

Each entry in the `files` array must include:

```json
{
    "path": "src/Layer/ClassName.php",
    "fqcn": "CoquiBot\\Coqui\\Layer\\ClassName",
    "layer": "agent|command|config|contract|tool|toolkit|observer|storage",
    "description": "One-paragraph description of what the class does and why it exists.",
    "methods": [
        "methodName(params): ReturnType — brief description of what it does"
    ]
}
```

### Validation

Run `project_source_map` after editing to verify the JSON is valid and the structure is correct. Every source file under `src/` should have a corresponding entry.

## Docker

Coqui ships with Docker support for development, testing, and isolated execution. The image is based on `php:8.4-cli` (not a web server) since Coqui is a CLI REPL.

### Architecture

| File | Purpose |
|------|---------|
| `Dockerfile` | PHP 8.4 CLI + all extensions + Composer. Xdebug and pcov are installed but disabled by default (enabled via compose overlays). |
| `compose.yaml` | Base service: bind-mounts source, named volume for `.workspace/`, passes API keys from host, connects to host Ollama via `host.docker.internal`. |
| `compose.dev.yaml` | Developer overlay: enables Xdebug (debug + profile), mounts workspace parent for Composer path repo resolution (`../php-agents`, `../coqui-brave-search`), adds Webgrind for profiler analysis. |
| `compose.test.yaml` | Test overlay: non-interactive, enables pcov for coverage, disables OPcache. |
| `Makefile` | Self-documenting targets: `make run`, `make dev`, `make test`, `make shell`, etc. |
| `conf.d/coqui.ini` | CLI-optimized PHP config: 512M memory, OPcache + JIT enabled, errors to stderr. |
| `conf.d/xdebug.ini` | Xdebug config: trigger-based activation, profiler output to `/tmp/xdebug`, IDE key `COQUI`. |
| `conf.d/test.ini` | Test config: pcov enabled, OPcache disabled, 1G memory. |
| `.env.example` | Documents all environment variables (API keys, ports, UID/GID). |

### Key Design Decisions

- **CLI base image**: `php:8.4-cli` keeps the image ~300MB smaller than Apache/FPM variants. Coqui has no HTTP server.
- **`docker compose run` over `up`**: The REPL requires interactive TTY. Use `run --rm` for sessions. Background services (Webgrind) use `up -d` separately.
- **Host Ollama**: Users connect to `host.docker.internal:11434`. Avoids GPU passthrough complexity and duplicate model storage.
- **Workspace root mount in dev**: `compose.dev.yaml` mounts the entire parent directory (`..`) as `/workspace` so Composer path repositories (`../php-agents`, `../coqui-brave-search`) resolve identically to the host.
- **Xdebug + pcov installed but disabled**: Both built into the image at build time but only activated via ini file mounts in their respective overlays. Zero runtime overhead in base mode.
- **Named volume for `.workspace/`**: Session databases, bot-installed packages, and workspace state persist across `docker compose run` invocations.

### Running in Docker

```bash
# Build image
make build

# Interactive REPL
make run

# Dev mode (Xdebug + path repos)
make dev

# Start Webgrind
make dev-up    # http://localhost:9002

# Tests
make test
make test-coverage

# Shell access
make shell

# Composer operations
make install
make composer CMD="require foo/bar"

# Cleanup
make clean             # all containers + volumes
make clean-workspace   # workspace volume only
```

### Environment Variables

Copy `.env.example` to `.env` before running. Key variables:

| Variable | Default | Purpose |
|----------|---------|---------|
| `COQUI_UID` / `COQUI_GID` | `1000` | Match host user to avoid permission issues |
| `OPENAI_API_KEY` | — | Passed into the container |
| `ANTHROPIC_API_KEY` | — | Passed into the container |
| `OLLAMA_HOST` | `http://host.docker.internal:11434` | Ollama endpoint |
| `COQUI_WEBGRIND_PORT` | `9002` | Webgrind port (dev overlay) |

### Xdebug Profiling Workflow

1. Start Webgrind: `make dev-up`
2. Run Coqui with Xdebug trigger: `XDEBUG_TRIGGER=1 make dev`
3. Open Webgrind at `http://localhost:9002`
4. After profiling, clear output: `make xdebug-clear`

## Documentation Policy

When making changes to Coqui, keep documentation in sync:

- **README.md** — update when adding user-facing features (new CLI options, new tools, new capabilities, changed behavior). The README is the first thing users see.
- **AGENTS.md** — update when adding architectural patterns, new conventions, new contributor workflows, or modifying the safety/security model.
- **config/source.json** — update when adding, renaming, removing, or significantly modifying source files (see Source Map Maintenance above).
- **CHANGELOG.md** — update for versioned releases with user-visible changes.
