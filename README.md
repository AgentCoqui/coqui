# Coqui Bot

![Coqui Bot](assets/coqui.webp)

Terminal AI agent with multi-model orchestration, persistent sessions, and runtime extensibility via Composer.

Coqui is a CLI-first AI assistant that lives in your terminal. Ask it questions, delegate coding tasks, manage packages, execute PHP, and extend its abilities on the fly — powered by [php-agents](https://github.com/carmelosantana/php-agents) and any mix of locally hosted or cloud LLMs.

> Coqui is a WIP and under rapid development. Be careful when running this tool. Always test in a safe environment.

Join the [Discord community](https://discord.gg/TaCpZVqbbT) to follow along, ask questions, and share your creations!

## Features

- **Multi-model orchestration** — route tasks to the right model: cheap local models for orchestration, powerful cloud models for coding and review
- **Persistent sessions** — SQLite-backed conversations that survive restarts; resume where you left off
- **Workspace sandboxing** — all file I/O is sandboxed to a `.workspace` directory with its own Composer project, keeping your project safe
- **Runtime extensibility** — install Composer packages at runtime and Coqui auto-discovers new toolkits on every boot
- **Child agent delegation** — spawns specialized agents (coder, reviewer) using role-appropriate models
- **Interactive approval** — dangerous operations (package installs, shell exec, PHP execution) require your confirmation
- **Auto-approve mode** — skip interactive prompts with `--auto-approve` for unattended workflows; catastrophic commands are still blocked
- **Unsafe mode** — lift function restrictions with `--unsafe` for power users; catastrophic commands are still blocked
- **Catastrophic blacklist** — hardcoded safety net that blocks destructive commands (`rm -rf /`, `shutdown`, fork bombs, etc.) regardless of mode
- **Audit logging** — every tool execution decision (approved, denied, blocked) is logged to SQLite for traceability
- **Credential management** — secure `.env`-based secret storage with automatic credential guards; toolkits declare required credentials in `composer.json` and Coqui intercepts tool calls with actionable instructions when keys are missing
- **Script sanitization** — static analysis blocks dangerous functions before any generated code runs
- **Memory persistence** — saves facts to `MEMORY.md` across sessions so Coqui remembers what matters
- **Observer pattern** — real-time terminal rendering of agent lifecycle events with nested child output
- **OpenClaw config** — natively supports the OpenClaw config format for centralized model routing and workspace settings

## Requirements

- PHP 8.4 or later
- Extensions: `curl`, `json`, `mbstring`, `pdo_sqlite`
- Composer 2.x
- [Ollama](https://ollama.ai) (recommended for local inference)

Or use **Docker** — no local PHP required. See [Docker](#docker) below.

## Installation

```bash
git clone https://github.com/AgentCoqui/coqui.git
cd coqui
composer install
```

## Quick Start

```bash
./bin/coqui
```

That's it. Coqui starts a REPL session and you can start chatting:

For automatic crash recovery and restart support, use the launcher:

```bash
./bin/coqui-launcher
```

The launcher wraps `bin/coqui` and handles:
- **Clean exit** (exit code 0) — `/quit` stops the launcher
- **Restart** (exit code 10) — `/restart` or the `restart_coqui` tool triggers an immediate relaunch
- **Crash recovery** — unexpected exits auto-relaunch up to 3 consecutive times

```txt
 Coqui v0.1.0

 Session  a3f8b2c1
 Model    ollama/glm-4.7-flash:latest
 Project  /home/you/projects/my-app
 Workspace /home/you/projects/my-app/.workspace

 Type /help for commands, /quit to exit.

 You > Summarize the README.md file
 ▸ Using: read_file(path: "README.md")
 ✓ Done

 The README describes a PHP application that...
```

> Make sure Ollama is running: `ollama serve` and a model is pulled: `ollama pull glm-4.7-flash`

### CLI Options

| Option | Short | Description |
|--------|-------|-------------|
| `--config` | `-c` | Path to `openclaw.json` config file |
| `--new` | | Start a fresh session |
| `--session` | `-s` | Resume a specific session by ID |
| `--workdir` | `-w` | Working directory (default: current directory) |
| `--unsafe` | | Disable denied-function checks in ScriptSanitizer (catastrophic blacklist still active) |
| `--auto-approve` | | Auto-approve all tool executions without prompting (catastrophic blacklist still active) |

## REPL Commands

Once inside the Coqui REPL, use slash commands:

| Command | Description |
|---------|-------------|
| `/new` | Start a new session |
| `/history` | Show conversation history |
| `/sessions` | List all saved sessions |
| `/resume <id>` | Resume a session by ID |
| `/model [role]` | Show model configuration |
| `/help` | List available commands |
| `/restart` | Restart Coqui (re-reads config, re-discovers toolkits) |
| `/quit` `/exit` `/q` | Exit Coqui |

## Providers & OpenClaw Config

Coqui uses an `openclaw.json` config file for centralized model routing. It supports three providers out of the box:

### Provider Setup

```php
// Ollama (local — no API key needed)
"ollama": {
    "baseUrl": "http://localhost:11434/v1",
    "apiKey": "ollama-local",
    "api": "openai-completions"
}

// OpenAI
"openai": {
    "baseUrl": "https://api.openai.com/v1",
    "apiKey": "your-openai-api-key",
    "api": "openai-completions"
}

// Anthropic
"anthropic": {
    "baseUrl": "https://api.anthropic.com/v1",
    "apiKey": "your-anthropic-api-key",
    "api": "anthropic"
}
```

Set your API keys as environment variables or directly in `openclaw.json`:

```bash
export OPENAI_API_KEY="sk-..."
export ANTHROPIC_API_KEY="sk-ant-..."
```

### Role-Based Model Routing

The real power is in role-to-model mapping. Assign the best model for each job:

```json
{
    "agents": {
        "defaults": {
            "model": {
                "primary": "ollama/glm-4.7-flash:latest",
                "fallbacks": ["ollama/qwen3-coder:latest"]
            },
            "roles": {
                "orchestrator": "openai/gpt-4.1",
                "coder": "anthropic/claude-opus-4-6",
                "reviewer": "openai/gpt-4o-mini"
            }
        }
    }
}
```

The orchestrator runs on a cost-effective model for routing and simple tasks, then delegates to expensive models only when needed — keeping costs low while maintaining quality where it counts.

### Model Aliases

Define short aliases for quick reference:

```json
{
    "models": {
        "ollama/qwen3:latest": { "alias": "qwen" },
        "anthropic/claude-opus-4-6": { "alias": "opus" },
        "openai/gpt-4.1": { "alias": "gpt4.1" }
    }
}
```

## Built-in Tools

Coqui ships with a rich set of tools the agent can use autonomously:

### Custom Tools

| Tool | Description |
|------|-------------|
| `spawn_agent` | Delegate tasks to specialized child agents (coder, reviewer) using role-appropriate models |
| `composer` | Manage Composer dependencies — target the workspace (default) or project root, with framework denylist |
| `credentials` | Secure credential management via `.env` — values are never exposed to the LLM; toolkit credential requirements are enforced automatically |
| `packagist` | Search Packagist for packages by keyword, popularity, advisories |
| `package_info` | Introspect installed packages — read READMEs, list classes, inspect method signatures |
| `php_execute` | Execute generated PHP code in a sandboxed subprocess with script sanitization |
| `restart_coqui` | Trigger a graceful restart — re-reads config, re-discovers toolkits, resumes session automatically |

### Inherited Toolkits (from php-agents)

| Toolkit | Description |
|---------|-------------|
| `FilesystemToolkit` | Sandboxed read/write to the `.workspace` directory |
| `ShellToolkit` | Run shell commands from project root (`git`, `grep`, `find`, `cat`, `ls`, etc.) |
| `MemoryToolkit` | Persistent memory via `MEMORY.md` for facts that survive across sessions |

## Extending Coqui

Coqui auto-discovers toolkits from installed Composer packages. Create a package that implements `ToolkitInterface` and Coqui picks it up automatically.

### 1. Implement `ToolkitInterface`

```php
<?php

declare(strict_types=1);

namespace Acme\BraveSearch;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

final class BraveSearchToolkit implements ToolkitInterface
{
    public function __construct(
        private readonly string $apiKey = '',
    ) {}

    public static function fromEnv(): self
    {
        $key = getenv('BRAVE_SEARCH_API_KEY');
        return new self(apiKey: $key !== false ? $key : '');
    }

    public function tools(): array
    {
        return [$this->buildSearchTool()];
    }

    public function guidelines(): string
    {
        return 'Use brave_search to find current information from the web.';
    }
}
```

### 2. Register in `composer.json`

Declare your toolkit class and any required credentials:

```json
{
    "extra": {
        "php-agents": {
            "toolkits": [
                "Acme\\BraveSearch\\BraveSearchToolkit"
            ],
            "credentials": {
                "BRAVE_SEARCH_API_KEY": "Brave Search API key — free tier at https://brave.com/search/api/"
            }
        }
    }
}
```

When a toolkit declares `credentials`, Coqui automatically wraps its tools with a credential guard. If the user calls a tool before setting the required key, the agent receives a structured error with the exact credential name and instructions for saving it — no token-wasting guesswork.

### 3. Install and go

```bash
composer require acme/brave-search
```

Coqui discovers the toolkit on next startup — no configuration needed. If credentials are missing, the agent will ask the user and save them with the correct key name automatically.

### Safety

Coqui has multiple layers of protection:

1. **Framework denylist** — blocks full-framework packages (`laravel/*`, `symfony/symfony`, `laminas/*`, etc.) from being installed to keep the runtime lean
2. **ScriptSanitizer** — static analysis blocks dangerous functions (`eval`, `exec`, `system`, `passthru`, etc.) in generated PHP code. Bypass with `--unsafe` for power users
3. **Catastrophic blacklist** — a hardcoded safety net that *always* blocks destructive commands like `rm -rf /`, `shutdown`, `mkfs`, fork bombs, and credential exfiltration — even in `--unsafe` and `--auto-approve` modes. Additional patterns can be added via `agents.defaults.blacklist` in `openclaw.json`
4. **Interactive approval** — gated tools require user confirmation before execution. Bypass with `--auto-approve` for unattended workflows
5. **Audit logging** — every tool execution decision (approved, denied, blocked) is recorded in the session database

```json
{
    "agents": {
        "defaults": {
            "blacklist": [
                "custom-pattern-to-block"
            ]
        }
    }
}
```

## Docker

Run Coqui in a container with zero host dependencies. The Docker setup uses `php:8.4-cli` with all required extensions, Composer, and optional Xdebug/pcov for development and testing.

### Quick Start (Docker)

```bash
# Build the image
make build

# Start the interactive REPL
make run
```

Pass API keys from your host environment:

```bash
OPENAI_API_KEY=sk-... make run
```

Or copy `.env.example` to `.env` and fill in your keys:

```bash
cp .env.example .env
```

### Connect to Ollama

Coqui connects to Ollama on your host machine via `host.docker.internal`. Make sure Ollama is running:

```bash
ollama serve
```

### Development Mode

Development mode enables Xdebug (step debugging + profiling) and mounts sibling repositories (`php-agents`, `coqui-brave-search`) so Composer path repos resolve inside the container:

```bash
# Start REPL with Xdebug + path repos
make dev

# Start Webgrind profiler viewer (background)
make dev-up
# Open http://localhost:9002

# Stop Webgrind
make dev-down
```

### Running Tests

```bash
# Run Pest tests
make test

# Run with code coverage (pcov)
make test-coverage

# Open a shell in the test container
make test-shell
```

### Useful Commands

| Command | Description |
|---------|-------------|
| `make run` | Interactive REPL |
| `make run-launcher` | REPL with crash recovery |
| `make dev` | REPL with Xdebug + path repos |
| `make dev-up` | Start Webgrind in background |
| `make test` | Run Pest tests |
| `make test-coverage` | Tests with coverage report |
| `make shell` | Bash shell in container |
| `make install` | Run `composer install` |
| `make composer CMD="..."` | Run any Composer command |
| `make clean` | Remove containers, images, volumes |
| `make help` | Show all available targets |

### Configuration

Mount your `openclaw.json` config:

```bash
make run-config CONFIG=openclaw.json
```

Or pass it directly:

```bash
docker compose run --rm -v ./openclaw.json:/app/openclaw.json:ro coqui
```

### File Overview

| File | Purpose |
|------|---------|
| `Dockerfile` | PHP 8.4 CLI + extensions + Composer + Xdebug/pcov (disabled by default) |
| `compose.yaml` | Base service with workspace volume + host Ollama access |
| `compose.dev.yaml` | Xdebug, workspace root mount, Webgrind |
| `compose.test.yaml` | Non-interactive test runner with pcov |
| `Makefile` | Self-documenting convenience targets |
| `.env.example` | Environment variable documentation |
| `conf.d/coqui.ini` | CLI-optimized PHP config (OPcache + JIT) |
| `conf.d/xdebug.ini` | Xdebug debug + profile config (dev only) |
| `conf.d/test.ini` | pcov + no OPcache (test only) |

## Community

We're building a community where people share agents, ask for help, and collaborate on new toolkits.

- **Discord** — [Join us](https://discord.gg/TaCpZVqbbT) for support, discussions, and sharing your toolkits
- **GitHub** — [AgentCoqui/coqui](https://github.com/AgentCoqui/coqui) for issues, PRs, and source code

## Contributing

We'd love your help making Coqui even mightier:

- **Build new toolkits** — create Composer packages that implement `ToolkitInterface`
- **Add child agent roles** — define new specialized roles with tailored system prompts
- **Improve tools** — enhance existing tools or add new ones in `src/Tool/`
- **Write tests** — expand coverage in `tests/Unit/`
- **Fix bugs & improve docs** — every contribution counts

See [AGENTS.md](AGENTS.md) for code conventions and architecture guidelines.

## License

MIT
