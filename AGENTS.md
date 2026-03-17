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

#### Optional Credentials

Some credentials are optional — the toolkit works without them but gains additional capabilities when they're set. Declare optional credentials using the object format:

```json
{
    "extra": {
        "php-agents": {
            "credentials": {
                "REQUIRED_KEY": "This key is required — tools will not execute without it",
                "OPTIONAL_KEY": {
                    "description": "Optional config — enhances functionality but not required",
                    "optional": true
                }
            }
        }
    }
}
```

Optional credentials:
- Do **not** block tool execution when missing
- Are shown in the credential status guidelines with an `○` indicator (vs `✗` for required)
- Can be set at any time via the `credentials` tool

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



## Tool Gating Architecture

Coqui provides declarative destructive-command confirmation for toolkit packages. Toolkits declare which operations are dangerous in `composer.json`, and Coqui automatically prompts the user for confirmation before executing them. The `--auto-approve` flag bypasses all confirmation prompts for power users.

### How It Works

1. **Toolkit packages declare gated operations** in `composer.json` via `extra.php-agents.gated` — a map of `tool_name` → array of gating rules.
2. **`ToolkitDiscovery::collectAllGatedTools()`** reads gated declarations from all registered packages and merges them into a single map.
3. **`RunCommand::mergeGatedTools()`** merges the package-declared gates with Coqui's hardcoded `GATED_TOOLS` (composer, exec, php_execute, restart_coqui).
4. **`InteractiveApprovalPolicy`** receives the merged map and checks every tool call against it. Matching calls trigger an interactive confirmation prompt. Denied calls return `ToolResult::error()` — the agent sees the denial and can inform the user.
5. **`AutoApprovalPolicy`** (activated by `--auto-approve`) skips all confirmation. Only the `CatastrophicBlacklist` still blocks.

### Gating Rule Types

| Rule | Format | Example | Behavior |
|------|--------|---------|----------|
| Wildcard | `["*"]` | `"git_push": ["*"]` | Gates every invocation of the tool |
| Action match | `["action_name"]` | `"git_branch": ["delete"]` | Gates when `action`/`command` argument matches |
| Predicate | `[{"arg": value}]` | `"git_commit": [{"amend": true}]` | Gates when argument equals value |
| Presence | `[{"arg": "*"}]` | `"git_checkout": [{"files": "*"}]` | Gates when argument is present and truthy |

Rules are evaluated with OR semantics — any matching rule triggers confirmation. Predicate objects use AND semantics internally (all key-value pairs must match).

### For Toolkit Authors

Add gated tool declarations to your `composer.json`:

```json
{
    "extra": {
        "php-agents": {
            "toolkits": ["Acme\\MyToolkit\\MyToolkit"],
            "gated": {
                "my_deploy": ["*"],
                "my_delete": ["*"],
                "my_update": [{"force": true}]
            }
        }
    }
}
```

The gating system handles the confirmation UX — your toolkit does not need to implement its own confirmation logic. Read-only tools should not be gated.

### Key Source Files

| File | Purpose |
|------|---------|
| `src/Config/InteractiveApprovalPolicy.php` | Prompt-based gating with predicate rule matching |
| `src/Config/AutoApprovalPolicy.php` | Auto-approves all tools (blacklist still active) |
| `src/Config/CatastrophicBlacklist.php` | Hardcoded patterns that always block, regardless of mode |
| `src/Config/ToolkitDiscovery.php` | Reads `extra.php-agents.gated` from packages; `collectAllGatedTools()` merges all declarations |
| `src/Command/RunCommand.php` | `mergeGatedTools()` combines hardcoded + discovered gates |



## Toolkit Visibility Architecture

Toolkit visibility controls how much of each tool's schema is exposed to the LLM. This is distinct from _tool gating_ (which confirms destructive operations) — visibility determines whether the LLM sees a tool at all, and at what level of detail.

### Three-Tier Model

| Tier | Value | Behaviour |
|------|-------|-----------|
| Enabled | `"enabled"` | Full schema in LLM context (default) |
| Stub | `"stub"` | Minimal schema; LLM discovers full details via `tool_search` |
| Disabled | `"disabled"` | Tool not instantiated; invisible to the LLM |

Visibility can be applied at two granularities:

- **Package** — applies to every tool provided by a Composer package
- **Tool** — overrides an individual tool regardless of its package

### Persistence

State is stored in `workspace/toolkit-visibility.json`:

