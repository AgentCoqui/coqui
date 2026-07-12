# Identity & Backstory Consolidation

**Date:** 2026-07-12
**Status:** Approved (design)
**Scope:** DESIGN ONLY — no source changes in this spec. Implementation is a later, separate brief.
**Roadmap:** `docs/superpowers/specs/2026-07-10-platform-thinning-roadmap-design.md`
**Related (settled inputs, do not redesign):**
- Artifacts — shared, `created_by` provenance: `docs/superpowers/specs/2026-07-11-artifacts-files-only-design.md`
- Memories — profile-scoped: `src/Memory/MemoryStore.php`
**Priority frame:** loops > profiles > prompt-budgeting. Less-is-more: consolidate and cut, do not add a parallel system.

## Purpose

Untangle Coqui's identity layer along three axes and make it leaner:

1. **Backstory storage.** Strip the heavy document-ingestion *generator* out of core; keep identity as plain markdown files the agent (or user) writes directly.
2. **Cross-profile data model.** Place identity data on the same coherent share/scope spectrum already used by artifacts and memories.
3. **Vocabulary.** Give the four overlapping terms (`soul` / `persona` / `profile` / `backstory`) crisp, non-overlapping definitions and one target term set.

This is a **removal-heavy** change: it deletes ~30 files / ~130 KB from core and relocates them to an optional toolkit. It adds only a tiny markdown-context loader.

## Two efforts (sequencing)

The work splits into two independent efforts. This spec designs **Effort 1** in full and **outlines Effort 2**.

- **Effort 1 — Backstory cleanup (ships now).** Move the backstory *generator* (extractors, assembler, manifest, inspection, `/backstory` command, API/REPL handlers, auto-regen) into `coqui-toolkit-backstory`. Core keeps only markdown loading: `soul.md` + `backstory.md` + a new `context/` subdir of `.md` notes. No rename.
- **Effort 2 — `profile → persona` rename (later, outlined).** A cross-platform terminology rename with **no migration code** — a clean break where users rename their own workspace folders. Kept out of Effort 1 so a cosmetic-but-broad rename never blocks the concrete storage cleanup.

The two are parallel-safe: Effort 1 touches the backstory subsystem and prompt composition; Effort 2 is a mechanical rename of the container concept. Either can land first.

---

## § 1 — Settled vocabulary

Investigation finding: the "four overlapping terms" are really **three real concepts + one prose-only word**.

- `soul` (13 files) — a first-class concept: `soul.md`, the pinned core-identity prompt section.
- `profile` (83 files) — a first-class concept: the identity container directory.
- `backstory` (41 files) — a first-class concept: a large generated-narrative subsystem.
- `persona` (9 files) — **not a stored concept.** Every occurrence is English prose ("personality profile", "the active persona"). No persona file, type, table, or code path exists.

### Target term set (end state, after both efforts)

| Term | Definition (crisp, non-overlapping) | Storage |
| --- | --- | --- |
| **persona** | The identity **entity/container** — a distinct character the agent embodies, defined by its soul + backstory + context. The unit switched with `/persona`. (Today implemented as the `profiles/` "profile"; renamed in Effort 2.) | filesystem dir |
| **soul** | The **core identity** prompt (`soul.md`): who the persona fundamentally *is* — values, voice, disposition. Required; pinned highest. The *essence*. | `soul.md` |
| **backstory** | The **narrative continuity** layer (`backstory.md`): origin, milestones, relational anchors, evolving history. Optional; pinned below soul. The *history*. | `backstory.md` |
| **context** | **Supplementary reference notes** (`context/*.md`): durable, topic-scoped facts about the persona's world (e.g. `github.md`, `stack.md`). Obsidian/Karpathy-style. Optional; pinned below backstory. The *reference sheet*. | `context/*.md` |
| **identity** | Informal umbrella for *soul + backstory + context* (the persona-owned tier). Not a file or type — just the collective word. | — |

