# Coqui Bot

<p align="center">
    <picture>
        <img src="https://raw.githubusercontent.com/AgentCoqui/coqui/main/assets/coqui.webp" alt="Coqui" width="256" />
    </picture>
</p>

<p align="center">
  <a href="https://github.com/AgentCoqui/coqui/actions/workflows/ci.yml?branch=main"><img src="https://img.shields.io/github/actions/workflow/status/AgentCoqui/coqui/ci.yml?branch=main&style=for-the-badge" alt="CI status"></a>
  <a href="https://github.com/AgentCoqui/coqui/releases"><img src="https://img.shields.io/github/v/release/AgentCoqui/coqui?include_prereleases&style=for-the-badge" alt="GitHub release"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.4+"></a>
  <a href="https://discord.gg/TaCpZVqbbT"><img src="https://img.shields.io/discord/1471632654624489668?label=Discord&logo=discord&logoColor=white&color=5865F2&style=for-the-badge" alt="Discord"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-blue?style=for-the-badge" alt="MIT License"></a>
  <a href="https://github.com/sponsors/carmelosantana"><img src="https://img.shields.io/github/sponsors/carmelosantana?label=Sponsors&logo=github-sponsors&logoColor=white&color=EA4AAA&style=for-the-badge" alt="GitHub Sponsors"></a>
</p>

<p align="center">
  <a href="https://coquibot.org/">Website</a> ·
  <a href="https://coquibot.org/docs">Docs</a> ·
  <a href="https://coqui.space">Toolkits</a> ·
  <a href="https://github.com/sponsors/carmelosantana">Sponsor</a>
</p>

> **Book a 1:1 call** — paid sessions for real-time implementation help, AI agent consulting, or just to support Coqui's active development. [Schedule time →](https://cal.com/carmelosantana/coqui-1:1)

Terminal AI agent with multi-model orchestration, persistent sessions, and runtime extensibility via Composer.