```json
{
  "packages": { "acme/vision-toolkit": "stub" },
  "tools":    { "spawn_agent": "stub" }
}
```

Anything not listed defaults to `enabled`. Setting a value back to `enabled` removes the entry from the file.

### Protected Tool Constants

Defined in `src/Contract/ToolkitVisibility.php`:

| Constant | Tools | Guard |
|----------|-------|-------|
| `ALWAYS_ENABLED` | `tool_search`, `credentials` | Can never be stubbed or disabled — bypass all visibility checks |
| `CANNOT_DISABLE` | `spawn_agent`, `vision_analyze`, `restart_coqui` | Can be stubbed, but disable is blocked with `InvalidArgumentException` |

`ToolkitVisibilityRegistry` enforces these guards on every write, and `getToolVisibility()` always returns `Enabled` for `ALWAYS_ENABLED` tools regardless of what is on disk.

### Stub Schema Pattern

When a tool has `stub` visibility, `StubTool` wraps the real implementation and overrides `toFunctionSchema()`:

```php
[
    'type' => 'function',
    'function' => [
        'name' => 'spawn_agent',
        'description' => '[STUB] <first 150 chars of real description>... Use tool_search("spawn_agent") for full parameter details.',
        'parameters' => [
            'type' => 'object',
            'properties' => new \stdClass(),   // empty — no typed params
            'additionalProperties' => true,    // LLM can still call it
        ],
    ],
]
```

The LLM sees `[STUB]` tools in context and knows to call `tool_search` before invoking them. The BM25 `ToolRegistry` always indexes the **real** tool (via `StubToolkit::realTools()`), so `tool_search` returns full schemas.

### Implementation Classes

| File | Purpose |
|------|---------|
| `src/Contract/ToolkitVisibility.php` | Backed string enum; protection constants; `isAlwaysEnabled()`, `canDisable()`, `canStub()` |
| `src/Config/ToolkitVisibilityRegistry.php` | Read/write `toolkit-visibility.json`; in-memory cache; guard enforcement |
| `src/Tool/StubTool.php` | Wraps `ToolInterface`; returns minimal stub schema; forwards `execute()` to real tool |
| `src/Toolkit/StubToolkit.php` | Wraps `ToolkitInterface`; `tools()` returns `StubTool` wrappers; `realTools()` returns originals for BM25 |
| `src/Config/ToolkitDiscovery.php` | `instantiateRegisteredGrouped()` — skips Disabled packages, returns `[package, toolkit]` pairs; `allWithVisibility()` for API listing |
| `src/Agent/OrchestratorAgent.php` | Applies per-package and per-tool visibility in `addToolkit()`; `getSystemPromptText()` for prompt preview |
| `src/Agent/AgentRunner.php` | `buildPromptPreview()` — creates a temporary agent and returns rendered prompt + counts |
| `src/Api/Handler/ToolkitHandler.php` | `GET /api/v1/toolkits`, `POST /api/v1/toolkits/visibility` |
| `src/Api/Handler/PromptHandler.php` | `GET /api/v1/server/prompt` |

### Boot Sequence Integration

```text
loadConfig
initializeWorkspace
  → new ToolkitVisibilityRegistry(workspacePath)     ← creates/loads toolkit-visibility.json
initializeMounts
discoverRoles
initializeCredentials
initializeMemory
discoverToolkits(visibilityRegistry)
  → instantiateRegisteredGrouped() skips Disabled packages
discoverSkills
new OrchestratorAgent(visibilityRegistry)
  → addToolkit() wraps Stub packages in StubToolkit
  → tools() applies ALWAYS_ENABLED bypass, then per-tool visibility
```

### REPL Commands

| Command | Description |
|---------|-------------|
| `/toolkits` | List all packages and tools with current visibility |
| `/toolkits enable <pkg>` | Set package to `enabled` |
| `/toolkits stub <pkg>` | Set package to `stub` |
| `/toolkits disable <pkg>` | Set package to `disabled` |
| `/toolkits enable tool:<name>` | Set individual tool to `enabled` |
| `/toolkits stub tool:<name>` | Set individual tool to `stub` |
| `/toolkits disable tool:<name>` | Set individual tool to `disabled` |
| `/prompt` | Print the fully rendered system prompt |

Tab autocomplete is provided for all subcommands, package names, and tool names via `readline_completion_function()`.

## Background Tool Architecture

Coqui supports running individual tools asynchronously in background processes. This builds on the existing background task infrastructure — same database table, same process manager, same monitoring tools — but executes a single tool call directly instead of spawning a full LLM agent.

