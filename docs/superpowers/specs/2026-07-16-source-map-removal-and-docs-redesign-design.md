# Source Map Removal + `coqui_docs_*` Redesign — Design

**Date:** 2026-07-16
**Status:** Design approved (brainstormed 2026-07-16; forks decided below). Ready for spec review → plan.
**Supersedes:** the source-map fidelity/auditor design explored earlier the same day — **withdrawn** (rationale in "Why not the fidelity gate").

## Context

`config/source.json` is a 340-entry, hand-written map of `src/` (path, fqcn, layer, description, methods), read only by `CoquiSourceToolkit`'s `coqui_source_map` tool. It exists so the agent can navigate its own codebase.

The evidence says it cannot do that job, and does not currently do it at all.

### It is unfaithful

- **15 ghost methods** — names the map lists that do not exist on the class (1,030 names checked by reflection across three parser variants; the same 15 every time; zero false positives; all 340 FQCNs resolve). Examples: `LoopDiscovery::discover()` → really `discoverAll()`; `names()` → `availableLoops()`; `seedBuiltins()` → `seedBuiltinLoops()`. An agent trusting the map calls a method that fatals.
- **Three phantom API endpoints.** The `RoleHandler` entry claims *"POST /api/v1/config/roles (create), PATCH /{name} (update), DELETE /{name} (delete, blocked for built-ins)"*. `ApiCommand.php:639-640` registers exactly two routes, both GET. `RoleHandler`'s own docblock (`:20-21`) is correct; the map is what lies. This is worse than a ghost method: it advertises product capability that does not exist.
- **`layer` is 86% derivable from the directory, and the remainder is error, not insight.** All 7 `src/Renderer/*` are labelled `observer`. Seven of nine `src/Exception/*` are not in the declared `exception` layer (they are `config` or `contract`). `src/Api/Router.php` and all four middlewares are labelled `config`, while the `api` layer is declared as *"routing, middleware, endpoint handlers"* — the map contradicts its own layer definitions.
- **13 files are unmapped** beyond the 29 covered by its stated exclusions — including `src/Contract/AgentTurnRunnerInterface.php`, created in `5fe0e7a`, **the same commit that edited `config/source.json`**, during the sweep whose purpose was hardening the map, under three external reviews. The manual rule fails at maximum supervision.

### It is unaffordable, and unaffordable by construction

- **305,469 B ≈ 76K tokens.** `coqui_source_map` with no `section` returns all of it (`CoquiSourceToolkit.php:99-127`). Descriptions ≈ 27K tokens; `methods` ≈ 24K.
- Dropping `methods[]` **and** `fqcn` **and** truncating every description to one sentence still leaves **≈20K tokens**. A 340-entry map cannot be made small by trimming fields — only by cutting entries.
- For comparison, aider's repo map targets **~1K tokens** (`--map-tokens` default 1024, a documented soft target).

### It is expensive, and nobody is buying

- **31 of 195 commits (15.9%) touch it** — roughly one in six. Its hand-written churn is **+4,435 / −685** lines, chasing a `src/` that churned **+99,509 / −18,348** over the same period. It is 4,435 lines tracking a target moving 20× faster. Drift is arithmetic, not discipline.
- **The shipped default never loads it.** `TOOL_PROFILE_DEFAULT = 'lean'` (`CoquiDefaults.php:211`) and `LEAN_CORE_TOOLKITS` is only `FileSystemToolkit` + `ShellToolkit` (`:221-224`). `addSystemToolkit()` therefore wraps `CoquiSourceToolkit` in `StubToolkit` (zero prompt footprint) and `OrchestratorAgent.php:636-641` drops the `coqui-source` prompt slug. The tax is paid on every sixth commit; the default agent never sees the asset.

### Four of its five fields are duplication; the fifth is the one nothing can verify

`path` **is** the file. `fqcn` and `methods` are derivable from it. `layer` is 86% directory-derivable and otherwise wrong. Only `description` is genuinely curated — and it is the field no mechanical check can validate, and where `RoleHandler`'s phantom CRUD lives.

### External evidence: the empty quadrant

