# Coqui

<!-- markdownlint-disable MD033 -->
<p align="center">
    <picture>
        <img src="https://raw.githubusercontent.com/carmelosantana/coqui/main/assets/coqui.webp" alt="Coqui" width="256" />
    </picture>
</p>

<p align="center">
  <a href="https://github.com/carmelosantana/coqui/actions/workflows/ci.yml?branch=main"><img src="https://img.shields.io/github/actions/workflow/status/carmelosantana/coqui/ci.yml?branch=main&style=for-the-badge" alt="CI status"></a>
  <a href="https://github.com/carmelosantana/coqui/releases"><img src="https://img.shields.io/github/v/release/carmelosantana/coqui?include_prereleases&style=for-the-badge" alt="GitHub release"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.4+"></a>
  <a href="https://discord.gg/TaCpZVqbbT"><img src="https://img.shields.io/discord/1471632654624489668?label=Discord&logo=discord&logoColor=white&color=5865F2&style=for-the-badge" alt="Discord"></a>
  <a href="LICENSE"><img src="https://img.shields.io/badge/License-MIT-blue?style=for-the-badge" alt="MIT License"></a>
  <a href="https://github.com/sponsors/carmelosantana"><img src="https://img.shields.io/github/sponsors/carmelosantana?label=Sponsors&logo=github-sponsors&logoColor=white&color=EA4AAA&style=for-the-badge" alt="GitHub Sponsors"></a>
</p>

<p align="center">
  <a href="https://agentcoqui.com/">Website</a> ·
  <a href="https://agentcoqui.com/docs">Docs</a> ·
  <a href="https://agentcoqui.com">Toolkits</a> ·
  <a href="https://github.com/sponsors/carmelosantana">Sponsor</a>
</p>
<!-- markdownlint-enable MD033 -->

Coqui is your personal operating system for AI work: a lightweight, hackable agent runtime for coding, research, automation, and long-running projects.