### How It Works

1. **Agent calls `start_background_tool`** with the tool name, JSON-encoded arguments, and a title.
2. **`BackgroundTaskToolkit`** validates the parameters, creates a task record in `background_tasks` with `tool_name` and `tool_arguments` columns set, and returns the task ID.
3. **`BackgroundTaskManager`** picks up the pending task and spawns `bin/coqui task:run <id>` — identical to agent background tasks.
4. **`TaskRunCommand`** detects that `tool_name` is set in the task record and delegates to **`BackgroundToolExecutor`** instead of `AgentRunner`.
5. **`BackgroundToolExecutor`** builds the same toolkits as `OrchestratorAgent` (filesystem, shell, discovered packages, etc.), resolves the tool by name, and calls `execute()` directly — no LLM involved.
6. **The result** (success or error) is persisted to the task record and emitted as task events for SSE streaming.
7. **The agent monitors** progress using the same `task_status` and `list_tasks` tools used for agent background tasks.

### Key Differences from Background Tasks

| Aspect | `start_background_task` | `start_background_tool` |
|--------|------------------------|------------------------|
| Execution | Full LLM agent loop | Direct `tool->execute()` call |
| LLM tokens | Yes (agent reasons and iterates) | None (zero token cost) |
| Iterations | Up to 100 (configurable) | Always 1 (single tool call) |
| Use case | Complex multi-step work | Single long-running tool call |

### Key Source Files

| File | Purpose |
|------|--------|
| `src/Agent/BackgroundToolExecutor.php` | Builds toolkits, resolves tool by name, calls `execute()` directly |
| `src/Toolkit/BackgroundTaskToolkit.php` | Agent-facing tools including `start_background_tool` |
| `src/Command/TaskRunCommand.php` | Branches on `tool_name` presence: agent path vs direct tool execution |



## Vision Architecture

Coqui provides image analysis via a dedicated `vision` role. The system uses a single-shot child agent pattern (like `TitleGenerator`) — no persistent state, no tool access, just one LLM call with the image embedded.

### How It Works

1. **Agent calls `vision_analyze`** with an image source (URL, file path, or base64 data URI) and an optional prompt.
2. **`VisionTool`** validates input and delegates to `VisionAnalyzer`.
3. **`VisionAnalyzer`** resolves the vision model via `RoleResolver::resolve('vision')` and builds a multimodal message.
4. **Image normalization** — all images are converted to base64 data URIs before being sent to the provider:
   - **Data URIs** pass through as-is
   - **URLs** are downloaded via Symfony HttpClient, MIME type detected from Content-Type header, then base64-encoded
   - **File paths** are read from disk and base64-encoded with MIME detection via `mime_content_type()`
5. **Provider sends** the multimodal message to the vision model and returns the analysis.
6. **Error surfacing** — on failure, `VisionAnalyzer` returns a descriptive error string (prefixed with `Error: `) instead of silently returning null. The agent sees the actual failure reason (e.g., "401 Unauthorized", "file not found") and can inform the user.

### Configuration

The vision role model is configured in `openclaw.json`:

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

**Resolution priority:** `openclaw.json` roles mapping → primary model fallback. The vision role file (`config/roles/vision.md`) defines instructions and access level but does **not** hardcode a model — this is intentional so `openclaw.json` controls the operational model choice.

### Provider Compatibility

All image sources work with all providers because `VisionAnalyzer` normalizes everything to base64 before the provider sees it. Additionally, `GeminiProvider` has its own URL download fallback for any base64 content that was missed.

| Provider | Base64 | URL (via pre-download) |
|----------|:------:|:----------------------:|
| OpenAI Compatible | ✅ | ✅ |
| OpenAI Responses | ✅ | ✅ |
| Anthropic | ✅ | ✅ |
| Gemini | ✅ | ✅ |
| Ollama | ✅ | ✅ |
| xAI (Grok) | ✅ | ✅ |
| Mistral | ✅ | ✅ |

### Key Source Files

| File | Purpose |
|------|---------|
| `src/Agent/VisionAnalyzer.php` | Single-shot child agent: resolves provider, downloads/encodes images, sends multimodal chat |
| `src/Tool/VisionTool.php` | Agent-facing tool: validates input, delegates to VisionAnalyzer, surfaces errors |
| `config/roles/vision.md` | Role definition: instructions for structured image analysis |



## Shell Allowlist Architecture

