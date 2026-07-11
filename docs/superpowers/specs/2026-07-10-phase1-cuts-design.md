# Phase-1 Thinning Batch: Channels, Packages, Background Tasks

**Date:** 2026-07-10
**Status:** Approved (design)
**Roadmap:** `docs/superpowers/specs/2026-07-10-platform-thinning-roadmap-design.md`
**Priority frame:** loops > profiles > prompt-budgeting. Trim anything that does not serve those three or their required substrate. API is the primary surface; the REPL is a conversation portal.

## Purpose

Land the next Phase-1 cuts from the thinning roadmap as **three independent removal PRs plus one no-op decision**. This batch is removal-only — it deletes non-aligned surface and funnels asynchronous work through loops. It does **not** add features. The loop-maturation work this batch points toward (live transparency) is a **successor spec**, not part of this batch.

Four items:

1. **Channels** — full delete.
2. **Composer + Packagist toolkits** — delete from core, no mod.
3. **Vision** — keep baked in. No code change (documented decision only).
4. **Background tasks** — full de-tool: remove the agent-facing `BackgroundTaskToolkit` and its child/tool offshoots; keep the execution substrate (loops run on it).

## Non-Goals

- No loop-maturation work here. Live streaming / SSE / WebSocket transparency and in-chat activity surfacing are a **separate successor spec** (`2026-07-1x-loop-transparency-design.md`). This batch only removes.
- No image-generation work. Vision stays exactly as-is.
- No new mod packages. The stale standalone `coqui-toolkit-composer` / `coqui-toolkit-packagist` snapshots are left untouched on disk and are **not** revived or published in this batch.
- No dependency removal. `symfony/http-client` stays in core (used by the provider HTTP stack, `WebToolkit`, and `VisionAnalyzer`); the deleted toolkits do not carry any Composer dependency of their own.

## Decisions Locked (from brainstorming)

- **Composer/Packagist:** delete from core with **no** mod. The old standalone snapshots are stale (Feb/Mar) and the core versions diverged materially (e.g. `ComposerTool` grew 480 → 957 lines with a `doctor` action, path repositories, an expanded framework denylist, backups, restart integration). Reviving the snapshots would regress; extracting the current versions is more work than the value justifies right now. Cleanest is straight removal. Agent loses `composer`/`packagist` tools; a future revive remains possible from the on-disk snapshots.
- **Channels:** full clean removal, including the session schema / API row shape. Removing `channel`, `channel_bound`, and `session_origin='channel'` from the session row is a **conscious API-shape change**.
- **Background tasks:** full de-tool. The agent no longer creates or manages raw background tasks; loops are the primary mechanic for spinning up async work. Every removed background-*agent* capability already has a loop equivalent that exists today (see mapping below). `start_background_tool` is dropped outright.
- **Vision:** unchanged.

## Architectural Grounding

- **Loops run on the background-task substrate.** `LoopManager` advances a loop by calling `storage->createTask(...)` (`src/Api/LoopManager.php:157`); each stage executes as a background task via `BackgroundTaskManager` → `TaskRunCommand` (`proc_open`). `createTask` is shared infrastructure also used by `ScheduleManager`, `WebhookDispatchService`, the `/tasks` API (`TaskHandler`), `RetryBackgroundTaskAction`, and `EscalateLoopFailureAction`. **This substrate is not removable** — de-tooling means removing only the agent-facing toolkit that sits on top of it.
- **`/mods` install does not use `ComposerTool`.** The only callers of `ComposerTool`/`ComposerToolkit` are agent-facing (`OrchestratorAgent`, `BackgroundToolExecutor`). Removing the Composer toolkit does not affect mod installation.
- **Children already cannot spawn agents.** `SpawnAgentTool` is instantiated once at top level (`OrchestratorAgent:649`) and never inside `buildToolkits`. The only way a child spawns async work today is the `childBackgroundTasks` attach-block in `SpawnAgentTool.buildToolkits`. Removing that block is the full "no child background agents" change; no deeper recursion work is needed.
- **`start_background_tool` is the sole producer of `tool_name` task records.** Deleting it retires `BackgroundToolExecutor` entirely and the `tool_name` branch in `TaskRunCommand`. `BackgroundToolExecutor` also imports the Composer/Packagist toolkits, so deleting it first removes that coupling before the Composer/Packagist PR.

### Background-agent → loop capability mapping (no regression)

