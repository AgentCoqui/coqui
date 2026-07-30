# Phase 1 — `profile → persona` identity rename Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rename coqui's authored-identity concept from "profile" to "persona" comprehensively across storage, config/discovery, API, REPL, agent/memory, examples, and docs — **the identity sense only** — leaving the build and full test suite green after every task, with zero identity-sense "profile" remaining and every collision sense untouched.

**Architecture:** Pure mechanical rename, no behavior change and no new persistence. A "profile" is a file-backed directory addressed by its lowercased slug; that stays true, just renamed to "persona". Every persisted reference already stores the slug, so `profile_id → persona_id` (and siblings) are **value-preserving** column renames. The persisted `personas` index table + `id`/`version`/timestamps + producing a schema-valid Persona object are **out of scope here** — they land in Phase 2 (storage reshape).

**Tech Stack:** PHP 8.4, Pest, PHPStan, SQLite (per-store DDL, no migration framework).

## Global Constraints

- Work ONLY in the worktree `/home/carmelo/Projects/CoquiBot/Core/coqui-cap-migration` (branch `feat/cap-0.5-conformance`). Never mutate the primary checkout `/home/carmelo/Projects/CoquiBot/Core/coqui` or the read-only spec repo `/home/carmelo/Projects/CoquiBot/Core/coqui-agent-spec`.
- PHP 8.4; `declare(strict_types=1);`; `final` by default; one class per file; 4-space indentation.
- No-legacy rule: **rename in place, no `profile`→`persona` compatibility aliases**, no ALTER-chains. The store is recreate-from-empty (no meta/version stamp exists yet; Phase 2 adds it). Tests build fresh DBs, so DDL string renames are safe; a developer with a stale local dev DB recreates it.
- `composer test` (Pest) and `composer analyse` (PHPStan `--memory-limit=512M`) MUST be green at the end of **every** task. Never commit red. A rename that leaves a dangling reference breaks the build — each task renames a symbol **and all its references together** so the tree stays green.
- Commit after each task with the message shown in its final step.

### RENAME ONLY the identity sense. NEVER touch these collision senses (verbatim exclusion list):

1. **Tool-profile** (the "lean"/"full" eager-toolkit preset — a different concept): `ToolProfileResolver`, its method `profile()`/`isFull()`, constants `TOOL_PROFILE_DEFAULT`/`TOOL_PROFILE_LEAN`/`TOOL_PROFILE_FULL` (`src/Contract/CoquiDefaults.php`), config key `agents.defaults.toolProfile` (`config/defaults.json`), and their uses in `BootManager`/`ToolkitLoadingRegistry`/`OrchestratorAgent`. Tests: `tests/Unit/Agent/LeanDefault*`, `tests/Unit/Config/ToolProfileResolverTest.php`. **`LeanDefaultProfilePrecedenceTest.php` has "Profile" in its name but is tool-profile — DO NOT rename it.**
2. **Performance/test profiling**: composer script `test:profile`, `scripts/test-profile.php`, env vars `COQUI_TEST_PROFILE_MEMORY_LIMIT`/`_OUTPUT_DIR`/`_OUTPUT_NAME`/`_INCLUDE_PERFORMANCE`/`_ACTIVE`, `tests/Unit/PerformanceTest.php`.
3. **InstanceInfo capability `profiles[]`** (the optional capability sets `remote`/`skills`/`artifacts`/`questions`/`mcp`/`schedules`): does not exist in coqui yet — when Phase 4 adds `InstanceInfo`, it uses the spec term "profiles" for capabilities. Nothing to rename here; just never conflate it with identity.

A blind global `s/profile/persona/` corrupts all of the above. Rename symbol-by-symbol, checking each hit's sense.

### Identity-sense rename map (authoritative — from verified source inventory)