|  | Hand-authored | Derived from source |
|---|---|---|
| **Intent / conventions** | `AGENTS.md`, `.cursor/rules`, `CONVENTIONS.md`, Serena memories — **universal** | — |
| **Structure** | **nobody** | aider (tree-sitter + personalized PageRank), Cursor (AST chunks + embeddings + Merkle freshness), Serena (live LSP) |

No established agent tool navigates by a hand-written, file-by-file structural map; aider's "custom repo-map methods" remains an *open* feature request. Separately, a peer-reviewed study of 3,000+ GitHub projects found **28.9%** of the most-starred currently carry an outdated code-element reference in their docs and **82.3%** did at some point — because developers cannot tell when a source change invalidates prose that names code elements. `source.json` names code elements 1,030 times.

`config/source.json` is hand-authored structure: the one quadrant nothing occupies, at 76× aider's budget, with an 82.3% drift base rate.

### The docs index has the same disease

`config/documentation.json` is described in `AGENTS.md` as generated and never to be hand-edited. That is true of the file and **false of the generator**: `scripts/generate-documentation-index.php:12-24` holds a **hardcoded array of 12 paths**. Only section headings are derived. Eight of eighteen docs are consequently invisible to the agent — **`docs/LOOPS.md`, `docs/PROFILES.md`**, `QUESTIONS.md`, `ARTIFACTS.md`, `PROJECTS.md`, `CHAT.md`, `DATA_FLOW.md`, `TOOLKIT-EXTENSIBILITY.md`. Coqui cannot discover the documentation for its own two highest-priority subsystems. The "it's generated, don't hand-edit" rule created false confidence that stopped anyone from noticing — eight times.

## Goal

Delete the hand-authored structural map and route Coqui's self-knowledge through **docs** — curated intent, a genuinely generated index, and targeted retrieval.

- **Part A — Remove.** `config/source.json`, `coqui_source_map`, and all read-only source access; `CoquiSourceToolkit` → `CoquiDocsToolkit`.
- **Part B — `coqui_docs_*`.** Three tools: `map` (compact discovery), `read` (section retrieval, no silent truncation), `search` (**new** — full-text, the capability that does not exist today).
- **Part C — Make the docs index actually generated.** Glob, not an allowlist.

Everything retained reads generated or curated-and-reviewed content. Nothing retained is hand-authored structure.

## Part A — Removal

### Why source access goes entirely

Read-only source access is strictly weaker than the tools it sits beside. `ShellToolkit` is lean-core, eager, and defaults to *all commands except denied* (`ShellToolkit.php:88, :265` — `allowedCommands = []`); with `FileSystemToolkit` it already reads **and writes**. Where those are enabled, `coqui_read`/`list`/`search` are redundant. Where they are not, read-only source access only lets Coqui recite code it cannot change — which docs do better. The tools' one genuine justification (`FileSystemToolkit` is sandboxed to `workspacePath` + mounts and cannot reach the install dir) is real but pointed at low-value content; the same justification, pointed at docs, is what Part B keeps.

Nothing is destroyed: `config/source.json` remains in git history (`git show <sha>:config/source.json`) if its 340 descriptions are ever wanted as raw material.

### Delete

- `config/source.json`
- `tests/Unit/Config/SourceMapIntegrityTest.php`
- The `coqui_source_map`, `coqui_read`, `coqui_list`, `coqui_search` tools and their helpers (`sourceMapTool`, `readTool`, `listTool`, `searchTool`, the glob helpers at `:629`/`:645`, `MAX_GLOB_RESULTS`).

### Rename and rewire — every site

- `src/Toolkit/CoquiSourceToolkit.php` → `src/Toolkit/CoquiDocsToolkit.php` (drop `projectRoot`-scoped source access; keep the root only to locate `config/documentation.json` and `docs/`).
- `src/Agent/OrchestratorAgent.php:162` — slug map `'CoquiSourceToolkit' => 'coqui-source'` → `'CoquiDocsToolkit' => 'coqui-docs'`.
- `src/Agent/OrchestratorAgent.php:484` — registration; `:636-641` — `excludedToolPromptSlugs`.
- `src/Contract/CoquiDefaults.php:200` — `SYSTEM_TOOLKITS`.
- `src/Agent/AgentRunner.php:2172` — code-review reviewer toolkits.
- `src/Tool/SpawnAgentTool.php:319, :333, :338` — child-agent presets.
- `prompts/tools/coqui-source.md` → `prompts/tools/coqui-docs.md`; `prompts/tools/workspace.md` references.
- `config/roles/plan.md`; `tests/Unit/Toolkit/CoquiSourceToolkitTest.php` → `CoquiDocsToolkitTest`; fixture names in `tests/Unit/Config/RoleParserTest.php` and `RoleToolkitResolverTest.php`.