Boundary neighbors kept distinct: **memories** (learned facts, DB, profile-scoped), **role** (capability layer, orthogonal to identity), **preferences** (behavior tuning).

Note on `persona`: it is **promoted**, not deleted. It stops being a stray prose synonym and becomes the real first-class name for the container (via Effort 2). Until Effort 2 lands, code and docs continue to say "profile"; the 9 prose sites align automatically once the rename happens (no separate prose cleanup needed).

---

## § 2 — Cross-profile data model (three tiers)

Identity data joins the same spectrum artifacts and memories already sit on:

| Tier | Members | Access | Attribution |
| --- | --- | --- | --- |
| **Shared** | artifacts | visible to all personas; no scoping | `created_by` — display-only provenance |
| **Profile-scoped** | memories | own + untagged-legacy visible; new writes tagged to the active persona | `profile_id` tag (`MemoryStore::buildProfileClause`) |
| **Persona-owned** | **identity** (soul + backstory + context) | single owner; **no cross-persona write path** | implicit (single owner) |

- **Identity is the strictest tier.** Soul, backstory, and context files belong to exactly one persona and are only ever written by that persona (or a maintenance agent acting *as* it). There is no ambient path for one persona to write another's identity — that would erase the boundary that makes a persona itself.
- **No new attribution field for identity.** Because identity is single-owner, provenance is implicit; a generator (e.g. the `context/` producer or `identity-curator`) may note itself *in-content*, but no `created_by`-style column is added. (Contrast: artifacts need `created_by` precisely because they are shared.)
- **`identity-curator` fits without change.** The template role curates persistent memory entries; running for persona X it writes memories tagged X — acting as X, never reaching into another persona. This is consistent with the tier model and requires no code change.

This axis is orthogonal to Effort 1/Effort 2: it is a conceptual model the implementation must honor, already true of the current on-disk layout (identity lives inside the persona directory).

---

## § 3 — Effort 1: Backstory storage cleanup (detailed)

### Core's new contract

Core knows identity only as **markdown files inside the persona directory**. It reads them; it never generates them.

```text
profiles/<name>/            (Effort 1 keeps the "profiles/" name; Effort 2 renames → personas/)
├── soul.md                 # required — core identity, pinned highest
├── backstory.md            # optional — narrative continuity, pinned below soul
├── context/                # optional — supplementary markdown notes (NEW)
│   ├── 01-github.md
│   └── stack.md
├── preferences.json        # optional — behavior/prompt policy (unchanged)
├── roles/                  # optional — role overrides (unchanged)
└── samples/responses/      # optional — fidelity samples (unchanged)
```

Removed from the persona directory's *core* handling: the `backstory/` **source dir** and `.backstory-manifest.json`. These become artifacts of the optional toolkit — the toolkit reads `backstory/` sources and writes `backstory.md`. A persona with no toolkit installed simply has a hand/agent-authored `backstory.md` (or none).

### Prompt composition

Composition order gains one section:

```text
soul → backstory → context → memories → preferences → body → deferred → project
```

- `context/*.md` are discovered and concatenated in **numbered-first natural sort** (the same ordering the backstory pipeline uses today), headings downshifted one level (as backstory is), and rendered as a single pinned block immediately after backstory.
- **Pinning:** context sits in the identity tier — pinned like backstory (survives budget pressure), *below* soul and backstory in priority. It carries the same "keep it lean" budget caveat: context files are loaded verbatim and pinned, so large notes cost prompt budget every turn. This is the user's deliberate tradeoff (a clear picture vs. budget), mirrored on the backstory caveat.
- Both the orchestrator path and the role path load context, exactly as they both load backstory today (`OrchestratorAgent::buildProfileIdentityParts` for the role path; `PromptLoader::buildBackstoryContent` for the orchestrator path).

### Core code changes (Effort 1)