**Classes / files (rename class + file + all references + test files):**
| From | To | File |
|---|---|---|
| `ProfileDiscovery` | `PersonaDiscovery` | `src/Config/ProfileDiscovery.php` |
| `ProfileParser` | `PersonaParser` | `src/Config/ProfileParser.php` |
| `ProfilePreferences` | `PersonaPreferences` | `src/Config/ProfilePreferences.php` |
| `ProfileSessionLifecycleManager` | `PersonaSessionLifecycleManager` | `src/Support/ProfileSessionLifecycleManager.php` |
| `ProfileHandler` (REPL) | `PersonaHandler` | `src/Repl/Handler/ProfileHandler.php` |
| `PersonaContextReader` | *(already persona — leave)* | `src/Prompt/PersonaContextReader.php` |

Test files: `tests/Unit/Config/ProfileDiscoveryTest.php`, `ProfilePreferencesTest.php`, `ProfilePreferencesContextGateTest.php`, `ProfilePreferencesContextLabelTest.php`, `ProfileExampleFixturesTest.php`, `tests/Unit/Repl/Handler/ProfileHandlerTest.php`, `tests/Integration/ProfileSwitchingTest.php` → `Persona*`.

**DB columns + indexes (DDL + every query, value-preserving — all hold the slug today):**
| From | To |
|---|---|
| `sessions.profile` | `sessions.persona_id` |
| index `idx_sessions_profile_updated` | `idx_sessions_persona_updated` |
| `session_group_members.profile_name` | `session_group_members.persona_id` |
| index `idx_session_group_members_profile` | `idx_session_group_members_persona` |
| `memories.profile_id` | `memories.persona_id` |
| index `idx_memories_profile` | `idx_memories_persona` |
| `memory_summary.profile_hash` | `memory_summary.persona_hash` |

**Config key:** `agents.defaults.profile` → `agents.defaults.persona` (`OpenClawConfig::getDefaultProfile()` → `getDefaultPersona()`; `SetupWizard`; `RunCommand` startup check; REPL set/clear).

**API:** routes `/profiles*` → `/personas*`, `/config/profiles*` → `/config/personas*`, `/profile-preferences/schema` → `/persona-preferences/schema`, path param `/sessions/{id}/members/{profile}` → `{persona}`. `ConfigHandler` methods `profiles`/`profile`/`createProfile`/`updateProfile`/`deleteProfile`/`normalizeProfileSummary`/`normalizeProfileDetail` → `personas`/`persona`/`createPersona`/`updatePersona`/`deletePersona`/`normalizePersonaSummary`/`normalizePersonaDetail`. Response keys `profiles`→`personas`, `default_profile`→`default_persona`, `profile`→`persona`, `is_default` (keep). Error code `profile_session_active` → `persona_session_active` (`src/Api/ApiErrorCode.php`; `InteractiveSessionService::profileSessionActiveConflict` → `personaSessionActiveConflict`; the `confirm_close_active_profile_session` field → `confirm_close_active_persona_session`).

**REPL:** `/profile` → `/persona`, `/profiles` → `/personas` (`ReplCommandCatalog`, `SlashCommandRouter`, `TabCompletion::completeProfile` → `completePersona`).

**Agent/Memory:** `AgentRunner` `$profile`/`activeProfile`/`profileId:` locals/params → persona; `MemoryStore::buildProfileClause` → `buildPersonaClause`; `MemoryEntry->profileId` → `personaId`; `MemorySummarizer` `$profileHash` (keeps `crc32` semantics on the persona slug).

**Filesystem dir:** workspace runtime dir `profiles/` → `personas/` (`ProfileDiscovery::directory()` + the 3 path builders in `src/Agent/AgentRunner.php`). Repo fixtures `examples/profiles/` → `examples/personas/`.

**Docs:** `docs/PROFILES.md` → `docs/PERSONAS.md` (H1 + frontmatter title `Personas`); update references in `AGENTS.md`, `README.md`, `docs/CONFIGURATION.md`. (Note: `config/documentation.json` is generated/untracked — do not edit; `composer regen-docs` refreshes it.)