Built on [`php-agents`](https://github.com/carmelosantana/php-agents), it gives you a REPL, persistent memory, background tasks, schedules, artifacts, and runtime-extensible toolkits in one PHP 8.4 runtime you can inspect, modify, and self-host.

Start with [Installation](#installation), [Getting Started](https://docs.agentcoqui.com/getting-started), [Features](https://docs.agentcoqui.com/features), or browse the docs at [docs.agentcoqui.com](https://docs.agentcoqui.com).

Join the [Discord community](https://discord.gg/TaCpZVqbbT) to follow along, ask questions, and share your creations!

## Why Coqui?

- **No build or compile steps** — clone, `composer install`, run. That's it.
- **Hackable and readable** — plain PHP 8.4, strict types, one class per file. Read it, change it, own it.
- **Fast load times** — cold boot in ~78 ms with OPcache and JIT.
- **Low memory footprint** — 10–30 MB per process, even with dozens of tools loaded.
- **Scheduled tasks & background processes** — cron-style automation and isolated long-running agents that work while you don't.
- **Lean projects built in** — named working scopes backed by workspace directories, plus versioned artifacts and multi-iteration loops for structured work of any kind.

## Why PHP?

- **Low resources, fast, fault tolerant** — single-process, no garbage collector pauses, battle-tested in production for decades.
- **Friendly language** — readable syntax with a massive ecosystem and community.
- **Easy to read and understand** — approachable even for non-programmers who want to inspect or modify agent behavior.
- **Easy to host** — runs anywhere PHP runs: shared hosting, VPS, Docker, serverless. More hosting providers than any other runtime.
- **Self-hosting friendly** — no complex infrastructure, no cluster, no cloud dependency.

## Features

- 🤖 [**Multi-Model Orchestration**](docs/FEATURES.md#multi-model-orchestration) — route tasks to the right model with automatic failover
- 🔀 [**Agent Delegation**](docs/FEATURES.md#child-agent-delegation) — spawn specialized agents (coder, researcher, planner, reviewer, muse, philosopher) with role-appropriate models
- 🧠 [**Memory Persistence**](docs/FEATURES.md#memory-persistence) — cross-session memory with SQLite, FTS5, and optional vector embeddings
- 📦 [**Runtime Extensibility**](docs/FEATURES.md#runtime-extensibility) — install Composer toolkits at runtime; browse [coqui.space](https://agentcoqui.com)
- 🔐 [**Credential Management**](docs/FEATURES.md#credential-management) — declarative `.env`-based secrets with hot-reload and automatic guards
- 📋 [**Skills System**](docs/FEATURES.md#skills-system) — teach Coqui any workflow with plain Markdown files — no code required
- ⏰ [**Scheduled Tasks**](docs/FEATURES.md#scheduled-tasks) — cron-style automation with circuit breakers
- 🏗️ [**Background Tasks**](docs/FEATURES.md#background-tasks) — isolated processes for long-running work
- 🔁 [**Loops**](docs/FEATURES.md#loops) — fully automated multi-iteration workflows chaining roles in sequence
- 🧩 [**Cognitive Flexibility**](docs/FEATURES.md#cognitive-flexibility) — creative muse and philosopher roles, diverge-converge loops, sketch/hypothesis artifacts
- 🗂️ [**Artifacts & Plans**](docs/FEATURES.md#artifacts-and-plans) — versioned plan artifacts with draft→review→final lifecycle
- 🔧 [**Toolkit Visibility**](docs/FEATURES.md#toolkit-visibility) — 3-tier model (enabled/stub/disabled) to reduce token usage
- 🛡️ [**Layered Safety**](docs/FEATURES.md#layered-safety) — 5-layer security: sandbox → sanitizer → blacklist → approval → audit
- 🌐 [**HTTP API**](docs/FEATURES.md#http-api) — async REST + SSE server for dashboards and headless automation
- 💾 [**Persistent Sessions**](docs/FEATURES.md#persistent-sessions) — SQLite-backed conversations that survive restarts
- 👁️ [**Vision Analysis**](docs/FEATURES.md#vision-analysis) — analyze images from URLs, files, or base64 data
- 🪶 [**Soul**](docs/FEATURES.md#soul) — customizable orchestrator identity via `prompts/soul.md`

See [docs/FEATURES.md](docs/FEATURES.md) for the full feature reference with usage examples and token efficiency strategies.

## Platform Support

| Platform | Support Level | Notes |
| --- | --- | --- |
| Linux | Fully supported | Recommended native environment |
| macOS | Fully supported | Recommended native environment |
| WSL2 | Supported on Windows | Recommended Windows install path |
| Docker | Supported, with some terminal caveats | Containerized alternative when you do not want WSL2 |

For the best experience on Windows, use the WSL2 installer path first. Docker remains a containerized alternative when you do not want a WSL-based workflow.

## Requirements

- PHP 8.4 or later
- Core extensions: `dom`, `mbstring`, `pdo_sqlite`, `xml`
- Recommended extensions: `curl`, `readline`, `zip`
- Optional extensions: `gd` for bundled image previews, `pcntl` and `posix` on Linux or macOS for background task management
- [Ollama](https://ollama.ai) (recommended for local inference)

Or use **Docker** — no local PHP required. The Docker image includes the default batteries-included extension set. See [Docker](#docker) below.

## Installation

The installer detects your OS, installs PHP 8.4+ and required extensions if missing, downloads the latest Coqui release, verifies the SHA-256 checksum, and adds `coqui` to your PATH — no Git or Composer required.

### Linux / macOS / WSL2

```bash
curl -fsSL https://agentcoqui.com/install | bash
```

### Windows (WSL2 Bootstrap)

Run the Windows bootstrap in PowerShell. It checks for WSL2, offers to install Ubuntu when needed, and then runs the standard Coqui installer inside WSL.

```powershell
irm https://raw.githubusercontent.com/carmelosantana/coqui-installer/main/install.ps1 | iex
```

### Update

Re-run the same install command. The installer detects an existing installation and updates it automatically.

### Inspect before running

- Linux / macOS / WSL2: [install.sh](https://raw.githubusercontent.com/carmelosantana/coqui-installer/main/install.sh)
- Windows bootstrap: [install.ps1](https://raw.githubusercontent.com/carmelosantana/coqui-installer/main/install.ps1)

### Development Install

Clone the repository and install dependencies manually. Requires PHP 8.4+, Composer 2.x, and Git.

```bash
git clone https://github.com/carmelosantana/coqui.git
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

`./bin/coqui` is the main entry point. It starts the launcher-managed app: REPL in the foreground plus the API server in the background on port 3300.

The launcher-managed app handles:

- **Clean exit** (exit code 0) — `/quit` stops the launcher and all background services
- **Restart** (exit code 10) — `/restart` or the `restart_coqui` tool triggers an immediate relaunch
- **Crash recovery** — unexpected exits auto-relaunch up to 3 consecutive times
- **Service management** — `./bin/coqui status`, `stop`, and `cleanup` to manage background services and reclaim stale Coqui-owned processes

If a previous session left stale Coqui processes behind, run:

```bash
./bin/coqui cleanup
```

`cleanup` only targets stale or conflicting Coqui-owned processes for this checkout. It does not blindly kill unrelated PHP processes.

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

### Getting Started

Once you're in the REPL:

1. **Have a conversation** — ask questions, request code changes, or describe a task
2. **Try a different role** — `/role coder` for focused coding, `/role plan` for structured planning
3. **Extend with mods** — browse [agentcoqui.com](https://agentcoqui.com), install with `/mods install <package>`, then restart Coqui to activate newly discovered tools and toolkit-provided REPL commands
4. **Run API-only mode when needed** — `coqui --api-only`
5. **Explore models** — map roles to models in `openclaw.json` for cost-optimized routing

See [docs/ROLES.md](docs/ROLES.md) for all built-in roles and [docs/COMMANDS.md](docs/COMMANDS.md) for the full command reference.

### CLI Options

| Option | Short | Description |
| --- | --- | --- |
| `--config` | `-c` | Path to `openclaw.json` config file |
| `--wizard` | `-w` | Run the setup wizard |
| `--new` | | Start a fresh session |
| `--session` | `-s` | Resume a specific session by ID |
| `--workdir` | | Working directory / project root |
| `--workspace` | | Workspace directory override |
| `--unsafe` | | Disable PHP script sanitization |
| `--auto-approve` | | Auto-approve all tool executions |
| `--no-terminal` | | Headless mode: run a single prompt without the REPL |
| `--update` | | Check for and apply dependency updates |

See [docs/COMMANDS.md](docs/COMMANDS.md) for the full CLI reference including `api`, `setup`, and `doctor` subcommands.

## REPL Commands

| Command | Description |
| --- | --- |
| `/new` | Start a new session |
| `/sessions` | List all saved sessions |
| `/resume <id>` | Resume a session by ID |
| `/role [name]` | Show/switch active role |
| `/profile [name]` | Show/switch the active profile |
| `/group` | Inspect or manage orchestrator-led group sessions |
| `/toolkits` | Manage toolkit visibility |
| `/prompt` | Inspect or export the rendered system prompt |
| `/image` | Generate and manage workspace images |
| `/projects` | List projects or switch the active project |
| `/tasks [status]` | List background tasks |
| `/schedules` | List scheduled tasks |
| `/loops` | List and manage automated loops |
| `/mods` | Mods marketplace via the external manager toolkit |
| `/help` | Show the compact command reference |
| `/summarize` | Summarize conversation for token savings |
| `/quit` | Exit Coqui |

See [docs/COMMANDS.md](docs/COMMANDS.md) for the full command reference with examples.

## Providers & OpenClaw Config

Coqui uses an `openclaw.json` config file for centralized model routing. The format is fully compatible with [OpenClaw](https://github.com/openclaw/openclaw) — you can drop in your existing OpenClaw config and it works without any changes. Coqui-specific extensions (workspace, mounts, shell access control) live under `agents.defaults` and are safely ignored by other OpenClaw-compatible tools.

Config changes require a restart to take effect. Use `/restart` in the REPL or restart the process after editing `openclaw.json`.

For the full config reference, see [docs/CONFIGURATION.md](docs/CONFIGURATION.md).

### Supported Providers

| Provider | Protocol | API Key Env Var |
| --- | --- | --- |
| Ollama (local) | `openai-completions` | — |
| OpenAI | `openai-completions` | `OPENAI_API_KEY` |
| OpenAI Responses | `openai-responses` | `OPENAI_API_KEY` |
| Anthropic | `anthropic` | `ANTHROPIC_API_KEY` |
| OpenRouter | `openai-completions` | `OPENROUTER_API_KEY` |
| xAI (Grok) | `openai-completions` | `XAI_API_KEY` |
| Google Gemini | `gemini` | `GEMINI_API_KEY` |
| Mistral | `mistral` | `MISTRAL_API_KEY` |
| MiniMax | `openai-completions` | `MINIMAX_API_KEY` |

> **OpenAI Responses API** — use `openai-responses` for Codex models (e.g. `openai/codex-mini`). Standard OpenAI models use `openai-completions`.

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

Coqui ships with a rich set of tools organized into toolkits:

| Category | Key Tools | Description |
| --- | --- | --- |
| **Agent** | `spawn_agent`, `restart_coqui` | Delegate to child agents, restart Coqui |
| **Filesystem** | `read_file`, `write_file`, `replace_in_file`, `edit_history` | Sandboxed file I/O, surgical edits, undo history |
| **Shell** | `exec` | Run shell commands (open by default; opt-in allowlist via `shellAllowedCommands`, `cwd` support) |
| **Code** | `php_execute` | Execute PHP in a sandboxed subprocess |
| **Memory** | `memory_save`, `memory_search` | Persistent cross-session memory |
| **Planning** | `artifact_create` | Versioned artifacts |
| **Projects** | `project_create`, `project_switch` | Lean working scopes backed by workspace directories |
| **Scheduling** | `schedule_create` | Cron-style automation |
| **Loops** | `loop_start`, `loop_status`, `loop_definitions` | Automated multi-iteration workflows chaining roles |
| **Vision** | `vision_analyze` | Multi-provider image analysis |
| **Credentials** | `credentials` | Secure `.env`-based secret storage |

Toolkits from the [Coqui Mods marketplace](https://agentcoqui.com) and local workspace packages add more: GitHub, Brave Search, browser automation, Canva, Cloudflare, image generation, and more.

## Extending Coqui

Coqui auto-discovers toolkits from installed Composer packages. Create a package that implements `ToolkitInterface`, register it in `composer.json`, and Coqui picks it up automatically — including credentials and gated operations.

Toolkit-provided REPL commands follow the same boot-time discovery path. After `/mods install <package>` or a manual Composer install, restart Coqui to activate newly discovered tools and slash commands.

See [docs/TOOLKITS.md](docs/TOOLKITS.md) for the full walkthrough with examples.

## Performance

Coqui is optimized for low-latency agent loops. Key design decisions:

| Metric | Value | Notes |
| --- | --- | --- |
| Cold boot | ~78 ms | Autoload + BootManager + workspace init |
| Memory at boot | ~4 MB | Before toolkit discovery |
| Memory with toolkits | ~8 MB | 44 tools, 7 packages |
| Source files | ~40K lines | 157 PHP files in `src/` |
| Runtime dependencies | 8 direct, 27 total | Minimal dependency tree |

### OPcache & JIT

Coqui ships with a tuned `conf.d/coqui.ini` that enables OPcache and JIT (tracing mode 1255, 128MB buffer). The installer and `coqui doctor` check for proper OPcache/JIT configuration.

For best performance in a non-debug CLI, ensure your PHP CLI has OPcache enabled and JIT available:

```ini
opcache.enable_cli=1
opcache.jit=1255
opcache.jit_buffer_size=128M
```

If you keep `xdebug` or `pcov` loaded in your everyday local CLI, disable JIT locally instead of trying to silence the startup warning. Those extensions make JIT unavailable anyway.

```ini
opcache.enable_cli=1
opcache.jit=0
opcache.jit_buffer_size=0
```

On Homebrew PHP this is usually easiest as a late-loading override such as `/opt/homebrew/etc/php/8.4/conf.d/zz-local-no-jit.ini`.

### Benchmarking

Run the built-in benchmark command to measure performance on your system:

```bash
coqui benchmark
coqui benchmark --json          # Machine-readable output
coqui benchmark -i 500          # Custom iteration count
```

### SQLite Tuning

Coqui configures SQLite for CLI workloads: WAL journal mode, `synchronous=NORMAL`, 8MB page cache, and in-memory temp storage. These PRAGMAs reduce fsync overhead and improve query throughput for the single-user agent use case.

## Docker

> Docker is the supported containerized alternative on Windows when you do not want to use WSL2. GPU passthrough and some terminal features may still behave differently. Please [report issues](https://github.com/carmelosantana/coqui/issues).

Run Coqui in a container with zero host dependencies. The Docker setup uses `php:8.4-cli` with all required extensions and Composer.

The image includes the default batteries-included extension set: `dom`, `curl`, `gd`, `mbstring`, `pdo_sqlite`, `readline`, `xml`, `zip`, and `pcntl`.

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

### Profiling UI (Docker)

If you generate Xdebug cachegrind files locally under `build/profiles`, you can inspect them in a browser with Webgrind:

```bash
make docker-webgrind-up
open http://localhost:3390
```

This overlay is UI-only. It reads host-generated profile files and leaves the base Coqui image unchanged.

Or copy `.env.example` to `.env` and fill in your keys:

```bash
cp .env.example .env
```

### Connect to Ollama

Coqui connects to Ollama on your host machine via `host.docker.internal`. Make sure Ollama is running:

```bash
ollama serve
```

### Toolkit Discovery In Docker

Coqui discovers Composer-installed toolkits and toolkit-provided REPL commands on boot inside the container just like it does natively. After installing a toolkit with `/mods install <package>` or a workspace Composer command, restart the REPL or API container so the new tools and slash commands are registered.

### Useful Commands

| Command | Description |
| --- | --- |
| `make start` | Start REPL + API (native) |
| `make stop` | Stop all native services |
| `make status` | Show service status |
| `make cleanup` | Clean stale/conflicting native Coqui processes |
| `make repl` | REPL only (native) |
| `make api` | API only (native, `HOST=0.0.0.0` for network access) |
| `make docker-start` | REPL + API (Docker) |
| `make docker-repl` | REPL only (Docker) |
| `make docker-api` | API only (Docker) |
| `make docker-shell` | Bash shell in container |
| `make install` | Run `composer install` |
| `make clean` | Remove containers, images, volumes |
| `make help` | Show all available targets |

### Configuration

Pass a config file via the main entry point or the explicit launcher name:

```bash
# Native
./bin/coqui --config openclaw.json

# Docker
docker compose run --rm -v ./openclaw.json:/app/openclaw.json:ro coqui
```

### File Overview

| File | Purpose |
| --- | --- |
| `Dockerfile` | PHP 8.4 CLI + extensions + Composer |
| `compose.yaml` | Base service with workspace volume + host Ollama access |
| `compose.api.yaml` | API server service (port 3300) — runs alongside REPL |
| `Makefile` | Self-documenting targets: native (`start`, `api`) and Docker (`docker-*`) |
| `.env.example` | Environment variable documentation |
| `conf.d/coqui.ini` | CLI-optimized PHP config (OPcache + JIT) |

## Documentation

| Document | Description |
| --- | --- |
| [Features](docs/FEATURES.md) | Complete feature reference with usage examples |
| [Commands](docs/COMMANDS.md) | REPL slash commands and CLI reference |
| [Roles](docs/ROLES.md) | Built-in roles, access levels, and custom role creation |
| [Configuration](docs/CONFIGURATION.md) | `openclaw.json` reference |
| [API](docs/API.md) | Canonical HTTP API reference and client integration guide |
| [Background Tasks](docs/BACKGROUND-TASKS.md) | Background task architecture and usage |
| [Loops](docs/LOOPS.md) | Loop definitions, runtime model, and inspection |
| [Projects](docs/PROJECTS.md) | Lean project working scopes |
| [Artifacts](docs/ARTIFACTS.md) | Artifact lifecycle and versioning |
| [Testing](docs/TESTING.md) | Test layout, local commands, coverage, and PCOV/Xdebug setup |
| [Toolkits](docs/TOOLKITS.md) | Creating toolkit packages |
| [Skills](docs/SKILLS.md) | Skills system and schema |
| [GitHub Actions](docs/GITHUB-ACTIONS.md) | CI/CD integration |

## Community

We're building a community where people share agents, ask for help, and collaborate on new toolkits.

- **Discord** — [Join us](https://discord.gg/TaCpZVqbbT) for support, discussions, and sharing your toolkits
- **GitHub** — [carmelosantana/coqui](https://github.com/carmelosantana/coqui) for issues, PRs, and source code

## Contributing

We'd love your help making Coqui even mightier:

- **Build new toolkits** — create Composer packages that implement `ToolkitInterface`
- **Add loops & background tasks** — automate multi-iteration workflows and long-running processes
- **Improve tools** — enhance existing tools or add new ones in `src/Tool/`
- **Write tests** — expand coverage in `tests/Unit/`
- **Fix bugs & improve docs** — every contribution counts

See [AGENTS.md](AGENTS.md) for code conventions and architecture guidelines.

> **Book a 1:1 call** — paid sessions for real-time implementation help, AI agent consulting, or just to support Coqui's active development. [Schedule time →](https://cal.com/carmelosantana/coqui-1:1)

## License

MIT
