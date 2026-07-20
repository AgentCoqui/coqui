# Audit Log: Write-Time Redaction + Read Access — Design

**Date:** 2026-07-15 (amended 2026-07-20)
**Status:** Design approved (forks decided via brainstorming 2026-07-15; amended after review). Open Items resolved via brainstorming 2026-07-20 — **export cut to a Non-Goal**. Ready for plan.
**Spun from:** the 2026-07-15 tech-debt sweep (which removed the unused `SecretMasker` helper) and its security follow-up.

## Context

Coqui writes an audit trail via `SessionStorage::logAudit()` (`src/Storage/SessionStorage.php:1755`) into the `audit_log` table (`id, session_id, tool_name, arguments, action, reason, turn_id, created_at`). Three call sites write it:

- `AutoApprovalPolicy::log()` — logs **every** auto-approval decision (the raw tool-call arguments of each tool it decides on).
- `InteractiveApprovalPolicy` — logs only **gated** decisions: `blocked` (catastrophic), `denied`, `approved`. **Ungated tools return early and are NOT logged** (`InteractiveApprovalPolicy.php:70`, `if (!$this->requiresApproval(...)) return true;`).
- `QuestionPersistence` — `question_asked` / `question_answered` payloads.

So this is an **approval/decision + question audit trail**, not yet a comprehensive record of all tool or API activity. That is a fine scope; the design must not claim more.

Two facts drive the work:

1. **Secrets are stored raw.** `arguments` (and `reason`) are persisted verbatim (`audit_log.arguments TEXT NOT NULL`); nothing redacts them. Coqui is shell-open by default, so secrets frequently land inside free-text values (e.g. a `shell` command `curl -H "Authorization: Bearer sk-…"`), and `reason` can echo matched content (e.g. `"CATASTROPHIC BLOCK: <matched text>"`).
2. **The trail is write-only.** `getAuditLog()` (`:1790`) has **zero callers** anywhere — no API route, no REPL command, no toolkit, no test. Coqui records a trail nobody can read.

The removed `SecretMasker::mask()` only redacted a single known substring and could not handle nested audit args — wiring it would have been incomplete/false-confidence, hence removal + this design.

## Goal

Make the audit log both **safe** (never stores secrets, historically or going forward) and **useful** (readable/queryable), as two coupled parts:

- **Part A — Write-time redaction + legacy migration:** `audit_log` never persists a secret, and existing rows are redacted before any reader exists. This is the security precondition for Part B.
- **Part B — Read/query access:** one shared query store exposed as a rich authenticated API and a thin REPL `/audit` browser, with no duplicated logic, no TUI, and no export.

## Part A — Write-Time Redaction

### Placement — one choke point, fail-closed

Add an `AuditRedactor` service, injected (nullable) into `SessionStorage`. `logAudit` applies it to **both `arguments` and `reason`** (and thereby all question payloads, which flow through `arguments`) before the INSERT:

```php
$safeArgs   = $this->redactor?->redact($arguments) ?? $arguments;
$safeReason = $this->redactor?->redactScalar($reason) ?? $reason;
```

- **One choke point** covers all three callers uniformly and keeps `SessionStorage` clean (it delegates rather than owning redaction logic).
- **Fail-closed:** if `redact()` or the subsequent `json_encode` throws, persist a safe placeholder (e.g. `["<redaction-failed>"]`) — **never** the raw arguments. A redaction bug must not silently write secrets.
- Nullable so tests can construct storage without redaction and there is no hard dependency.

### Three detection layers (applied recursively over the argument tree, and over the `reason` string)

- **L1 — Known-value redaction (most precise; reuses existing infra).** Build the credential-name set from: `ToolkitDiscovery::collectAllCredentialRequirements()` (`:860`, toolkit-declared env names), **plus** `CredentialResolverInterface::keys()` (`:88`, all configured credential keys), **plus** core names (`COQUI_API_KEY`), configured provider credential names, and the explicit `api.key`. Toolkit declarations alone do NOT cover every configured credential. **Cache the name set** (refreshable), and **resolve each value at write time** via `CredentialResolver::get()` — this preserves hot-reload and avoids holding secret values in memory. Redact **exact occurrences of those non-empty resolved values anywhere**, including inside free-text (shell commands, URLs). Zero false positives.
- **L2 — Sensitive key-name matching (recursive).** Redact values whose key matches a default set (`password`, `passwd`, `token`, `secret`, `api_key`, `apikey`, `authorization`, `auth`, `credential`, `private_key`, …). Catches structured secrets not in the credential store.
- **L3 — Value-pattern backstop.** Redact substrings matching high-confidence patterns: `Bearer <token>`, provider prefixes (`sk-`, `ghp_`, `xox[bp]-`), PEM blocks, JWT shapes. Catches free-text embeds L1/L2 miss.