---

## File Structure

Renames span these areas; each task below owns a coherent, independently-green slice:
- `src/Config/` — Persona{Discovery,Parser,Preferences}, OpenClawConfig, SetupWizard
- `src/Storage/`, `src/Memory/` — DDL, queries, MemoryEntry, MemorySummarizer
- `src/Api/` — routes, ConfigHandler, ApiErrorCode, InteractiveSessionService
- `src/Repl/` — PersonaHandler, catalog, router, completion
- `src/Support/`, `src/Agent/`, `src/Prompt/`, `src/Command/` — lifecycle manager, threading, path builders
- `examples/`, `docs/`, `AGENTS.md`, `README.md`

---

### Task 1: Identity classes (Discovery / Parser / Preferences / SessionLifecycle) + all references

**Files:**
- Rename: `src/Config/ProfileDiscovery.php` → `PersonaDiscovery.php`, `src/Config/ProfileParser.php` → `PersonaParser.php`, `src/Config/ProfilePreferences.php` → `PersonaPreferences.php`, `src/Support/ProfileSessionLifecycleManager.php` → `PersonaSessionLifecycleManager.php`
- Modify: every referencing file (`git grep -l` the class names) — expected: `BootManager`, `OrchestratorAgent`, `AgentRunner`, `ConfigHandler`, `RunCommand`, `SetupWizard`, `SlashCommandRouter`/`ProfileHandler`, and others the grep surfaces
- Test: rename `tests/Unit/Config/ProfileDiscoveryTest.php`, `ProfilePreferencesTest.php`, `ProfilePreferencesContextGateTest.php`, `ProfilePreferencesContextLabelTest.php` → `Persona*Test.php` (and the class names inside)

**Interfaces:**
- Produces: classes `PersonaDiscovery`, `PersonaParser`, `PersonaPreferences`, `PersonaSessionLifecycleManager`. Public method names on these classes keep their non-"profile" names; any method literally named with "profile" in the identity sense is renamed (e.g. none expected on Parser; check `PersonaPreferences` for `profile`-named methods and rename identity ones). Later tasks reference these class names.

- [ ] **Step 1: Inventory the references (RED baseline is the failing build after a partial rename — so do it atomically)**

Run to see the blast radius (do not act on collision hits):

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui-cap-migration
git grep -n -w -e ProfileDiscovery -e ProfileParser -e ProfilePreferences -e ProfileSessionLifecycleManager -- src tests
```

Note every file. **Do NOT match `ToolProfileResolver`** (different word — the `-w` word-boundary + explicit names above already exclude it; verify no `ToolProfile*` appears in your list).

- [ ] **Step 2: Rename the four classes + files + all references atomically**

Rename each file (`git mv`), change the `class` declaration, and update EVERY reference found in Step 1 (`use` statements, `new`, type hints, constructor wiring in `BootManager`, docblocks). Also rename the identity-sense method names inside these classes if any contain "profile" (e.g. a `PersonaPreferences` accessor); keep tool-profile and unrelated names intact.

- [ ] **Step 3: Rename the four unit test files + their class/references**

`git mv` the four `tests/Unit/Config/Profile*Test.php` files to `Persona*Test.php`, rename the test classes, and update any references to the renamed production classes.

- [ ] **Step 4: Build + full suite must be green**

Run:

```bash
composer dump-autoload && composer test
```

Expected: PASS, same test count as before (minus none — tests were renamed, not removed). If red, a reference was missed — fix it (the failure names the file). This is the real gate for an atomic rename: green means no dangling reference.

- [ ] **Step 5: Static analysis**

```bash
composer analyse
```

Expected: no new errors.

- [ ] **Step 6: Verify the identity classes are gone and collisions intact**

```bash
! git grep -n -w -e ProfileDiscovery -e ProfileParser -e ProfilePreferences -e ProfileSessionLifecycleManager -- src tests
git grep -n -w ToolProfileResolver -- src | head    # MUST still exist (untouched)
```

Expected: first command prints nothing and exits 0 (no identity class names remain); second still shows `ToolProfileResolver` (proves collision untouched).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(persona): rename identity discovery/parser/preferences/lifecycle classes"
```