The `ShellToolkit` (from php-agents) restricts which shell commands the agent can execute via a configurable allowlist. This is a separate safety layer from the `CatastrophicBlacklist` and `InteractiveApprovalPolicy`.

### How It Works

1. **`ShellToolkit`** accepts an `allowedCommands` array at construction. If non-empty, only commands whose first word matches the allowlist can execute.
2. **`OrchestratorAgent`** reads the allowlist from `agents.defaults.shellAllowedCommands` in `openclaw.json`. If not configured, it uses a built-in default.
3. **`SpawnAgentTool`** passes the same allowlist to child agents (children cannot exceed parent permissions).
4. **Shell injection detection** — when an allowlist is active, `ShellToolkit` also blocks metacharacters (`;`, `&&`, `|`, `$(...)`, backticks) that could bypass the allowlist.
5. **Deny patterns** — regardless of the allowlist, built-in regex patterns block `rm -rf`, pipe-to-shell (`curl | bash`), `php -r`, `mkfifo`, and `netcat` variants.

### Configuration

Customize the shell allowlist in `openclaw.json`:

```json
{
    "agents": {
        "defaults": {
            "shellAllowedCommands": [
                "php", "git", "grep", "find", "cat", "head", "tail", "wc", "ls",
                "curl", "wget", "make", "sort", "uniq", "sed", "awk", "diff",
                "docker", "npm", "node"
            ]
        }
    }
}
```

### Default Allowlist

When `agents.defaults.shellAllowedCommands` is not set, the following commands are allowed:

`php`, `git`, `grep`, `find`, `cat`, `head`, `tail`, `wc`, `ls`, `curl`, `wget`, `make`, `sort`, `uniq`, `sed`, `awk`, `diff`

### Safety Layer Interactions

Each CLI flag affects a specific safety layer. They do **not** interact with each other:

| Flag | Affects | What it changes | What it does NOT change |
|------|---------|----------------|------------------------|
| `--auto-approve` | `InteractiveApprovalPolicy` | Skips confirmation prompts for gated tools | Shell allowlist, ScriptSanitizer, CatastrophicBlacklist |
| `--unsafe` | `ScriptSanitizer` | Allows all PHP functions in `php_execute` | Shell allowlist, approval policy, CatastrophicBlacklist |
| Neither | — | All safety layers active at defaults | — |

The `CatastrophicBlacklist` (hardcoded destructive patterns) **cannot be disabled by any flag**.

The shell allowlist is only configurable via `openclaw.json` — it is not affected by CLI flags. This is by design: the allowlist is a structural configuration choice, not a runtime safety toggle.

### Key Source Files

| File | Purpose |
|------|---------|
| `src/Agent/OrchestratorAgent.php` | Reads `shellAllowedCommands` from config, constructs ShellToolkit |
| `src/Tool/SpawnAgentTool.php` | Passes shell allowlist to child agent ShellToolkit |
| php-agents `src/Toolkit/ShellToolkit.php` | Enforces allowlist, injection detection, deny patterns |
| `src/Config/CatastrophicBlacklist.php` | Always-on destructive command blocking |



## Failover Provider Architecture

Coqui provides automatic model failover via the `FallbackProvider` decorator pattern. When the primary LLM provider fails with a retryable error (rate limit, server error, timeout), Coqui transparently retries the request on the next configured fallback provider. This logic lives entirely in Coqui — php-agents has no awareness of fallback models.

### How It Works

1. **`OpenClawConfig::getFallbacks()`** reads `agents.defaults.model.fallbacks` from `openclaw.json` — an ordered array of `"provider/model"` strings.
2. **`OrchestratorAgent::createAgent()`** checks if fallbacks are configured. If so, it uses `ProviderFactory` to create a provider for each fallback string, wraps the primary provider in a `FallbackProvider` decorator, and wires the `onNotify` callback to the agent's observer system.
3. **`FallbackProvider`** implements `ProviderInterface` — it is invisible to `AbstractAgent`. On every `chat()`, `stream()`, or `structured()` call, it tries the primary provider first. If a retryable error occurs, it tries fallbacks in order. Non-retryable errors throw immediately.
4. **`ProviderErrorClassifier`** determines whether an error is retryable (429, 5xx, 408, network/connection) or fatal (401, 403, 400, 404). Fatal errors are never retried.
5. **Fallback events** are surfaced to the agent's observer system via the `setOnNotify()` closure. The agent emits `agent.warning` events when switching providers, so the user sees which model is being tried.

### Configuration

Add fallback models to `openclaw.json`:

