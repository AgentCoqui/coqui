# Configuration

Coqui uses an `openclaw.json` file as its single source of configuration. This format is fully compatible with the [OpenClaw](https://github.com/openclaw/openclaw) standard, meaning you can use an existing OpenClaw config file with Coqui without any modifications.

## Config File Location

Coqui resolves the config file in this order:

1. **`--config` CLI flag** — explicit path to a config file
2. **`./openclaw.json`** — in the current working directory
3. **Bundled default** — the `openclaw.json` shipped with Coqui
4. **Setup wizard** — if no config exists in interactive mode, the wizard runs automatically

```bash
# Use a specific config file
coqui --config /path/to/openclaw.json

# Default: looks for ./openclaw.json in the working directory
coqui

# Run the setup wizard directly — no REPL, no session
coqui --wizard
coqui -w
```

## How Config Changes Are Applied

Config changes require a restart to take effect. Coqui loads configuration once at boot and constructs all internal components from it. A restart ensures every component is freshly initialized with the new values.

**After editing config, restart using one of these methods:**

| Change Source | How Restart Happens |
|---------------|-------------------|
| `coqui --wizard` / `coqui -w` | Edit config without starting the REPL — changes apply on next launch |
| `/config edit` (setup wizard) | Coqui prompts: "Restart now to apply?" — confirm to restart immediately |
| API (`POST /api/v1/config/validate`) | Validation only — apply changes through the REPL or a manual edit, then restart |
| Manual edit in your editor | Use `/restart` in the REPL, or the `restart_coqui` agent tool |
| Agent `config` tool (set/switch_model) | Agent can call `restart_coqui`, or you can use `/restart` |

A restart re-reads `openclaw.json`, re-discovers toolkit packages, re-seeds roles, and reconstructs all providers and resolvers from scratch.

## Config Schema

### Minimal Config

The simplest valid config only needs a primary model:

```json
{
    "agents": {
        "defaults": {
            "model": {
                "primary": "ollama/qwen3:latest"
            }
        }
    }
}
```

### Full Config Reference

```json
{
    "agents": {
        "defaults": {
            "model": {
                "primary": "ollama/qwen3:latest",
                "fallbacks": ["ollama/llama3.2:latest"],
                "utility": "ollama/gemma3:4b"
            },
            "imageModel": {
                "primary": "ollama/jmorgan/z-image-turbo:fp8",
                "fallbacks": [
                    "openai/gpt-image-1.5",
                    "ollama/x/z-image-turbo:latest",
                    "ollama/x/flux2-klein:4b-fp8"
                ],
                "vendors": {
                    "openai": {
                        "model": "gpt-image-1.5",
                        "baseUrl": "https://api.openai.com/v1",
                        "quality": "standard",
                        "size": "1024x1024"
                    },
                    "ollama": {
                        "model": "jmorgan/z-image-turbo:fp8",
                        "host": "http://localhost:11434"
                    }
                }
            },
            "roles": {
                "orchestrator": "ollama/qwen3:latest",
                "coder": "anthropic/claude-opus-4-6",
                "reviewer": "openai/gpt-4.1",
                "vision": "gemini/gemini-2.5-flash"
            },
            "workspace": "~/.coqui/.workspace",
            "profile": "caelum",
            "maxIterations": 256,
            "backgroundTaskMaxIterations": 512,
            "childBackgroundTasks": false,
            "shellAllowedCommands": ["php", "git", "grep", "find", "cat", "ls"],
            "allowSudo": false,
            "blacklist": ["/pattern-to-block/i"],
            "mounts": [
                {
                    "path": "/home/user/data",
                    "alias": "data",
                    "access": "ro",
                    "description": "Shared datasets"
                }
            ],
            "memory": {
                "embeddingModel": "openai/text-embedding-3-small"
            },
            "context": {
                "autoSummarizeMode": "token",
                "autoSummarizeThreshold": 64,
                "autoSummarizeTurnThreshold": 20,
                "autoSummarizeKeepRecent": 15,
                "keepRecentTurns": 10,
                "budgetSafetyMarginPercent": 20,
                "budgetExitThreshold": 0.85,
                "budgetExitWrapUpIterations": 2
            },
            "evaluation": {
                "lookbackHours": 24,
                "inactivityHours": 3,
                "minTurns": 2
            }
        }
    },
    "models": {
        "mode": "merge",
        "providers": {
            "ollama": {
                "baseUrl": "http://localhost:11434/v1",
                "api": "openai-completions",
                "models": []
            }
        }
    },
    "api": {
        "key": "your-api-key",
        "tasks": {
            "maxConcurrent": 6
        }
    }
}
```

## Agent Defaults (`agents.defaults`)

### `model`

The primary model used when no role-specific mapping exists.

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `primary` | string | yes | Model string in `provider/model` format |
| `fallbacks` | string[] | no | Fallback models tried in order if the primary fails |
| `utility` | string | no | Cheap/fast model for internal tasks (titles, summaries, memory compression) |

```json
{
    "model": {
        "primary": "ollama/qwen3:latest",
        "fallbacks": ["ollama/llama3.2:latest", "openai/gpt-4.1-mini"],
        "utility": "ollama/gemma3:4b"
    }
}
```

**Utility model resolution**: `model.utility` → `COQUI_UTILITY_MODEL` env var → title-generator role model → primary model.

### `imageModel`

Separate defaults for image-generation toolkits and the `/image` REPL command. This config is independent from the active chat or role model.

| Key | Type | Required | Description |
|-----|------|----------|-------------|
| `primary` | string | no | Default image model in `provider/model` format |
| `fallbacks` | string[] | no | Fallback image models tried in order by image-capable toolkits |
| `ownerName` | string | no | Default metadata owner name embedded into generated images unless explicitly overridden |
| `choices` | object | no | Optional curated image-model choices used by the setup wizard |
| `vendors` | object | no | Vendor-specific defaults such as `model`, `baseUrl`, `host`, `quality`, and `size` |

```json
{
    "imageModel": {
        "primary": "ollama/jmorgan/z-image-turbo:fp8",
        "fallbacks": [
            "openai/gpt-image-1.5",
            "ollama/x/z-image-turbo:latest",
            "ollama/x/flux2-klein:4b-fp8"
        ],
        "vendors": {
            "openai": {
                "model": "gpt-image-1.5",
                "baseUrl": "https://api.openai.com/v1",
                "quality": "standard",
                "size": "1024x1024"
            },
            "ollama": {
                "model": "jmorgan/z-image-turbo:fp8",
                "host": "http://localhost:11434"
            }
        }
    }
}
```

Current first-party image support targets `openai` and `ollama`. For Ollama, Coqui checks whether the resolved image model is already available locally and asks for confirmation before pulling a missing model.

### `roles`

Map agent roles to specific models. This enables cost-efficient orchestration where the orchestrator uses a fast, cheap model and delegates expensive work to stronger models.

| Role | Description | Default |
|------|-------------|---------|
| `orchestrator` | Routes tasks, handles simple queries | Primary model |
| `coder` | Writes and refactors code | Primary model |
| `reviewer` | Reviews code for bugs, security, style | Primary model |
| `vision` | Analyzes images | Primary model |

Custom roles defined in `workspace/roles/` are also resolved here.

```json
{
    "roles": {
        "orchestrator": "openai/gpt-4.1-mini",
        "coder": "anthropic/claude-opus-4-6",
        "reviewer": "openai/gpt-4.1",
        "vision": "gemini/gemini-2.5-flash"
    }
}
```

**Resolution priority**: role file `model` field > `agents.defaults.roles` mapping > primary model.

### `workspace`

The sandboxed directory where Coqui reads and writes files. Supports `~` (home directory), relative paths (resolved against the project root), and absolute paths.

| Value | Behavior |
|-------|----------|
| `~/.coqui/.workspace` | Default — uses a shared workspace in your home directory |
| `/path/to/workspace` | Absolute path to any directory |

**Default behavior** (when not set): uses `~/.coqui/.workspace` in your home directory. This prevents session sprawl across directories.

### `profile`

Optional default startup profile name. The value must match a directory under `workspace/profiles/{name}/` that contains a `soul.md` file.

```json
{
    "profile": "caelum"
}
```

When set, Coqui tries to reattach the current `.coqui-session` if it already belongs to that profile. Otherwise it resumes the most recent session for that profile or creates a new one.

### `maxIterations`

Global limit on agent loop iterations per turn. Each iteration is one LLM call that may include tool use. Default: `256`.

Set to `0` for unlimited iterations (the agent runs until it calls the `done` tool or encounters an error). Background tasks are clamped separately via `backgroundTaskMaxIterations`.

Per-role overrides are configured in role `.md` files via the `max_iterations` frontmatter field.

### `backgroundTaskMaxIterations`

Maximum iterations any single background task can run. This is a per-task safety limit that prevents unattended tasks from running indefinitely. Default: `512`.

```json
{
    "agents": {
        "defaults": {
            "backgroundTaskMaxIterations": 512
        }
    }
}
```

This cap applies to all background tasks: those created via `start_background_task`, webhook-triggered tasks, schedule-triggered tasks, and API-created tasks.

### `childBackgroundTasks`

When `true`, child agents spawned via `spawn_agent` with `full` access level can create their own background tasks. Default: `false`.

```json
{
    "agents": {
        "defaults": {
            "childBackgroundTasks": true
        }
    }
}
```

**Warning:** Enabling this allows child agents to spawn background tasks, which consume LLM tokens and system resources. Background tasks spawned by children cannot spawn further background tasks (recursion is bounded to 2 levels).

### `shellAllowedCommands`

An opt-in restrictive allowlist for shell commands. When omitted, all commands are permitted (open-by-default mode), subject to built-in deny patterns and the `allowSudo` setting below. When set to a non-empty array, only commands whose first word matches the list are permitted, and shell metacharacters (`;`, `&&`, `|`, `$(...)`, backticks) are also blocked to prevent allowlist bypass.

```json
{
    "agents": {
        "defaults": {
            "shellAllowedCommands": [
                "php", "git", "grep", "find", "cat", "head", "tail", "wc", "ls",
                "curl", "wget", "make", "sort", "uniq", "sed", "awk", "diff"
            ]
        }
    }
}
```

Omit the key entirely to keep the default open-by-default behavior.

### `allowSudo`

Controls whether the `sudo` command is permitted. Defaults to `false` (sudo is blocked via the denied-commands list). Set to `true` to allow sudo — it will still be subject to `CatastrophicBlacklist` and `InteractiveApprovalPolicy`.

```json
{
    "agents": {
        "defaults": {
            "allowSudo": true
        }
    }
}
```

> **`exec` `cwd` parameter** — the `exec` tool accepts an optional `cwd` argument. Relative paths are resolved from the default working directory (project root). If the path does not exist or is not a directory, the tool returns an error.

### `blacklist`

Additional regex patterns to add to the catastrophic blacklist. These patterns block commands regardless of `--auto-approve` or `--unsafe` mode. The hardcoded patterns (`rm -rf /`, `shutdown`, fork bombs, etc.) cannot be removed.

```json
{
    "blacklist": [
        "/\\bdrop\\s+database\\b/i",
        "/\\btruncate\\b/i"
    ]
}
```

### `mounts`

Declare external directory mounts that give agents access to directories outside the workspace. Mounts appear as symlinks under `workspace/mnt/{alias}`.

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| `path` | yes | — | Absolute path to the external directory (must exist) |
| `alias` | yes | — | Short name used as the symlink name |
| `access` | no | `ro` | `ro` (read-only) or `rw` (read-write) |
| `description` | no | `''` | Description shown in the agent's storage map |

```json
{
    "mounts": [
        {
            "path": "/home/user/datasets",
            "alias": "datasets",
            "access": "ro",
            "description": "Training datasets (read-only)"
        },
        {
            "path": "/home/user/projects/my-app",
            "alias": "my-app",
            "access": "rw",
            "description": "External application source code"
        }
    ]
}
```

**Access control**:
- Mounts default to read-only unless explicitly set to `rw`
- Child agents (spawned via `spawn_agent`) always get read-only access regardless of the mount's declared access level
- Write protection is enforced at the filesystem toolkit level

### `memory`

Configure the memory system's embedding provider for semantic search.

| Key | Type | Description |
|-----|------|-------------|
| `embeddingModel` | string | Embedding provider in `provider/model` format |
| `enabled` | bool | Set to `false` to disable memory embeddings entirely |

```json
{
    "memory": {
        "embeddingModel": "ollama/nomic-embed-text"
    }
}
```

**Auto-detection**: If no embedding model is configured but an `OPENAI_API_KEY` is set, Coqui automatically uses `text-embedding-3-small`. Without any embedding provider, memory still works using SQLite FTS5 keyword search.

### `context`

Configure automatic conversation summarization behavior.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `autoSummarizeMode` | string | `"token"` | Summarization trigger mode: `"token"` (trigger on context window usage), `"turn"` (trigger after N user turns), or `"manual"` (no auto-summarization; use `/summarize` on demand) |
| `autoSummarizeThreshold` | int/float | `64` | Token usage percentage that triggers auto-summarization (used when mode is `"token"`). Accepts 1–100 (percentage) or 0.0–1.0 (ratio, auto-converted) |
| `autoSummarizeTurnThreshold` | int | `20` | Number of user turns that triggers auto-summarization (used when mode is `"turn"`) |
| `autoSummarizeKeepRecent` | int | `15` | Turns preserved during auto-summarization (clamped 1–20) |
| `keepRecentTurns` | int | `10` | Default turns preserved during on-demand summarization (`/summarize`) |
| `budgetSafetyMarginPercent` | int | `20` | Safety margin percentage applied by per-iteration budget pruning to account for token estimation inaccuracy (0–50) |
| `budgetExitThreshold` | float | `0.85` | Context window usage ratio (0.0–1.0) based on the latest provider-reported usage for the current iteration. When crossed, Coqui injects a wrap-up instruction and the agent has `budgetExitWrapUpIterations` iterations to call `done()` before it is force-exited. Set to `0.0` to disable |
| `budgetExitWrapUpIterations` | int | `2` | Number of iterations the agent has to wrap up after the budget exit threshold is crossed. Must be ≥ 1 |

```json
{
    "context": {
        "autoSummarizeMode": "token",
        "autoSummarizeThreshold": 64,
        "autoSummarizeKeepRecent": 32,
        "keepRecentTurns": 24
    }
}
```

**Summarization modes:**

- **`token`** (default) — Summarizes when estimated token usage exceeds `autoSummarizeThreshold` percent of the effective context window. This is the recommended mode: it preserves as much conversation as possible while preventing context overflow.
- **`turn`** — Summarizes after `autoSummarizeTurnThreshold` user turns, regardless of token usage. Useful for predictable summarization behavior on smaller context models.
- **`manual`** — Disables all automatic pre-turn summarization. Use the `/summarize` REPL command, the `summarize_conversation` agent tool, or the API endpoint to summarize on demand. The per-iteration `SummarizePruningStrategy` safety net still fires to prevent context window overflow during agent execution.

Regardless of mode, the per-iteration budget pruning strategy always runs as a safety net to prevent the conversation from exceeding the model's context window within a single turn.

**Budget-based exit:**

When `budgetExitThreshold` is set (default `0.85`), the agent monitors the latest provider-reported context usage for each iteration as a percentage of the effective context window. When usage crosses the threshold, php-agents emits a generic budget warning event and Coqui reacts by injecting a workflow-aware wrap-up instruction that preserves todos, artifacts, and sprint state. The agent then has `budgetExitWrapUpIterations` iterations to call `done()`. If it does not exit gracefully within that wrap-up window, the turn ends with a `budget_exhausted` finish reason.

This budget-based exit complements `maxIterations`; it does not replace the iteration limit. A turn can still stop because the configured iteration cap was reached before or after any budget warning.

### `evaluation`

Configure the session evaluation system.

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `lookbackHours` | int | `24` | How far back to search for sessions to evaluate |
| `inactivityHours` | int | `3` | Minimum hours since last activity before a session is eligible |
| `minTurns` | int | `2` | Minimum turns for a session to be worth evaluating |

```json
{
    "evaluation": {
        "lookbackHours": 24,
        "inactivityHours": 3,
        "minTurns": 2
    }
}
```

The evaluator model is configured via the roles mapping: `"roles": {"evaluator": "ollama/gemma3:4b"}`.

## Model Providers (`models.providers`)

Each provider is a named entry under `models.providers` with connection settings and an optional model catalog.

### Provider Configuration

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `baseUrl` | string | yes | API endpoint URL |
| `apiKey` | string | no | API key (prefer environment variables instead) |
| `api` | string | yes | API protocol: `openai-completions`, `openai-responses`, `anthropic`, `gemini`, `mistral` |
| `models` | array | no | Model catalog with capabilities and parameters |

### Supported Providers

| Provider | `api` Protocol | Env Variable | Default Base URL |
|----------|---------------|-------------|-----------------|
| Ollama | `openai-completions` | — | `http://localhost:11434/v1` |
| OpenAI | `openai-completions` | `OPENAI_API_KEY` | `https://api.openai.com/v1` |
| Anthropic | `anthropic` | `ANTHROPIC_API_KEY` | `https://api.anthropic.com/v1` |
| OpenRouter | `openai-completions` | `OPENROUTER_API_KEY` | `https://openrouter.ai/api/v1` |
| xAI (Grok) | `openai-completions` | `XAI_API_KEY` | `https://api.x.ai/v1` |
| Google Gemini | `gemini` | `GEMINI_API_KEY` | `https://generativelanguage.googleapis.com/v1beta` |
| Mistral | `mistral` | `MISTRAL_API_KEY` | `https://api.mistral.ai/v1` |
| MiniMax | `openai-completions` | `MINIMAX_API_KEY` | `https://api.minimax.chat/v1` |

Any OpenAI-compatible provider can be added using `openai-completions` as the API protocol.

### Model Catalog

Each model entry describes capabilities and parameters:

```json
{
    "id": "qwen3:latest",
    "name": "Qwen 3",
    "reasoning": false,
    "input": ["text"],
    "contextWindow": 128000,
    "maxTokens": 8192,
    "alias": "qwen",
    "numCtx": 32768,
    "cost": {
        "input": 0,
        "output": 0,
        "cacheRead": 0,
        "cacheWrite": 0
    }
}
```

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `id` | string | — | Model identifier as recognized by the provider |
| `name` | string | `id` | Display name |
| `reasoning` | bool | `false` | Whether this is a reasoning/chain-of-thought model |
| `input` | string[] | `["text"]` | Input capabilities: `text`, `image`, `audio` |
| `contextWindow` | int | `4096` | Maximum context window in tokens |
| `maxTokens` | int | `2048` | Maximum output tokens |
| `alias` | string | — | Short alias for quick reference (e.g., `"opus"`) |
| `numCtx` | int | — | Ollama-specific context override (useful for memory-constrained setups) |
| `cost` | object | — | Token pricing for cost tracking |

### `models.mode`

Controls how the model catalog is built:

| Mode | Behavior |
|------|----------|
| `merge` | Append your declared models to the provider's discovered models |
| `override` | Use only your declared models, ignore discovery |

If omitted, models are resolved via provider-specific discovery (e.g., Ollama's model list endpoint).

## API Configuration (`api`)

Settings for the HTTP API server (`coqui api`).

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `api.key` | string | — | API authentication key (required for network-bound hosts) |
| `api.tasks.maxConcurrent` | int | `6` | Maximum concurrent background tasks |

## Environment Variable Overrides

Several settings can be overridden via environment variables. These take precedence over `openclaw.json` values for their respective concerns:

| Variable | Purpose |
|----------|---------|
| `OPENAI_API_KEY` | OpenAI API key |
| `ANTHROPIC_API_KEY` | Anthropic API key |
| `XAI_API_KEY` | xAI API key |
| `GEMINI_API_KEY` | Google Gemini API key |
| `MISTRAL_API_KEY` | Mistral API key |
| `OPENROUTER_API_KEY` | OpenRouter API key |
| `MINIMAX_API_KEY` | MiniMax API key |
| `OLLAMA_HOST` | Ollama base URL (useful for Docker: `http://host.docker.internal:11434`) |
| `COQUI_CHECK_UPDATES` | Check for updates on startup (`true`/`false`, default: `true`) |
| `COQUI_AUTO_UPDATE` | Auto-apply updates on startup (`true`/`false`, default: `false`) |
| `COQUI_AUTO_APPROVE` | Auto-approve tool executions (`true`/`false`, env equivalent of `--auto-approve`) |
| `COQUI_UNSAFE` | Disable script sanitization (`true`/`false`, env equivalent of `--unsafe`) |

API keys set via environment variables are checked fresh on every agent turn, so you can update them at runtime without restarting.

## OpenClaw Compatibility

Coqui natively supports the [OpenClaw](https://github.com/openclaw/openclaw) configuration format. You can use your existing `openclaw.json` with Coqui without any modifications.

### Shared Format (OpenClaw Standard)

These config sections are part of the OpenClaw standard and work identically across OpenClaw-compatible tools:

- **`models.providers.*`** — provider connection settings (baseUrl, apiKey, api protocol)
- **`models.providers.*.models[]`** — model catalog with capabilities and parameters
- **`models.mode`** — merge vs override behavior
- **`agents.defaults.model`** — primary model and fallbacks
- **`agents.defaults.roles`** — role-to-model mapping

### Coqui Extensions

Coqui adds the following keys under `agents.defaults` that are specific to Coqui and safely ignored by other OpenClaw-compatible tools:

| Key | Purpose |
|-----|---------|
| `agents.defaults.workspace` | Workspace directory path |
| `agents.defaults.mounts` | External directory mounts |
| `agents.defaults.shellAllowedCommands` | Opt-in shell command allowlist (empty = open-by-default) |
| `agents.defaults.allowSudo` | Allow `sudo` commands (default: `false`) |
| `agents.defaults.maxIterations` | Agent iteration budget |
| `agents.defaults.backgroundTaskMaxIterations` | Per-task background iteration cap |
| `agents.defaults.childBackgroundTasks` | Allow child agents to spawn background tasks |
| `agents.defaults.blacklist` | Additional catastrophic blacklist patterns |
| `agents.defaults.memory` | Memory system configuration |
| `api.*` | HTTP API server settings |

### Drop-in Migration

To use an existing OpenClaw config with Coqui:

1. Copy your `openclaw.json` to the Coqui project directory (or use `--config`)
2. Run `coqui` — it works immediately
3. Optionally add Coqui-specific settings (workspace, mounts, etc.) as needed

To use a Coqui config with OpenClaw:

1. The OpenClaw tool reads the shared `models.*` and `agents.defaults.model/roles` sections
2. Coqui-specific keys are ignored by OpenClaw — no conflicts

## Managing Config

### Setup Wizard

Run the interactive wizard to create or modify your config:

```bash
# First-time setup (runs automatically if no config exists)
coqui setup

# Re-run from within a session
/config edit
```

When an existing `openclaw.json` is detected, the wizard offers **section-based editing** — you choose which sections to reconfigure while preserving all other settings. You can also start fresh if needed.

### REPL Commands

| Command | Description |
|---------|-------------|
| `/config` | Show current config summary |
| `/config show` | Display raw `openclaw.json` content |
| `/config edit` | Re-run the setup wizard |
| `/restart` | Full restart (re-reads config, re-discovers toolkits, re-seeds roles) |

### Credential Management

API keys should be stored as environment variables or in the workspace `.env` file — not directly in `openclaw.json`. The agent manages credentials via the `credentials` tool:

```
credentials(action: "set", key: "OPENAI_API_KEY", value: "sk-...")
```

Credentials set this way are persisted to `workspace/.env` and take effect immediately via `putenv()` hot-reload.

## Architecture Notes

The configuration system is split between two packages:

- **`php-agents`** provides `OpenClawConfig` — a thin config reader with dot-notation access, alias resolution, and model definition parsing. It has no opinion about workspace management, safety, or agent behavior.
- **Coqui** interprets the config through its own `src/Config/` layer: `BootManager` orchestrates the boot sequence, `RoleResolver` maps roles to models, `WorkspaceResolver` handles workspace path resolution, `MountManager` creates directory mounts, and `CatastrophicBlacklist` reads safety patterns.

This separation means `php-agents` remains a general-purpose provider implementation that any project can use, while Coqui owns all the agent-specific behavior built on top of the shared config format.