---

### Task 2: Storage + memory DB columns, indexes, and agent threading

**Files:**
- Modify: `src/Storage/SessionStorage.php` (DDL for `sessions.profile`→`persona_id`, `session_group_members.profile_name`→`persona_id`, their indexes, and every query/method: `createSession`/`updateSessionProfile`/`normalizeGroupMembers`/`persistGroupMembers` and callers)
- Modify: `src/Memory/MemoryStore.php` (`memories.profile_id`→`persona_id`, index; `buildProfileClause`→`buildPersonaClause`), `src/Memory/MemorySummarizer.php` (`profile_hash`→`persona_hash`, `$profileHash` local), `src/Memory/MemoryEntry.php` (`profileId`→`personaId`)
- Modify: `src/Agent/AgentRunner.php` (the `$profile`/`activeProfile`/`profileId:` threading into memory writes — identity sense)
- Test: the storage/memory tests that reference these columns/methods (grep surfaces them)

**Interfaces:**
- Consumes: `PersonaDiscovery` etc. from Task 1.
- Produces: `sessions.persona_id`, `session_group_members.persona_id`, `memories.persona_id`, `memory_summary.persona_hash`; `MemoryEntry->personaId`; `MemoryStore::buildPersonaClause`. The stored VALUE remains the persona slug (unchanged semantics). Later API/REPL tasks consume `updateSessionProfile`'s renamed form and the `MemoryEntry` field.

- [ ] **Step 1: Inventory column + method references**

```bash
git grep -n -e "profile_name" -e "profile_id" -e "profile_hash" -e "'profile'" -e '"profile"' -e buildProfileClause -e profileId -e idx_sessions_profile -e idx_memories_profile -e idx_session_group_members_profile -- src tests
```

Classify each hit: DB-column/identity (rename) vs any tool-profile/test-profile (leave — none expected in storage/memory).

- [ ] **Step 2: Rename DDL + indexes + queries + PHP identifiers atomically**

Apply the DB column/index renames from the map to the `CREATE TABLE`/`CREATE INDEX` strings and EVERY SQL statement referencing them, plus the PHP method/property renames (`buildProfileClause`→`buildPersonaClause`, `MemoryEntry->profileId`→`personaId`, `$profileHash`). Keep `crc32(...)` on the persona slug in `MemorySummarizer` (semantics unchanged). Keep the `sessions` OWNER column rename to `persona_id` (Phase 2 adds `members[]`/`kind`/`version`; do not add them here).

- [ ] **Step 3: Update the storage/memory tests**

Rename any column/method references in the affected tests (grep from Step 1). Do not weaken assertions.

- [ ] **Step 4: Build + full suite green**

```bash
composer test
```

Expected: PASS. A missed query reference will fail a storage/memory test — fix and re-run.

- [ ] **Step 5: Static analysis**

```bash
composer analyse
```

Expected: no new errors.

- [ ] **Step 6: Verify columns renamed, collisions intact**

```bash
! git grep -n -e "profile_name" -e "profile_id" -e "profile_hash" -e buildProfileClause -e "idx_.*_profile" -- src
git grep -n -w toolProfile -- src config | head   # tool-profile config key MUST remain
```

