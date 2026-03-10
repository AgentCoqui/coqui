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
```

## How Config Changes Are Applied

Coqui automatically detects when `openclaw.json` is modified and reloads configuration before processing your next message. No restart is required.

**Auto-detection** works for all edit sources:
- Editing the file manually in your editor
- Saving changes via the API (`PUT /api/config`)
- Running the setup wizard (`/config edit`)

When a change is detected, Coqui displays:

```
Config changes detected — reloaded automatically.
```

The following are refreshed on reload:
- Model routing and role assignments
- Provider configuration (base URLs, API protocols)
- Workspace path
- Mount definitions
- Catastrophic blacklist patterns
- Max iteration limits

You can also force a full restart with `/restart`, which additionally re-discovers toolkit packages and re-seeds roles.

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
                "fallbacks": ["ollama/llama3.2:latest"]
            },
            "roles": {
                "orchestrator": "ollama/qwen3:latest",
                "coder": "anthropic/claude-opus-4-6",
                "reviewer": "openai/gpt-4.1",
                "vision": "gemini/gemini-2.5-flash"
            },
            "workspace": "~/.coqui/workspace",
            "maxIterations": 25,
            "shellAllowedCommands": ["php", "git", "grep", "find", "cat", "ls"],
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
            "maxConcurrent": 1
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

```json
{
    "model": {
        "primary": "ollama/qwen3:latest",
        "fallbacks": ["ollama/llama3.2:latest", "openai/gpt-4.1-mini"]
    }
}
```

### `roles`

Map agent roles to specific models. This enables cost-efficient orchestration where the orchestrator uses a fast, cheap model and delegates expensive work to stronger models.

| Role | Description | Default |
|------|-------------|---------|
| `orchestrator` | Routes tasks, handles simple queries | Primary model |
| `coder` | Writes and refactors code | Primary model |
| `reviewer` | Reviews code for bugs, security, style | Primary model |
| `vision` | Analyzes images | Primary model |

Custom roles defined in `.workspace/roles/` are also resolved here.

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
| `~/.coqui/workspace` | Default — uses a shared workspace in your home directory |
| `.workspace` | Project-local workspace (dev mode) |
| `/path/to/workspace` | Absolute path to any directory |

**Default behavior** (when not set):
1. If `.workspace/` exists in the current directory, use it (dev mode)
2. Otherwise, use `~/.coqui/workspace` in your home directory

This prevents session sprawl across directories while supporting developer workflows where the workspace lives alongside the project.

### `maxIterations`

Global limit on agent loop iterations per turn. Each iteration is one LLM call that may include tool use. Default: `25`.

Set to `0` for unlimited iterations (the agent runs until it calls the `done` tool or encounters an error). Background tasks are always clamped to 100 regardless of this setting.

Per-role overrides are configured in role `.md` files via the `max_iterations` frontmatter field.

### `shellAllowedCommands`

Restricts which shell commands the agent can execute. When set, only commands whose first word matches the allowlist are permitted.

**Default allowlist** (when not configured):

```json
["php", "git", "grep", "find", "cat", "head", "tail", "wc", "ls",
 "curl", "wget", "make", "sort", "uniq", "sed", "awk", "diff"]
```

Shell metacharacters (`;`, `&&`, `|`, `$(...)`, backticks) are blocked when an allowlist is active to prevent bypass.

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

Declare external directory mounts that give agents access to directories outside the workspace. Mounts appear as symlinks under `.workspace/mnt/{alias}`.

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
| `api.tasks.maxConcurrent` | int | `1` | Maximum concurrent background tasks |

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
| `agents.defaults.shellAllowedCommands` | Shell command allowlist |
| `agents.defaults.maxIterations` | Agent iteration budget |
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

The wizard guides you through provider selection, API key entry, model discovery, and role assignment.

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

Credentials set this way are persisted to `.workspace/.env` and take effect immediately via `putenv()` hot-reload.

## Architecture Notes

The configuration system is split between two packages:

- **`php-agents`** provides `OpenClawConfig` — a thin config reader with dot-notation access, alias resolution, and model definition parsing. It has no opinion about workspace management, safety, or agent behavior.
- **Coqui** interprets the config through its own `src/Config/` layer: `BootManager` orchestrates the boot sequence, `RoleResolver` maps roles to models, `WorkspaceResolver` handles workspace path resolution, `MountManager` creates directory mounts, and `CatastrophicBlacklist` reads safety patterns.

This separation means `php-agents` remains a general-purpose provider implementation that any project can use, while Coqui owns all the agent-specific behavior built on top of the shared config format.