**Keep in core (identity *read* path):**
- `PromptLoader::buildBackstoryContent()` (`PromptLoader.php:251`) — reads `backstory.md`. Add a sibling `buildContextContent()` that discovers + concatenates `context/*.md`, wired into body/section composition (`PromptLoader.php:355` region).
- `OrchestratorAgent` backstory composition + pinning (`OrchestratorAgent.php:944`, `957-959`, `1093-1104`; pinning rationales at `1702`/`1787`). Extend `buildProfileIdentityParts()` to also return context, and add a pinned context prompt section mirroring the backstory one.
- `ConfigHandler` identity read/write (`ConfigHandler.php:528`, `555`, `599`) — writes `backstory.md` as a plain string, no assembler dependency. Stays; optionally extended to read/write `context/*.md` (nice-to-have, not required for Effort 1).
- `preferences.json` `labels.backstory` heading label and `prompt_sections` gating for `backstory` (`PROFILES.md:146,148`). Add an analogous `context` section gate/label if cheap; otherwise reuse identity-tier behavior.

**Move out of core → `coqui-toolkit-backstory` (the *generator*):**
- `src/Backstory/*` — `BackstoryAssembler`, `BackstoryFileDiscovery`, `BackstoryManifest`, `BackstoryInspectionService`, `BackstorySourceInventory`, `BackstoryResult`, `BackstoryFileEntry`, `BackstoryUnsupportedFileEntry`, and `src/Backstory/Extractor/*` (all ~20 extractors incl. `Odp`/`Ods`/`Xlsx`/`Pptx`/`Rtf`/`Sql`/`Xml` and the `ExtractorFactory`/discovery).
- `src/Api/Handler/BackstoryHandler.php` — source-dir CRUD + regen endpoints.
- `src/Repl/Handler/BackstoryHandler.php` + `/backstory` command wiring in `SlashCommandRouter.php` (`:12`, `:64`, `:122`, `:278`, `:310`, `:325`, `:410`). The `/backstory` command **self-registers from the toolkit** via the documented toolkit-extensibility mechanism (`docs/TOOLKIT-EXTENSIBILITY.md`).
- Startup auto-regen hook `RunCommand.php:731-739` (`BackstoryAssembler::needsRegeneration` / `generate`). Becomes a toolkit-provided boot hook or an on-demand command; core no longer auto-regenerates.
- The `/prompt` backstory summary line (`SlashCommandRouter.php:278`) — core drops the file-count/token summary (inspection is gone); it may show a plain "backstory.md present" line, or the toolkit re-adds the rich summary.

**Toolkit package (`coqui-toolkit-backstory`):**
- Absorbs the existing `coqui-toolkit-backstory-formats` mod and its dependencies (`phpoffice/phpword`, `smalot/pdfparser`, `league/html-to-markdown`) plus the extractors that were core-resident (ODS/ODP/XLSX/PPTX/RTF/SQL/XML/CSV/JSON/YAML/code).
- **Output contract:** reads `profiles/<name>/backstory/` sources → writes `profiles/<name>/backstory.md`. Core consumes that file transparently. The toolkit is a *producer*; core is the *consumer*.
- Provides `/backstory`, the API handler, inspection/`/backstory failed`, and (optionally) auto-regen on boot.

### Savings (quantified)

Moving the generator removes ~28 files in `src/Backstory/*` (~130 KB: ~35 KB non-extractor + ~95 KB extractors) plus 2 handlers and the `/backstory` command wiring from core. Keeping lightweight multi-markdown loading adds only a small file-discovery + concat (~2–3 KB). It is **not** all-or-nothing: the "folder of markdown builds the picture" capability (`github.md`, notes) stays in core for near-zero cost; only the document-ingestion ETL leaves.

### Testing (Effort 1)