| Removed background tool | Loop equivalent (exists today) |
| --- | --- |
| `start_background_task` (async agent run) | `loop_start(definition: "goal-driven", goal: …)` |
| `task_status` / `list_tasks` | `loop_status` / `loop_list` |
| `cancel_task` | `loop_control(action: "stop")` |
| `start_background_tool` (async single tool) | *intentionally dropped* |

Built-in loop definitions available out of the box: `goal-driven`, `research`, `reflection`, `harness`, `diverge-converge` (`config/loops/`).

---

## Item 1 — Channels: full delete

### Delete (files)

Source:
- `src/Api/ChannelExecutionManager.php`
- `src/Api/ChannelManager.php`
- `src/Api/Handler/ChannelHandler.php`
- `src/Channel/` (entire directory: `ChannelConfig.php`, `ChannelConfigurationEditor.php`, `ChannelDiscovery.php`, and `Builtin/` — `DiscordChannelDriver.php`, `TelegramChannelDriver.php`, `SignalChannelDriver.php`, `SignalCliChannelRuntime.php`, `PlaceholderChannelRuntime.php`)
- `src/Contract/ChannelDriverInterface.php`
- `src/Contract/ChannelRuntimeInterface.php`
- `src/Repl/Handler/ChannelHandler.php`
- `src/Storage/ChannelStore.php` (this is the creator of the `session_channel` table)

Tests:
- `tests/Support/Channel/TestExternalChannelDriver.php`
- `tests/Unit/Api/ChannelExecutionManagerTest.php`
- `tests/Unit/Api/ChannelManagerTest.php`
- `tests/Unit/Api/Handler/ChannelHandlerTest.php`
- `tests/Unit/Channel/ChannelDiscoveryTest.php`
- `tests/Unit/Channel/SignalChannelDriverTest.php`
- `tests/Unit/Repl/Handler/ChannelHandlerTest.php`
- `tests/Unit/Storage/ChannelStoreTest.php`

Docs:
- `docs/CHANNELS.md`

### Excise (edit, remove channel threads)

- `src/Repl/ReplCommandCatalog.php`, `src/Repl/SlashCommandRouter.php`, `src/Repl/TabCompletion.php` — channel command/routing/completion entries.
- `src/Api/Handler/ServerHandler.php`, `HealthHandler.php`, `ConfigHandler.php`, `SessionHandler.php` — channel manager / health / config / session references.
- `src/Api/Webhook/WebhookDispatchService.php:87` — remove `'channel'` from the placeholder-path list.
- `src/Command/RunCommand.php`, `src/Command/ApiCommand.php` — channel wiring.
- `src/Config/BootManager.php` — remove `ChannelDiscovery` field, `channelDiscovery()` accessor, and `discoverChannels()` (called from boot at ~line 115).
- `src/Config/OpenClawConfig.php` — remove `getChannelConfig()`.
- `src/Config/ConfigValidator.php` — remove `validateChannels()` and its call site (~line 42).
- `src/Contract/CoquiDefaults.php` — remove `CHANNEL_*` constants (unknown-user policy, execution policy, inbound rate limit, outbound concurrency, health-check interval).
- `src/Storage/SessionStorage.php` — remove the `session_channel` join (`sessionChannelJoin`), the `sc.channel_*` columns from session SELECTs, and the channel row-hydration (`channel_bound`, `channel`, `session_origin='channel'`). **This drops `channel`/`channel_bound` from the session row shape and removes `'channel'` as a possible `session_origin` value.**
- `src/Support/ProfileSessionLifecycleManager.php` — remove the `channel_bound` filter (currently excludes channel-bound sessions).
- `src/Support/InteractiveSessionService.php` — channel references.
- API routing — remove `/api/v1/channels*` routes wherever registered.
- `config/source.json` — remove channel entries.
- `docs/API.md`, `docs/COMMANDS.md`, `docs/FEATURES.md`, and any README channel mentions — remove.

### Schema / data notes

- The `session_channel` table is created by `ChannelStore`. Deleting `ChannelStore` stops the table from being (re)created on fresh DBs. On existing DBs the table becomes a **dormant orphan** — acceptable for a personal OS; no explicit `DROP TABLE` migration is required. (If a clean-up migration is desired, follow the existing `SessionStorage` migration pattern and add `DROP TABLE IF EXISTS session_channel`; optional, low priority.)
- `openclaw.json` `channels` block becomes ignored. Since `validateChannels()` is removed, it is no longer validated; a leftover block is inert. Document this in configuration docs.

### API-shape change (call out in PR + `docs/API.md`)