```json
{
    "agents": {
        "defaults": {
            "model": {
                "primary": "anthropic/claude-sonnet-4-20250514",
                "fallbacks": [
                    "openai/gpt-4o",
                    "ollama/llama3"
                ]
            }
        }
    }
}
```

Fallbacks are tried in order. If all fallbacks also fail, the last error is propagated to the agent.

### Key Source Files

| File | Purpose |
|------|---------|
| `src/Provider/FallbackProvider.php` | `ProviderInterface` decorator — tries primary then fallbacks on retryable errors |
| `src/Exception/ProviderErrorClassifier.php` | Classifies errors as retryable (429, 5xx, 408, network) or fatal (401, 403, 400, 404) |
| `src/Config/OpenClawConfig.php` | Application-specific `ConfigInterface` implementation — reads `openclaw.json`, provides `getFallbacks()` |
| `src/Exception/ConfigNotFoundException.php` | Domain exception for missing/unreadable `openclaw.json` |



## Mount System Architecture

Coqui supports declarative directory mounts that give agents access to external directories beyond the primary workspace. Mounts are configured in `openclaw.json` and surfaced as symlinks under `workspace/mnt/` for agent discoverability.

### How It Works

1. **Mount declarations** are read from `agents.defaults.mounts` in `openclaw.json` — an array of `{path, alias, access, description}` objects.
2. **`MountDefinition`** is a validated value object for each mount. It enforces: path must exist as a directory, alias must not contain path separators, access must be `ro` or `rw`.
3. **`MountManager`** creates/updates symlinks in `workspace/mnt/{alias}` → real path. It provides:
   - `allowedPaths()` — array of `{realPath, readOnly}` for `FilesystemToolkit` to whitelist symlink-resolved paths
   - `allowedPathsReadOnly()` — same but forces all mounts to read-only (used for child agents)
   - `storageMap()` — markdown table injected into the system prompt describing available mounts
   - `openBasedirPaths()` — real paths for `PhpExecuteTool` open_basedir extension
4. **`FilesystemToolkit`** accepts an `allowedPaths` parameter. When `resolvePath()` encounters a symlink that resolves outside the workspace root, it checks if the real path falls under an allowed mount. Write tools additionally check `isReadOnlyMountPath()` before mutation operations.
5. **`BootManager::initializeMounts()`** reads the config, creates `MountDefinition` instances (silently skipping invalid entries), and initializes the `MountManager`. Runs after workspace initialization and before role discovery.
6. **The storage map** is injected into the workspace prompt via the `{{storage_map}}` placeholder in `prompts/tools/workspace.md`.

### Configuration

Add mounts to `openclaw.json`:

```json
{
    "agents": {
        "defaults": {
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
    }
}
```

| Field | Required | Default | Description |
|-------|----------|---------|-------------|
| `path` | yes | — | Absolute path to the external directory (must exist) |
| `alias` | yes | — | Short name used as the symlink name under `workspace/mnt/` |
| `access` | no | `ro` | `ro` (read-only) or `rw` (read-write) |
| `description` | no | `''` | Human-readable description shown in the storage map |

### Access Control

- **Default read-only.** Mounts default to `ro` unless explicitly set to `rw`.
- **Orchestrator** gets the declared access level (`ro` or `rw`) for each mount.
- **Child agents** (spawned via `spawn_agent`) always get read-only access to mounts, regardless of the mount's declared access level.
- **PhpExecuteTool** includes mount paths in `open_basedir` so PHP subprocesses can access them.
- **Write protection** is enforced at the `FilesystemToolkit` level — `write_file`, `create_directory`, and `delete_file` check `isReadOnlyMountPath()` before executing.

### Symlink Management

Symlinks are managed in `workspace/mnt/`:
- Created/updated on boot by `MountManager::initialize()`
- Stale symlinks (pointing to removed mounts) are automatically cleaned up
- The `mnt/` directory is created on demand

### Key Source Files

| File | Purpose |
|------|---------|
| `src/Contract/MountDefinition.php` | Value object: validated mount path, alias, access level, description |
| `src/Config/MountManager.php` | Symlink lifecycle, allowedPaths generation, storage map rendering, open_basedir paths |
| `src/Config/BootManager.php` | `initializeMounts()` reads config and initializes MountManager |


## Memory System Architecture

Coqui provides a persistent, cross-session memory system backed by SQLite. The system supports full CRUD operations, hybrid search (FTS5 full-text + optional vector embeddings), area-based organization, and automatic core memory injection into the agent's system prompt.