Coqui is a CLI-first AI assistant that lives in your terminal. Ask it questions, delegate coding tasks, manage packages, execute PHP, and extend its abilities on the fly — powered by [`php-agents`](https://github.com/carmelosantana/php-agents) and any mix of locally hosted or cloud LLMs.

> Coqui is a WIP and under rapid development. Be careful when running this tool. Always test in a safe environment.

Join the [Discord community](https://discord.gg/TaCpZVqbbT) to follow along, ask questions, and share your creations!

## Features

- **Multi-model orchestration** — route tasks to the right model: cheap local models for orchestration, powerful cloud models for coding and review
- **Persistent sessions** — SQLite-backed conversations that survive restarts; resume where you left off
- **Workspace sandboxing** — all file I/O is sandboxed to the workspace directory (`~/.coqui/.workspace` by default) with its own Composer project, keeping your project safe
- **Runtime extensibility** — install Composer packages at runtime and Coqui auto-discovers new toolkits on every boot
- **Child agent delegation** — spawns specialized agents (coder, reviewer) using role-appropriate models
- **Interactive approval** — dangerous operations (package installs, shell exec, PHP execution) require your confirmation
- **Auto-approve mode** — skip interactive prompts with `--auto-approve` for unattended workflows; catastrophic commands are still blocked
- **Unsafe mode** — lift PHP function restrictions with `--unsafe` for power users; only affects `php_execute`, not shell commands; catastrophic commands are still blocked
- **Catastrophic blacklist** — hardcoded safety net that blocks destructive commands (`rm -rf /`, `shutdown`, fork bombs, etc.) regardless of mode
- **Audit logging** — every tool execution decision (approved, denied, blocked) is logged to SQLite for traceability
- **Turn tracking** — each request-response cycle is tracked as a turn with token usage, duration, tools used, and child agent counts for full observability
- **Credential management** — secure `.env`-based secret storage with automatic credential guards; toolkits declare required credentials in `composer.json` and Coqui intercepts tool calls with actionable instructions when keys are missing
- **Script sanitization** — static analysis blocks dangerous functions before any generated code runs
- **Memory persistence** — saves facts to `MEMORY.md` across sessions so Coqui remembers what matters
- **Vision / image analysis** — analyze images from URLs, file paths, or base64 data URIs using a vision-capable model; images are pre-downloaded and base64-encoded for universal provider compatibility
- **Background tasks** — run long-running agent work in separate processes while the main conversation continues (API mode)
- **Network access** — expose the API to your local network with `--host 0.0.0.0` so you can connect from phones, tablets, and other machines
- **Observer pattern** — real-time terminal rendering of agent lifecycle events with nested child output
- **OpenClaw compatible** — drop-in support for the [OpenClaw](https://github.com/openclaw/openclaw) config format; use your existing `openclaw.json` without any changes, or start fresh with the built-in setup wizard

## Requirements

- PHP 8.4 or later
- Extensions: `curl`, `json`, `mbstring`, `pdo_sqlite`
- [Ollama](https://ollama.ai) (recommended for local inference)

Or use **Docker** — no local PHP required. See [Docker](#docker) below.

## Installation

The installer detects your OS, installs PHP 8.4+ and required extensions if missing, downloads the latest Coqui release, verifies the SHA-256 checksum, and adds `coqui` to your PATH — no Git or Composer required.

### Linux / macOS / WSL2

```bash
curl -fsSL https://coquibot.org/install | bash
```

### Windows (PowerShell)

```powershell
irm https://raw.githubusercontent.com/AgentCoqui/coqui-installer/main/install.ps1 | iex
```

### Update

Re-run the same install command. The installer detects an existing installation and updates it automatically.

### Inspect before running

- Linux / macOS: [install.sh](https://raw.githubusercontent.com/AgentCoqui/coqui-installer/main/install.sh)
- Windows: [install.ps1](https://raw.githubusercontent.com/AgentCoqui/coqui-installer/main/install.ps1)

### Development Install

Clone the repository and install dependencies manually. Requires PHP 8.4+, Composer 2.x, and Git.

```bash
git clone https://github.com/AgentCoqui/coqui.git
cd coqui
composer install
```

Alternatively, use the `--dev` flag with the installer to clone and set up in one step:

```bash
# Linux / macOS
./install.sh --dev

# Windows
.\install.ps1 -Dev
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

The launcher starts the REPL (foreground) + API server (background on port 3300) by default. It also handles:
- **Clean exit** (exit code 0) — `/quit` stops the launcher and all background services
- **Restart** (exit code 10) — `/restart` or the `restart_coqui` tool triggers an immediate relaunch
- **Crash recovery** — unexpected exits auto-relaunch up to 3 consecutive times
- **Service management** — `./bin/coqui-launcher stop` / `status` to manage background services

```txt
 Coqui v0.1.0

 Session  a3f8b2c1
 Model    ollama/glm-4.7-flash:latest
 Project  /home/you/projects/my-app
 Workspace /home/you/.coqui/.workspace

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

### Commands

| Command | Description |
|---------|-------------|
| `run` | Start the Coqui REPL (default command) |
| `setup` | Interactive wizard to create or overwrite `openclaw.json` |
| `doctor` | Run system health checks and optionally repair issues |

#### Doctor Command

The `doctor` command checks 10 health categories and reports issues:

```bash
./bin/coqui doctor
./bin/coqui doctor --repair      # Auto-fix issues where possible
./bin/coqui doctor --json        # Machine-readable output
```

| Option | Description |
|--------|-------------|
| `--config` | Path to `openclaw.json` config file |
| `--workdir` | Working directory (default: current directory) |
| `--repair` | Attempt to auto-fix detected issues |
| `--json` | Output results as JSON |

**Health checks:** PHP environment, config validation, workspace integrity, database health (including per-session UTF-8 message integrity scans), credentials, provider connectivity, toolkit discovery, skills, launcher, and disk space.

## REPL Commands

Once inside the Coqui REPL, use slash commands:

| Command | Description |
|---------|-------------|
| `/new` | Start a new session |
| `/history` | Show conversation history |
| `/sessions` | List all saved sessions |
| `/resume <id>` | Resume a session by ID |
| `/model [role]` | Show model configuration |
| `/tasks [status]` | List background tasks (optional status filter) |
| `/task <id>` | Show background task details |
| `/task-cancel <id>` | Cancel a background task |
| `/help` | List available commands |
| `/restart` | Restart Coqui (re-reads config, re-discovers toolkits) |
| `/quit` `/exit` `/q` | Exit Coqui |

## Providers & OpenClaw Config

Coqui uses an `openclaw.json` config file for centralized model routing. The format is fully compatible with [OpenClaw](https://github.com/openclaw/openclaw) — you can drop in your existing OpenClaw config and it works without any changes. Coqui-specific extensions (workspace, mounts, shell allowlist) live under `agents.defaults` and are safely ignored by other OpenClaw-compatible tools.

Config changes are detected automatically — edit the file and your next message uses the new settings. No restart required.

For the full config reference, see [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

### Supported Providers

| Provider | Protocol | API Key Env Var |
|----------|----------|----------------|
| Ollama (local) | `openai-completions` | — |
| OpenAI | `openai-completions` | `OPENAI_API_KEY` |
| Anthropic | `anthropic` | `ANTHROPIC_API_KEY` |
| OpenRouter | `openai-completions` | `OPENROUTER_API_KEY` |
| xAI (Grok) | `openai-completions` | `XAI_API_KEY` |
| Google Gemini | `gemini` | `GEMINI_API_KEY` |
| Mistral | `mistral` | `MISTRAL_API_KEY` |
| MiniMax | `openai-completions` | `MINIMAX_API_KEY` |

Any OpenAI-compatible provider can be added by specifying `openai-completions` as the API protocol.

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

// xAI (Grok)
"xai": {
    "baseUrl": "https://api.x.ai/v1",
    "apiKey": "your-xai-api-key",
    "api": "openai-completions"
}
```

Set your API keys as environment variables or directly in `openclaw.json`:

```bash
export OPENAI_API_KEY="sk-..."
export ANTHROPIC_API_KEY="sk-ant-..."
export XAI_API_KEY="xai-..."
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
| `vision_analyze` | Analyze images from URLs, file paths, or base64 data URIs using a vision-capable model |
| `restart_coqui` | Trigger a graceful restart — re-reads config, re-discovers toolkits, resumes session automatically |
| `start_background_task` | Start a long-running task in a background process — keeps the main conversation responsive |
| `task_status` | Check a background task's status and recent output |
| `list_tasks` | List all background tasks in the current session |
| `cancel_task` | Cancel a running background task |

### Inherited Toolkits (from php-agents)

| Toolkit | Description |
|---------|-------------|
| `FilesystemToolkit` | Sandboxed read/write to the workspace directory (`~/.coqui/.workspace` by default) |
| `ShellToolkit` | Run shell commands from project root — configurable allowlist (default includes `git`, `grep`, `find`, `cat`, `ls`, `curl`, `wget`, etc.) |
| `MemoryToolkit` | Persistent memory via `MEMORY.md` for facts that survive across sessions |

## Background Tasks

Coqui can run long-running agent work in separate processes so the main conversation stays responsive. The agent spawns a background task, continues answering questions, and reports back when the task finishes.

Background tasks are automatically available when running the API server — no configuration required:

```bash
php bin/coqui api
```

Each task runs as an isolated PHP process (`task:run`) with its own agent stack, session storage, and lifecycle. Tasks support:

- **Process isolation** — each task is a separate OS process managed via `proc_open`
- **Live progress** — events stream via Server-Sent Events (SSE)
- **User input** — send follow-up messages to running tasks
- **Cancellation** — cooperative cancellation via `SIGTERM`
- **Crash recovery** — orphaned tasks are automatically marked as failed on server restart
- **Concurrency control** — configurable via `api.tasks.maxConcurrent` in `openclaw.json` (default: 1)

The agent can start, monitor, and cancel tasks using four built-in tools (`start_background_task`, `task_status`, `list_tasks`, `cancel_task`). The HTTP API exposes the same capabilities for external clients.

For full API reference, architecture details, and usage examples, see [docs/BACKGROUND-TASKS.md](docs/BACKGROUND-TASKS.md).

## Vision / Image Analysis

Coqui can analyze images using a vision-capable model. The agent uses the `vision_analyze` tool, which accepts:

- **URLs** — `https://example.com/photo.jpg` (auto-downloaded and base64-encoded)
- **File paths** — absolute or workspace-relative paths to local image files
- **Base64 data URIs** — `data:image/png;base64,...`

Images are always pre-downloaded and converted to base64 before being sent to the provider, ensuring compatibility with all providers (including Gemini, which doesn't support URL references natively).

### Configuration

Assign a vision-capable model to the `vision` role in `openclaw.json`:

```json
{
    "agents": {
        "defaults": {
            "roles": {
                "vision": "openai/gpt-5"
            }
        }
    }
}
```

Any vision-capable model works: OpenAI GPT-4o/GPT-5, Anthropic Claude, Google Gemini, xAI Grok, etc.

If no vision role is configured, the primary model is used as a fallback. Ensure the fallback model supports image input.

### Provider Image Support

| Provider | Base64 | URL | Notes |
|----------|:------:|:---:|-------|
| OpenAI | ✅ | ✅ | Native support for both formats |
| OpenAI Responses | ✅ | ✅ | Same as OpenAI |
| Anthropic | ✅ | ✅ | Auto-converted to Anthropic's `image` source format |
| Gemini | ✅ | ✅* | URLs auto-downloaded to base64 `inlineData` |
| Ollama | ✅ | ✅ | Via OpenAI-compatible format |
| xAI (Grok) | ✅ | ✅ | Content types converted to `input_image` format |
| Mistral | ✅ | ✅ | Image URLs flattened to Mistral's string format |

\* Gemini doesn't support URL references natively; both `VisionAnalyzer` and `GeminiProvider` download and base64-encode the image automatically.

### Supported Image Formats

JPEG, PNG, GIF, WebP, BMP, TIFF, SVG

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
make docker-build

# Start REPL + API
make docker-start
```

Pass API keys from your host environment:

```bash
OPENAI_API_KEY=sk-... make docker-start
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

Development mode enables Xdebug (step debugging + profiling) and mounts sibling repositories so Composer path repos resolve inside the container:

```bash
# Start REPL with Xdebug + path repos
make docker-dev

# Webgrind profiler viewer runs automatically
# Open http://localhost:3390
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
| `make start` | Start REPL + API (native) |
| `make stop` | Stop all native services |
| `make status` | Show service status |
| `make repl` | REPL only (native) |
| `make api` | API only (native, `HOST=0.0.0.0` for network access) |
| `make docker-start` | REPL + API (Docker) |
| `make docker-repl` | REPL only (Docker) |
| `make docker-api` | API only (Docker) |
| `make docker-dev` | Dev mode with Xdebug + Webgrind |
| `make docker-shell` | Bash shell in container |
| `make test` | Run Pest tests |
| `make test-coverage` | Tests with coverage report |
| `make install` | Run `composer install` |
| `make clean` | Remove containers, images, volumes |
| `make help` | Show all available targets |

### Configuration

Pass a config file via the launcher or directly:

```bash
# Native
./bin/coqui-launcher --config openclaw.json

# Docker
docker compose run --rm -v ./openclaw.json:/app/openclaw.json:ro coqui
```

### File Overview

| File | Purpose |
|------|---------|
| `Dockerfile` | PHP 8.4 CLI + extensions + Composer + Xdebug/pcov (disabled by default) |
| `compose.yaml` | Base service with workspace volume + host Ollama access |
| `compose.api.yaml` | API server service (port 3300) — runs alongside REPL |
| `compose.dev.yaml` | Xdebug, workspace root mount, Webgrind (port 3390) |
| `compose.test.yaml` | Non-interactive test runner with pcov |
| `Makefile` | Self-documenting targets: native (`start`, `api`) and Docker (`docker-*`) |
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