Session objects returned by the API lose `channel` and `channel_bound`, and `session_origin` can no longer be `'channel'`. Any client relying on those fields must adapt.

---

## Item 2 — Composer + Packagist: delete from core, no mod

### Delete (files)

- `src/Tool/ComposerTool.php`
- `src/Tool/PackagistTool.php`
- `src/Toolkit/ComposerToolkit.php`
- `src/Toolkit/PackagistToolkit.php`
- `tests/Unit/Toolkit/ComposerToolkitTest.php`
- `tests/Unit/Toolkit/PackagistToolkitTest.php`

(No separate `ComposerToolTest`/`PackagistToolTest` exist.)

### Excise (edit)

- `src/Agent/OrchestratorAgent.php` — remove the `addSystemToolkit('ComposerToolkit', …)` and `addSystemToolkit('PackagistToolkit', …)` calls (~lines 487–491), the gate-map entries `'ComposerToolkit' => 'packages'` and `'PackagistToolkit' => 'packages'` (~lines 169–170), and the imports (~lines 67, 69).
- `src/Agent/BackgroundToolExecutor.php` — remove the `ComposerToolkit`/`PackagistToolkit` registration (~lines 128, 132) and imports. **Note:** if Item 4 lands first (recommended order), `BackgroundToolExecutor` is already deleted and this step is a no-op.
- `src/Contract/CoquiDefaults.php` — remove `'ComposerToolkit'` and `'PackagistToolkit'` from the default system-toolkit list (~lines 219–220).
- `config/source.json` — remove the two toolkit entries (~lines 1504–1515).
- `docs/TOOLKITS.md`, `docs/FEATURES.md`, README — remove composer/packagist toolkit references.

### Keep

- `src/Tool/PackageInfoTool.php` — independent of Packagist (reads local vendor package info; no composer/packagist coupling). Stays.
- `symfony/http-client` in `composer.json` — still used elsewhere. No change.
- The stale `/home/carmelo/Projects/CoquiBot/Toolkits/coqui-toolkit-{composer,packagist}` snapshots — untouched on disk, not wired, not published.

### Consequence

The agent can no longer install or search packages via tools. This is consistent with the API-first direction (package management is driven by humans/apps, not agent conversation). The `/mods` install path is unaffected.

---

## Item 3 — Vision: keep, no code change

`src/Tool/VisionTool.php` and `src/Agent/VisionAnalyzer.php` remain baked into core. No files change. Image generation is a **future consideration**, explicitly out of scope. This item exists in the spec only to record the decision; it produces **no PR**.

---

## Item 4 — Background tasks: full de-tool

### Delete (files)

- `src/Toolkit/BackgroundTaskToolkit.php` (all five tools: `start_background_task`, `task_status`, `list_tasks`, `cancel_task`, `start_background_tool`).
- `src/Agent/BackgroundToolExecutor.php` (dead once `start_background_tool` is gone; also removes its Composer/Packagist coupling).
- `tests/Unit/Toolkit/BackgroundTaskToolkitTest.php`.
- Any `BackgroundToolExecutor` test, if present (verify during implementation).

### Excise (edit)

- `src/Agent/OrchestratorAgent.php` — remove the `BackgroundTaskToolkit` import (~line 64) and the gate-map entry `'BackgroundTaskToolkit' => 'background-tasks'` (~line 174), plus any registration.
- `src/Agent/OrchestratorDependencies.php` — remove the `backgroundTaskToolkit` field (~line 62) and import (~line 32).
- `src/Agent/AgentRunner.php` — remove the `BackgroundTaskToolkit` construction (~line 976) and import (~line 52).
- `src/Tool/SpawnAgentTool.php` — remove the `childBackgroundTasks` attach-block in `buildToolkits` (~lines 445–460), `isChildBackgroundTasksEnabled()`, the `agents.defaults.childBackgroundTasks` read (~line 528), the `isFeatureEnabled('background_tasks')` gate on that block (~line 440), and the import (~line 39). `SpawnAgentTool` itself stays.
- `src/Config/SetupWizard.php` — remove `configureChildBackgroundTasks()` and all `childBackgroundTasks` references (~lines 86, 110, 245, 247, 295, 334, 353–356).
- `src/Command/TaskRunCommand.php` — remove the `tool_name` branch (~lines 108, 273–385) and the `BackgroundToolExecutor` import (~line 8). Keep the prompt/agent branch.
- `config/source.json` — remove `BackgroundTaskToolkit` and `BackgroundToolExecutor` entries.
- Guidelines / system prompt — update wording that pointed agents at "run this in the background" so async work is directed to `loop_start` (e.g. a note in `LoopToolkit` guidelines and any orchestrator guidance).
- `docs/BACKGROUND-TASKS.md` — rewrite to reflect that agents no longer create background tasks directly; the substrate powers loops, schedules, webhooks, and the `/tasks` API; agent-driven async work goes through loops. Update `docs/FEATURES.md`, `docs/TOOLKITS.md`.

