# Audit Log: Write-Time Redaction + Read/Export Access — Design

**Date:** 2026-07-15
**Status:** Design approved (forks decided via brainstorming 2026-07-15; amended after review). Ready for spec review → plan.
**Spun from:** the 2026-07-15 tech-debt sweep (which removed the unused `SecretMasker` helper) and its security follow-up.

## Context

Coqui writes an audit trail via `SessionStorage::logAudit()` (`src/Storage/SessionStorage.php:1756`) into the `audit_log` table (`id, session_id, tool_name, arguments, action, reason, turn_id, created_at`). Three call sites write it:

- `AutoApprovalPolicy::log()` — logs **every** auto-approval decision (the raw tool-call arguments of each tool it decides on).
- `InteractiveApprovalPolicy` — logs only **gated** decisions: `blocked` (catastrophic), `denied`, `approved`. **Ungated tools return early and are NOT logged** (`InteractiveApprovalPolicy.php:70`, `if (!$this->requiresApproval(...)) return true;`).
- `QuestionPersistence` — `question_asked` / `question_answered` payloads.

So this is an **approval/decision + question audit trail**, not yet a comprehensive record of all tool or API activity. That is a fine scope; the design must not claim more.

Two facts drive the work:

1. **Secrets are stored raw.** `arguments` (and `reason`) are persisted verbatim (`audit_log.arguments TEXT NOT NULL`); nothing redacts them. Coqui is shell-open by default, so secrets frequently land inside free-text values (e.g. a `shell` command `curl -H "Authorization: Bearer sk-…"`), and `reason` can echo matched content (e.g. `"CATASTROPHIC BLOCK: <matched text>"`).
2. **The trail is write-only.** `getAuditLog()` (`:1791`) has **zero callers** anywhere — no API route, no REPL command, no toolkit, no test. Coqui records a trail nobody can read.

The removed `SecretMasker::mask()` only redacted a single known substring and could not handle nested audit args — wiring it would have been incomplete/false-confidence, hence removal + this design.

## Goal

Make the audit log both **safe** (never stores secrets, historically or going forward) and **useful** (readable/exportable), as two coupled parts:

- **Part A — Write-time redaction + legacy migration:** `audit_log` never persists a secret, and existing rows are redacted before any reader exists. This is the security precondition for Part B.
- **Part B — Read/query + export access:** one shared query store exposed as a rich authenticated API and a thin REPL `/audit` browser, with no duplicated logic and no TUI.

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

Existing `audit_log` rows already contain raw secrets. Before any read/export surface ships, run a **one-time migration** that applies the redactor to the stored `arguments` and `reason` of every historical row (with the same fail-closed behavior and a safe fallback for malformed legacy JSON). This is the single most important sequencing constraint: **exposing an un-migrated audit log would serve historical secrets.**

### Wiring — all construction paths

The redactor is assembled from `ToolkitDiscovery` + `CredentialResolver` (available post-discovery) and **owned by `BootManager`**. Every production `SessionStorage` construction must attach it — there are **seven** sites:
`BootManager.php:561`, `Command/TurnRunCommand.php:85`, `Command/ApiCommand.php:138`, `Command/SessionTitleRunCommand.php:66`, `Command/TaskRunCommand.php:86`, `Command/DoctorCommand.php:439`, `Command/RunCommand.php:204`.
Prefer routing construction through a single `BootManager`-owned path/factory so no process writes raw. **Do not** wire it only in `AgentRunnerFactory` — the separate task/turn/title processes construct their own storage and would be missed.

### Config

Sane hardcoded defaults for the L2 key set and L3 patterns; an optional `security.audit.*` extension is **deferred** unless trivial. L1 needs no config.

## Part B — Read/Query + Export Access

### One shared query store (no duplication; don't grow SessionStorage)

Introduce a dedicated **`AuditLogStore`** owning read/query/count/export. `SessionStorage::logAudit()` remains the **write** choke point (with the redactor); `AuditLogStore` is the **read** side — this avoids growing the already-large `SessionStorage`. Both the API handler and the REPL command are thin adapters over `AuditLogStore` (in-process; the REPL does **not** round-trip through HTTP), mirroring loops (`LoopStore` ← `LoopHandler` + `/loops`).

### Query semantics (precise)

