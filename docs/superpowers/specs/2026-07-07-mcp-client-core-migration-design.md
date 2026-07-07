# MCP Client as Core Behavior — Design

- **Date:** 2026-07-07
- **Status:** Approved (design)
- **Repos:** `coquibot/coqui` (core), `coquibot/coqui-toolkit-mcp-client` (toolkit)

## Problem

MCP (Model Context Protocol) support in Coqui currently lives entirely in the
`coquibot/coqui-toolkit-mcp-client` composer dependency, which core `require`s
(`composer.json:20`). That package bundles two very different concerns:

1. A reusable **MCP client engine** (protocol, transport, orchestration, config,
   schema conversion) with no Coqui-specific opinions.
2. A **management/operator layer** (the `mcp` agent tool with ~17 actions, the
   `/mcp` REPL command + help, OAuth, formatting) that is Coqui-specific UX.

Core already reaches directly into the engine and management service:
`ApiCommand.php:377-392` news up `McpConfig`, `McpServerManager`,
`McpManagementService`, `OAuthHandler`, `ServerLoadingModeStore`, and
`McpServerPolicy` from the `CoquiBot\Toolkits\Mcp\*` namespace, and
`McpServerHandler` takes the service via constructor injection. So the
"dependency boundary" is largely fiction at the API layer.

The bundling forces an all-or-nothing choice: to use MCP at all you take the
full management tool surface, which bloats the default system prompt and ties a
core capability to an external package.

## Goals

1. Reduce codebase size / complexity.
2. Remove `coquibot/coqui-toolkit-mcp-client` as a core dependency; keep it as an
   optional dependency only.
3. Reduce the default system prompt.
4. Sharpen product direction: core = MCP runtime + API; toolkit = interactive
   management UX.

### Honest goal scorecard

- **Goal 2 — achieved.** Dependency removed from core `require`; toolkit optional.
- **Goal 3 — achieved.** The `mcp` tool, `/mcp` REPL, and management guidelines
  leave the default load. Per-server tools stay deferred by default.
- **Goal 4 — mostly achieved.** Clean line: *core owns the MCP runtime and the
  HTTP API; the toolkit owns the interactive management UX.*
- **Goal 1 — roughly neutral, explicitly.** This is a **relocation**, not a
  deletion. Core grows ~700-900 lines; the toolkit shrinks by about the same.
  Total LOC is roughly unchanged. The only lever that genuinely cuts total LOC is
  a feature-trim (see "Out of scope / future levers"). That is intentionally
  **not** part of this change.

## Decisions (from brainstorming)

1. **Per-server tool exposure:** agent + programmatic, **deferred**. Core
   connects enabled servers from config and exposes each server's tools to the
   agent respecting the existing eager/deferred loading model; internal toolkits
   and functions can also call them programmatically.
2. **MCP's role in core:** the **engine ships in core by default** so MCP works
   out of the box and internal toolkits/functions get it natively. The
   management UX stays in the optional toolkit.
3. **Toolkit coupling:** **loose, via context.** Core passes the live
   `McpRuntime` into the toolkit through the existing
   `fromCoquiContext($context)` array. The toolkit declares **no** composer
   dependency on coqui and no-ops cleanly when the runtime is absent.
4. **API scope:** the HTTP API keeps **full CRUD** (add/remove/update/connect/
   test) working **with the toolkit absent**, so the runtime service that backs
   the API lives in core. The API is the priority management surface.

## Architecture

### Core — `CoquiBot\Coqui\Mcp\*` under `src/Mcp/`

The MCP runtime, always available:

- **Engine**
  - `McpClient` — protocol lifecycle (initialize, tools/list, tools/call)
  - `Transport/TransportInterface`, `Transport/StdioTransport`
  - `JsonRpc/Message`, `JsonRpc/JsonRpcError`, `JsonRpc/IdGenerator`
  - `Schema/SchemaConverter` — MCP inputSchema → Coqui `Parameter`
- **Config**
  - `Config/McpConfig` — reads/writes `.workspace/mcp.json`
  - `Config/EnvResolver` — `${VAR}` placeholder resolution
- **Orchestration**
  - `McpServerManager` — multi-server connect + namespaced tool routing
- **Runtime service (backs the API; programmatic, no UI)**
  - config CRUD + connect/disconnect/refresh/test/status/snapshot
  - `Support/ServerLoadingModeStore` (eager/deferred persistence)
  - `Support/McpServerPolicy` (stdio allow/deny enforcement)
- **Agent exposure**
  - `McpServerToolkit` — per-server tools with loading keys; **deferred** by
    default; registered by a core-owned built-in seam (see Integration points)
- **Facade**
  - `McpRuntime` — small facade built once at boot (wrapping config + manager +
    runtime service + policy), shared by all consumers
- **Exceptions** — `Exception/*`

The exact division of the current 650-line `McpManagementService` is: the
programmatic runtime portions (CRUD, connect/test/status/snapshot, loading
modes, policy) move to core's runtime service; presentation/interactive portions
(formatting, OAuth orchestration, human-oriented output) stay in the toolkit.