### How It Works

1. **`MemoryStore`** is the core storage engine. It manages a dedicated SQLite database at `workspace/data/memory.db` with FTS5 virtual tables for keyword search and an optional `memory_embeddings` table for vector similarity search.
2. **`MemorySummarizer`** generates a compressed summary of core memories, cached in a `memory_summary` table. The summary is invalidated when the memory count changes. Optionally uses an LLM provider for compression.
3. **`MemoryToolkit`** (in Coqui, not php-agents) exposes 6 tools to the agent: `memory_save`, `memory_search`, `memory_update`, `memory_delete`, `memory_forget`, `memory_list`.
4. **At boot**, `BootManager::initializeMemory()` creates the `MemoryStore`, resolves an optional embedding provider, and creates the `MemorySummarizer`.
5. **In the system prompt**, `OrchestratorAgent::instructions()` appends the core memory summary (from `MemorySummarizer`) as a `# CORE MEMORIES` section. This gives the agent ambient awareness of what it knows about the user.

### Search Strategy

Search uses a 3-tier fallback:

1. **Vector search** (if embedding provider available) — cosine similarity on stored embeddings, merged with FTS5 results.
2. **FTS5 full-text search** — Porter-tokenized keyword matching via SQLite FTS5.
3. **LIKE fallback** — simple substring matching when FTS5 returns no results.

### Embedding Provider Resolution

Resolved at boot time via `BootManager::resolveEmbeddingProvider()`:

1. Explicit config: `agents.defaults.memory.embeddingModel` in `openclaw.json` (e.g. `ollama/nomic-embed-text` or `openai/text-embedding-3-small`).
2. Auto-detect: If an `OPENAI_API_KEY` is set, uses `text-embedding-3-small` automatically.
3. Fallback: No provider — FTS5-only mode. Fully functional, just no semantic search.

### Memory Organization

Memories are classified by **area** (`preferences`, `facts`, `solutions`, `context`) and optionally tagged with free-form **tags** (stored as comma-separated strings). The core summary groups memories by area for structured system prompt injection.

### Key Source Files

| File | Purpose |
|------|---------|
| `src/Memory/MemoryStore.php` | SQLite + FTS5 + optional vector storage — full CRUD |
| `src/Memory/MemorySummarizer.php` | Cached summary generation for system prompt injection |
| `src/Toolkit/MemoryToolkit.php` | 6 agent-facing tools (save, search, update, delete, forget, list) |

### Configuration

No explicit configuration is required. The memory system initializes automatically on boot. Optional settings in `openclaw.json`:

```json
{
    "agents": {
        "defaults": {
            "memory": {
                "embeddingModel": "ollama/nomic-embed-text"
            }
        }
    }
}
```


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
- `.gitignore` must include: `vendor/`, `.env`, `*.cache`, `.phpunit.result.cache`, `workspace/`.

### Key Source Files

| File | Purpose |
|------|---------|
| `src/Config/CatastrophicBlacklist.php` | Hardcoded + configurable always-on safety patterns |
| `src/Config/ScriptSanitizer.php` | Static analysis of generated PHP (respects `--unsafe`) |
| `src/Config/AutoApprovalPolicy.php` | Auto-approves tools except catastrophic commands |
| `src/Config/InteractiveApprovalPolicy.php` | Interactive user confirmation with audit logging |
| `src/Config/WorkspaceComposerManager.php` | Manages `workspace/composer.json` lifecycle |
| `src/Config/ToolkitDiscovery.php` | Boot-time discovery of toolkit packages; implements `PackageEventListenerInterface`; wraps toolkits with credential guards |
| `src/Config/CredentialResolver.php` | Workspace `.env` management with hot-reload via `putenv()` |
| `src/Config/UpdateManager.php` | Dependency update checking and application; controlled by `COQUI_CHECK_UPDATES` / `COQUI_AUTO_UPDATE` env vars |
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

1. **Workspace sandboxing** — file writes via `FilesystemToolkit` are sandboxed to the workspace directory. The Composer toolkit (extracted to `coqui-toolkit-composer`) targets the workspace only — it cannot modify the project's `composer.json`. `PhpExecuteTool` runs with `cwd` set to the workspace and `open_basedir` restrictions that prevent writes outside workspace boundaries. The project root is read-only, accessible through `ProjectSourceToolkit` and shell commands.
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

### Update Management

Coqui supports dependency update checking and application via `UpdateManager`. The system is controlled entirely through ENV vars stored in the workspace `.env` (not in `openclaw.json`):