### Output

Keep the key; replace the value (or, for L1/L3 substring hits, the matched substring) with `"[REDACTED]"`. The trail still shows *that* a secret passed through and *where*, without the value.

### Legacy-row migration (must precede Part B)

Existing `audit_log` rows already contain raw secrets. Before any read surface ships, run a **one-time migration** that applies the redactor to the stored `arguments` and `reason` of every historical row (with the same fail-closed behavior and a safe fallback for malformed legacy JSON). This is the single most important sequencing constraint: **exposing an un-migrated audit log would serve historical secrets.**

### Wiring — all construction paths

The redactor is assembled from `ToolkitDiscovery` + `CredentialResolver` (available post-discovery) and **owned by `BootManager`**. Every production `SessionStorage` construction must attach it — there are **seven** sites:
`BootManager.php:561`, `Command/TurnRunCommand.php:85`, `Command/ApiCommand.php:138`, `Command/SessionTitleRunCommand.php:66`, `Command/TaskRunCommand.php:86`, `Command/DoctorCommand.php:439`, `Command/RunCommand.php:204`.
Prefer routing construction through a single `BootManager`-owned path/factory so no process writes raw. **Do not** wire it only in `AgentRunnerFactory` — the separate task/turn/title processes construct their own storage and would be missed.

### Config

Sane hardcoded defaults for the L2 key set and L3 patterns; an optional `security.audit.*` extension is **deferred** unless trivial. L1 needs no config.

## Part B — Read/Query Access

### One shared query store (no duplication; don't grow SessionStorage)

Introduce a dedicated **`AuditLogStore`** owning read/query/count. `SessionStorage::logAudit()` remains the **write** choke point (with the redactor); `AuditLogStore` is the **read** side — this avoids growing the already-large `SessionStorage`. Both the API handler and the REPL command are thin adapters over `AuditLogStore` (in-process; the REPL does **not** round-trip through HTTP), mirroring loops (`LoopStore` ← `LoopHandler` + `/loops`).

**Construction — follow `LoopStore` exactly (resolved 2026-07-20).** `AuditLogStore::__construct(PDO $db)` takes the **shared** `SessionStorage` PDO via `getPdo()`; it does **not** open its own read handle. This is the established pattern for every store in the codebase (`BootManager.php:568`, `Repl/Handler/LoopHandler.php:33`, `Command/ApiCommand.php:356`). SQLite is single-writer, so a second connection buys nothing and adds lock-contention surface. `BootManager` owns one instance behind an `auditLogStore()` accessor for the API server; the REPL handler constructs lazily from `$this->storage->getPdo()` as `LoopHandler` does.

### Query semantics (precise)