`config/source.json` is referenced by no build, release, Docker, or composer path — removal is clean there.

### Correct the stale claims

`CoquiSourceToolkit.php:93` ("*a structured index of **every core source file*** … *Use this first*"), `:80` ("*The source map describes **every core file***"), `:67` ("*Start with `coqui_source_map`*"), the class docblock `:19`, and `prompts/tools/coqui-source.md:6` ("*start here*") all die with the map. The replacement guidance must not reproduce a "start here, load everything" instruction.

### Documentation

`AGENTS.md`: delete the **Source Map Maintenance** section and item 5 of the Practical Change Checklist. Update the **Generated: `config/documentation.json`** section — it currently implies full generation and must state that the file list is globbed and per-doc metadata comes from frontmatter.

## Part B — `coqui_docs_*`

Three tools on `CoquiDocsToolkit`. All read `config/documentation.json` (generated) and `docs/*.md` (curated, user-facing, reviewed under the docs policy). Names move from `coqui_doc_*` to `coqui_docs_*`; these are model-facing tool names with no external consumers, so the rename is free.

### `coqui_docs_map` — discovery, compact by default

No-arg today returns the whole 27K-token index (`:310-312`). It must instead return a **compact summary**: one line per doc (`path`, `title`, `description`, section count) — ~600 tokens. Headings stay behind the existing `file` param (`:314-319`), which already returns a single doc's entry. The unknown-file error listing all paths (`:321-323`) is retained.

### `coqui_docs_read` — section retrieval, and no silent truncation

Keep the existing resolution chain: index line-ranges (`extractSectionFromIndex`, `:409`), fallback to direct heading parse (`extractSectionFromFile`, `:478`), fuzzy ≥50% "did you mean" (`findClosestHeading`, `:605-622`). The fallback matters and must survive Part C.

**Fix the truncation.** `MAX_READ_BYTES` is 65,536 (`:32`); `docs/API.md` is **143,927 B**. A section-less read of API.md today silently returns ~46% of it with no signal — the same false-confidence failure as the `phpunit.xml` suite that never ran. When a whole-file read would exceed the cap, return the **section list plus guidance to pass `section`** instead of truncating.

### `coqui_docs_search` — new

Full-text search across the indexed docs; returns bounded results of `path` + nearest `heading` + snippet + line, so the agent can chain straight into `coqui_docs_read`. **No documentation search exists today** — `coqui_search` was filename-glob only (`glob()`/`fnmatch()`, `:629`/`:645`).

This is the redesign's one capability gain, and it is the honest version of what the source map pretended to be: targeted retrieval over prose that is curated, reviewed, user-facing, and derived at call time from the `.md` files — so it cannot drift. It converts ~114K tokens of documentation from unreachable bulk into addressable knowledge.

## Part C — Make the docs index actually generated

`scripts/generate-documentation-index.php`:

- **Glob** `docs/*.md` plus `README.md` and `AGENTS.md` instead of the hardcoded array at `:12-24`. A new doc is indexed the moment it exists; the eight-doc blind spot cannot recur.
- **Per-doc frontmatter** supplies `title` and `description`, falling back to the H1 and first paragraph when absent. Intent lives inside the artifact it describes; structure is derived. This preserves today's curated descriptions (which are good) while removing the parallel list that made them drift.
- Add frontmatter to the 18 `docs/*.md` files (none have it today — they open directly on an H1).

The principle is the same one that condemns the source map: **derive structure, author intent, and never keep a hand-maintained list of what exists.**

## Why not the fidelity gate

