# Platform Thinning Roadmap — Design

- **Status:** Approved (design), pending spec review
- **Date:** 2026-07-10
- **Author:** Carmelo Santana (with Claude)
- **Predecessor:** [The Lean Default](2026-07-07-lean-default-design.md) — this roadmap generalizes that prompt-budget cut into a whole-platform triage.

## Positioning

Coqui is a **hackable, local-first PHP agent runtime for self-hosters**. Going forward:

- The **HTTP API is the primary driver.** Frontends take many forms — a Flutter app, a web UI, a script, a future `Coqui::agent("code this project")` embed. Users build custom apps to serve their distinct needs.
- The **REPL is a simple communication portal**, not the widest control surface. It is for conversation, not administration. Rich loop monitoring lives on the **API** (WebSockets a likely future enhancement); the REPL shows only a minimal text status.
- **Ollama-first.** Best experience on small, free, local models. Every core token counts.

Three pillars carry the product, in priority order:

1. **Loops** — automated multi-role workflows.
2. **Profiles** — the identity layer (soul, backstory, preferences, profile-scoped memory).
3. **Prompt budgeting** — keep the default prompt and tool surface small.

Everything else is measured against these. This roadmap is the map; each row below becomes its own spec → plan → implement cycle, exactly as The Lean Default did.

## Decision Rule

> Does the feature **serve loops, profiles, or prompt-budgeting**, or is it **required substrate** for the API / sessions / safety? If neither, it leaves core.

"Leaves core" defaults to **extraction to an optional mod package** (the proven `mods` / marketplace / MCP-client pattern), *not* deletion — except where a feature is dead or directly duplicative of the API-primary direction, which is deleted outright.

## Disposition Legend

| Tag | Meaning |
|---|---|
| `PILLAR` | Keep and actively invest. |
| `SUBSTRATE` | Keep in core; the API/loops/sessions depend on it. May shed its agent-facing tool surface. |
| `SLIM` | Keep the concept; cut its sprawl (extra formats, heavy deps, rich UI). |
| `EXTRACT-NOW` | Pure toolkit+tool, no API handler or store — move to a mod package today. |
| `EXTRACT-LATER` | Has an API handler + storage — cannot leave cleanly until core grows a package extension point (Phase 2). |
| `DELETE` | Dead, duplicative, or in direct tension with API-primary. Removed entirely. |

## Triage Table (confirmed)

| Feature | Current surface | Disposition | Rationale |
|---|---|---|---|
| **Loops** | Toolkit, `LoopStore`, `LoopHandler`, TUI screens | `PILLAR` | Priority #1. |
| **Profiles / soul / preferences** | Config, prompt stack, profile-scoped memory | `PILLAR` | Priority #2. |
| **Prompt budgeting** | lean-default, toolkit stubbing, `ToolProfileResolver`, summarization | `PILLAR` | Priority #3. Extraction below compounds this. |
| **Memory** | `MemoryToolkit`, `MemoryStore`, core-summary | `SUBSTRATE` | Profile-scoped memory + core value proposition. |
| **HTTP API / Sessions / Safety** | `Api/`, `SessionStorage`, safety model, SSE observer | `SUBSTRATE` | The primary surface itself; safety is non-negotiable. |
| **Background-tasks** | `BackgroundTaskToolkit` + engine + `BackgroundTaskRecordStore` + `TaskHandler` | `SUBSTRATE`, de-tool | Loops run on the task **engine** (`requireTaskManager: true`). Keep engine + API `TaskHandler`; **remove the agent-facing `start_background_task` / `start_background_tool` toolkit**. |
| **Schedules** | `ScheduleToolkit`, `ScheduleStore`, `ScheduleHandler` (cron dep) | `SUBSTRATE`, keep | Loop-adjacent: scheduled loops are a natural pillar-#1 capability. Cron dep already present; small surface. |
| **Projects** | `ProjectToolkit`, `ProjectHandler`, `SessionProjectHandler`, `ProjectStore` | `SUBSTRATE`, keep on | "Lean working scopes," low cost, broadly useful to custom apps via the API. **Stays enabled** (reversed from default-off, 2026-07-10). |
| **Backstory generator** | `Backstory/` (~4.5k LOC, 22 extractor files) | `SLIM` core + `EXTRACT` formats | Keep text/structured/code extractors in core (`.txt .md .json .yaml .csv` + fenced code). **Remove** office/pdf/html/sql/xml/rtf from core (−3 Composer deps, ~2.9k LOC) and **re-home them in a new `coqui-toolkit-backstory-formats` mod** via an extractor-registration hook (Phase 2, item 10). |
| **REPL** | `Repl/` (~7.5k LOC) + `Tui/` (~1.9k LOC) | `SLIM` | Reduce to a communication portal. **Remove only the fullscreen `Tui/` framework + toolkit screen bridge** (~2.0k LOC). **Keep** toolkit *text* commands (`/mods`, `/mcp`), tab completion, and a **minimal text `/loops`** monitor. Rich loop detail → API. |
| **CoquiSource** | `CoquiSourceToolkit` + tool | `EXTRACT-NOW` | Pure toolkit, no API/store. Self-inspection is niche. |
| **Composer + Packagist** | `ComposerToolkit`, `PackagistToolkit` + tools | `EXTRACT-NOW` | One "package-management" mod. |
| **Vision** | Thin in-core `VisionTool` | `EXTRACT-NOW` | Already backed by external `carmelosantana/coqui-toolkit-images`; remove the in-core wrapper. |
| **Channels (Signal)** | `Channel/` (~1.6k) + `ChannelHandler` + `ChannelStore` (~3.0k total) | **`DELETE`** | A second ingress surface that duplicates "API is primary; users build their own apps." A custom app *is* the channel. |
| **Webhooks** | `WebhookToolkit` + 2 handlers + `WebhookStore` | `EXTRACT-LATER` | External event ingress a custom app could own. Non-pillar. |
| **Artifacts (+ sketch/hypothesis)** | `ArtifactToolkit` + `ArtifactHandler` + `ArtifactStore` + `ArtifactFileService` + `EditHistory` | `EXTRACT-LATER` | Heavyweight draft→review→final plan system. Non-pillar. Sketch/hypothesis types + phenomenological workflow ride with it. |