- Core: a persona with `soul.md` + `backstory.md` + `context/*.md` renders all three in the pinned identity tier, in order, with headings downshifted and numbered-first sort applied to context.
- Core: context section is emitted at the pinned (non-`Volatile`) priority and survives a simulated budget squeeze.
- Core: a persona with no `context/` dir renders soul + backstory only (no error).
- Core: `prompt_sections` gate/stub for `backstory` (and `context` if added) still works.
- Toolkit: with the toolkit installed, `backstory/` sources generate `backstory.md`; core then loads it unchanged.
- Regression: core no longer references `BackstoryAssembler` (`grep` for `Backstory` in `src/` outside the read path returns nothing after the move).
- `composer test` / targeted `./vendor/bin/pest` + `./vendor/bin/phpstan analyse`.

### Docs to fix (Effort 1)

- `docs/PROFILES.md` — remove the entire "Backstory Generator" section (source layout, supported file types, sort order, change detection, auto-regeneration, `/backstory` commands); it moves to the toolkit README. Keep only: "`backstory.md` is an optional markdown file loaded after soul"; add the new `context/` subdir description and its load/pinning behavior.
- `docs/COMMANDS.md` — `/backstory` moves to the toolkit's self-registered command list.
- `docs/API.md` — backstory source-management endpoints move to the toolkit.
- `config/source.json` — drop the `src/Backstory/*` entries from core; note the relocation.
- The new toolkit ships its own README covering the generator, formats, and commands.

---

## § 4 — Effort 2: `profile → persona` rename (outline only)

Not designed in detail here; captured so the target vocabulary (§ 1) has a landing path.

- **Rename `profile → persona`** across code identifiers, the `/profile` REPL command (→ `/persona`, with `/profiles` → `/personas`), the API `profile` field (→ `persona`), and `openclaw.json` `agents.defaults.profile` (→ `persona`).
- **Directory:** `profiles/` → `personas/`.
- **No migration code (explicit non-goal).** Clean break: users rename their own workspace folder. No dual-read/back-compat shim, no automatic move. Documented as a breaking change in release notes.
- `soul` / `backstory` / `context` terms and files are unchanged by Effort 2.
- Memory attribution column `profile_id` may keep its name (internal) or rename to `persona_id` — an implementation call for that brief; not required for correctness.

---

## Rename / migration map (summary)

| From (today) | To (target) | Effort | Migration |
| --- | --- | --- | --- |
| `persona` (prose synonym) | `persona` (first-class container term) | 2 | none — prose aligns once container is renamed |
| `profile` (container) | `persona` | 2 | users rename `profiles/` → `personas/`; no code migration |
| `soul` | `soul` (unchanged) | — | none |
| `backstory` (generated) | `backstory` (hand/agent-authored or toolkit-generated) | 1 | generator relocates to toolkit; `backstory.md` file format unchanged |
| — | `context/*.md` (new) | 1 | new optional subdir; additive |
| `src/Backstory/*`, both `BackstoryHandler`s, `/backstory` | `coqui-toolkit-backstory` | 1 | code relocation; core keeps read path only |
| `coqui-toolkit-backstory-formats` (mod) | absorbed into `coqui-toolkit-backstory` | 1 | mod consolidation |

## Less-is-more cut list

- **Cut from core → toolkit:** ~20 extractors, `BackstoryAssembler`, `BackstoryManifest`, `BackstoryInspectionService`, `BackstorySourceInventory`, `BackstoryFileDiscovery`, `BackstoryResult`/entry value objects, both `BackstoryHandler`s, `/backstory` command wiring, startup auto-regen hook (~30 files / ~130 KB).
- **Cut concept from core:** the `backstory/` source-dir + `.backstory-manifest.json` as core-managed artifacts (toolkit-owned now).
- **Add to core (minimal):** `context/*.md` discovery + concat (~2–3 KB) and one pinned prompt section.
- **No new systems, no new dependencies in core, no new attribution columns.**

## Out of scope

- No code changes in this spec (design only).
- No redesign of artifacts or memories — their decisions (shared + `created_by`; profile-scoped) are fixed inputs this model aligns to.
- Effort 2's detailed rename plan is a later brief.
