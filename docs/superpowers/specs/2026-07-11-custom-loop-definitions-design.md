# Custom Loop Definitions via API

**Date:** 2026-07-11
**Status:** Approved (design)
**Program:** Loops-API maturation — **Spec C of 3** (A: Live View · B: Headless start · C: Custom definitions via API). Successor to Spec B (`docs/superpowers/specs/2026-07-11-headless-loop-start-design.md`); the final spec in the program.
**Frame:** loops > profiles > prompt-budgeting; API-first. Additive (build-new), not thinning.

## Purpose

Let users design and manage custom loop definitions **straight through the API** — create, read, update, and delete the JSON definitions that `loop_start` and `POST /loops` run. Today definitions are hand-authored JSON files in `workspace/loops/`; this spec exposes full CRUD over them, validated and safe.

## Non-Goals

- **No database store.** Definitions stay as JSON files in `workspace/loops/` (the existing model that `LoopDiscovery` scans). No new table.
- **No versioning/history/rollback.** A write replaces the file.
- **No effect on running loops.** Loops snapshot their definition at start (`loops.configuration`); editing or deleting a definition never touches an in-flight loop.
- **No auth changes.** These endpoints sit behind the same API protection as the rest of `/api/v1`.
- **No UI.** API/data contract only.

## Architectural Grounding (verified)

- Definitions are `workspace/loops/*.json`, scanned by `LoopDiscovery` (`discoverAll`, `get`, `exists`, `getRawDefinition`, `availableLoops`). `LoopDiscovery` is currently **read + seed only** — no per-definition write/delete — so this spec adds those.
- `LoopDefinition::fromArray()` validates the structure: each `roles` entry and each `parameters` entry must be an object, and `termination_condition` must be an object (delegating to `LoopRoleDefinition`/`LoopParameterDefinition`/`TerminationCondition`). It **throws `InvalidArgumentException`** on a bad shape. It is **lenient** on `name`/`description` (both default to `''`).
- Built-ins live in `config/loops/` and are seeded into `workspace/loops/` on first boot by `seedBuiltinLoops`, which **"only copies if the target doesn't exist — never overwrite user edits."** Consequences: overriding a built-in **persists**; deleting a built-in file gets it **re-seeded to default on next boot**; deleting a user definition is **permanent**.
- Running loops are unaffected by definition changes (they carry a snapshot in `loops.configuration`).
- The existing `GET /api/v1/loops/definitions` lists definitions but does not distinguish built-in from custom.

## Decisions Locked

- **Full CRUD on all definitions**, built-ins included. Built-ins are editable/deletable; overrides persist, a deleted built-in resets to default on next boot. The list marks each definition `builtin: true|false` so a UI can warn. Structural validation still blocks invalid writes.
- **`POST` = create-only** (`409` if the name already exists). **`PUT /{name}` = upsert** (create-or-replace, idempotent).

## Design

### Endpoints (under the existing `/api/v1/loops/definitions`)

- `GET /api/v1/loops/definitions` — list. Enriched: each entry gains `builtin: bool`.
- `GET /api/v1/loops/definitions/{name}` — fetch one raw definition (the decoded JSON). `404` if unknown.
- `POST /api/v1/loops/definitions` — create from the request body (name taken from `body.name`). `409` if the name exists; `201` with the stored definition on success.
- `PUT /api/v1/loops/definitions/{name}` — upsert. The stored definition's `name` is forced to match `{name}` (the path is authoritative). `200` with the stored definition.
- `DELETE /api/v1/loops/definitions/{name}` — delete `workspace/loops/{name}.json`. `200` on delete, `404` if the file does not exist.

### Component: write methods on `LoopDiscovery`

`LoopDiscovery` owns `workspace/loops/`, `ensureLoopsDir()`, and `invalidateCache()`, so writes belong there:

