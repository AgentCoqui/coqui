# The Lean Default — Design Spec

**Date:** 2026-07-07
**Status:** Approved (design)
**Scope:** A minimal-by-default agent tuned for small local (Ollama) models. Covers reduction of the default system prompt, the eager tool count, and on-demand capability exposure. Does **not** cover the API-primary surface reduction, embedding facade, or loops-to-API work — those are a separate later program.

---

## 1. Positioning (the lens)

**Coqui is a hackable, local-first PHP agent runtime for self-hosters.**

The core user runs their own persistent agent on their own machine, primarily against **local Ollama models** (free, private, hackable), and drives it through the **HTTP API** — so any frontend (Flutter app, web UI, script, future embed) is a first-class client. The **REPL is for conversation and hands-on hacking**, not for feature parity with the API.

Cut rules that follow from this:

1. **Optimize the default experience for a small local model.** A lean prompt and few eager tools are correctness requirements, not nice-to-haves — an 8B model cannot afford an 80-tool, 6k-token preamble.
2. **API is the primary surface.** New capability lands as API first; REPL parity is opt-in, not automatic.
3. **Thin, opinionated core.** Features not essential to "run a local agent" become optional toolkits (the MCP split is the template) or get cut.
4. **Hackable over turnkey.** Prefer readable, overridable config and small surfaces over deep built-in machinery.

Secondary users (embedding devs who want `Coqui::agent()`, toolkit authors) are served by the same thin core, but when they conflict with the self-hoster, the self-hoster wins.

---

## 2. Problem

A fresh, no-role session today loads:

- **~80–85 eager tools** in the initial schema.
- **~6,140 prompt tokens**, of which only ~820 is core identity (soul/base/security/done). The remaining **~5,320 tokens (87%) is the 17 `prompts/tools/*.md` files**, which load because their tools load.

The tools load because **12 toolkits are hardcoded as `SYSTEM_TOOLKITS`** in `src/Contract/CoquiDefaults.php` and are never deferrable, plus 10 always-on standalone tools.

Coqui already has the deferral machinery — `tool_search` + `StubToolkit` (85–94% token savings) and skills (progressive disclosure) — but the hardcoded system set can't use it. This spec redraws the always-on line so the default is lean and everything else is discoverable on demand.

**Reference audit (current state):**

| Always-on today | Tools | Prompt (approx) |
|---|---|---|
| FileSystem | 20 | ~140 |
| Shell | 1 | (in workspace.md) |
| Web | 3 | — |
| Memory | 9 | ~930 |
| Artifacts | 6 | ~440 |
| Projects | 6 | (in project ctx) |
| CoquiSource | 6 | ~140 |
| Composer / Packagist | 2 | ~350 |
| Loops | 5 | ~580 |
| Schedules | 8 | ~430 |
| 10 standalone tools | 10 | various |

Source anchors: `src/Contract/CoquiDefaults.php:211-242` (system toolkits + standalone tools), `src/Agent/OrchestratorAgent.php:879-951` (prompt assembly), `:1184-1209` (memory block), `:1241-1285` (project context), `:1293-1323` (deferred-toolkit hint), `src/Toolkit/StubToolkit.php`, `src/Tool/ToolSearchTool.php`, `src/Config/ToolkitLoadingRegistry.php`, `src/Contract/ToolkitLoadingMode.php`.

---

## 3. Target

A fresh `lean` (no-role) session:

- **~30 eager tools.**
- **~1.5–2k prompt tokens** (core identity ~820 + core tool prompts ~300 + capability index ~150–250 + variable memory recall block).
- ~70% prompt reduction, ~60% tool reduction versus today.
- **No capability lost** — everything deferred is reachable via `tool_search` in one step.
- `toolProfile: "full"` reproduces today's behavior exactly.

---

## 4. Design

### 4.1 The always-on core (shipped `lean` profile)

Eager, always loaded:

- `FileSystem`
- `Shell`
- `tool_search`
- `coqui_skills`
- `coqui_toolkits`
- `config`
- `credentials`

Everything else **defers**: Memory tools, Artifacts, Projects, Loops, Schedules, Web, CoquiSource, Composer, Packagist, Vision, `spawn_agent`, `summarize_conversation`, `restart_coqui`, `extract_memories`.

Two judgment calls, recorded explicitly:

- **Web search defers.** A local agent is not necessarily online, and web is one `tool_search` away.
- **`spawn_agent` defers.** Sub-agents are a power feature, not bootstrap.

### 4.2 Two-half rule: passive value stays, active tooling defers

Deferring a toolkit drops **both** its tool schemas *and* its `prompts/tools/*.md` guidance (deferred toolkits already exclude their prompt slugs — this is the primary source of the token savings). But passive, prompt-injected value is independent of the tools and is retained:

- **Memory:** the recall block (`OrchestratorAgent::buildMemoryBlock()`, `:1184-1209`) still injects relevant memories, so the agent remembers across turns. Only the 9 CRUD/management tools + their ~930-token `memory.md` prompt defer.
- **Auto-summarization:** the internal context-compression path still runs on budget pressure. Only the user-invokable `summarize_conversation` *tool* defers.
- **Projects:** active-project context still injects when a project is active (`injectProjectContext()`, `:1241-1285`). The 6 project management tools defer.

### 4.3 Discovery — concise capability index

A generated block (~150–250 tokens) lists the **deferred capability categories** with the search term to load each, so a small model has a map without paying for full schemas. Example rendering:

> Also available — load with `tool_search("<term>")`: memory, loops, schedules, artifacts, projects, web, vision, source, packages.

Requirements:

- **Generated from the actual deferred set**, not a static string. If a toolkit is promoted to eager (via profile/config/override), it drops off the index automatically.
- Replaces / extends the existing deferred-toolkit hint (`injectDeferredToolkitHint()`, `:1293-1323`) rather than adding a second parallel mechanism.
- One line, category-level. No per-tool enumeration, no when-to-use prose (that was the rejected "richer" option).

### 4.4 Config surface (hackable + safe migration)

- `agents.defaults.toolProfile: "lean"` — **new shipped default.** Resolves to the core set in 4.1.
- `agents.defaults.toolProfile: "full"` — restores today's everything-on behavior in one line. Backward-compatibility escape hatch.
- `agents.defaults.coreToolkits: [ ... ]` — explicit editable list of always-on toolkits/tools. When present, overrides the profile preset (advanced self-hoster control).
- Existing per-toolkit overrides in `.workspace/toolkit-loading.json` and the `/toolkits` REPL command / API continue to apply **on top of** the resolved core set (e.g. a user can promote `loops` to eager without switching to `full`).

Precedence (highest wins): per-toolkit override → `coreToolkits` explicit list → `toolProfile` preset → shipped default (`lean`).

### 4.5 Mechanism changes

1. **Resolve the core set at boot instead of hardcoding.** `SYSTEM_TOOLKITS` shrinks to the bootstrap core; the effective always-on set is computed from `toolProfile` / `coreToolkits`. Non-core toolkits default to **deferred** mode (deterministic), not budget-`auto` (budget-dependent). This makes the default predictable regardless of installed packages.
2. **Deferrable standalone tools.** Today standalone tools (`spawn_agent`, `vision_analyze`, `summarize_conversation`, `restart_coqui`, `extract_memories`) are added directly and always eager. Introduce a path to register a standalone tool as a **deferred stub** in the `tool_search` index (mirroring `StubToolkit` for toolkits). Core standalone tools (`tool_search`, `config`, `credentials`, `coqui_skills`, `coqui_toolkits`) stay eager.
3. **Capability index generation** as described in 4.3, sourced from the resolved deferred set.

### 4.6 Interaction with roles

Roles already replace the orchestrator body/tools wholesale. The lean default is the **no-role baseline**; role behavior is unchanged by this spec. A role may still opt into a broader tool set.

---

## 5. Backward compatibility & migration

- **Default changes** from ~80 tools to ~30. This is intentional and is the headline behavior change.
- `toolProfile: "full"` restores prior behavior exactly, in one config line. Documented in the upgrade notes.
- Existing `.workspace/toolkit-loading.json` overrides remain valid and are honored.
- On-disk paths and config keys for existing features are unchanged; only the *default eager set* and new `toolProfile` / `coreToolkits` keys are added.
- No storage schema changes.

---

## 6. Testing

- **Prompt-size assertion:** a fresh `lean` session's rendered system prompt is under a token ceiling (target < 2.5k as a guard) and a fresh `full` session matches the pre-change baseline.
- **Eager tool-count assertion:** `lean` exposes exactly the 4.1 core set; `full` exposes today's set.
- **Deferral correctness:** each deferred toolkit/standalone tool is (a) absent from the initial schema, (b) absent from the prompt (no `prompts/tools/*.md` guidance), and (c) discoverable via `tool_search` and callable after discovery.
- **Passive-value retention:** with memories present, the recall block still injects under `lean`; with a project active, project context still injects; auto-summarization still triggers on budget pressure — all with the corresponding *tools* deferred.
- **Capability index:** lists exactly the deferred categories; promoting a toolkit to eager removes it from the index.
- **Config precedence:** per-toolkit override > `coreToolkits` > `toolProfile` > default, verified with a small matrix.
- **Discover-then-call (synthetic, always runs):** with a **mocked/fake provider** scripted to return a `tool_search` call followed by a call to a deferred tool, assert the full path works — the deferred tool is found and invoked without ever being in the initial schema. This is the CI-safe, deterministic proof that aggressive deferral is usable; it has **no dependency on a live model or endpoint**.
- **Real-endpoint integration (optional, skipped by default):** the same discover-then-call flow against an actual small local Ollama model, gated behind an env var (e.g. `COQUI_OLLAMA_IT=1`) and **skipped when no endpoint is configured**. Verifies a genuine non-frontier model spontaneously uses the capability index + `tool_search`. Run manually once an endpoint is provisioned (see note below).

Run: `composer test`, `./vendor/bin/phpstan analyse` (level 8), targeted Pest files for prompt assembly and toolkit loading.

**Endpoint availability note:** no Ollama endpoint is available during implementation. All required tests are synthetic (mocked provider) and must pass in CI without a model. The real-endpoint integration test is the only check that needs a live model; it is optional/skippable and is the pre-merge manual gate. Provisioning options: (a) the maintainer provisions an Ollama endpoint, or (b) a tiny CPU-only model (e.g. a sub-1B model) is installed locally for a one-off manual pass. This gate must be run before the lean default is enabled for real users, but does not block landing the synthetic-tested implementation behind the `toolProfile` switch.

---

## 7. Out of scope (separate program)

Deliberately excluded — these belong to the API-primary surface program, brainstormed separately:

- Embedding facade (`Coqui::agent("...")`).
- Loops / schedules moved off REPL-first to API-primary; TUI dashboard trimming.
- Group-chat and memory **API** parity.
- Deleting REPL/TUI display code.
- Deeper Ollama polish beyond what leanness requires (embedding auto-detect, doc/example cleanup).

---

## 8. Open questions

None blocking. Implementation-plan stage will settle: exact config key names/casing to match existing `openclaw.json` conventions, the precise token ceiling for the test guard, and whether the capability index reuses or replaces `injectDeferredToolkitHint()` verbatim.