| Variable | Default | Purpose |
|----------|---------|---------|
| `COQUI_CHECK_UPDATES` | `true` | Check for outdated packages on startup |
| `COQUI_AUTO_UPDATE` | `false` | Automatically apply updates and restart on startup |

**Update triggers:**

1. **`--update` CLI flag** — `coqui --update` checks for and applies updates, then restarts via exit code 10.
2. **`/update` REPL command** — same behavior from within a running session.
3. **Startup check** — on every boot, if `COQUI_CHECK_UPDATES` is enabled, `UpdateManager::checkForUpdates()` runs `composer outdated --format=json --direct` in both the project root and workspace. If updates are found and `COQUI_AUTO_UPDATE` is enabled, they are applied automatically and Coqui restarts.

**Update scope:**

- Project root: `composer update` updates Coqui itself and all its dependencies.
- Workspace: `composer update` updates bot-installed packages in `workspace/`.

The setup wizard (`SetupWizard`) includes a step to configure these preferences, persisting them via `CredentialResolver::set()` to the workspace `.env`.

### Workspace Composer Isolation

The workspace directory (default `~/.coqui/.workspace`) contains its own `composer.json` managed by the bot. This separates bot-installed dependencies from the host project:

- **Default location:** `~/.coqui/.workspace` in the user's home directory. This prevents session sprawl when running Coqui from different directories.
- **Custom paths:** Users can set any path via `agents.defaults.workspace` in `openclaw.json` (supports `~`, relative, and absolute paths).
- `WorkspaceComposerManager` initializes the workspace Composer project on boot.
- The Composer toolkit (auto-discovered from `coqui-toolkit-composer`) always targets the workspace. It cannot modify the project's `composer.json`.
- The workspace autoloader is loaded at boot via `WorkspaceComposerManager::loadAutoloader()`.
- Toolkit discovery (`ToolkitDiscovery::discoverAll()`) scans both the project and workspace `installed.json` files on every boot.

This means agents can install packages into the workspace without touching the host `composer.json`.

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

Roles are defined as `.md` files with YAML frontmatter in `workspace/roles/` (user-created) or `config/roles/` (built-in). On first boot, built-in roles are seeded into the workspace. To add a new role:

1. Create a `.md` file in `workspace/roles/` with the required frontmatter fields
2. Optionally map the role to a model in `openclaw.json` under `agents.defaults.roles`
3. The role is auto-discovered — no code changes needed

#### Role Frontmatter Schema

```yaml
---
name: coder                    # required — lowercase, alphanumeric + hyphens
display_name: Coder            # required
description: Expert PHP dev... # required
version: 1                     # optional (default: 1)
access_level: full             # full | readonly | minimal
is_builtin: true               # optional
model: anthropic/claude-...    # optional — overrides openclaw.json
title_model: ollama/...        # optional
allowed-tools: ...             # optional
max_iterations: 30             # optional — per-role iteration limit (0 = unlimited)
---
<markdown instructions body>
```

### Max Iterations Configuration

Controls how many agent loop iterations a role can perform before stopping.

**Resolution priority:** role file `max_iterations` → `agents.defaults.maxIterations` in `openclaw.json` → hardcoded fallback (25).

#### Global Default

Set in `openclaw.json`:

```json
{
    "agents": {
        "defaults": {
            "maxIterations": 25
        }
    }
}
```

#### Per-Role Override

Add `max_iterations` to a role's `.md` frontmatter:

```yaml
---
name: coder
max_iterations: 30
---
```

#### Unlimited Iterations (Sentinel: 0)

Setting `max_iterations: 0` means "run until the task is done" — the agent loops until it calls the `done` tool, a provider error occurs, or cancellation is triggered. Internally this maps to `PHP_INT_MAX`. A warning is logged when unlimited mode activates.

**Background tasks are always clamped to 100 iterations** regardless of role configuration, since they run unattended without human oversight.

#### Built-in Role Defaults

| Role | `max_iterations` | Rationale |
|------|------------------|-----------|
| orchestrator | global default | Main agent — uses `agents.defaults.maxIterations` |
| coder | 30 | Complex coding tasks need more iterations |
| reviewer | 15 | Read-only analysis is usually quick |
| assistant | global default | General purpose — inherits global |
| title-generator | 5 | Single-shot title generation |

#### Iteration Budget Awareness