- Filters: `session_id`, `tool_name`, `action`, and a time range with **validated ISO-8601** `before`/`after` boundaries (define inclusive/exclusive explicitly — recommend `after` inclusive, `before` exclusive).
- **Bounded** `limit` (e.g. 1–500, default 100) and `offset` (>= 0).
- **Deterministic** ordering: `ORDER BY created_at DESC, id DESC` (`created_at` is second-resolution `date('c')`, so `id` is the required tiebreaker for stable pagination).
- Return structured rows **including `turn_id`** (currently omitted by `getAuditLog`'s SELECT at `:1811`) plus a **total count**.
- **Decode `arguments` into structured JSON** for API responses, with a safe fallback (`{"_raw": "…"}` or similar) for malformed legacy rows.
- **Add indexes** for the new query paths: `idx_audit_log_created_at` and `idx_audit_log_tool` (`session`/`action`/`turn` already exist at `SessionStorage.php:137-149`).

### API (authenticated, core — never public)

- `GET /api/v1/audit` — global, filterable, paginated. Params: `session_id`, `tool_name`, `action`, `before`, `after`, `limit`, `offset`. Returns `{ entries: [...], total, limit, offset }`.
- `GET /api/v1/sessions/{id}/audit` — session-scoped convenience (same filters minus `session_id`).
There is **no export endpoint** — see Non-Goals. The paginated list endpoint is the sole retrieval path; a client that wants the full trail pages it with `limit`/`offset`.

All audit routes are normal authenticated routes (API-key), never `addPublicRoute`.

### REPL — `/audit` (canonical, core static command, thin, no TUI)

`/audit` is a **core** command: a static `ReplCommandSpec` in `ReplCommandCatalog`, dispatched by `SlashCommandRouter`, modeled on `/loops` (NOT self-registered — self-registration is the toolkit extension path). It renders a plain SymfonyStyle table over `AuditLogStore` — **not a screen, not fullscreen.** Filters as args: `/audit`, `/audit tool shell`, `/audit session <id>`, `/audit action <action>`, `/audit --limit 50`. `/audit` is browse-only.

`/audit` is the single canonical name in both API and REPL. **No `/logs` alias (resolved 2026-07-20)** — the name is ambiguous with server/process logs, no `/logs` command exists in `src/Repl/` today, and an undocumented alias is alias-maintenance code for no user benefit. A future single unified TUI consumes this same API.

## Sequencing

1. **Part A first:** redactor + fail-closed write path + **legacy-row migration** (redact all existing rows).
2. **Part B second, never ahead of A:** `AuditLogStore` + query + API + `/audit`. Exposure must not ship until both the write path and historical rows are redacted.

Builds off the post-tech-debt-sweep `main`. May be two SDD dispatches (A then B) under one spec.

## Testing

- **Redactor:** a case per layer (L1 known-value incl. inside a shell string; L2 nested sensitive key; L3 Bearer/PEM/prefix); golden "`shell` command with inline `Bearer` token"; **`credentials(set)` before the value exists in the resolver**; **credential added after redactor construction** (name-set refresh / resolve-at-write); **`reason` redaction + question-payload minimization**; "no-secret args pass through unchanged"; **redactor/encode failure is fail-closed** (never writes raw).
- **Legacy migration:** historical rows with raw secrets end up redacted; malformed legacy JSON handled safely.
- **AuditLogStore/query:** filters, deterministic pagination, invalid/out-of-bounds `limit`/`offset`, `turn_id` present, structured-JSON decode + malformed fallback.
- **API:** each filter, pagination envelope, `401` without key.
- **Negative control (required):** at least one test that **fails if the redactor is removed or stubbed out**. Redaction that silently no-ops is indistinguishable from redaction that works, and every row it writes is a secret on disk. Assert on redacted *output*, never on "the code path ran."
- **Wiring (production, not only direct unit tests):** the API server and REPL paths construct storage **with** the redactor attached; spot-check the separate task/turn processes.

## Non-Goals

- **No export surface of any kind (resolved 2026-07-20).** No `/api/v1/audit/export`, no CSV writer, no `?format=` flag, no `Content-Disposition`/streaming path, and correspondingly no export size cap or mandatory time range. The paginated list endpoint already exposes the full trail via `limit`/`offset`, so export was a convenience format rather than a distinct capability. If it is ever wanted, it is a new spec with its own justification — `AuditLogStore` is deliberately **not** shaped to accommodate a future exporter.
- No read-time / display-time redaction (write-time only).
- No retention / TTL / pruning; no destructive audit operations (delete/prune).
- No fullscreen TUI or bespoke audit screen; no REPL-over-HTTP.
- No `security.audit.*` config surface beyond sane defaults (extension deferred).

## Documentation (explicit tasks in the plan)

Update `docs/API.md` (the two new `/audit` endpoints + error/auth shape) and `docs/COMMANDS.md` (the `/audit` REPL command).

**`config/source.json` no longer exists** — it was deleted in #162. The earlier instruction to update it is obsolete; ignore it. Every doc claim written must be traceable to code actually read.

## Open Items — Resolved (2026-07-20)

1. **Export size cap vs. mandatory time range** → **moot: export removed entirely.** See Non-Goals. (A mandatory time range was also rejected on its merits: `after=1970-01-01` is a legal unbounded request, so it is a bound that looks like a bound and is not.)
2. **`/logs` alias** → **no.** `/audit` only. No `/logs` command exists today, so this is "don't add one," not "remove one."
3. **`AuditLogStore` construction / PDO** → **shared `SessionStorage` PDO, following `LoopStore` exactly.** See Part B → Construction.

## Build Process

Brainstorm (done) → this spec → user review → `/prompt-agent-task` → plan (STOP for review) → SDD (Opus implementer + reviewer, two-verdict gate, whole-branch review) → independent verification → merge. Two phases (A then B) under one spec; A (incl. legacy migration) must precede B.

Cross-references: tech-debt sweep spec `docs/superpowers/specs/2026-07-15-tech-debt-sweep-design.md`; API-first direction (rich API, thin REPL consumer, shared store — same shape as loops).