### Keep (substrate — do not touch)

- `src/Api/BackgroundTaskManager.php`, `src/Storage/BackgroundTaskRecordStore.php`, `src/Observer/BackgroundTaskObserver.php`, `src/Contract/BackgroundTaskSummary.php`.
- `src/Notification/RetryBackgroundTaskAction.php`, `src/Notification/EscalateLoopFailureAction.php`.
- `src/Api/Handler/TaskHandler.php` and the `/tasks` API.
- `src/Command/TaskRunCommand.php` prompt/agent execution path and `SessionStorage::createTask`.
- `CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS` and `OpenClawConfig::getBackgroundTaskMaxIterations()` — still used by `TaskHandler:78` and `TaskRunCommand:142`.
- The `background_tasks` DB table (and its now-vestigial `tool_name`/`tool_arguments` columns) — SQLite cannot cheaply drop columns; leave dormant. The table remains the substrate's store.

### Config note

`agents.defaults.childBackgroundTasks` is removed from the setup wizard. Existing configs carrying it become inert (no reader). Document in configuration docs.

---

## Execution Plan

Four items → **three removal PRs** (Vision is a no-op). Shared hotspots — `CoquiDefaults.php` (all three), `OrchestratorAgent.php` (Items 2 and 4), `BackgroundToolExecutor.php` (Items 2 and 4) — mean the PRs must land **serially, one at a time**, each fresh agent rebasing on the prior merge. This matches the "fire them off one at a time" fallback.

**Recommended order:**

1. **Channels** — most independent; touches the session schema, config, API handlers, `CoquiDefaults` `CHANNEL_*`, and the REPL, but not the toolkit-gating hotspots.
2. **Background full de-tool** — deletes `BackgroundToolExecutor` up front, removing the Composer/Packagist coupling before Item 3 runs.
3. **Composer + Packagist delete** — with `BackgroundToolExecutor` already gone, this PR only touches `OrchestratorAgent`, `CoquiDefaults`, and `config/source.json`.

Each task is handed to a fresh agent via `/prompt-agent-task` (subagent-driven development). This session reviews and the user merges (self-approval is blocked).

## Testing & Validation

Per PR:

- `composer test` (Pest) fully green.
- `composer analyse` (PHPStan level 8) `[OK]`.
- No orphaned references: `grep -rl` for the removed symbols across `src/`, `tests/`, `config/`, `docs/` returns empty (e.g. `Channel`, `ComposerToolkit`/`PackagistToolkit`, `BackgroundTaskToolkit`/`BackgroundToolExecutor`/`start_background_tool`/`childBackgroundTasks`).
- `config/source.json` updated to match source.
- Nearest canonical docs updated in the same PR.
- Working tree constraint: never `git add -A`/`git add .`; stage only the exact paths changed. The two intentional local edits (`.gitignore` modified, `.vscode/settings.json` deleted) stay unstaged.

## Risks & Mitigations

- **Channels session-shape change** breaks API clients reading `channel`/`channel_bound`/`session_origin='channel'`. → Conscious decision; document in `docs/API.md`. Dormant `session_channel` table on existing DBs is harmless.
- **Background substrate coupling** — verify during implementation that removing the toolkit leaves `BACKGROUND_TASK_MAX_ITERATIONS`, the `background_tasks` result payload (`AgentTurnResult:121`), and `/tasks` API intact (they are substrate, confirmed in grounding).
- **Composer/Packagist loss of agent self-install** → acceptable per API-first; `/mods` path unaffected; future revive possible from on-disk snapshots.
- **Serial-landing rework** if PRs are done out of order → follow the recommended order so `BackgroundToolExecutor` is deleted before the Composer/Packagist PR.

## Successor

After this batch: **loop transparency** spec (`2026-07-1x-loop-transparency-design.md`) — live streaming (SSE/WebSocket) of a running loop's stage tool-calls and output, loop activity surfaced back into the chat session, and optionally a lighter ad-hoc "quick task" affordance. That is where loops *become* the async mechanic experientially. It is additive (build-new), so it gets its own brainstorm → spec → plan cycle.