Agents are automatically informed of their iteration budget via the system prompt. `SystemPrompt::withIterationBudget()` injects an `# ITERATION BUDGET` section between `# TOOLS` and `# TOOL USAGE RULES` that tells the agent how many iterations it has, what an iteration represents, and how to manage resources wisely (batch tool calls, prioritize impactful actions, prepare continuation questions when nearing the limit).

When `max_iterations` is `0` (unlimited), the budget section is omitted entirely — the agent receives no iteration constraint messaging.

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

Coqui ships with Docker support for isolated execution. The image is based on `php:8.4-cli` (not a web server) since Coqui is a CLI REPL.

### Architecture

| File | Purpose |
|------|---------|
| `Dockerfile` | PHP 8.4 CLI + all extensions + Composer. |
| `compose.yaml` | Base service: bind-mounts source, named volume for workspace at `/app/workspace`, passes API keys from host, connects to host Ollama via `host.docker.internal`. |
| `compose.api.yaml` | Defines a separate `coqui-api` service for the HTTP API server on port 3300. Runs alongside the REPL without overriding it. |
| `Makefile` | Self-documenting targets. Native targets use bare names (`start`, `api`), Docker targets use `docker-*` prefix. |
| `conf.d/coqui.ini` | CLI-optimized PHP config: 512M memory, OPcache + JIT enabled, errors to stderr. |
| `.env.example` | Documents all environment variables (API keys, ports, runtime flags, UID/GID). |

### Key Design Decisions

- **CLI base image**: `php:8.4-cli` keeps the image ~300MB smaller than Apache/FPM variants. Coqui has no HTTP server.
- **`docker compose run` over `up`**: The REPL requires interactive TTY. Use `run --rm` for sessions. The API service uses `up -d` separately.
- **Separate `coqui-api` service**: The API runs as its own service in `compose.api.yaml` rather than overriding the REPL's `coqui` service. This allows running REPL (interactive) and API (daemon) simultaneously from the same compose project.
- **Host Ollama**: Users connect to `host.docker.internal:11434`. Avoids GPU passthrough complexity and duplicate model storage.
- **Named volume for workspace**: Session databases, bot-installed packages, and workspace state persist across `docker compose run` invocations. The volume mounts at `/app/workspace` and both compose files set `COQUI_WORKSPACE=/app/workspace` so `BootManager` uses this path directly (bypassing `WorkspaceResolver::DEFAULT_WORKSPACE`). The Dockerfile pre-creates `/app/workspace` with correct ownership so named volumes inherit the `coqui` user permissions.
- **Port convention**: API=3300. Avoids conflicts with common services on 8080/3000.

### Running in Docker

```bash
# Build image
make docker-build

# Interactive REPL + API
make docker-start           # REPL interactive, API on port 3300

# REPL only
make docker-repl

# API only (daemon)
make docker-api

# Shell access
make docker-shell

# Composer operations
make install
make composer CMD="require foo/bar"

# Stop / cleanup
make docker-stop             # stop all containers
make clean                   # remove containers, images, volumes
make clean-workspace         # workspace volume only
```

### Environment Variables

Copy `.env.example` to `.env` before running. Key variables:

| Variable | Default | Purpose |
|----------|---------|---------|  
| `COQUI_UID` / `COQUI_GID` | `1000` | Match host user to avoid permission issues |
| `COQUI_WORKSPACE` | `/app/workspace` | Workspace directory inside the container (must match the named volume mount) |
| `OPENAI_API_KEY` | — | Passed into the container |
| `ANTHROPIC_API_KEY` | — | Passed into the container |
| `OLLAMA_HOST` | `http://host.docker.internal:11434` | Ollama endpoint |
| `COQUI_API_HOST` | `127.0.0.1` | API bind address (`0.0.0.0` for network access) |
| `COQUI_API_PORT` | `3300` | API server port |
| `COQUI_AUTO_APPROVE` | `false` | Env-var equivalent of `--auto-approve` |
| `COQUI_UNSAFE` | `false` | Env-var equivalent of `--unsafe` |

## Documentation Policy

When making changes to Coqui, keep documentation in sync:

- **README.md** — update when adding user-facing features (new CLI options, new tools, new capabilities, changed behavior). The README is the first thing users see.
- **AGENTS.md** — update when adding architectural patterns, new conventions, new contributor workflows, or modifying the safety/security model.
- **config/source.json** — update when adding, renaming, removing, or significantly modifying source files (see Source Map Maintenance above).
- **CHANGELOG.md** — update for versioned releases with user-visible changes.