- `saveDefinition(string $name, array $definition): void` — validate the name (see below), force `$definition['name'] = $name`, validate the structure via `LoopDefinition::fromArray($definition)` (throws on bad shape) and require **≥1 role**, then write pretty-printed JSON to `workspace/loops/{name}.json` and `invalidateCache()`.
- `deleteDefinition(string $name): bool` — validate the name, delete `workspace/loops/{name}.json`, `invalidateCache()`, return whether a file was removed.
- `isBuiltin(string $name): bool` — `file_exists(config/loops/{name}.json)` (the built-in source dir).
- `isValidDefinitionName(string $name): bool` — the shared name guard below.

### Validation (two layers)

1. **Name** (`400` on failure) — enforced in the handler and `LoopDiscovery` before any file path is built: `preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)`. This is the **path-traversal guard**: names are filenames, so anything with `/`, `..`, whitespace, or other characters is rejected. Non-negotiable, since these endpoints write files from API input.
2. **Structure** (`400` on failure) — `LoopDefinition::fromArray()` throws `InvalidArgumentException` for malformed `roles`/`parameters`/`termination_condition`; additionally require a non-empty `roles` array (`fromArray` accepts an empty one). The handler maps these throws to `400` (`VALIDATION_ERROR`) with the exception message. (The codebase has no `422`; see the error-code note below.)

Both name and structure failures are `400`; the message distinguishes them. The handler validates the name first (cheap guard, avoids any filesystem touch), then lets `saveDefinition`'s structural `InvalidArgumentException` surface as `400`.

### Response shapes & status codes

- `GET` one → the raw definition object.
- `POST` → `201` + stored definition; `409` if exists; `400` invalid structure or name.
- `PUT` → `200` + stored definition; `400` invalid structure or name.
- `DELETE` → `200` (`{ "deleted": true, "name": ... }`); `404` if not found; `400` invalid name.
- `GET` list → existing shape plus `builtin` per entry.

**Error-code note (reconciled with the codebase):** `ApiErrorCode` has **no `422`** — it maps all validation (`VALIDATION_ERROR`/`MISSING_FIELD`/`INVALID_FORMAT`) to **400** and `CONFLICT` to 409. So both invalid *name* and invalid *structure* return **400** (`VALIDATION_ERROR`), distinguished by the message, following the established convention rather than introducing a new 422 code.

## Error Handling

- Malformed JSON body → `400` (matches `LoopHandler::create`'s existing handling).
- Invalid name (traversal/format) → `400` (`VALIDATION_ERROR`), never touching the filesystem.
- Invalid structure → `400` (`VALIDATION_ERROR`) with the `fromArray` message.
- `POST` onto an existing name → `409`.
- `GET`/`DELETE` unknown name → `404`.
- Filesystem write failure → `500` (surfaced as a generic server error).
- Deleting a built-in returns `200` but the file re-seeds on next boot — documented, not an error.

## Testing

- `LoopDiscovery::saveDefinition` writes a valid custom definition, which then appears in `discoverAll()`/`exists()`; the stored `name` matches the filename.
- `saveDefinition` rejects: a traversal name (`../evil`, `a/b`) — no file written; a structurally-invalid body (bad `roles`/missing `termination_condition`) — throws; an empty `roles` array — throws.
- `deleteDefinition` removes a custom file and returns `true`; returns `false` for a missing name.
- `isBuiltin` is `true` for `harness` and `false` for a user definition.
- Handler: `POST` creates (`201`) and `409`s on duplicate; `PUT` upserts (`200`) and forces the path name; `GET /{name}` returns raw / `404`; `DELETE` removes / `404`; `GET` list marks `builtin`.
- A definition created via the API is runnable: `POST /loops` (or `loop_start`) with that definition name starts a loop (integration-level check that the cache invalidation makes it visible).

## Program Completion

Spec C is the last of the loops-API maturation program (A → B → C). After it lands, loops can be observed richly (A), started headlessly (B), and authored entirely through the API (C). A natural future follow-on remains the **loop live-streaming** successor noted in Spec A (`/events?since=` cursor → SSE), which is out of scope here.