**`muse` / `philosopher` roles and `diverge-converge` / `reflection` loop definitions** are role files + loop JSON with near-zero code cost — **keep**. Their only code dependency (sketch/hypothesis artifact types) leaves with Artifacts.

## The Phase-2 Blocker

Packages can self-register **toolkits** today (`composer.json` → `extra.php-agents.toolkits`) and REPL commands, but **not API handlers or storage**. API handlers are hardcoded in `src/Command/ApiCommand.php` (25+ manual `new XHandler(...)` with tightly-coupled constructors). So every `EXTRACT-LATER` feature is blocked until core grows a **package extension point for API routes + storage migrations**. That extension point is the first task of Phase 2.

## Phasing

Each numbered item is an independent unit of work with its own spec → plan → review cycle. Phase 1 items need **no new core plumbing** and can land in any order (with the sequencing notes below). Phase 2 is gated on the extension point.

### Phase 1 — Wins that need no plumbing

1. **Delete Channels.** Remove `Channel/`, `ChannelHandler`, `ChannelStore`, `ChannelConfigurationEditor`, `/channels`, config `channels.instances`, `docs/CHANNELS.md`. Drop channel wiring from `ApiCommand`, `HealthHandler`, `BootManager`. (~3.0k LOC.)
2. **Slim Backstory extractors (core).** Keep in core the text/structured/code extractors (`Text`, `Markdown`, `Json`, `Yaml`, `Csv`, `CodeBlock`, `BackstoryTextReader`, interface/result/factory). Remove from core `Docx`, `Pdf`, `Html`, `Odt`, `Ods`, `Odp`, `Pptx`, `Xlsx`, `Rtf`, `Sql`, `Xml`, `OpenDocumentArchiveReader` (~2.9k LOC), and drop Composer deps `phpoffice/phpword`, `smalot/pdfparser`, `league/html-to-markdown`. Update `docs/PROFILES.md` supported-formats table to mark the extended formats as mod-provided. Capability is restored by the Phase-2 backstory-formats mod (item 10); there is an accepted window between this item and item 10 where extended formats are unavailable.
3. **Extract pure toolkits.** Move `CoquiSource`, `Composer`+`Packagist` (one package-management mod), and the in-core `Vision` wrapper out to mod packages. Remove from core `SYSTEM_TOOLKITS` / defaults. (~3.2k LOC leaves core.)
4. **De-tool Background-tasks.** Remove the agent-facing `BackgroundTaskToolkit` (`start_background_task` / `start_background_tool`). Keep the task engine + API `TaskHandler` (loops and the API still use them). Verify loops still run.
5. **Slim the REPL to a communication portal.** Remove **only** the fullscreen TUI: the entire `Tui/` framework (screen runner/shell/frame + `LoopDashboardScreen`/`LoopDetailScreen`) and the toolkit fullscreen bridge (`ToolkitScreenAdapter`, `ToolkitScreenHost`, `ToolkitScreenHostInterface`), plus the fullscreen branches in `LoopHandler`/`SlashCommandRouter` (~2.0k LOC). **Keep** toolkit *text*-command registration (so `/mods`, `/mcp` survive — confirmed they use `io->text` only), tab completion, and conversation I/O. `/loops` degrades to its existing text path (list + `/loops <id>` status); rich loop detail lives on the API. Retire admin commands only for deleted features (`/channels`). Update `docs/COMMANDS.md`.
6. ~~Default-off Projects~~ — **reversed (2026-07-10).** Projects stays enabled in all profiles. No action; listed only to record the decision.