### Toolkit — `coquibot/coqui-toolkit-mcp-client` (optional, thin)

Interactive management UX only:

- `McpToolkit` shell
- the `mcp` agent tool — dispatch only, delegating to `McpRuntime`
- `Command/McpCommandHandler` — `/mcp` REPL command + help + tab completion
- `Support/McpManagementFormatter`, `Support/ArgumentTokenizer`
- `Auth/OAuthHandler` — browser/PKCE OAuth flow (the `auth` action)

Coupling: reads `$context['mcp_runtime']`; **no composer dependency on coqui**;
`fromCoquiContext` registers nothing when the runtime is absent (old core / not
wired), so it degrades cleanly.

### One runtime, three consumers

Core builds a single `McpRuntime` during boot (owned by `BootManager`). It is
shared by:

1. **Agent exposure (core, default):** a built-in composite toolkit that
   `OrchestratorAgent` registers directly (not via composer discovery), yielding
   per-server `McpServerToolkit` children with their existing loading keys →
   deferred by default, agent + programmatic. Replaces today's
   discovery-registered `McpToolkit`.
2. **HTTP API (core, priority):** `ApiCommand.php:377-392` stops constructing
   toolkit classes and pulls the shared `McpRuntime`; `McpServerHandler`'s import
   flips to the core namespace. Full CRUD works with the toolkit absent.
3. **Optional toolkit:** core adds `$context['mcp_runtime']` to the
   toolkit-discovery context; the thin `mcp` tool + `/mcp` command operate on the
   same live connections.

## API behavior

- All existing MCP endpoints in `McpServerHandler` keep working with the toolkit
  absent **except** `POST /api/v1/mcp/servers/{name}/auth` (OAuth), which is the
  one endpoint gated on the toolkit. When the toolkit is missing it returns a
  clear error: OAuth requires the management toolkit.
- Endpoint paths, request/response shapes, and error codes are unchanged.

## Compatibility & guardrails (preserve exactly)

- On-disk formats/paths unchanged: `.workspace/mcp.json`, the loading-mode store
  file (exact filename verified against source during implementation), and
  `.mcp-tokens/`.
- Config keys `agents.defaults.mcp.allowedStdioCommands` /
  `deniedStdioCommands`, `ConfigValidator`, and `ConfigGuard` (agent cannot edit
  stdio policy) all stay.
- `McpServerPolicy` enforcement, audit logging, and stdio sandboxing preserved.
- `CoquiDefaults` system-toolkit handling updated to reflect that core now owns
  the built-in exposure rather than a discovered `McpToolkit`.
- Coordinated release: the toolkit gets a version bump whose `fromCoquiContext`
  no-ops without `mcp_runtime`, so an old-core + new-toolkit pairing does not
  double-register, and docs state core ships the engine as of this release. The
  previous toolkit (`^0.1`, which still bundles the engine) must not be paired
  with the new core; the new core drops it from `require` and the toolkit
  version bump makes the expectation explicit.

## Integration points to verify during planning (clean fallbacks exist)

1. **Built-in exposure seam.** Confirm the exact `OrchestratorAgent` /
   `BootManager` seam for registering a core-owned toolkit directly (the way
   system toolkits are assembled). Fallback: keep a tiny always-present core
   `McpToolkit` registered through the existing system-toolkit path.
2. **Loading-mode filename.** Exploration reported two possible names
   (`.workspace/toolkit-loading.json` vs `.workspace/mcp.loading-modes.json`).
   Confirm the real path against source and preserve it byte-for-byte.

## Testing

- Port engine unit tests into core's suite: JSON-RPC message, schema converter,
  `McpConfig`, env resolver, and the runtime-service portions (loading mode,
  policy).
- Keep tool/REPL/formatter and OAuth tests in the toolkit.
- Definition of done: `composer test` and `phpstan analyse` clean in **both**
  repos.

## Rollout / definition of done

1. Relocate engine + runtime service into core (`src/Mcp/`), update namespaces
   and imports (including `McpServerHandler` and `ApiCommand`).
2. Introduce `McpRuntime` and wire the three consumers.
3. Slim the toolkit to the management UX; add context-based coupling with a
   clean no-op fallback; version bump.
4. Remove `coquibot/coqui-toolkit-mcp-client` from core `composer.json`.
5. Update `config/source.json` and docs (`TOOLKITS`, `CONFIGURATION`, `API`,
   `FEATURES`) plus the toolkit README to reflect the split.
6. Port/split tests; `composer test` + `phpstan analyse` clean in both repos.
7. **Goal 3 — reconnect repos to GitHub** using the powerbank git-identity /
   remote rules: this working copy of core is currently **not** a git repo, so
   this step includes `git init` (or re-attaching the correct remote/identity)
   for core and verifying the toolkit remote, then committing this work.

## Out of scope / future levers

- **Feature-trim for real LOC reduction (goal 1).** Not part of this change.
  Candidates to drop or gate if a genuine size cut is wanted later: OAuth
  browser/PKCE flow, audit trails, full-text tool search, and eager/deferred
  management UX. This is the only lever that actually reduces total codebase
  size; it is recorded here as a deliberate, separate follow-up.