Expected: first prints nothing (exit 0); second still shows `toolProfile`.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(persona): rename profile DB columns/indexes + memory threading to persona"
```

---

### Task 3: API surface — routes, ConfigHandler, error code, session-member param

**Files:**
- Modify: `src/Command/ApiCommand.php` (route registrations `/profiles*`, `/config/profiles*`, `/profile-preferences/schema`, `/sessions/{id}/members/{profile}`)
- Modify: `src/Api/Handler/ConfigHandler.php` (methods + response keys per the map), `src/Api/Handler/SessionHandler.php` (the `{profile}` member param → `{persona}`)
- Modify: `src/Api/ApiErrorCode.php` (`profile_session_active`→`persona_session_active`), `src/Support/InteractiveSessionService.php` (`profileSessionActiveConflict`→`personaSessionActiveConflict`, field `confirm_close_active_profile_session`→`confirm_close_active_persona_session`)
- Test: API handler tests referencing these routes/keys/error code

**Interfaces:**
- Consumes: Task-1 classes, Task-2 `updateSessionProfile`/storage.
- Produces: routes `/api/v1/personas*` + `/api/v1/config/personas*`; response keys `personas`/`default_persona`/`persona`; error code `persona_session_active`. The Flutter app (Phase 6) consumes these renamed routes/keys — this is the wire-boundary change.

- [ ] **Step 1: Inventory API references**

```bash
git grep -n -e "/profiles" -e "/profile-preferences" -e "'profile'" -e '"profile"' -e profile_session_active -e "{profile}" -e normalizeProfileDetail -e normalizeProfileSummary -e createProfile -e updateProfile -e deleteProfile -- src/Api src/Command src/Support tests
```

- [ ] **Step 2: Rename routes, handler methods, response keys, error code atomically**

Apply the API section of the rename map. Rename route path strings, the `ConfigHandler` method names + the `$router->...('...', [$h, 'method'])` wirings, the response array keys (`profiles`→`personas`, `default_profile`→`default_persona`, `profile`→`persona`), the `{profile}` member path param → `{persona}` (route + handler signature), the `ApiErrorCode` case, and `InteractiveSessionService` method + confirm field. `GET /profile-preferences/schema` → `GET /persona-preferences/schema` (route + `ConfigHandler` method).

- [ ] **Step 3: Update API tests**

Update handler tests to hit `/personas*`, assert `personas`/`persona` keys, and expect `persona_session_active`. Keep assertions strong.

- [ ] **Step 4: Build + full suite green**

```bash
composer test
```

Expected: PASS.

- [ ] **Step 5: Static analysis + route-shape sanity**

```bash
composer analyse
git grep -n "/personas" src/Command/ApiCommand.php   # confirm renamed routes registered
```

Expected: analyse clean; the routes appear as `/personas`.

- [ ] **Step 6: Verify identity API refs gone, collisions intact**

```bash
! git grep -n -e "/profiles" -e "/profile-preferences" -e profile_session_active -e normalizeProfileDetail -e createProfile -- src/Api src/Command
```

Expected: prints nothing (exit 0).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(persona): rename /profiles API surface + error code to persona"
```

---

### Task 4: REPL surface — PersonaHandler, commands, catalog, completion

**Files:**
- Rename: `src/Repl/Handler/ProfileHandler.php` → `PersonaHandler.php` (class + methods `handleProfile`→`handlePersona`, `handleProfiles`→`handlePersonas`)
- Modify: `src/Repl/ReplCommandCatalog.php` (specs for `/profile`+`/profiles` → `/persona`+`/personas`), `src/Repl/SlashCommandRouter.php` (injection + dispatch + delegation), `src/Repl/TabCompletion.php` (`completeProfile`→`completePersona` + command/arg completions)
- Test: rename `tests/Unit/Repl/Handler/ProfileHandlerTest.php` → `PersonaHandlerTest.php`; update REPL router/completion tests

**Interfaces:**
- Consumes: Task-1/2/3 renames.
- Produces: REPL commands `/persona` (subcommands `default`/`reset`/`none` unchanged) and `/personas`; class `PersonaHandler`.

- [ ] **Step 1: Inventory REPL references**

```bash
git grep -n -e ProfileHandler -e "/profile" -e "/profiles" -e handleProfile -e completeProfile -- src/Repl tests/Unit/Repl tests/Integration
```