The withdrawn design added an `exclude[]` policy, a shared auditor (`unclassified`/`dead`/`ghosts`/`badFqcn`/`unparseable`/`staleExclude`), a test gate, a composer script, a `.claude/skills/` manual, and a cleanup of the 13 + 15. It was sound, and it was aimed at the wrong target: it would have enforced truth on the four **derivable** fields while remaining structurally blind to `description`, the only curated one — the field where the phantom CRUD lives. Building an auditor to police data that should not be stored is the wrong trade. Deleting `methods[]` eliminates 100% of the ghost class; deleting the map eliminates the rest.

## Sequencing

1. **Part C first** (generator glob + frontmatter) — the docs index is the foundation Part B stands on, and it currently hides `LOOPS.md`/`PROFILES.md`. Landing B on a broken index would ship a search tool that cannot see eight docs.
2. **Part B** — the three `coqui_docs_*` tools.
3. **Part A** — removal and rename, once the replacement is proven.

May be two dispatches (C+B, then A) under one spec.

## Testing

- **Generator:** every `docs/*.md` on disk is indexed (the regression that hid LOOPS.md); frontmatter title/description honoured; H1/first-paragraph fallback when frontmatter is absent; README/AGENTS included.
- **`coqui_docs_map`:** no-arg returns the compact summary and **not** full headings, with an asserted token/byte ceiling; `file` returns that doc's headings; unknown file errors with the available list.
- **`coqui_docs_read`:** section by exact heading; case/backtick-insensitive match; substring match; fuzzy "did you mean"; **whole-file read of `docs/API.md` returns the section list, never a silent truncation**; index-absent fallback to direct file parse.
- **`coqui_docs_search`:** finds a term in an indexed doc and reports the right heading; result bound enforced; a term in a doc that Part C newly indexed (e.g. a loops-only term) is found — the direct regression test for the eight-doc blind spot; no-match returns empty, not error.
- **Removal:** no `src/`, `prompts/`, `config/`, or `tests/` reference to `CoquiSourceToolkit`, `coqui_source_map`, `coqui_read`, `coqui_list`, `coqui_search`, or `coqui-source` survives; the toolkit still registers and still defers correctly under the lean profile; `SpawnAgentTool` presets and `AgentRunner` reviewer toolkits still construct.
- **Full suite + PHPStan L8** green (baseline: 2,370 passing, 382/382).

## Non-Goals

- **No `docs/ARCHITECTURE.md` in this change.** `AGENTS.md`'s "Architecture in One Page" + "Source of Truth Rules" already carry the architectural index, and `AGENTS.md` **is** in the docs index — so it is already reachable via `coqui_docs_read`. Adding a third architecture artifact before a gap is demonstrated repeats the mistake. Revisit only if a real gap appears.
- **No distillation of the 340 descriptions.** They stay in git history; recover them if ever needed.
- **No source-navigation replacement** (`coqui_grep` and friends). Shell and `FileSystemToolkit` cover it where permitted; where they are not, source access is not wanted.
- **No `coqui_doc_*` aliases.** Clean rename.
- **No semantic/embedding search.** `coqui_docs_search` is lexical.
- **No changes to `FileSystemToolkit`, `ShellToolkit`, or the tool-profile system.**

## Open Items for the Plan

1. `config/documentation.json` is git-ignored and generated. On a fresh checkout without `composer regen-docs`, `coqui_docs_map` errors (`:296`) and `coqui_docs_read` silently falls back to direct file parsing. Decide whether `coqui_docs_map` and `coqui_docs_search` should fall back to globbing `docs/*.md` rather than failing — recommend yes for `map`, since discovery failing is worse than a slower path.
2. Frontmatter key names and whether an existing parser can be reused (`SkillParser` already parses SKILL.md frontmatter — check before writing a second one).
3. Whether `coqui_docs_search` searches section bodies only, or also headings/titles/descriptions (recommend all, ranked heading-first).
4. Result bound for `coqui_docs_search` (recommend 10–20, explicit, and reported when truncated — never a silent cap).

## Build Process

Brainstorm (done) → this spec → user review → `/prompt-agent-task` → plan (STOP for review) → SDD (Opus implementer + reviewer, two-verdict gate, whole-branch review) → independent verification → merge.

Cross-references: the withdrawn fidelity design (superseded, above); `docs/superpowers/specs/2026-07-15-tech-debt-sweep-design.md` (which corrected and marked the map selective in `0f09e39`); the platform-thinning roadmap (thin the core, prefer deletion to machinery).