- Filters: `session_id`, `tool_name`, `action`, and a time range with **validated ISO-8601** `before`/`after` boundaries (define inclusive/exclusive explicitly — recommend `after` inclusive, `before` exclusive).
- **Bounded** `limit` (e.g. 1–500, default 100) and `offset` (>= 0).
- **Deterministic** ordering: `ORDER BY created_at DESC, id DESC` (`created_at` is second-resolution `date('c')`, so `id` is the required tiebreaker for stable pagination).
- Return structured rows **including `turn_id`** (currently omitted by `getAuditLog`'s SELECT at `:1812`) plus a **total count**.
- **Decode `arguments` into structured JSON** for API responses, with a safe fallback (`{"_raw": "…"}` or similar) for malformed legacy rows.
- **Add indexes** for the new query paths: `idx_audit_log_created_at` and `idx_audit_log_tool` (`session`/`action`/`turn` already exist at `SessionStorage.php:138-150`).

### API (authenticated, core — never public)

- `GET /api/v1/audit` — global, filterable, paginated. Params: `session_id`, `tool_name`, `action`, `before`, `after`, `limit`, `offset`. Returns `{ entries: [...], total, limit, offset }`.
- `GET /api/v1/sessions/{id}/audit` — session-scoped convenience (same filters minus `session_id`).
- `GET /api/v1/audit/export` — **dedicated** export endpoint (not a `?format=` flag on the list endpoint, which must keep a stable JSON envelope + pagination). Owns its own concerns: `Content-Disposition`, its own size limits, buffering/streaming, and CSV. **Cap export size or require a time range** (reject unbounded dumps). JSON default; `?format=csv` for CSV.
- **CSV:** columns `id, session_id, turn_id, tool_name, action, reason, arguments` (arguments as compact JSON) `, created_at`. **Guard against CSV formula injection:** any cell whose value begins with `=`, `+`, `-`, or `@` must be neutralized (prefix with `'`).

All audit routes are normal authenticated routes (API-key), never `addPublicRoute`.

### REPL — `/audit` (canonical, core static command, thin, no TUI)

`/audit` is a **core** command: a static `ReplCommandSpec` in `ReplCommandCatalog`, dispatched by `SlashCommandRouter`, modeled on `/loops` (NOT self-registered — self-registration is the toolkit extension path). It renders a plain SymfonyStyle table over `AuditLogStore` — **not a screen, not fullscreen.** Filters as args: `/audit`, `/audit tool shell`, `/audit session <id>`, `/audit action <action>`, `/audit --limit 50`. Export stays an API-side capability; `/audit` is browse-only.

`/audit` is canonical in both API and REPL. `/logs` is avoided (ambiguous with server/process logs); optionally retain `/logs` as an undocumented alias, but help presents `/audit`. A future single unified TUI consumes this same API.

## Sequencing

1. **Part A first:** redactor + fail-closed write path + **legacy-row migration** (redact all existing rows).
2. **Part B second, never ahead of A:** `AuditLogStore` + query + API + `/audit` + export. Exposure must not ship until both the write path and historical rows are redacted.

Builds off the post-tech-debt-sweep `main`. May be two SDD dispatches (A then B) under one spec.

## Testing

- **Redactor:** a case per layer (L1 known-value incl. inside a shell string; L2 nested sensitive key; L3 Bearer/PEM/prefix); golden "`shell` command with inline `Bearer` token"; **`credentials(set)` before the value exists in the resolver**; **credential added after redactor construction** (name-set refresh / resolve-at-write); **`reason` redaction + question-payload minimization**; "no-secret args pass through unchanged"; **redactor/encode failure is fail-closed** (never writes raw).
- **Legacy migration:** historical rows with raw secrets end up redacted; malformed legacy JSON handled safely.
- **AuditLogStore/query:** filters, deterministic pagination, invalid/out-of-bounds `limit`/`offset`, `turn_id` present, structured-JSON decode + malformed fallback.
- **API:** each filter, pagination envelope, `401` without key; export JSON + CSV, **CSV escaping + formula-injection**, CSV headers, **export caps/time-range requirement**.
- **Wiring (production, not only direct unit tests):** the API server and REPL paths construct storage **with** the redactor attached; spot-check the separate task/turn processes.

## Non-Goals

- No read-time / display-time redaction (write-time only).
- No retention / TTL / pruning; no destructive audit operations (delete/prune).
- No fullscreen TUI or bespoke audit screen; no REPL-over-HTTP.
- No `security.audit.*` config surface beyond sane defaults (extension deferred).

## Documentation (explicit tasks in the plan)

Update `docs/API.md` (the new `/audit` endpoints + export + error/auth shape), `docs/COMMANDS.md` (the `/audit` REPL command), and `config/source.json` (new `AuditRedactor`, `AuditLogStore`, `AuditLogHandler`, and the changed `SessionStorage`/`getAuditLog` responsibilities).

## Open Items for the Plan

1. Export size cap value vs. mandatory time-range (pick one; recommend: require a time range OR hard-cap at N rows, whichever the plan prefers).
2. Whether to keep `/logs` as an undocumented alias for `/audit`.
3. Exact `AuditLogStore` construction/injection point and whether it shares the `SessionStorage` PDO or opens its own read handle.

## Build Process

Brainstorm (done) → this spec → user review → `/prompt-agent-task` → plan (STOP for review) → SDD (Opus implementer + reviewer, two-verdict gate, whole-branch review) → independent verification → merge. Two phases (A then B) under one spec; A (incl. legacy migration) must precede B.

Cross-references: tech-debt sweep spec `docs/superpowers/specs/2026-07-15-tech-debt-sweep-design.md`; API-first direction (rich API, thin REPL consumer, shared store — same shape as loops).