- [ ] **Step 2: Rename the handler + commands + wiring atomically**

`git mv` the handler, rename class + methods, update the catalog command specs (`/persona`, `/personas`, keep firstArguments `default,reset,none`), the router injection/dispatch/delegation, and `TabCompletion` (method + the `/persona` arg + `default` sub-args completions).

- [ ] **Step 3: Rename + update the REPL test(s)**

`git mv` `ProfileHandlerTest.php` → `PersonaHandlerTest.php`, rename the class, update command strings to `/persona[s]`; update router/completion tests.

- [ ] **Step 4: Build + full suite green**

```bash
composer test
```

Expected: PASS.

- [ ] **Step 5: Static analysis**

```bash
composer analyse
```

Expected: no new errors.

- [ ] **Step 6: Verify REPL identity refs gone**

```bash
! git grep -n -e ProfileHandler -e handleProfile -e completeProfile -e "'/profile'" -e "'/profiles'" -- src/Repl
```

Expected: prints nothing (exit 0).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(persona): rename /profile REPL commands + handler to persona"
```

---

### Task 5: Config dir + config key + examples + docs

**Files:**
- Modify: `src/Config/PersonaDiscovery.php` (`directory()` dir string `profiles`→`personas`), `src/Agent/AgentRunner.php` (the 3 workspace path builders `.../profiles/...`→`.../personas/...`), `src/Config/OpenClawConfig.php` (`getDefaultProfile`→`getDefaultPersona`, key `agents.defaults.profile`→`agents.defaults.persona`), `src/Config/SetupWizard.php`, `src/Command/RunCommand.php` (startup default check)
- Rename: `examples/profiles/` → `examples/personas/` (`git mv` the dir incl. `deliberate-operator/`); update `tests/Unit/Config/PersonaExampleFixturesTest.php` (renamed in Task 1) path + any fixture-path references
- Rename: `docs/PROFILES.md` → `docs/PERSONAS.md` (H1 `# Personas`, frontmatter `title: Personas`, `description` updated); update refs in `AGENTS.md`, `README.md`, `docs/CONFIGURATION.md`
- Test: fixture test + any config test asserting the default key

**Interfaces:**
- Consumes: all prior tasks.
- Produces: runtime persona dir `personas/`; config key `agents.defaults.persona`; docs at `docs/PERSONAS.md`.

- [ ] **Step 1: Inventory remaining identity refs**

```bash
git grep -n -e "profiles/" -e "agents.defaults.profile" -e getDefaultProfile -e "examples/profiles" -e PROFILES.md -- src tests examples docs AGENTS.md README.md
```

Classify: rename identity hits; leave any `toolProfile`/`test:profile`.

- [ ] **Step 2: Rename dir strings, config key, examples, docs atomically**

Change the `profiles`→`personas` dir strings in `PersonaDiscovery::directory()` and the `AgentRunner` path builders; rename `getDefaultProfile`→`getDefaultPersona` + the config key literal `agents.defaults.profile`→`agents.defaults.persona` in `OpenClawConfig`/`SetupWizard`/`RunCommand`/REPL; `git mv examples/profiles examples/personas`; `git mv docs/PROFILES.md docs/PERSONAS.md` and fix its H1/frontmatter; update the doc references in `AGENTS.md`/`README.md`/`docs/CONFIGURATION.md`.

- [ ] **Step 3: Update fixture + config tests**

Point `PersonaExampleFixturesTest` at `examples/personas/`; update any config test asserting `agents.defaults.profile`.

- [ ] **Step 4: Build + full suite green**

```bash
composer test
```

Expected: PASS.

- [ ] **Step 5: Static analysis + regenerate docs index**

```bash
composer analyse
composer regen-docs   # refreshes the generated (untracked) config/documentation.json
```

Expected: analyse clean; regen succeeds (do not commit `config/documentation.json` — it is gitignored/untracked).

