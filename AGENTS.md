# Agents.md — Coqui Project Guidelines

## php-agents Foundation

Coqui is built on [`carmelosantana/php-agents`](https://github.com/carmelosantana/php-agents). `OrchestratorAgent` and `ChildAgent` both extend `AbstractAgent`. Understanding these primitives is required before working on any agent-layer code.

### AbstractAgent Constructor Parameters

| Parameter | Type | Default | Purpose |
| ------------------- | --------------------------------- | ------- | ----------------------------------------------- |
| `provider` | `ProviderInterface` | — | LLM for reasoning (required) |
| `maxIterations` | `int` | `50` | Safety cap on tool-use loops |
| `executionPolicy` | `?ToolExecutionPolicyInterface` | `null` | Pre-execution gating (approval/auto-approve) |
| `cancellationToken` | `?CancellationTokenInterface` | `null` | Cooperative cancellation (SIGINT, ESC) |
| `pendingInputProvider` | `?PendingInputProviderInterface` | `null` | Inject messages mid-loop (API mode) |
| `contextWindow` | `?ContextWindowInterface` | `null` | Token budget tracking + auto-pruning |
| `pruningStrategy` | `?BudgetPruningStrategyInterface` | `null` | Custom budget pruning (default: trim + drop) |

### Run Loop Output

`AbstractAgent::run()` returns an `Output` value object:

| Field | Type | Description |
| -------------- | --------------- | ------------------------------------------------ |
| `content` | `string` | Final text response |
| `usage` | `Usage` | Cumulative token usage across all iterations |
| `finishReason` | `FinishReason` | Why the loop stopped |
| `toolCalls` | `ToolCall[]` | Tool calls in the final response (usually empty) |
| `history` | `MessageInterface[]` | Full conversation |

`FinishReason` values: `Stop` (natural / DoneTool), `ToolCalls` (internal), `MaxTokens`, `Error`, `Cancelled`.

**DoneTool** — auto-registered by `AbstractAgent`. The LLM calls `done(response: "...")` to exit the loop. Never register it manually.

### Observer Events

Agents implement `SplSubject`. Coqui attaches `TerminalObserver` (REPL) and `SseObserver` (API) to render live output. Events emitted:

| Event | Data | When |
| -------------------- | -------------- | ------------------------------- |
| `agent.start` | `MessageInterface` | Before first iteration |
| `agent.iteration` | `int` | Top of each loop |
| `agent.tool_call` | `ToolCall` | Before executing a tool |
| `agent.tool_result` | `ToolResult` | After successful tool execution |
| `agent.tool_error` | `string` | When a tool throws |
| `agent.warning` | `string` | Non-fatal warnings (e.g. provider fallback) |
| `agent.done` | `array` | Agent finished |
| `agent.error` | `string` | Unrecoverable error |

### Key Interfaces (Coqui-relevant)

| Interface | Coqui Implementation |
| --------------------------------- | ------------------------------------------------------------------ |
| `ProviderInterface` | Resolved by `ProviderFactory` from `openclaw.json` |
| `ToolInterface` | All tools in `src/Tool/`; standalone tools in `OrchestratorAgent` |
| `ToolkitInterface` | `src/Toolkit/`, plus auto-discovered workspace packages |
| `ToolExecutionPolicyInterface` | `InteractiveApprovalPolicy`, `AutoApprovalPolicy` |
| `CancellationTokenInterface` | `CancellationToken` — driven by SIGINT handler and `EscCancellationObserver` |
| `ContextWindowInterface` | `ContextWindow` — optionally enabled; prunes conversation on token pressure |
| `BudgetPruningStrategyInterface` | `SummarizePruningStrategy` — summarize-then-drop; falls back to `DefaultBudgetPruningStrategy` |

### Deprecated in php-agents — Do Not Use in Coqui

| Item | Replacement |
| ----------------------------------- | ------------------------------------- |
| `FileMemory` | `src/Memory/MemoryStore.php` (SQLite + FTS5) |
| `MemoryToolkit` (php-agents) | `src/Toolkit/MemoryToolkit.php` (Coqui's own) |
| `FileAgent`, `WebAgent`, `CodeAgent` | `AbstractAgent` + explicit toolkits |



## Credential System Architecture

Coqui provides first-class credential management for toolkit packages. The system ensures the LLM never wastes tokens figuring out credential names or storage — everything is declarative and enforced automatically.

### How It Works

1. **Toolkit packages declare credentials** in `composer.json` via `extra.php-agents.credentials` — a map of `KEY_NAME` → `description`.
2. **`CredentialResolver`** manages the workspace `.env` file and process environment. Calling `set()` persists the value AND calls `putenv()` for immediate availability (hot-reload).
3. **`CredentialGuardToolkit`** wraps discovered toolkits whose packages declare credential requirements. Each tool is wrapped in a `CredentialGuardTool` decorator.
4. **`CredentialGuardTool`** intercepts `execute()` — if any required credential is missing, it returns a structured `ToolResult::error()` with exact key names, descriptions, and the precise `credentials` tool call syntax. The inner tool is never invoked.
5. **After the user provides the key**, the LLM calls `credentials(action: "set", key: "EXACT_NAME", value: "...")` → `CredentialTool` → `CredentialResolver::set()` → `.env` + `putenv()`. The next tool call succeeds immediately — no restart needed.

### Key Source Files

| File                                           | Purpose                                                                                  |
| ---------------------------------------------- | ---------------------------------------------------------------------------------------- |
| `src/Contract/CredentialRequirement.php`       | Value object: credential name + description                                              |
| `src/Contract/CredentialResolverInterface.php` | Interface for get/set/delete/has with hot-reload                                         |
| `src/Config/CredentialResolver.php`            | Implementation: reads workspace `.env` lazily, `set()` calls `putenv()`                  |
| `src/Tool/CredentialGuardTool.php`             | `ToolInterface` decorator — intercepts execution when credentials missing                |
| `src/Tool/CredentialGuardToolkit.php`          | `ToolkitInterface` decorator — wraps all tools + appends credential status to guidelines |
| `src/Tool/CredentialTool.php`                  | LLM-facing CRUD tool for credentials (delegates to `CredentialResolver`)                 |

### For Toolkit Authors

See README for the basic `composer.json` credentials declaration. Below covers the optional credentials extension.

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

| Rule         | Format             | Example                            | Behavior                                       |
| ------------ | ------------------ | ---------------------------------- | ---------------------------------------------- |
| Wildcard     | `["*"]`            | `"git_push": ["*"]`                | Gates every invocation of the tool             |
| Action match | `["action_name"]`  | `"git_branch": ["delete"]`         | Gates when `action`/`command` argument matches |
| Predicate    | `[{"arg": value}]` | `"git_commit": [{"amend": true}]`  | Gates when argument equals value               |
| Presence     | `[{"arg": "*"}]`   | `"git_checkout": [{"files": "*"}]` | Gates when argument is present and truthy      |

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

| File                                       | Purpose                                                                                        |
| ------------------------------------------ | ---------------------------------------------------------------------------------------------- |
| `src/Config/InteractiveApprovalPolicy.php` | Prompt-based gating with predicate rule matching                                               |
| `src/Config/AutoApprovalPolicy.php`        | Auto-approves all tools (blacklist still active)                                               |
| `src/Config/CatastrophicBlacklist.php`     | Hardcoded patterns that always block, regardless of mode                                       |
| `src/Config/ToolkitDiscovery.php`          | Reads `extra.php-agents.gated` from packages; `collectAllGatedTools()` merges all declarations |
| `src/Command/RunCommand.php`               | `mergeGatedTools()` combines hardcoded + discovered gates                                      |



## Toolkit Visibility Architecture

Toolkit visibility controls how much of each tool's schema is exposed to the LLM. This is distinct from _tool gating_ (which confirms destructive operations) — visibility determines whether the LLM sees a tool at all, and at what level of detail.

### Three-Tier Model

| Tier     | Value        | Behavior                                                    |
| -------- | ------------ | ------------------------------------------------------------ |
| Enabled  | `"enabled"`  | Full schema in LLM context (default)                         |
| Stub     | `"stub"`     | Minimal schema; LLM discovers full details via `tool_search` |
| Disabled | `"disabled"` | Tool not instantiated; invisible to the LLM                  |

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

| Constant         | Tools                                            | Guard                                                                  |
| ---------------- | ------------------------------------------------ | ---------------------------------------------------------------------- |
| `ALWAYS_ENABLED` | `tool_search`, `credentials`                     | Can never be stubbed or disabled — bypass all visibility checks        |
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

| File                                       | Purpose                                                                                                                               |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------- |
| `src/Contract/ToolkitVisibility.php`       | Backed string enum; protection constants; `isAlwaysEnabled()`, `canDisable()`, `canStub()`                                            |
| `src/Config/ToolkitVisibilityRegistry.php` | Read/write `toolkit-visibility.json`; in-memory cache; guard enforcement                                                              |
| `src/Tool/StubTool.php`                    | Wraps `ToolInterface`; returns minimal stub schema; forwards `execute()` to real tool                                                 |
| `src/Toolkit/StubToolkit.php`              | Wraps `ToolkitInterface`; `tools()` returns `StubTool` wrappers; `realTools()` returns originals for BM25                             |
| `src/Config/ToolkitDiscovery.php`          | `instantiateRegisteredGrouped()` — skips Disabled packages, returns `[package, toolkit]` pairs; `allWithVisibility()` for API listing |
| `src/Agent/OrchestratorAgent.php`          | Applies per-package and per-tool visibility in `addToolkit()`; `getSystemPromptText()` for prompt preview                             |
| `src/Agent/AgentRunner.php`                | `buildPromptPreview()` — creates a temporary agent and returns rendered prompt + counts                                               |
| `src/Api/Handler/ToolkitHandler.php`       | `GET /api/v1/toolkits`, `POST /api/v1/toolkits/visibility`                                                                            |
| `src/Api/Handler/PromptHandler.php`        | `GET /api/v1/server/prompt`                                                                                                           |

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

| Command                         | Description                                         |
| ------------------------------- | --------------------------------------------------- |
| `/toolkits`                     | List all packages and tools with current visibility |
| `/toolkits enable <pkg>`        | Set package to `enabled`                            |
| `/toolkits stub <pkg>`          | Set package to `stub`                               |
| `/toolkits disable <pkg>`       | Set package to `disabled`                           |
| `/toolkits enable tool:<name>`  | Set individual tool to `enabled`                    |
| `/toolkits stub tool:<name>`    | Set individual tool to `stub`                       |
| `/toolkits disable tool:<name>` | Set individual tool to `disabled`                   |
| `/prompt`                       | Print the fully rendered system prompt              |

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

| Aspect     | `start_background_task`          | `start_background_tool`       |
| ---------- | -------------------------------- | ----------------------------- |
| Execution  | Full LLM agent loop              | Direct `tool->execute()` call |
| LLM tokens | Yes (agent reasons and iterates) | None (zero token cost)        |
| Iterations | Up to 100 (configurable)         | Always 1 (single tool call)   |
| Use case   | Complex multi-step work          | Single long-running tool call |

### Key Source Files

| File                                    | Purpose                                                               |
| --------------------------------------- | --------------------------------------------------------------------- |
| `src/Agent/BackgroundToolExecutor.php`  | Builds toolkits, resolves tool by name, calls `execute()` directly    |
| `src/Toolkit/BackgroundTaskToolkit.php` | Agent-facing tools including `start_background_tool`                  |
| `src/Command/TaskRunCommand.php`        | Branches on `tool_name` presence: agent path vs direct tool execution |

## Schedule System Architecture

Coqui supports autonomous, timer-driven execution via a cron-style scheduling system. The agent can create, manage, and self-schedule recurring or one-shot tasks that execute as background tasks inside the ReactPHP event loop.

### How It Works

1. **`ScheduleStore`** manages a `scheduled_tasks` SQLite table. Each schedule has a name, cron expression, prompt, role, iteration limit, timezone, and circuit-breaker counters.
2. **`ScheduleManager`** runs inside the ReactPHP event loop via a 60-second periodic timer. On each `tick()`, it queries `getReadySchedules(now)` for enabled schedules whose `next_run_at` has passed, creates background tasks via `SessionStorage`, and updates the schedule records.
3. **`ScheduleHandler`** exposes 8 REST endpoints for CRUD, manual trigger, and enable/disable operations.
4. **`ScheduleToolkit`** provides 6 agent-facing tools so the agent can create and manage its own schedules during conversations.
5. **Circuit breaker** — when a schedule's `failure_count` reaches `max_failures` (default 3), the schedule is automatically disabled. The agent or user must re-enable it after investigating.
6. **One-shot schedules** — the special expression `@once` creates a schedule that runs once at the next tick and is then automatically disabled.

### Schedule Schema

| Column | Type | Description |
| --- | --- | --- |
| `id` | TEXT PK | Random hex ID |
| `name` | TEXT UNIQUE | Human-readable schedule name |
| `description` | TEXT | Optional description |
| `schedule_expression` | TEXT | Cron expression or `@once` |
| `prompt` | TEXT | Prompt sent to the agent on execution |
| `role` | TEXT | Agent role for the background task (default: `orchestrator`) |
| `max_iterations` | INTEGER | Iteration limit for the background task (default: 48) |
| `enabled` | INTEGER | 1 = active, 0 = disabled |
| `timezone` | TEXT | Timezone for cron evaluation (default: `UTC`) |
| `next_run_at` | TEXT | Computed next execution time (ISO 8601) |
| `last_run_at` | TEXT | Last execution time |
| `last_task_id` | TEXT | ID of the most recent background task |
| `last_status` | TEXT | Status of the last run |
| `run_count` | INTEGER | Total successful executions |
| `failure_count` | INTEGER | Consecutive failures (resets on success) |
| `max_failures` | INTEGER | Circuit breaker threshold (default: 3) |
| `metadata` | TEXT | Optional JSON metadata |

### Agent-Facing Tools (ScheduleToolkit)

| Tool | Description |
| --- | --- |
| `schedule_create` | Create a new schedule with cron expression, prompt, role, timezone |
| `schedule_list` | List all schedules with optional enabled filter |
| `schedule_get` | Get schedule details by ID or name |
| `schedule_update` | Update cron, prompt, enabled, role, or max_iterations |
| `schedule_delete` | Delete a schedule |
| `schedule_trigger` | Immediately trigger a schedule without waiting for the next cron tick |

### API Endpoints

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/api/v1/schedules` | List schedules |
| `POST` | `/api/v1/schedules` | Create schedule |
| `GET` | `/api/v1/schedules/{id}` | Get schedule |
| `PATCH` | `/api/v1/schedules/{id}` | Update schedule |
| `DELETE` | `/api/v1/schedules/{id}` | Delete schedule |
| `POST` | `/api/v1/schedules/{id}/trigger` | Manual trigger |
| `POST` | `/api/v1/schedules/{id}/enable` | Enable schedule |
| `POST` | `/api/v1/schedules/{id}/disable` | Disable schedule |

### ReactPHP Timer Integration

`ScheduleManager` uses two timer patterns inside the API server's event loop:

- **60-second tick** — evaluates due schedules and creates background tasks. Enforces a minimum 60-second gap (`MIN_INTERVAL_SECONDS`) between consecutive runs of the same schedule to prevent duplicate executions.
- **Result polling** — after creating tasks, the manager polls for recently completed schedule-linked tasks to update success/failure counters on the schedule record.

### REPL Command

| Command | Description |
| --- | --- |
| `/schedules` | Table-formatted list of all schedules with status, cron, next run, and run count |

### Key Source Files

| File | Purpose |
| --- | --- |
| `src/Storage/ScheduleStore.php` | SQLite CRUD with circuit breaker, cron next-run computation, stats |
| `src/Api/ScheduleManager.php` | ReactPHP timer-driven scheduler with MIN_INTERVAL_SECONDS enforcement |
| `src/Api/Handler/ScheduleHandler.php` | 8 REST endpoints for schedule management |
| `src/Toolkit/ScheduleToolkit.php` | 6 agent-facing tools for self-scheduling |


## Webhook System Architecture

Coqui supports receiving external webhooks that trigger agent background tasks. The system verifies webhook signatures (GitHub, Slack, or generic HMAC), logs deliveries for auditing, and provides full CRUD management via both API and agent tools.

### How It Works

1. **External service sends a POST** to `/api/v1/webhooks/incoming/{name}` with a signed payload.
2. **`WebhookHandler`** looks up the subscription by name, verifies the signature using the appropriate `WebhookVerifierInterface` implementation, and creates a background task with the webhook's prompt template (with `{{payload}}` replaced by the request body).
3. **`WebhookStore`** persists subscriptions and delivery logs to SQLite. Each subscription auto-generates an HMAC signing secret (`bin2hex(random_bytes(32))`) at creation time.
4. **`WebhookManagementHandler`** exposes CRUD + secret rotation + delivery log endpoints.
5. **`WebhookToolkit`** provides 4 agent-facing tools for the agent to create and manage its own webhook subscriptions.
6. **Delivery purging** — a 3600-second ReactPHP timer periodically calls `purgeOldDeliveries()` to remove delivery records older than 7 days.

### Webhook Subscription Schema

| Column | Type | Description |
| --- | --- | --- |
| `id` | TEXT PK | Random hex ID |
| `name` | TEXT UNIQUE | Subscription name (used in the incoming URL) |
| `description` | TEXT | Optional description |
| `source` | TEXT | Verifier type: `generic`, `github`, or `slack` |
| `secret` | TEXT | HMAC signing secret (auto-generated) |
| `prompt_template` | TEXT | Prompt with `{{payload}}` placeholder |
| `role` | TEXT | Agent role for the background task (default: `orchestrator`) |
| `max_iterations` | INTEGER | Iteration limit (default: 48) |
| `enabled` | INTEGER | 1 = active, 0 = disabled |
| `event_filter` | TEXT | Optional comma-separated event types to accept |
| `trigger_count` | INTEGER | Total deliveries processed |

### Webhook Delivery Log Schema

| Column | Type | Description |
| --- | --- | --- |
| `id` | TEXT PK | Random hex ID |
| `webhook_id` | TEXT FK | References webhook subscription (CASCADE delete) |
| `event_type` | TEXT | Event type from the request headers |
| `payload_summary` | TEXT | Truncated payload for auditing |
| `task_id` | TEXT | Background task created (if any) |
| `status` | TEXT | Delivery status (e.g. `accepted`, `rejected_disabled`, `rejected_signature`, `rejected_event`) |
| `source_ip` | TEXT | Sender IP address |

### Signature Verification

Three verifier implementations, selected by the subscription's `source` field:

| Verifier | Source | Header | Algorithm |
| --- | --- | --- | --- |
| `GithubWebhookVerifier` | `github` | `X-Hub-Signature-256` | HMAC-SHA256 with `sha256=` prefix |
| `SlackWebhookVerifier` | `slack` | `X-Slack-Signature` | HMAC-SHA256 v0 signing with 5-minute replay protection |
| `GenericWebhookVerifier` | `generic` | `X-Webhook-Signature`, `X-Signature`, or `Authorization: Bearer` | HMAC-SHA256 or bearer token match |

`WebhookVerifierRegistry` maps source names to verifier instances. The registry is populated at boot in `ApiCommand`.

### Agent-Facing Tools (WebhookToolkit)

| Tool | Description |
| --- | --- |
| `webhook_create` | Create a webhook subscription with prompt template, source type, event filter |
| `webhook_list` | List all webhook subscriptions |
| `webhook_get` | Get subscription details by ID |
| `webhook_delete` | Delete a webhook subscription |

### API Endpoints

| Method | Endpoint | Description |
| --- | --- | --- |
| `POST` | `/api/v1/webhooks/incoming/{name}` | Receive and process incoming webhook |
| `GET` | `/api/v1/webhooks` | List subscriptions (secrets masked) |
| `POST` | `/api/v1/webhooks` | Create subscription |
| `GET` | `/api/v1/webhooks/{id}` | Get subscription |
| `PUT` | `/api/v1/webhooks/{id}` | Update subscription |
| `DELETE` | `/api/v1/webhooks/{id}` | Delete subscription |
| `POST` | `/api/v1/webhooks/{id}/rotate` | Rotate signing secret |
| `GET` | `/api/v1/webhooks/{id}/deliveries` | List delivery log |

### REPL Command

| Command | Description |
| --- | --- |
| `/webhooks` | Table-formatted list of all webhook subscriptions with status, source, and trigger count |

### Key Source Files

| File | Purpose |
| --- | --- |
| `src/Storage/WebhookStore.php` | SQLite CRUD for subscriptions + delivery log with auto-purge |
| `src/Api/Handler/WebhookHandler.php` | Incoming webhook receiver with signature verification |
| `src/Api/Handler/WebhookManagementHandler.php` | CRUD + secret rotation + delivery log endpoints |
| `src/Contract/WebhookVerifierInterface.php` | Verifier contract: `verify(request, secret): bool` |
| `src/Api/Webhook/GithubWebhookVerifier.php` | GitHub X-Hub-Signature-256 verification |
| `src/Api/Webhook/SlackWebhookVerifier.php` | Slack v0 signing with replay protection |
| `src/Api/Webhook/GenericWebhookVerifier.php` | Generic HMAC or bearer token verification |
| `src/Api/Webhook/WebhookVerifierRegistry.php` | Maps source names to verifier instances |
| `src/Toolkit/WebhookToolkit.php` | 4 agent-facing tools for webhook management |


## Artifact-Driven Planning & Role Handoff

Coqui supports structured planning and implementation handoffs via the artifact management system. The `plan` role creates versioned plan artifacts that flow through a lifecycle (`draft` → `review` → `final`), then hand off to the `coder` role for execution.

### Workflow

1. **User activates the plan role** via `/role plan` or the orchestrator delegates via `spawn_agent(role: "plan", ...)`.
2. **Plan agent creates a plan artifact** using `artifact_create(type: "plan", ...)`. The plan evolves through discovery and design phases, updated via `artifact_update`.
3. **Parallel exploration** — the plan agent can spawn background tasks with `start_background_task(role: "explorer", ...)` to investigate multiple subsystems concurrently. Results are gathered via `task_status` and consolidated into the plan artifact.
4. **User review** — the plan artifact moves to `review` stage via `artifact_stage`. The user provides feedback, and the plan agent iterates.
5. **Handoff** — once approved, the artifact moves to `final` stage. The user or plan agent triggers implementation via `spawn_agent(role: "coder", task: "Execute the approved plan in artifact [ID]")`.
6. **Coder reads the plan** — child agents receive `ArtifactToolkit` with the parent's session ID, so the coder can read the plan artifact via `artifact_get` and follow its steps.

### Artifact Sharing Between Parent and Child Agents

`SpawnAgentTool::buildToolkits()` injects `ArtifactToolkit` into every child agent (regardless of access level). The toolkit uses the parent's `sessionId`, so all artifacts created by the orchestrator, plan agent, or coder are visible to each other within the same session.

### Built-in Roles for Planning

| Role       | Access Level     | Purpose                                              |
| ---------- | ---------------- | ---------------------------------------------------- |
| `plan`     | `readonly`       | Creates and manages plan artifacts; never implements  |
| `explorer` | `readonly-shell` | Gathers codebase context using filesystem + shell search commands |
| `coder`    | `full`           | Reads plan artifacts and implements the plan          |
| `reviewer` | `readonly`       | Reads artifacts for code review and analysis          |

### Key Source Files

| File                            | Purpose                                                            |
| ------------------------------- | ------------------------------------------------------------------ |
| `config/roles/plan.md`         | Plan role definition with artifact workflow and plan style guide    |
| `config/roles/explorer.md`     | Explorer role for focused read-only codebase investigation         |
| `src/Toolkit/ArtifactToolkit.php` | Agent-facing CRUD tools for versioned artifacts                 |
| `src/Storage/ArtifactStore.php`   | SQLite-backed artifact persistence with version history          |
| `src/Tool/SpawnAgentTool.php`     | Injects ArtifactToolkit into child agents for session-shared access |

## Todo System Architecture

Coqui provides session-scoped task tracking via the todo system. Agents use todos to plan work, track progress, and hand off structured task lists between roles. The system integrates with artifacts for plan→execution traceability and supports automatic todo generation from finalized plan artifacts.

### How It Works

1. **`TodoStore`** manages a `todos` table in the shared SQLite database (same PDO as SessionStorage). Each todo is scoped to a session and optionally linked to an artifact and/or parent todo. Supports `bulkCreate()` and `bulkUpdate()` for efficient batch operations.
2. **`TodoToolkit`** exposes 8 agent-facing tools: `todo_add`, `todo_update`, `todo_complete`, `todo_list`, `todo_get`, `todo_delete`, `todo_bulk_add`, `todo_bulk_update`. Tool availability is role-aware — readonly roles only get list, get, add, and update tools.
3. **Guidelines injection** — `TodoToolkit::guidelines()` dynamically generates a progress summary (progress bar, active/pending listing) that is included in the system prompt on every iteration. Todos linked to artifacts display ` → artifact: {title}` for traceability. A recovery hint reminds agents to check `todo_list` and `artifact_list` after conversation summarization.
4. **Child agent sharing** — `SpawnAgentTool::buildToolkits()` injects `TodoToolkit` into every child agent with the parent's session ID, so todos are visible across all agents in a session.
5. **Auto-generation** — `PlanTodoGenerator` automatically creates todos when a plan artifact reaches `final` stage via `artifact_stage`. Uses the utility model to extract implementation steps from the plan content.
6. **Boot cleanup** — `BootManager::initializeArtifacts()` calls `TodoStore::cleanupOrphaned()` and `TodoStore::cleanupStale()` on every boot to remove orphaned todos (sessions deleted) and stale completed/cancelled todos from inactive sessions.

### Cross-References

`TodoToolkit` accepts an optional `ArtifactStore` to resolve artifact titles for linked todos. `ArtifactToolkit` accepts an optional `TodoStore` to show todo progress (e.g. `todos: 3/5`) next to plan artifacts in guidelines. `SpawnAgentTool` wires both stores into child agent toolkits.

### Todo Schema

| Column | Type | Description |
| --- | --- | --- |
| `id` | TEXT PK | Random hex ID |
| `session_id` | TEXT FK | Scoped to session (CASCADE delete) |
| `artifact_id` | TEXT FK | Optional link to artifact (SET NULL on delete) |
| `parent_id` | TEXT FK | Optional subtask hierarchy (CASCADE delete) |
| `title` | TEXT | Task description |
| `status` | TEXT | `pending` / `in_progress` / `completed` / `cancelled` |
| `priority` | TEXT | `high` / `medium` / `low` |
| `created_by` | TEXT | Role that created the todo |
| `completed_by` | TEXT | Role that completed it |
| `notes` | TEXT | Additional context |
| `sort_order` | INTEGER | Display ordering |

### Role-Based Permissions

| Access Level | Available Tools |
| --- | --- |
| `full` | All 8 tools |
| `readonly`, `readonly-shell` | `todo_list`, `todo_get`, `todo_add`, `todo_update`, `todo_bulk_add`, `todo_bulk_update` |
| `minimal` | `todo_list`, `todo_get` |

### Planning Workflow Integration

1. **Plan agent** creates a plan artifact and stages it to `final` via `artifact_stage`. **`PlanTodoGenerator` automatically creates linked todos** from the plan content using the utility model.
2. **Coder agent** reads the todo list via `todo_list(artifact_id: "...")`, implements each step, and marks todos complete via `todo_complete`.
3. **Reviewer agent** checks completed todos against actual implementation using `todo_list`.
4. **Manual fallback** — if auto-generation fails or produces no todos (visible in the `artifact_stage` response), the plan agent can create them manually via `todo_bulk_add`.

### Bulk Operations

`todo_bulk_add` and `todo_bulk_update` accept up to 25 items per call. Both are transaction-wrapped for atomicity. Use bulk operations when creating or updating 5+ todos at once to reduce tool call overhead.

### Auto-Generation from Plan Artifacts

When `artifact_stage(stage: "final")` is called on a `type: "plan"` artifact, `PlanTodoGenerator` is invoked automatically:

1. Sends the plan content to the utility model (same model used for titles and summarization).
2. Extracts actionable implementation steps as structured JSON.
3. Creates todos via `TodoStore::bulkCreate()`, linked to the plan artifact.
4. Returns the count of generated todos in the `artifact_stage` response.

This is best-effort — the stage transition always succeeds even if todo generation fails. The `PlanTodoGenerator` follows the `TitleGenerator` pattern: single-shot LLM call, catches all errors, never blocks.

### REPL & API

| Interface | Command/Endpoint | Description |
| --- | --- | --- |
| REPL | `/todos [status]` | Show session todos with progress stats |
| API | `GET /api/v1/sessions/{id}/todos` | List todos with filters |
| API | `POST /api/v1/sessions/{id}/todos` | Create todo |
| API | `POST /api/v1/sessions/{id}/todos/bulk` | Bulk create (max 25) |
| API | `GET /api/v1/sessions/{id}/todos/stats` | Session stats |
| API | `GET /api/v1/sessions/{id}/todos/{todoId}` | Get todo with subtasks |
| API | `PATCH /api/v1/sessions/{id}/todos/{todoId}` | Update todo |
| API | `PATCH /api/v1/sessions/{id}/todos/bulk` | Bulk update (max 25) |
| API | `POST /api/v1/sessions/{id}/todos/{todoId}/complete` | Mark complete |
| API | `DELETE /api/v1/sessions/{id}/todos/{todoId}` | Delete todo |

### Key Source Files

| File | Purpose |
| --- | --- |
| `src/Storage/TodoStore.php` | SQLite CRUD with session scoping, subtask hierarchy, bulk ops, stats |
| `src/Toolkit/TodoToolkit.php` | 8 agent-facing tools with role-aware permissions and dynamic guidelines |
| `src/Api/Handler/TodoHandler.php` | REST API endpoints for todo CRUD and bulk operations |
| `src/Agent/PlanTodoGenerator.php` | Auto-generates todos from finalized plan artifacts via utility model |
| `prompts/tools/todos.md` | Agent usage guidelines for todo workflow |

## Evaluation System Architecture

Coqui provides an asynchronous post-run evaluation pipeline where a utility/cheap model grades completed sessions on performance, hallucinations, and tool efficiency. The system leverages the existing Schedule System and Background Tasks infrastructure for autonomous operation.

### How It Works

1. **`EvaluationStore`** manages an `evaluations` table in the shared SQLite database. Each evaluation is linked to a session and contains numeric scores (0.0–1.0) for completion, hallucination absence, and tool efficiency, plus a weighted composite score and a full markdown report.
2. **`SessionEvaluationToolkit`** provides 4 agent-facing tools: `evaluation_list_sessions`, `evaluation_read_transcript`, `evaluation_read_child_runs`, `evaluation_save_report`. The toolkit is only registered when the active role is `evaluator`.
3. **The `evaluator` role** (`config/roles/evaluator.md`) defines the grading criteria, scoring rubric (A–F scale), and structured report format. It operates at `access_level: readonly` — no file writes or shell commands.
4. **Session eligibility** — sessions are eligible for evaluation when they have no existing evaluation record, are not background task sessions, have been inactive longer than the inactivity threshold (default 3 hours), fall within the lookback window (default 24 hours), and have a minimum number of turns (default 2).
5. **Autonomous operation** — the user or agent creates a schedule via the Schedule System to run evaluations periodically (e.g., daily). The schedule spawns a background task with `role: evaluator`, which grades all eligible sessions and saves reports.

### Evaluation Criteria

| Criterion | Weight | Score Range | Description |
| --- | --- | --- | --- |
| Completion | 40% | 0.0–1.0 | Did the agent fulfill the user's request? |
| Hallucination Absence | 40% | 0.0–1.0 | Were all references to APIs, methods, and files accurate? |
| Tool Efficiency | 20% | 0.0–1.0 | Did the agent use tools effectively without waste? |

### Grading Scale

| Grade | Criteria |
| --- | --- |
| A | All scores ≥ 0.8, no major issues |
| B | Mostly successful, minor issues (overall ≥ 0.7) |
| C | Partial completion or notable hallucinations (overall ≥ 0.5) |
| D | Major failures in at least one criterion (overall ≥ 0.3) |
| F | Fundamental failure |

### Agent-Facing Tools (SessionEvaluationToolkit)

| Tool | Description |
| --- | --- |
| `evaluation_list_sessions` | Find unevaluated sessions within the lookback window |
| `evaluation_read_transcript` | Read a session's conversation history with tool call summaries |
| `evaluation_read_child_runs` | Read child agent executions for a session |
| `evaluation_save_report` | Save a structured evaluation report with grade and scores |

### Configuration

Optional keys in `openclaw.json` under `agents.defaults.evaluation`:

| Key | Default | Description |
| --- | --- | --- |
| `lookbackHours` | `24` | How far back to search for sessions to evaluate |
| `inactivityHours` | `3` | Minimum hours since last activity before a session is eligible |
| `minTurns` | `2` | Minimum turns for a session to be worth evaluating |

The evaluator model is assigned via the standard roles mapping: `"roles": {"evaluator": "ollama/gemma3:4b"}`.

### REPL Command

| Command | Description |
| --- | --- |
| `/evaluations [grade]` | Table-formatted list of evaluation reports with optional grade filter |

### Key Source Files

| File | Purpose |
| --- | --- |
| `src/Storage/EvaluationStore.php` | SQLite CRUD with unevaluated session discovery via LEFT JOIN |
| `src/Toolkit/SessionEvaluationToolkit.php` | 4 agent-facing tools with dynamic guidelines |
| `config/roles/evaluator.md` | Role definition with grading criteria and report format |


## Role-Scoped Toolkit Architecture

Coqui supports restricting toolkits and individual tools to specific agent roles via the declarative `toolkits` field in role frontmatter. This replaces the need for PHP interface implementations — toolkit visibility is configured entirely in role `.md` files.

### How It Works

1. **Role frontmatter declares `toolkits:`** — a comma-separated string of `+Pattern` (allow) and `-Pattern` (deny) rules. Example: `toolkits: "+*, -ShellToolkit, -php_execute"`.
2. **`RoleToolkitResolver`** parses the pattern string at agent construction. Rules are evaluated left-to-right, last match wins. The first `*` rule sets the default mode (`-*` = deny-by-default, `+*` = allow-by-default).
3. **`OrchestratorAgent::addToolkit()`** checks every toolkit against the resolver before registration. Denied toolkits are silently skipped. `StubToolkit` wrappers are unwrapped for matching.
4. **`OrchestratorAgent::tools()`** checks individual standalone tools against the resolver. `ALWAYS_ENABLED` tools (`tool_search`, `credentials`) bypass all role filtering.
5. **`SpawnAgentTool`** builds a `RoleToolkitResolver` from the child role's frontmatter and filters toolkits before passing them to child agents.

### Pattern Format

| Pattern | Behavior |
| --- | --- |
| `+*` | Allow all by default |
| `-*` | Deny all by default |
| `+ToolkitName` | Allow a specific toolkit (matches class basename) |
| `-ToolkitName` | Deny a specific toolkit |
| `+tool_name` | Allow a specific tool |
| `-tool_name` | Deny a specific tool |
| `+vendor/package` | Allow by Composer package name |
| `-vendor/package` | Deny by Composer package name |

Rules are case-insensitive. Multiple rules separated by commas. Last match wins.

### Built-in Role Toolkit Configurations

| Role | `toolkits` | Strategy |
| --- | --- | --- |
| orchestrator | `+*, -SessionEvaluationToolkit, -LearningToolkit, -ToolkitGeneratorToolkit` | Allow-all minus role-specific toolkits |
| coder | *(none — allow all)* | Full access to all toolkits |
| assistant | *(none — allow all)* | Full access to all toolkits |
| explorer | `+*, -MemoryToolkit, -spawn_agent, -php_execute` | Allow-all minus dangerous tools |
| plan | `+*, -ShellToolkit, -MemoryToolkit, -php_execute, -LearningToolkit, -SessionEvaluationToolkit` | Allow-all minus write-capable and role-specific |
| reviewer | `+*, -MemoryToolkit, -LearningToolkit, -SessionEvaluationToolkit, -php_execute` | Allow-all minus write-capable and role-specific |
| evaluator | `-*, +SessionEvaluationToolkit, +ProjectSourceToolkit` | Deny-all, only evaluation tools |
| learner | `-*, +LearningToolkit, +SkillToolkit, +ProjectSourceToolkit` | Deny-all, only learning tools |
| vision | `-*` | Deny all (single-shot image analysis, no tools) |
| title-generator | `-*` | Deny all (single-shot title generation) |
| plan-todo-generator | `-*` | Deny all (single-shot todo extraction) |

### Backward Compatibility

The `RoleParser` falls back to reading `allowed-tools:` from frontmatter when `toolkits:` is absent, supporting older role files.

### Key Source Files

| File | Purpose |
| --- | --- |
| `src/Config/RoleToolkitResolver.php` | Parses `toolkits` patterns and evaluates allow/deny rules |
| `src/Agent/OrchestratorAgent.php` | Applies role toolkit filtering in `addToolkit()` and `tools()` |
| `src/Tool/SpawnAgentTool.php` | Builds child `RoleToolkitResolver` and filters child toolkits |


## Learning System Architecture

Coqui provides an autonomous learning loop where a `learner` role analyzes poor evaluation reports and synthesizes corrective Skills (SOPs) to prevent the system from repeating the same mistakes. This closes the evaluate→learn feedback loop and fulfills the "Learning" element of the 10 Elements of Agentic System Design.

### How It Works

1. **`EvaluationStore::getPoorEvaluations()`** queries the evaluations table for sessions scoring below a threshold (default 0.5) within a rolling time window (default 7 days).
2. **`LearningToolkit`** provides 2 agent-facing tools: `learning_list_poor_evaluations` and `learning_read_evaluation`. The toolkit's visibility is controlled by the `learner` role's `toolkits` frontmatter field (`-*, +LearningToolkit, +SkillToolkit, +ProjectSourceToolkit`), so these tools are only available when the active role is `learner`.
3. **The `learner` role** (`config/roles/learner.md`) defines the autonomous workflow: list poor evaluations → read each report → identify failure patterns → check existing skills → create or update Skills with corrective procedures.
4. **Skill creation and updates** — the learner uses the standard `SkillToolkit` (available to all roles) to create new skills via `skill_create` or append lessons to existing skills via `skill_update`.
5. **Autonomous scheduling** — the user creates a schedule via the Schedule System to run the learner periodically (e.g., daily). The schedule spawns a background task with `role: learner`.

### Failure Pattern Categories

The learner classifies failures into three categories:

| Category | Trigger | SOP Focus |
| --- | --- | --- |
| Hallucination | Low `score_hallucination` | Document correct APIs, add anti-patterns |
| Completion | Low `score_completion` | Step-by-step procedures, verification checkpoints |
| Tool Efficiency | Low `score_efficiency` | Batching patterns, early-exit conditions |

### Agent-Facing Tools (LearningToolkit)

| Tool | Description |
| --- | --- |
| `learning_list_poor_evaluations` | Find recent evaluations below a score threshold (default: 0.5) within a time window (default: 7 days) |
| `learning_read_evaluation` | Read the full evaluation report, scores, and session context for a specific evaluation |

### Scheduling the Learner

Create a daily learning schedule via the agent or API:

```
schedule_create(
    name: "daily-learning",
    expression: "0 2 * * *",
    prompt: "Analyze recent poor evaluations and create or update Skills with corrective operating procedures.",
    role: "learner",
    timezone: "UTC"
)
```

### Key Source Files

| File | Purpose |
| --- | --- |
| `config/roles/learner.md` | Role definition with failure analysis workflow and skill creation guidelines |
| `src/Toolkit/LearningToolkit.php` | 2 agent-facing tools with role-scoped access (`learner` only) |
| `src/Storage/EvaluationStore.php` | `getPoorEvaluations()` — threshold-based query with rolling time window |
| `src/Toolkit/SkillToolkit.php` | `skill_update` tool enables iterative skill refinement |


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

> Configure via `roles.vision` in `openclaw.json`. The vision role file (`config/roles/vision.md`) defines instructions and access level but does **not** hardcode a model. Resolution priority: roles mapping → primary model fallback. See README Vision section for provider compatibility and configuration examples.

### Key Source Files

| File                           | Purpose                                                                                     |
| ------------------------------ | ------------------------------------------------------------------------------------------- |
| `src/Agent/VisionAnalyzer.php` | Single-shot child agent: resolves provider, downloads/encodes images, sends multimodal chat |
| `src/Tool/VisionTool.php`      | Agent-facing tool: validates input, delegates to VisionAnalyzer, surfaces errors            |
| `config/roles/vision.md`       | Role definition: instructions for structured image analysis                                 |



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

### Read-Only Shell Access (`readonly-shell`)

The `readonly-shell` access level gives child agents read-only filesystem access **plus** a restricted subset of shell commands for codebase exploration. This sits between `readonly` (no shell at all) and `full` (full shell).

The restricted command list is defined in `SpawnAgentTool::READ_ONLY_SHELL_COMMANDS`:

`grep`, `find`, `cat`, `head`, `tail`, `wc`, `ls`, `sort`, `uniq`, `sed`, `awk`, `diff`

These are all read-only search and inspection commands — no `git`, `php`, `curl`, `wget`, `make`, or any command that can modify state. The `OrchestratorAgent` uses the same restricted list when the active role's access level is `readonly-shell`.

### Safety Layer Interactions

Each CLI flag affects a specific safety layer. They do **not** interact with each other:

| Flag             | Affects                     | What it changes                            | What it does NOT change                                 |
| ---------------- | --------------------------- | ------------------------------------------ | ------------------------------------------------------- |
| `--auto-approve` | `InteractiveApprovalPolicy` | Skips confirmation prompts for gated tools | Shell allowlist, ScriptSanitizer, CatastrophicBlacklist |
| `--unsafe`       | `ScriptSanitizer`           | Allows all PHP functions in `php_execute`  | Shell allowlist, approval policy, CatastrophicBlacklist |
| Neither          | —                           | All safety layers active at defaults       | —                                                       |

The `CatastrophicBlacklist` (hardcoded destructive patterns) **cannot be disabled by any flag**.

The shell allowlist is only configurable via `openclaw.json` — it is not affected by CLI flags. This is by design: the allowlist is a structural configuration choice, not a runtime safety toggle.

### Key Source Files

| File                                      | Purpose                                                           |
| ----------------------------------------- | ----------------------------------------------------------------- |
| `src/Agent/OrchestratorAgent.php`         | Reads `shellAllowedCommands` from config, constructs ShellToolkit |
| `src/Tool/SpawnAgentTool.php`             | Passes shell allowlist to child agent ShellToolkit                |
| php-agents `src/Toolkit/ShellToolkit.php` | Enforces allowlist, injection detection, deny patterns            |
| `src/Config/CatastrophicBlacklist.php`    | Always-on destructive command blocking                            |



## Failover Provider Architecture

Coqui provides automatic model failover via the `FallbackProvider` decorator pattern. When the primary LLM provider fails with a retryable error (rate limit, server error, timeout), Coqui transparently retries the request on the next configured fallback provider. This logic lives entirely in Coqui — php-agents has no awareness of fallback models.

### How It Works

1. **`OpenClawConfig::getFallbacks()`** reads `agents.defaults.model.fallbacks` from `openclaw.json` — an ordered array of `"provider/model"` strings.
2. **`OrchestratorAgent::createAgent()`** checks if fallbacks are configured. If so, it uses `ProviderFactory` to create a provider for each fallback string, wraps the primary provider in a `FallbackProvider` decorator, and wires the `onNotify` callback to the agent's observer system.
3. **`FallbackProvider`** implements `ProviderInterface` — it is invisible to `AbstractAgent`. On every `chat()`, `stream()`, or `structured()` call, it tries the primary provider first. If a retryable error occurs, it tries fallbacks in order. Non-retryable errors throw immediately.
4. **`ProviderErrorClassifier`** determines whether an error is retryable (429, 5xx, 408, network/connection) or fatal (401, 403, 400, 404). Fatal errors are never retried.
5. **Fallback events** are surfaced to the agent's observer system via the `setOnNotify()` closure. The agent emits `agent.warning` events when switching providers, so the user sees which model is being tried.

> Configure via `agents.defaults.model.fallbacks` in `openclaw.json` — see README for the config format. Fallbacks are tried in order; if all fail, the last error propagates to the agent.

### Key Source Files

| File                                        | Purpose                                                                                                  |
| ------------------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `src/Provider/FallbackProvider.php`         | `ProviderInterface` decorator — tries primary then fallbacks on retryable errors                         |
| `src/Exception/ProviderErrorClassifier.php` | Classifies errors as retryable (429, 5xx, 408, network) or fatal (401, 403, 400, 404)                    |
| `src/Config/OpenClawConfig.php`             | Application-specific `ConfigInterface` implementation — reads `openclaw.json`, provides `getFallbacks()` |
| `src/Exception/ConfigNotFoundException.php` | Domain exception for missing/unreadable `openclaw.json`                                                  |



## Utility Model Architecture

Many Coqui subsystems need a "cheap/fast" LLM for internal tasks (title generation, conversation summarization, memory compression). Rather than each subsystem independently resolving a model, Coqui provides a unified utility model resolution chain via `RoleResolver::resolveUtility()`.

### Resolution Chain

`resolveUtility()` checks the following sources in order, returning the first non-empty value:

1. **`agents.defaults.model.utility`** in `openclaw.json` — explicit utility model config
2. **`COQUI_UTILITY_MODEL`** environment variable — override without editing config
3. **`resolve('title-generator')`** — falls through the standard 3-tier role resolution (role file → openclaw.json roles → primary model)

### Subsystems Using Utility Model

| Subsystem | Callsite | Purpose |
| --- | --- | --- |
| Title generation | `TitleGenerator::resolveProvider()` | Session title generation |
| Auto-summarization | `AgentRunner::autoSummarizeIfNeeded()` | Pre-turn budget-triggered summarization |
| On-demand summarization | `SummarizeConversationTool::execute()` | Agent-invoked conversation compression |
| API summarization | `SummarizeHandler::handle()` | HTTP API summarization endpoint |
| REPL summarization | `RunCommand::handleSummarizeCommand()` | `/summarize` command |
| Memory compression | `OrchestratorAgent::instructions()` | MemorySummarizer core memory summary |
| Budget pruning | `OrchestratorAgent::__construct()` | SummarizePruningStrategy for in-loop pruning |

### Configuration

```json
{
    "agents": {
        "defaults": {
            "model": {
                "utility": "ollama/gemma3:4b"
            }
        }
    }
}
```

If not configured, the title-generator role's model is used. If that's also not configured, the primary model is used.

### Key Source Files

| File | Purpose |
| --- | --- |
| `src/Config/RoleResolver.php` | `resolveUtility()` — unified resolution chain |
| `src/Config/OpenClawConfig.php` | `getUtilityModel()` — reads config key and env var |


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

| Field         | Required | Default | Description                                                |
| ------------- | -------- | ------- | ---------------------------------------------------------- |
| `path`        | yes      | —       | Absolute path to the external directory (must exist)       |
| `alias`       | yes      | —       | Short name used as the symlink name under `workspace/mnt/` |
| `access`      | no       | `ro`    | `ro` (read-only) or `rw` (read-write)                      |
| `description` | no       | `''`    | Human-readable description shown in the storage map        |

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

| File                               | Purpose                                                                               |
| ---------------------------------- | ------------------------------------------------------------------------------------- |
| `src/Contract/MountDefinition.php` | Value object: validated mount path, alias, access level, description                  |
| `src/Config/MountManager.php`      | Symlink lifecycle, allowedPaths generation, storage map rendering, open_basedir paths |
| `src/Config/BootManager.php`       | `initializeMounts()` reads config and initializes MountManager                        |


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

| File                              | Purpose                                                           |
| --------------------------------- | ----------------------------------------------------------------- |
| `src/Memory/MemoryStore.php`      | SQLite + FTS5 + optional vector storage — full CRUD               |
| `src/Memory/MemorySummarizer.php` | Cached summary generation for system prompt injection             |
| `src/Toolkit/MemoryToolkit.php`   | 6 agent-facing tools (save, search, update, delete, forget, list) |

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


## Context Window & Conversation Summarization

Coqui provides automatic context window management and conversation summarization to prevent token limit overflows. The system uses php-agents' `ContextWindow` for per-iteration pruning, a pluggable `BudgetPruningStrategyInterface` for custom pruning logic, and LLM-powered summarization for intelligent conversation compression.

### Context Window Integration

1. **`OrchestratorAgent::resolveContextWindow()`** reads the `ModelDefinition` from config and creates a `ContextWindow` via `ContextWindow::fromModel()`. Falls back to 128K max / 4K reserved if no model definition is available.
2. **The `ContextWindow`** is passed to `AbstractAgent` at construction. On every iteration, `AbstractAgent::run()` calls `Conversation::fitWithinBudget()` to prune oldest messages when the conversation exceeds the token budget.
3. **Warning/critical thresholds** (80%/95% of max tokens) are set automatically by `ContextWindow::fromModel()`.

### Budget Pruning Strategy

`AbstractAgent` accepts an optional `BudgetPruningStrategyInterface` at construction. This strategy is passed to `Conversation::fitWithinBudget()` on every iteration:

- **`DefaultBudgetPruningStrategy`** (php-agents) — the extracted built-in logic: trim tool results → drop oldest turns → aggressive trim → repair → merge. Used when no custom strategy is provided.
- **`SummarizePruningStrategy`** (Coqui) — attempts LLM summarization via `ConversationSummarizer` before falling back to default pruning. If summarization doesn't reduce tokens enough, applies `DefaultBudgetPruningStrategy` on top. On any failure, falls back entirely to default pruning.

`OrchestratorAgent` creates a `SummarizePruningStrategy` at construction using the utility provider, session storage, memory store, and configurable `keepRecentTurns`.

### Automatic Summarization

Before each agent turn, `AgentRunner::autoSummarizeIfNeeded()` checks the conversation's estimated token usage against the context window budget. If usage exceeds a configurable threshold, the conversation is automatically summarized:

1. **Threshold check** — `agents.defaults.context.autoSummarizeThreshold` in `openclaw.json` (default: `75` = 75% of available budget). Accepts both percentage (1–100) and ratio (0.0–1.0) — ratios are automatically converted to percentages.
2. **Provider resolution** — uses the utility model resolution chain (see Utility Model section below).
3. **Keep recent** — `agents.defaults.context.autoSummarizeKeepRecent` controls how many recent turns are preserved during auto-summarization (default: `15`, clamped 1–20).
4. **Workflow context injection** — `AgentRunner::buildWorkflowContext()` queries `TodoStore` (stats, active, pending todos) and `ArtifactStore` (active artifacts) to build a structured context string. This is passed to `ConversationSummarizer` so the LLM preserves plan/todo state in the summary, preventing agents from losing their place after summarization.
5. **Summarization** — `ConversationSummarizer` splits the conversation, compresses older messages via LLM, rebuilds with a summary `SystemMessage`.
6. **Observer notification** — emits `agent.summary` event so terminal/SSE observers can alert the user.

### Workflow Context in Summarization

All summarization paths (auto-summarize, on-demand tool, API handler, REPL command) inject workflow context into the LLM compression prompt. The context includes:

- Active todo stats (total, completed, in-progress, pending counts)
- In-progress and pending todo titles
- Active artifact list with types and stages

This ensures that after summarization, agents retain awareness of their current plan, progress, and next steps. The prompt file `prompts/tools/todos.md` includes a recovery section instructing agents to check `todo_list` and `artifact_list` after detecting a `[CONVERSATION SUMMARY]` in history.

### On-Demand Summarization

Users and agents can trigger summarization manually:

| Interface | Trigger | Description |
| --- | --- | --- |
| REPL | `/summarize [recent N] [focus "topic"]` | Summarizes the current session |
| Agent tool | `summarize_conversation(scope, keep_recent, focus)` | Agent-invoked summarization |
| API | `POST /api/v1/sessions/{id}/summarize` | Body: `{keep_recent, focus}` |

### Summarization Process

`ConversationSummarizer` performs the following steps:

1. **Split** — finds user turn boundaries, keeps the N most recent turns (configurable via `agents.defaults.context.keepRecentTurns`, default 10), marks older messages for compression. System messages are always preserved.
2. **Compress** — sends the older messages to a cheap LLM with a structured prompt requesting a <500 word summary covering key decisions, technical details, code references, and unresolved items. If a `workflowContext` string is provided, it is injected into the LLM prompt as a "Current workflow state" section that must be preserved in the summary.
3. **Rebuild** — constructs a new `Conversation` with: original system messages + summary `SystemMessage` (marked with `[CONVERSATION SUMMARY]`) + preserved recent messages.
4. **Persist** (optional) — stores the summary as a `session_summary` area `MemoryEntry` in `MemoryStore` for cross-session awareness.

### Configuration

```json
{
    "agents": {
        "defaults": {
            "context": {
                "autoSummarizeThreshold": 75,
                "autoSummarizeKeepRecent": 15,
                "keepRecentTurns": 10
            }
        }
    }
}
```

| Key | Default | Description |
| --- | --- | --- |
| `autoSummarizeThreshold` | `75` | Token usage percentage that triggers auto-summarization. Accepts 1–100 (percentage) or 0.0–1.0 (ratio, auto-converted) |
| `autoSummarizeKeepRecent` | `15` | Turns preserved during auto-summarization (1–20) |
| `keepRecentTurns` | `10` | Default turns preserved during on-demand summarization |

### Key Source Files

| File | Purpose |
| --- | --- |
| `src/Memory/ConversationSummarizer.php` | Core summarization logic: split, compress via LLM, rebuild conversation |
| `src/Memory/ConversationSummaryResult.php` | Value object: summary text, message count, token metrics (before/after) |
| `src/Config/SummarizePruningStrategy.php` | BudgetPruningStrategyInterface — summarize-then-drop with default fallback |
| `src/Tool/SummarizeConversationTool.php` | Agent-facing tool with scope/keep_recent/focus parameters |
| `src/Api/Handler/SummarizeHandler.php` | POST API endpoint for session summarization |
| `src/Agent/AgentRunner.php` | `autoSummarizeIfNeeded()` — pre-turn budget check and auto-summarize |
| `src/Agent/OrchestratorAgent.php` | `resolveContextWindow()` — ContextWindow from ModelDefinition; creates SummarizePruningStrategy |


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

| Element    | Convention                      | Example                |
| ---------- | ------------------------------- | ---------------------- |
| Classes    | PascalCase                      | `VideoProcessor`       |
| Interfaces | PascalCase + `Interface` suffix | `ProviderInterface`    |
| Enums      | PascalCase                      | `Role`, `FinishReason` |
| Methods    | camelCase                       | `getConfig()`          |
| Properties | camelCase                       | `$maxTokens`           |
| Constants  | UPPER_SNAKE                     | `MAX_RETRIES`          |
| Functions  | camelCase                       | `buildPrompt()`        |
| Variables  | camelCase                       | `$outputPath`          |
| Namespaces | PascalCase                      | `CoquiBot\Coqui`       |

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

| File                                       | Purpose                                                                                                                    |
| ------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------- |
| `src/Config/CatastrophicBlacklist.php`     | Hardcoded + configurable always-on safety patterns                                                                         |
| `src/Config/ScriptSanitizer.php`           | Static analysis of generated PHP (respects `--unsafe`)                                                                     |
| `src/Config/AutoApprovalPolicy.php`        | Auto-approves tools except catastrophic commands                                                                           |
| `src/Config/InteractiveApprovalPolicy.php` | Interactive user confirmation with audit logging                                                                           |
| `src/Config/WorkspaceComposerManager.php`  | Manages `workspace/composer.json` lifecycle                                                                                |
| `src/Config/ToolkitDiscovery.php`          | Boot-time discovery of toolkit packages; implements `PackageEventListenerInterface`; wraps toolkits with credential guards |
| `src/Config/CredentialResolver.php`        | Workspace `.env` management with hot-reload via `putenv()`                                                                 |
| `src/Config/UpdateManager.php`             | Dependency update checking and application; controlled by `COQUI_CHECK_UPDATES` / `COQUI_AUTO_UPDATE` env vars             |
| `src/Tool/RestartTool.php`                 | Agent-facing tool to trigger graceful restart; sets flag via closure, gated by execution policy                            |
| `src/Storage/SessionStorage.php`           | Sessions, messages, and audit log persistence                                                                              |

## Documentation

- **README.md** — installation, quick start, usage examples.
- **PHPDoc** — only for complex logic, generics (`@template`), or where native types are insufficient.
- Inline comments explain *why*, not *what*.

## Security

- Never hardcode secrets. Use environment variables or `.env` files.
- Validate and sanitize all external input.
- Use `filter_var()` with appropriate filters.
- Escape output based on context (HTML, SQL, shell).
- Use parameterized queries — never concatenate SQL.

### Safety Architecture

> See README Safety section for the layered model overview. The 5 layers are: workspace sandboxing, ScriptSanitizer, CatastrophicBlacklist, InteractiveApprovalPolicy, and audit logging.

When adding new tools or modifying safety checks:

- Never remove patterns from `CatastrophicBlacklist::HARDCODED_PATTERNS`.
- Always log decisions through `SessionStorage::logAudit()` in approval policies.
- The catastrophic blacklist check must run **before** any user prompt or auto-approval.
- New dangerous operations should be added to the `gatedTools` array in `RunCommand`.

### Restart Architecture

> See README for the launcher exit code behavior (codes 0, 10, crash recovery). Implementation: `/restart` causes `RunCommand` to return `RESTART_EXIT_CODE` (10). The `restart_coqui` tool sets a `restartRequested` flag via a closure callback; the REPL loop checks it after `runAgent()` completes.

When adding restart triggers:

- Always use exit code `10` (`RunCommand::RESTART_EXIT_CODE`) for intentional restarts.
- The `restartRequested` flag is checked *after* `runAgent()` completes — the agent finishes its current turn gracefully.
- The launcher's crash counter resets on intentional restarts (code 10); only consecutive unintentional crashes count toward the 3-attempt limit.

### Signal Handling

**Ctrl+C sends SIGINT to the entire foreground process group** — both the bash launcher and the PHP REPL receive it simultaneously.

#### At the readline prompt

PHP uses readline's callback API + `stream_select` (not blocking `readline()`) so signals are delivered within ~200ms without requiring Enter.

| Press | Behavior |
| ----- | -------- |
| 1st Ctrl+C | Sets `$ctrlCPressed`, breaks `stream_select` loop, prints `(Press Ctrl+C again to quit.)` |
| 2nd Ctrl+C | Returns exit code `0`. Counter resets on any successful input. |

#### During agent execution

A two-stage SIGINT handler replaces the idle handler for each agent turn:

| Press | Behavior |
| ----- | -------- |
| 1st Ctrl+C | `$cancellationToken->cancel()` + yellow banner. Agent finishes current iteration then stops cooperatively. Blocking `curl_exec()` is **not** interrupted. |
| 2nd Ctrl+C | `pcntl_signal(SIGINT, SIG_DFL)` + `posix_kill(posix_getpid(), SIGINT)` — kills immediately, exit code `130`. |

ESC triggers cooperative cancellation via `EscCancellationObserver` (polls STDIN for `\x1b` during streaming).

#### Exit codes & launcher behavior

The launcher registers `trap cleanup_session INT TERM EXIT` to stop background services on exit.

| Exit code | Source | Launcher action |
| --------- | ------ | --------------- |
| `0` | `/quit` or 2× Ctrl+C at prompt | Stop — `cleanup_session` shuts down background services |
| `130` | 2× Ctrl+C during agent | Same as `0` |
| `10` | `/restart` or config change | Restart, reset crash counter |
| other | Crash | Restart, increment counter (max `MAX_CRASHES`=3) |

`run_api_loop()` follows the same logic for the background API process.

#### Bash 3.2 / `set -u` compatibility

The launcher runs under `set -euo pipefail`. macOS ships bash 3.2, which throws `unbound variable` on empty array expansion under `set -u`. Use the conditional idiom for every array that may be empty:

```bash
# WRONG — crashes on bash 3.2 when array is empty
for svc in "${session_services[@]}"; do
# CORRECT — safe on all bash versions
for svc in "${session_services[@]+"${session_services[@]}"}"; do
```

Regression tests: `tests/bash/launcher-sigint-test.sh` / `make test-launcher`.

### Update Management

Coqui supports dependency update checking and application via `UpdateManager`. The system is controlled entirely through ENV vars stored in the workspace `.env` (not in `openclaw.json`):

| Variable              | Default | Purpose                                            |
| --------------------- | ------- | -------------------------------------------------- |
| `COQUI_CHECK_UPDATES` | `true`  | Check for outdated packages on startup             |
| `COQUI_AUTO_UPDATE`   | `false` | Automatically apply updates and restart on startup |

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

See README "Extending Coqui" for a full walkthrough with `ToolkitInterface`, `composer.json` registration, and credential declarations. Key contracts: `ToolkitInterface::tools()` returns `Tool[]`, `guidelines()` returns a prompt string. Use `StringParameter`, `NumberParameter`, `BooleanParameter`, `EnumParameter` from php-agents for typed parameters.

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
is_template: true              # optional — hides from selectable roles (e.g. title-generator)
ignore_updates: true           # optional — skip built-in update notifications
model: anthropic/claude-...    # optional — overrides openclaw.json
title_model: ollama/...        # optional
allowed-tools: ...             # optional
max_iterations: 30             # optional — per-role iteration limit (0 = unlimited)
---
<markdown instructions body>
```

### Template Roles

Roles with `is_template: true` are internal utility roles that should not be used directly by users. They are excluded from `selectableRoles()` (used by `SpawnAgentTool` and `/role` switching) but remain available in `availableRoles()` for programmatic access.

Built-in template roles: `title-generator`, `plan-todo-generator`.

Template roles can still be edited via `/role edit <name>` to customize their behavior.

### Role Update Tracking

Built-in roles are seeded from `config/roles/` to `workspace/roles/` on first boot. The `RoleUpdateTracker` monitors content hashes to detect when built-in roles are updated:

1. **On seed**, SHA-256 hashes of both the built-in source and the workspace copy are recorded in `workspace/data/role-hashes.json`.
2. **On boot**, `autoUpdateAndNotify()` compares current hashes:
   - **Unmodified roles** (workspace hash matches seeded hash) are auto-updated silently.
   - **Modified roles** (user has customized the workspace copy) generate a notification shown after the welcome banner.
3. **`ignore_updates`** — roles with this flag set in their frontmatter are skipped entirely.
4. **Backups** — before any update, the workspace copy is backed up to `workspace/backups/roles/`.

#### REPL Commands

| Command | Description |
| --- | --- |
| `/roles` | List all roles with template, update, and ignore status |
| `/roles update [name]` | Apply pending built-in updates (prompts for modified roles) |
| `/roles ignore <name>` | Set `ignore_updates` on a role |
| `/roles unignore <name>` | Clear `ignore_updates` on a role |
| `/role edit <name>` | Open a role file in `$EDITOR` |

#### Key Source Files

| File | Purpose |
| --- | --- |
| `src/Config/RoleUpdateTracker.php` | Hash tracking, update detection, auto-update with backup |
| `src/Config/RoleUpdateInfo.php` | Value object for pending update metadata |

### Max Iterations Configuration

Controls how many agent loop iterations a role can perform before stopping.

**Resolution priority:** role file `max_iterations` → `agents.defaults.maxIterations` in `openclaw.json` → hardcoded fallback (48).

#### Global Default

Set in `openclaw.json`:

```json
{
    "agents": {
        "defaults": {
            "maxIterations": 48
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

| Role            | `max_iterations` | Rationale                                         |
| --------------- | ---------------- | ------------------------------------------------- |
| orchestrator    | global default   | Main agent — uses `agents.defaults.maxIterations` |
| coder           | 48               | Complex coding tasks need more iterations         |
| reviewer        | 15               | Read-only analysis is usually quick               |
| assistant       | global default   | General purpose — inherits global                 |
| title-generator | 5                | Single-shot title generation                      |

#### Iteration Budget Awareness

Agents are automatically informed of their iteration budget via the system prompt. `SystemPrompt::withIterationBudget()` injects an `# ITERATION BUDGET` section between `# TOOLS` and `# TOOL USAGE RULES` that tells the agent how many iterations it has, what an iteration represents, and how to manage resources wisely (batch tool calls, prioritize impactful actions, prepare continuation questions when nearing the limit).

When `max_iterations` is `0` (unlimited), the budget section is omitted entirely — the agent receives no iteration constraint messaging.

## Quick Reference: PHP 8.4 Features to Use

| Feature                                      | Use Case                                                      |
| -------------------------------------------- | ------------------------------------------------------------- |
| Property hooks                               | Computed/validated properties without boilerplate getters     |
| `new` without parentheses                    | `new Foo` instead of `new Foo()` when no args                 |
| Asymmetric visibility                        | `public private(set)` for read-public, write-private          |
| `#[\Deprecated]` attribute                   | Mark methods for removal with IDE + tooling support           |
| `array_find()`, `array_any()`, `array_all()` | Cleaner array filtering and checking                          |
| `Mb\trim()`, `ltrim()`, `rtrim()`            | Multibyte string trimming                                     |
| Lazy objects                                 | `ReflectionClass::newLazyProxy()` for deferred initialization |
| `Dom\HTMLDocument`                           | Spec-compliant HTML5 parsing (replaces DOMDocument hacks)     |

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

| File               | Purpose                                                                                                                                                          |
| ------------------ | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `Dockerfile`       | PHP 8.4 CLI + all extensions + Composer.                                                                                                                         |
| `compose.yaml`     | Base service: bind-mounts source, named volume for workspace at `/app/workspace`, passes API keys from host, connects to host Ollama via `host.docker.internal`. |
| `compose.api.yaml` | Defines a separate `coqui-api` service for the HTTP API server on port 3300. Runs alongside the REPL without overriding it.                                      |
| `Makefile`         | Self-documenting targets. Native targets use bare names (`start`, `api`), Docker targets use `docker-*` prefix.                                                  |
| `conf.d/coqui.ini` | CLI-optimized PHP config: 512M memory, OPcache + JIT enabled, errors to stderr.                                                                                  |
| `.env.example`     | Documents all environment variables (API keys, ports, runtime flags, UID/GID).                                                                                   |

### Key Design Decisions

- **CLI base image**: `php:8.4-cli` keeps the image ~300MB smaller than Apache/FPM variants. Coqui has no HTTP server.
- **`docker compose run` over `up`**: The REPL requires interactive TTY. Use `run --rm` for sessions. The API service uses `up -d` separately.
- **Separate `coqui-api` service**: The API runs as its own service in `compose.api.yaml` rather than overriding the REPL's `coqui` service. This allows running REPL (interactive) and API (daemon) simultaneously from the same compose project.
- **Host Ollama**: Users connect to `host.docker.internal:11434`. Avoids GPU passthrough complexity and duplicate model storage.
- **Named volume for workspace**: Session databases, bot-installed packages, and workspace state persist across `docker compose run` invocations. The volume mounts at `/app/workspace` and both compose files set `COQUI_WORKSPACE=/app/workspace` so `BootManager` uses this path directly (bypassing `WorkspaceResolver::DEFAULT_WORKSPACE`). The Dockerfile pre-creates `/app/workspace` with correct ownership so named volumes inherit the `coqui` user permissions.
- **Port convention**: API=3300. Avoids conflicts with common services on 8080/3000.

### Environment Variables

Copy `.env.example` to `.env` before running. Key variables:

| Variable                  | Default                             | Purpose                                                                      |
| ------------------------- | ----------------------------------- | ---------------------------------------------------------------------------- |
| `COQUI_UID` / `COQUI_GID` | `1000`                              | Match host user to avoid permission issues                                   |
| `COQUI_WORKSPACE`         | `/app/workspace`                    | Workspace directory inside the container (must match the named volume mount) |
| `OPENAI_API_KEY`          | —                                   | Passed into the container                                                    |
| `ANTHROPIC_API_KEY`       | —                                   | Passed into the container                                                    |
| `OLLAMA_HOST`             | `http://host.docker.internal:11434` | Ollama endpoint                                                              |
| `COQUI_API_HOST`          | `127.0.0.1`                         | API bind address (`0.0.0.0` for network access)                              |
| `COQUI_API_PORT`          | `3300`                              | API server port                                                              |
| `COQUI_AUTO_APPROVE`      | `false`                             | Env-var equivalent of `--auto-approve`                                       |
| `COQUI_UNSAFE`            | `false`                             | Env-var equivalent of `--unsafe`                                             |

## Documentation Policy

When making changes to Coqui, keep documentation in sync:

- **README.md** — update when adding user-facing features (new CLI options, new tools, new capabilities, changed behavior). The README is the first thing users see.
- **AGENTS.md** — update when adding architectural patterns, new conventions, new contributor workflows, or modifying the safety/security model.
- **config/source.json** — update when adding, renaming, removing, or significantly modifying source files (see Source Map Maintenance above).
