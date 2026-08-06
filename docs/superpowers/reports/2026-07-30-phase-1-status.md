# Phase 1 Status Report — `profile → persona` identity rename

**Phase:** 1 of 7 (CAP 0.5.0 conformance migration)
**Branch:** `feat/cap-0.5-conformance` (worktree `coqui-cap-migration`, unpushed)
**Range:** `8c9600d..e93afa8` (9 commits: 6 `refactor(persona)`, 3 `docs(cap)` scoping), 138 files
**Status:** ✅ COMPLETE — whole-branch review "Ready to merge = Yes"; no Critical/Important findings.

## Goal (met)

Rename coqui's authored-identity concept from **profile** to **persona** — the identity sense ONLY — across storage, config/discovery, HTTP API, REPL, agent/memory, examples, and docs, leaving the build and full suite green after every task, with **zero identity-sense `profile` remaining** and **every collision sense untouched**. This was a term-rename, not a schema reshape; persona persistence and exact CAP wire-shape are deferred to later phases by design.

## Acceptance evidence

- **Grep-gate CLEAN:** the scoped sweep (`git grep -in profile … ':!docs/superpowers' ':!tests/conformance/spec' | grep -viE '<collision filter>'`) returns only genuine collisions (xdebug `build/profiles`, `USERPROFILE`, shell-dotfile blacklist patterns `~/.profile`/`.bash_profile`/`.zprofile`, tool-profile preset prose). No authored-identity `profile` remains.
- **Collisions survive:** `ToolProfileResolver`, `test:profile`, `TOOL_PROFILE_LEAN`, `PerformanceTest`, `LeanDefaultProfilePrecedenceTest` all intact.
- **Green throughout:** `composer test` = 2551 passed / 6677 assertions (0 fail); `composer analyse` clean (389 files). Green after every task — never committed red.
- **Safety preserved:** `src/Config/CatastrophicBlacklist.php` and its test are byte-unchanged across all 6 commits (`git log 8c9600d..e93afa8 -- CatastrophicBlacklist.php` empty).

## Tasks (each: fresh Opus 4.8 implementer + Opus 4.8 spec+quality reviewer; review clean)

| # | Scope | Commit(s) |
|---|---|---|
| 1 | Identity classes: `PersonaDiscovery`/`PersonaParser`/`PersonaPreferences`/`PersonaSessionLifecycleManager` + 4 test files | `cf42f83..0b1f511` |
| 2 | DB columns/indexes (`persona_id`/`persona_hash`) + memory threading (`buildPersonaClause`, `MemoryEntry->personaId`, `updateSessionPersona`) + 11-file DB-row cascade | `0b1f511..f00531f` |
| 3 | HTTP API: 7 `/personas` routes, 8 `ConfigHandler` methods, response keys, `PERSONA_SESSION_ACTIVE`; session field held at `persona_id` per CAP `session.json` | `f00531f..5e2048a` |
| 4 | REPL: `PersonaHandler`, `/persona[s]`, catalog/router/completion | `5e2048a..555d15e` |
| 5 | Runtime dir string `/personas`, config key `agents.defaults.persona`, `examples/personas/`, `docs/PERSONAS.md` | `7832edb..34ed276` |
| 6 | Residual-identifier sweep (accessors/locals/wire-tokens/prose/fixtures), 117 files 1990/1990 pure substitution, final grep-gate | `34ed276..e93afa8` |

Plan-structure commits `c80d4b0` (split Task 5→5+6) and `7832edb` (scope the grep-gate to exclude SDD process docs + vendored spec fixtures) are the two mid-phase corrections.

## Cross-task coherence (whole-branch review confirmed)

- **End-to-end naming:** `sessions.persona_id`/`memory_summary.persona_hash` (DDL) ↔ `:persona_id` binds (storage) ↔ `MemoryEntry->personaId` ↔ HTTP `persona_id`; catalog uses `personas`/`persona`.
- **Wire-token split (uniform):** session-owner references → `persona_id` (CAP `session.json`); non-session "which persona" filters + schedule metadata → bare `persona`.
- **No dual naming / no shim / no alias.** No-legacy rule honored (rename in place; `migrateAddColumn` accretion renamed, not a new ALTER-chain — Phase 2 owns the `createTables` recreate).
- **Value-preserving:** every renamed column/field already stored the persona slug; `MemorySummarizer` keeps `crc32($personaId)` cache semantics.

## Deferred by design (NOT defects) → carry-forward

**Phase 2 (storage reshape):** add `personas` index table + `id`/`version`/timestamps and PRODUCE a schema-valid Persona (`persona.json`); rewrite `createTables` recreate-from-empty + stamp `meta.schema_version = 0.5.0`; add CAP `session.json` fields (`members[]`/`kind`/`status`/`pinned`/`version`/`model`/`workspace`). Watch the `Helper::toJSON` empty-assoc→`[]` gotcha when validating producer output.

**Phase 4 (API surface / wire shape):** exact CAP wire-shape conformance; tidy the deliberate DELETE-member path-segment `{persona}` vs add-member body `persona_id` asymmetry; CORE-36 lenient-bucket wire-tolerance mode; query-param naming.

**Informational:** `repl_unprofiled_` → `repl_unpersona_` label morphology (no test, defensible).

## Next

Phase 2 (storage reshape to 0.5.0). No plan yet — write via `superpowers:writing-plans`, then execute via `superpowers:subagent-driven-development`. Per user cadence, `/compact` before starting Phase 2.