- [ ] **Step 6: Verify Task-5 renames landed (narrow; the broad phase gate is Task 6)**

```bash
! git grep -n -e "agents.defaults.profile" -e getDefaultProfile -e "examples/profiles" -e "PROFILES.md" -- src tests examples docs AGENTS.md README.md
git grep -n "'personas'" src/Config/PersonaDiscovery.php   # dir string renamed
```

Expected: first prints nothing (exit 0); second shows the renamed dir string. The broad case-insensitive identity sweep is deferred to Task 6 (many residual internal identifiers remain at this point — expected).

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(persona): rename persona dir, default config key, examples, docs"
```

---

### Task 6: Residual identity-identifier sweep + FINAL grep-gate (phase acceptance)

**Why this is its own task:** Tasks 1–5 renamed each layer's *primary* surface (classes, columns, routes, commands, dir, config key, docs) but deliberately left a long tail of identity-sense internal identifiers, wire tokens, private helpers, prose, and test fixtures, each requiring per-hit sense classification. This task sweeps that tail to zero and runs the broad grep-gate that is the phase's real acceptance check. It is a large but purely mechanical rename, gated by a green suite and a CLEAN sweep.

**Files:** wherever the Step-1 sweep surfaces identity-sense `profile`. Known residuals carried forward from prior task reviews:
- Consumed accessor/method names: `BootManager`/`CoreServices` `profileDiscovery()`; `PersonaDiscovery` `profileExists()`/`availableProfiles()`/`readProfileModel()`/`getProfilePath()`/`profilesDir()`/`fromProfilePath()`/`normalizeProfile()`; `SessionHandler`/lifecycle `loadOrCreateProfileSession()`, `finalizeOtherActiveInteractiveSessionsForProfile()`, `enforceProfileRolePolicy()`; `RouteResult::stateChange(newActiveProfile:)`.
- Agent/memory locals: `AgentRunner` `$profile`/`$activeProfile`/`profileId:` named-args + the `$profileId` params on `MemoryExtractor`/`ConversationSummarizer`; `MemoryStore` `$profileMigrations` local + `// Migrate: add profile…` comment.
- Wire tokens deferred by Task 3 (rename the TERM now; Phase 4 conforms SHAPE later): session LIST `?profile=` filter/echo (`SessionHandler`); read-endpoint `?profile=` (`RoleHandler`, `TaskHandler`); scheduled-task metadata `'profile'` key (`ScheduleManager`, `ScheduleToolkit`); `personaSessionActiveConflict` detail key `'profile' => …`; group-member body/response key `'profile'` (+ `GroupSessionTypeHandler::addMember`); non-wire props `SessionScope::$profile`, `confirmCloseActiveProfileSession`.
- Private REPL helpers + prose: `showCurrentProfile`/`resetProfile`/`handleDefaultProfile`, "Available profiles:", "Default profile set…", "Active profile:".
- Test fixtures: `createProfileHandlerFixture`, `testBootManagerForProfiles`, `workspace/profiles/…` fixture dirs, any remaining `Profile*` fixture helpers.

**Naming rules (apply consistently):**
- Default term is `persona` (method/local/prop/key/prose/fixture: `profileDiscovery`→`personaDiscovery`, `$activeProfile`→`$activePersona`, `showCurrentProfile`→`showCurrentPersona`, etc.).
- Where the token denotes the SESSION's owning persona id (matches CAP `session.json` `persona_id` already used by the session object): use `persona_id`. Specifically the session-scoped `?profile=` filter/echo and any request/response key naming the session owner → `persona_id`. Generic "which persona" filters on non-session resources (role/task listing) → `persona`.
- Directory-path segment strings already handled in Task 5 — do not re-touch.

- [ ] **Step 1: Full identity sweep inventory**