### Phase 2 — Extraction plumbing + API-backed extraction

7. **Package extension point.** Give packages a way to register API routes (+ handler construction) and storage migrations, replacing the hardcoded handler list in `ApiCommand`. Design its own spec; likely a discovery + registry mirroring the toolkit `extra.php-agents` mechanism.
8. **Extract Webhooks** to a package on the new extension point.
9. **Extract Artifacts** (+ sketch/hypothesis types) to a package.
10. **Extractor-registration hook + backstory-formats mod.** A small hook letting packages register `ExtractorInterface` implementations with the core `ExtractorFactory`, then ship `coqui-toolkit-backstory-formats` providing the office/pdf/html/sql/xml/rtf extractors removed in item 2. Independent of the API/storage extension point (item 7) — a much smaller surface, so it may land earlier within Phase 2.

## Sequencing & Dependencies

- Phase 1 item **5 (REPL slim)** should land **after** item **1**, so the `/channels` admin command disappears with the feature rather than being edited twice. It removes only the fullscreen `Tui/` layer; the toolkit *text*-command machinery (`/mods`, `/mcp`) and tab completion **stay**, so it is decoupled from item 3.
- Item **3 (extract toolkits)** keeps working through the retained toolkit text-command machinery; no REPL change is required for it.
- Phase 2 items **8–9** are gated on the API/storage extension point (item **7**); item **10** (backstory-formats mod) is gated only on the smaller extractor-registration hook and can land independently.
- Backstory slim (item 2) is fully independent and is the cleanest first cut after Channels. Its extended-format capability is restored by item 10.

## Backward-Compatibility & Safety Policy

- **Extraction preserves capability**: an extracted feature remains installable as a mod (`/mods install ...`). Deletion (Channels only) does not.
- **`toolProfile: full`** must continue to behave sanely as features leave core — items that remove a toolkit update `CoquiDefaults::SYSTEM_TOOLKITS` and the `full` preset together, so `full` never references a moved toolkit.
- **Safety model is untouched** by every item: catastrophic blacklist, audit logging, sandboxing, and approval gates stay intact (per `AGENTS.md`).
- **Each item updates its docs in the same change** (`FEATURES.md`, the feature's page, `README.md` if user-facing, `config/source.json`) and runs the narrowest useful validation.
- **No `git add -A`.** Intentional unstaged working-tree edits must stay unstaged; each commit stages exact paths.

## Success Metrics

- **Core LOC:** ~11k+ removed from core across Phases 1–2 (Channels ~3.0k, backstory slim ~2.9k, pure-toolkit extract ~3.2k, REPL/TUI slim ~2.0k, Phase-2 extract ~3.1k).
- **Composer deps:** at least 3 removed (`phpoffice/phpword`, `smalot/pdfparser`, `league/html-to-markdown`); more as extracted packages carry their own.
- **Default tool/prompt surface:** measurably smaller under the lean profile (fewer core toolkits → fewer stubs and less guidance), compounding The Lean Default's 58% cut. Measure with `HeuristicCounter` on `getSystemPromptText()`, as in lean-default.
- **Posture:** administration lives on the API; the REPL is conversation-only.

## Non-Goals

- Not touching the `php-agents` generic loop (upstream package).
- Not redesigning loops, profiles, or memory internals — this is a *thinning* pass, not a rewrite of the pillars.
- Not building new features. The only net-new code is the two Phase-2 extension points (API/storage registration; extractor registration), which exist solely to enable removal from core.
- Not changing the safety model.

## Follow-on

On approval, each Phase-1 item gets its own brief spec (or, for the mechanical ones, goes straight to a plan). Recommended first cut: **item 2 (backstory slim)** — self-contained, removes 3 deps, and touches only pillar-#2-adjacent code — followed by **item 1 (delete Channels)**.