The gate scope EXCLUDES two trees that legitimately contain "profile" and MUST NOT be edited: `docs/superpowers/` (the SDD process docs — this very plan/spec describe the profile→persona rename) and `tests/conformance/spec/` (the hermetic vendored CAP spec snapshot, which carries the capability `profiles[]` sense verbatim). Those exclusions are pathspecs, not a licence to leave product hits.

```bash
git grep -in profile -- src tests config examples docs AGENTS.md README.md \
  ':!docs/superpowers' ':!tests/conformance/spec' \
  | grep -viE 'toolprofile|tool_profile|tool-profile|TOOL_PROFILE|test:profile|test-profile\.php|COQUI_TEST_PROFILE|PerformanceTest|LeanDefault'
```

Classify EVERY line: identity-sense (rename per the naming rules) vs a NEW collision/capability hit you must justify. There should be no tool-profile/test-profile hits after the exclusion filter.

- [ ] **Step 2: Rename all identity-sense residuals atomically**

Rename each identifier together with all its references (definition + call sites + named-args + docblocks). Keep the change purely mechanical — no logic/behavior change, no structural body reshaping (Phase 4 owns wire shape). Renaming a method requires updating every caller in the same commit so the tree stays green.

- [ ] **Step 3: Update affected tests + fixtures**

Rename fixture helper names and `workspace/profiles/` fixture dirs; do not weaken assertions.

- [ ] **Step 4: Build + full suite green**

```bash
composer test
```

Expected: PASS (same count; nothing removed).

- [ ] **Step 5: Static analysis**

```bash
composer analyse
```

Expected: no new errors.

- [ ] **Step 6: FINAL identity grep-gate (the phase's acceptance check)**

```bash
git grep -in profile -- src tests config examples docs AGENTS.md README.md \
  ':!docs/superpowers' ':!tests/conformance/spec' \
  | grep -viE 'toolprofile|tool_profile|tool-profile|TOOL_PROFILE|test:profile|test-profile\.php|COQUI_TEST_PROFILE|PerformanceTest|LeanDefault' \
  || echo "CLEAN: no identity-sense profile references remain"
```

Expected: `CLEAN: …`, OR a SHORT list every entry of which you justify in the report as a genuine collision/capability sense (not identity). Then confirm collisions survive:

```bash
git grep -n -e ToolProfileResolver -e "test:profile" -e TOOL_PROFILE_LEAN | head
```

Expected: all three still present.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "refactor(persona): sweep residual identity identifiers; close profile->persona rename"
```

---

## Self-Review

**Spec coverage (design §0 / §6 Phase 1):** identity classes (T1), DB columns + memory threading (T2), API routes/keys/error code (T3), REPL commands (T4), config dir + default key + examples + docs (T5), residual identity-identifier sweep + final grep-gate acceptance (T6). Persona persistence (index table, id/version/timestamps, schema-valid Persona production) is explicitly deferred to Phase 2 — noted in the design doc and this plan's Architecture.

**Placeholder scan:** no TBD/TODO. Each task gives the exact rename map, the exact inventory/verify grep commands, and the green gate. The "code" of a mechanical rename is the mapping + verification, which are all concrete.

**Type/name consistency:** the rename map is the single source; class names (`PersonaDiscovery`/`PersonaParser`/`PersonaPreferences`/`PersonaSessionLifecycleManager`/`PersonaHandler`), columns (`persona_id`/`persona_hash`), config key (`agents.defaults.persona`), routes (`/personas`), and error code (`persona_session_active`) are used identically across tasks. Method renames (`buildPersonaClause`, `getDefaultPersona`, `handlePersona`, `personaSessionActiveConflict`, `normalizePersonaDetail`) are introduced and consumed consistently.

**Collision safety:** the exclusion list is in Global Constraints and re-verified by a "collisions still present" grep in every task's verify step — the net that catches an over-broad rename.

**Cross-task greenness:** every task renames a symbol together with all its references and gates on a full green `composer test`, so the tree never commits red despite the rename spanning many files.
