---
title: Coqui HTTP API
description: Complete REST API reference with all endpoints, authentication, SSE streaming, rate limiting, CORS, and safety documentation
---

# Coqui HTTP API

> **REPL-first**: The terminal REPL is Coqui's primary interface. The API provides the stable application-facing execution and inspection surface. User-facing read and monitoring workflows are documented here. Most loop, schedule, and config-editing control flows remain REPL-first or tool-driven, while launcher-managed API restarts are now exposed over HTTP with explicit restart-state metadata.

The Coqui HTTP API provides programmatic access to Coqui's AI agent capabilities. It enables headless operation, remote session management, and real-time streaming of agent responses via Server-Sent Events (SSE).

The API is built on ReactPHP and runs as a long-lived PHP process. It shares the same core engine as the terminal REPL but without any terminal I/O dependency.

> **Extending the API from a mod?** This document covers *consuming* the API. To *add* your own routes — authenticated or public — from an installed mod, see the [API features](TOOLKIT-EXTENSIBILITY.md#api-features) section of the toolkit extensibility guide.

## Changelog

- Added the Core turn-scoped answer path `POST /api/v1/sessions/{id}/turns/{turnId}/answer` (`submitTurnAnswer`): a client answers a turn's blocking `ask_user` question here after the `question` SSE frame (which now carries `data.question_id`), without the optional `questions` profile.
- Added structured questions: `GET /api/v1/sessions/{id}/questions`, `POST /api/v1/sessions/{id}/questions/{questionId}/answer`, and the `question` SSE turn-event (see [Questions](#questions) and [QUESTIONS.md](QUESTIONS.md)).
- Session objects no longer include `channel`/`channel_bound`; `session_origin` is never `channel`.

## Starting the Server

Use the launcher-managed entrypoint by default. The API is a required runtime for background tasks, schedules, and other long-lived features.

```bash
# Recommended default: full app (REPL + API)
coqui

# API only
coqui --api-only

# Listen on all interfaces (accessible from other devices on your network)
coqui --api-only --host 0.0.0.0

# Custom host and port
coqui --api-only --host 0.0.0.0 --port 3000

# With a specific config file
coqui --api-only --config /path/to/openclaw.json

# With CORS origins restricted
coqui --api-only --cors-origin "http://localhost:3000,https://app.example.com"

# Explicit launcher name
./bin/coqui --api-only --host 0.0.0.0

# Via environment variable
COQUI_API_HOST=0.0.0.0 coqui

# Via Make
make start
make api HOST=0.0.0.0 PORT=3000

# Docker (already binds to 0.0.0.0 inside the container)
make docker-start            # REPL + API
make docker-api PORT=3000    # API only
```

### CLI Options

| Option | Short | Default | Description |
|--------|-------|---------|-------------|
| `--port` | | `3300` | Port to listen on |
| `--host` | | `127.0.0.1` | Host to bind to. Use `0.0.0.0` for network access. Also configurable via `COQUI_API_HOST` env var |
| `--config` | `-c` | `./openclaw.json` | Path to openclaw.json config |
| `--workdir` | `-w` | Current directory | Working directory (project root) |
| `--workspace` | | Config/default | Workspace directory (overrides config resolution). Also configurable via `COQUI_WORKSPACE` env var |
| `--unsafe` | | `false` | Disable script sanitization (dangerous) |
| `--cors-origin` | | `*` | Allowed CORS origins (comma-separated) |

## Authentication

When an API key is configured, all requests (except `GET /api/v1/health` and `OPTIONS`) must include the key in the `Authorization` header.

```
Authorization: Bearer <your-api-key>
```

### Configuring the API Key

The server resolves the API key from these sources (first match wins):

1. `api.key` field in `openclaw.json`
2. `COQUI_API_KEY` environment variable
3. `COQUI_API_KEY` in the workspace `.env` file

If no key is found and the server is bound to a non-localhost address, the server **refuses to start**. When bound to `127.0.0.1` (the default), the server starts without a key and allows unauthenticated access.

To generate an API key, run `coqui setup` or set `COQUI_API_KEY` in your workspace `.env` file.

### Error Responses

Unauthenticated requests receive:

```json
{
  "error": "Missing Authorization header",
  "code": "unauthorized"
}
```

## Network Access

By default, the API server binds to `127.0.0.1` (localhost only). To access the API from other devices on your network — such as a phone, tablet, or another computer — bind to all interfaces:

```bash
# Any of these methods work:
coqui --api-only --host 0.0.0.0
coqui --host 0.0.0.0
COQUI_API_HOST=0.0.0.0 coqui
make api HOST=0.0.0.0
```

Once running, the API is reachable at `http://<your-machine-ip>:3300` from any device on the same network.

### Host Resolution Priority

The bind address is resolved in this order:

1. `--host` CLI flag (highest priority)
2. `COQUI_API_HOST` environment variable
3. Default: `127.0.0.1`

### Security Considerations

Exposing the API to the network means any device on that network can reach it. Follow these practices:

- **API key is mandatory for network access.** When binding to `0.0.0.0`, the server refuses to start without an API key. Generate one with `coqui setup` or set `COQUI_API_KEY` in your `.env` file.
- **Use a strong API key.** Avoid short or easily guessable keys. The setup wizard generates a cryptographically random key.
- **Restrict CORS origins.** Use `--cors-origin` to limit which domains can make browser-based requests: `--cors-origin "http://192.168.1.100:3380"`
- **Configure your firewall.** Only expose port 3300 (or your chosen port) to trusted networks. Do not expose it to the public internet without additional protection.
- **Coqui does not handle TLS/SSL.** All traffic is unencrypted HTTP. For production or internet-facing deployments, place a reverse proxy (nginx, Caddy, Traefik) in front of Coqui to terminate TLS.
- **Rate limiting is active.** The built-in rate limiter (30 requests/minute per IP by default) helps prevent abuse. Configure it in `openclaw.json` under `api.rateLimit`.

### Example: Reverse Proxy with Caddy

For HTTPS access from outside your local network, use a reverse proxy:

```
# Caddyfile
coqui.example.com {
    reverse_proxy localhost:3300
}
```

Caddy automatically provisions TLS certificates via Let's Encrypt.

## Base URL

All endpoints are prefixed with `/api`. The default base URL is:

```
http://127.0.0.1:3300
```

When bound to `0.0.0.0`, use your machine's IP address from other devices:

```
http://192.168.1.100:3300
```

## Content Type

Most request bodies must be JSON with `Content-Type: application/json`.
The content upload endpoint accepts `Content-Type: multipart/form-data` or a raw binary body typed by its own `Content-Type`.
All responses return `Content-Type: application/json` unless noted otherwise (SSE streams use `text/event-stream`, file downloads use the file's MIME type).

## Error Format

All error responses use a consistent envelope:

```json
{
  "error": "Human-readable error description",
  "code": "machine_readable_code"
}
```

The `code` field is a stable machine-readable string that clients can branch on without parsing the `error` message. Some errors include an additional `details` field with structured context.

**Error codes:**

| Code | HTTP Status | Description |
|------|-------------|-------------|
| `not_found` | 404 | Resource not found |
| `session_not_found` | 404 | Session does not exist |
| `turn_not_found` | 404 | Turn does not exist |
| `role_not_found` | 404 | Role does not exist |
| `credential_not_found` | 404 | Credential does not exist |
| `question_not_found` | 404 | Question does not exist in this session |
| `validation_error` | 400 | Invalid input data |
| `missing_field` | 400 | Required field not provided |
| `invalid_format` | 400 | Field value has wrong format |
| `question_invalid_answer` | 422 | Answer is not valid for this question |
| `conflict` | 409 | Resource already exists |
| `agent_busy` | 409 | Session already has an active agent run |
| `persona_session_active` | 409 | A personaScoped session is already active and the client must confirm closure before creating or reassigning a fresh one |
| `group_session_active` | 409 | A group session with the requested composition is already active and the client must confirm closure before forcing a fresh composition session |
| `role_builtin` | 409 | Cannot modify a built-in role |
| `role_reserved` | 409 | Cannot create a role with a reserved name |
| `session_closed` | 409 | Session is closed and read-only; mutating session-scoped requests are rejected |
| `unauthorized` | 401 | Missing or invalid API key |
| `forbidden` | 403 | Access denied |
| `rate_limited` | 429 | Too many requests |
| `payload_too_large` | 413 | Request body exceeds size limit |
| `unsupported_media_type` | 415 | Content-Type must be application/json |
| `internal_error` | 500 | Internal server error |

HTTP status codes follow standard conventions.

## Client Workflow

Use this document as the canonical HTTP API reference. The current API is best suited for three client patterns:

1. Create or resume a session.
2. Send prompts over SSE for live progress, or use `?stream=false` for a blocking JSON response.
3. Inspect session state, turns, tasks, artifacts, projects, schedules, loops, and server status through read-oriented endpoints.

### Conversation Model

- **Session** — the durable conversation container.
- **Turn** — one prompt/response cycle within a session.
- **Message** — the stored user, assistant, and tool records produced during a turn.

### Recommended Integration Flow

1. If your client exposes personalities, call `GET /api/v1/personas` first.
2. Prefer `POST /api/v1/sessions/resolve` for sticky app sessions, or `POST /api/v1/sessions` when you explicitly need a fresh conversation.
3. Upload files with `POST /api/v1/sessions/{id}/files` before sending a prompt when the turn needs images or document context.
4. Call `POST /api/v1/sessions/{id}/messages` to send prompts.
5. Prefer SSE for interactive clients so you can surface iterations, tool calls, warnings, and completion metadata in real time.
6. Use `GET /api/v1/sessions/{id}/messages`, `GET /api/v1/sessions/{id}/turns`, and the read-oriented inspection endpoints for history, audit, and runtime visibility.

### Streaming vs Blocking

- Default behavior is `text/event-stream` with an initial `connected` event followed by agent lifecycle events and a final `complete` event.
- Add `?stream=false` when your client wants one blocking JSON response instead of incremental updates.
- Only one active run is allowed per session; concurrent prompts to the same session return `409 agent_busy`.

### Concurrency Rules

Coqui can process different sessions concurrently, but only one active run is allowed per session.

- Session A and Session B can both run at the same time.
- Session A cannot accept a second prompt until its current turn completes.
- Busy-session collisions return `409` with code `agent_busy`.

Client recommendation:

1. Treat each session as a serialized conversation lane.
2. Queue prompts per session on the client side.
3. Use separate sessions for separate conversations or tabs.

### File Upload Workflow

Use session file uploads when your client needs to attach images or documents to a prompt.

1. Upload via `POST /api/v1/sessions/{id}/files`.
2. Capture the returned `content_ref` from the `content.json` response.
3. Reference that blob when sending a message (typed `attachments[]` of `{content_ref, mime_type}`).

## Endpoints

### Health

#### `GET /api/v1/health`

Liveness check. Does **not** require authentication.

**Response `200`**

```json
{
  "status": "ok",
  "version": "dev",
  "uptime_seconds": 3421,
  "active_sessions": 1,
  "restart": {
    "required": false,
    "reason": null,
    "source": null,
    "required_at": null,
    "context": [],
    "supported": true,
    "managed_by_launcher": true,
    "pid": 21093,
    "started_at": "2026-04-22T20:59:23Z"
  }
}
```

### Sessions

A session is a persistent conversation context. Messages and turns are scoped to a session.

Persona-scoped sessions enforce a single active interactive conversation per persona. `POST /api/v1/sessions/resolve` keeps the newest active personaScoped session and archives/closes older duplicates automatically. `POST /api/v1/sessions` remains the fresh-conversation endpoint, but when a personaScoped session is already active it returns `409 persona_session_active` until the client explicitly confirms closure of that active personaScoped session.

Group sessions are orchestrator-managed interactive sessions identified by a normalized member composition. Create or resolve them by setting `group_enabled=true` and passing a `members` array. `POST /api/v1/sessions/resolve` reuses the active group session for the same member composition, while `POST /api/v1/sessions` forces a fresh one and returns `409 group_session_active` unless the client explicitly confirms closure of the conflicting active composition.

Session lifecycle fields have distinct meanings:

- `closed` is the mutability state. Closed sessions are terminal and read-only: prompts, message deletion, file uploads/deletes, project reassignment, session metadata updates, and session-bound task or loop attachment are rejected with `409 session_closed`.
- `archived` is the discovery state. Archived sessions are hidden from active listings by default but remain fully inspectable through the read endpoints.
- In the current persona-rollover flow, archived sessions are also closed. A session may be closed without being archived if Coqui needs a terminal but still explicitly visible record.

Hidden sessions are internal execution lanes for background work. They are excluded from session listings and other user-facing session inspection endpoints even when a client already knows the raw session ID. Visible session payloads now include a derived `session_origin` label: `user` for ordinary interactive sessions. Hidden background sessions resolve internally as `background` but are not returned by the user-facing session endpoints.

#### `GET /api/v1/sessions`

List sessions, ordered by most recently updated.

This endpoint is user-facing and only returns surfaced sessions (`visibility = visible`). Hidden background sessions such as scheduled, loop, and task execution lanes are intentionally excluded.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | `50` | Max sessions to return (capped at 200) |
| `status` | string | `"active"` | Filter by lifecycle state: `active`, `closed`, `archived`, or `all` |
| `include_closed` | bool | `false` | Legacy alias for `status=all` when `status` is omitted |
| `persona` | string | unset | Filter by persona scope. Use a persona name like `caelum` or `none` for unpersonaScoped sessions only |

Each returned session also includes `session_origin`, which is `user` for ordinary interactive sessions or `background` for hidden background sessions.

**Response `200`**

```json
{
  "sessions": [
    {
      "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
      "model_role": "orchestrator",
      "model": "openai/gpt-5",
      "active_project_id": null,
      "status": "active",
      "is_closed": 0,
      "is_archived": 0,
      "closed_at": null,
      "archived_at": null,
      "closure_reason": null,
      "created_at": "2026-02-16T14:30:00+00:00",
      "updated_at": "2026-02-16T15:45:12+00:00",
      "token_count": 12450
    }
  ],
  "count": 1,
  "status": "active",
  "persona": null,
  "counts": {
    "active": 1,
    "closed": 3,
    "archived": 3,
    "total": 4
  }
}
```

Use `GET /api/v1/sessions?status=archived` to browse historical conversations without making them active again. Once you have a session ID, the normal read endpoints such as `GET /api/v1/sessions/{id}`, `GET /api/v1/sessions/{id}/messages`, and `GET /api/v1/sessions/{id}/turns` continue to work for archived history.

Use `GET /api/v1/sessions?persona=caelum&status=all` to browse a single persona scope, or `GET /api/v1/sessions?persona=none&status=all` to browse only unpersonaScoped sessions.

#### `POST /api/v1/sessions`

Create a new session.

This endpoint always creates a fresh session. For REPL-style "resume the last active interactive session for this scope or create one" behavior, use `POST /api/v1/sessions/resolve`.

**Request Body**

```json
{
  "model_role": "orchestrator",
  "persona": "caelum",
  "confirm_close_active_persona_session": true
}
```

Group-session requests use the same endpoint with a group scope instead of `persona`:

```json
{
  "group_enabled": true,
  "members": ["caelum", "nova"],
  "group_max_rounds": 4,
  "confirm_close_active_group_session": true
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `model_role` | string | No | `"orchestrator"` | Role to resolve the model from config. Must be a known role. |
| `persona` | string | No | `null` | Personality persona name. Must match a `personas/{name}/soul.md` in the workspace. |
| `group_enabled` | bool | No | `false` | When `true`, create a group session instead of a single-persona or unpersonaScoped session. Group sessions must remain orchestrator-managed. |
| `members` | array<string> | When `group_enabled=true` | — | Group member persona names. Must be unique, known personas. |
| `group_max_rounds` | int | No | `3` | Max same-turn coordination rounds for a group session. Minimum `1`. |
| `confirm_close_active_persona_session` | bool | No | `false` | Required when `persona` already has an active interactive session and the client explicitly wants to close/archive it before starting a fresh one |
| `confirm_close_active_group_session` | bool | No | `false` | Required when the requested group composition already has another active interactive session and the client explicitly wants to close/archive it before forcing a fresh one |

Successful session create and resolve responses now include:

- `created`: whether this request created a new session or reused an existing one
- `closed_session_ids`: any older conflicting sessions that were closed/archived as part of the operation

**Response `201`**

```json
{
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "persona": "caelum",
  "active_project_id": null,
  "created": true,
  "closed_session_ids": []
}
```

**Response `201`** — group session created:

```json
{
  "id": "b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "persona": null,
  "group_enabled": 1,
  "group_max_rounds": 4,
  "group_members": [
    {
      "persona": "caelum",
      "position": 0
    },
    {
      "persona": "nova",
      "position": 1
    }
  ],
  "active_project_id": null,
  "created": true,
  "closed_session_ids": ["a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"]
}
```

**Response `409`** — active personaScoped session must be confirmed first:

```json
{
  "error": "Persona \"caelum\" already has an active session. Confirm closure before starting or reassigning a fresh session.",
  "code": "persona_session_active",
  "details": {
    "persona": "caelum",
    "active_session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
    "active_session_count": 1,
    "confirm_field": "confirm_close_active_persona_session"
  }
}
```

**Response `409`** — active group session must be confirmed first:

```json
{
  "error": "Group session composition already has an active session. Confirm closure before starting a fresh group session.",
  "code": "group_session_active",
  "details": {
    "members": ["caelum", "nova"],
    "active_session_id": "b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6",
    "confirm_field": "confirm_close_active_group_session"
  }
}
```

**Response `400`** — Unknown role:

```json
{
  "error": "Unknown model_role 'nonexistent'. Available roles: orchestrator, coder",
  "code": "validation_error"
}
```

#### `POST /api/v1/sessions/resolve`

Resolve the latest interactive session for a scope, or create one if none exists.

This mirrors REPL startup behavior:

- Omit `persona` to target the unpersonaScoped interactive session pool.
- Pass `persona` to target a persona-specific interactive session pool.
- Hidden worker sessions are excluded from ordinary reuse.
- If multiple active interactive sessions exist for the same persona, Coqui keeps the newest one and archives/closes the older duplicates before responding.

**Request Body**

```json
{
  "model_role": "orchestrator",
  "persona": "caelum"
}
```

Group-session resolve requests use the same endpoint:

```json
{
  "group_enabled": true,
  "members": ["caelum", "nova"],
  "group_max_rounds": 4
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `model_role` | string | No | `"orchestrator"` | Role used only when a new session must be created. Existing scoped sessions keep their stored role and model. |
| `persona` | string | No | `null` | Personality persona scope. Omit to resolve the unpersonaScoped session pool. |
| `group_enabled` | bool | No | `false` | When `true`, resolve the group-session pool for the supplied member composition. |
| `members` | array<string> | When `group_enabled=true` | — | Group member persona names. Order is normalized, so the same set resolves the same active session. |
| `group_max_rounds` | int | No | `3` | Used only when a new group session must be created. Existing sessions keep their stored round cap. |

**Response `200`** — existing session reused:

```json
{
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "persona": "caelum",
  "active_project_id": null,
  "created": false,
  "closed_session_ids": ["f0e1d2c3b4a5968778695a4b3c2d1e0f"]
}
```

**Response `201`** — new session created:

```json
{
  "id": "b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "persona": null,
  "group_enabled": 1,
  "group_max_rounds": 4,
  "group_members": [
    {
      "persona": "caelum",
      "position": 0
    },
    {
      "persona": "nova",
      "position": 1
    }
  ],
  "active_project_id": null,
  "created": true,
  "closed_session_ids": []
}
```

#### `GET /api/v1/sessions/{id}`

Returns one surfaced session. Hidden background sessions are treated as not found on this user-facing endpoint.

Get session details.

**Response `200`**

```json
{
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "persona": "caelum",
  "status": "active",
  "is_closed": 0,
  "is_archived": 0,
  "closed_at": null,
  "archived_at": null,
  "closure_reason": null,
  "active_project_id": "p1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "created_at": "2026-02-16T14:30:00+00:00",
  "updated_at": "2026-02-16T15:45:12+00:00",
  "token_count": 12450
}
```

Group sessions include `group_enabled`, `group_max_rounds`, `group_composition_key`, and `group_members` in the returned session payload.

#### `GET /api/v1/sessions/{id}/summary`

Return a compact dashboard view for a session without fetching every child collection separately.

**Response `200`**

```json
{
  "session": {
    "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
    "persona": "caelum",
    "status": "active"
  },
  "counts": {
    "messages": {
      "total": 24,
      "active": 18,
      "summarized": 6
    },
    "turns": 4,
    "child_runs": 2,
    "tasks": {
      "total": 3,
      "by_status": {
        "completed": 2,
        "failed": 1
      }
    },
    "artifacts": {
      "total": 2,
      "persistent": 1,
      "by_stage": {
        "draft": 1,
        "final": 1
      }
    }
  },
  "latest_turn": {
    "id": "turn_123",
    "turn_number": 4,
    "content": "Summary complete",
    "tools_used": ["read_file", "apply_patch"],
    "created_at": "2026-02-16T15:43:00+00:00",
    "completed_at": "2026-02-16T15:45:12+00:00"
  },
  "latest_message_at": "2026-02-16T15:45:12+00:00",
  "latest_task_at": "2026-02-16T15:44:20+00:00",
  "latest_activity_at": "2026-02-16T15:45:12+00:00"
}
```

**Response `404`**

```json
{
  "error": "Session not found",
  "code": "session_not_found"
}
```

#### `PATCH /api/v1/sessions/{id}`

Update session metadata. Supports renaming the title, updating the role, changing the persona scope for non-group sessions, and updating `group_max_rounds` for existing group sessions.

Closed or archived sessions are read-only. This endpoint returns `409 session_closed` when clients try to modify them.

**Request Body**

```json
{
  "title": "My refactoring session",
  "persona": "caelum",
  "confirm_close_active_persona_session": true
}
```

Group-session patch example:

```json
{
  "group_max_rounds": 5
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `title` | string | No | New session title (cannot be empty) |
| `model_role` | string | No | Update the stored role and re-resolve the model |
| `persona` | string | No | Set or clear the session persona (`""` clears it) |
| `group_max_rounds` | int | No | Update the round cap for an existing group session |
| `confirm_close_active_persona_session` | bool | No | Required when reassigning an active session into a persona that already has another active interactive session |

Group-session constraints:

- `model_role` must remain `orchestrator`.
- `persona` cannot be assigned to a group session.
- `group_enabled` cannot be toggled after session creation.
- `members` cannot be patched here; use the dedicated member endpoints below.

**Response `200`**

Returns the updated session object:

```json
{
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "title": "My refactoring session",
  "active_project_id": null,
  "created_at": "2026-02-16T14:30:00+00:00",
  "updated_at": "2026-02-16T15:45:12+00:00",
  "token_count": 12450
}
```

**Response `400`** — empty title:

```json
{
  "error": "Title cannot be empty",
  "code": "missing_field"
}
```

**Response `404`**

```json
{
  "error": "Session not found",
  "code": "session_not_found"
}
```

#### `GET /api/v1/sessions/{id}/members`

Return the normalized member list for a group session.

**Response `200`**

```json
{
  "session_id": "b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6",
  "group_enabled": true,
  "group_composition_key": "caelum|nova",
  "group_max_rounds": 4,
  "members": [
    {
      "persona": "caelum",
      "position": 0
    },
    {
      "persona": "nova",
      "position": 1
    }
  ],
  "count": 2
}
```

#### `PUT /api/v1/sessions/{id}/members`

Replace the full member list for an existing group session.

**Request Body**

```json
{
  "members": ["caelum", "iris"],
  "confirm_close_active_group_session": true
}
```

#### `POST /api/v1/sessions/{id}/members`

Add one member to an existing group session.

**Request Body**

```json
{
  "persona": "iris",
  "confirm_close_active_group_session": true
}
```

#### `DELETE /api/v1/sessions/{id}/members/{persona}`

Remove one member from an existing group session.

For the mutating member endpoints, the response body is the updated session object. If the requested membership change would collide with another active group session composition, Coqui returns `409 group_session_active` until the client confirms closure with `confirm_close_active_group_session=true`.

#### `DELETE /api/v1/sessions/{id}`

Delete a session and all its associated data.

**Response `200`**

```json
{
  "deleted": true,
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
}
```

#### `GET /api/v1/sessions/{id}/project`

Get the session's active project, if one is set.

**Response `200`**

```json
{
  "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "active_project_id": "p1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "project": {
    "id": "p1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
    "title": "Career Ops",
    "slug": "career-ops",
    "status": "active"
  }
}
```

#### `PATCH /api/v1/sessions/{id}/project`

Set or clear the session's active project.

**Request Body**

```json
{
  "project_slug": "career-ops"
}
```

Alternative clear form:

```json
{
  "clear": true
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `project_id` | string | No | Project ID to activate |
| `project_slug` | string | No | Project slug to activate |
| `clear` | bool | No | Clear the active project instead of setting one |

Provide exactly one of `project_id`, `project_slug`, or `clear=true`.

Closed or archived sessions are read-only. This endpoint returns `409 session_closed` when clients try to modify them.

#### Conversation Summarization

Compress older conversation history into a concise summary, preserving recent turns and workflow state (artifacts).

**Request Body**

```json
{
  "keep_recent": 10,
  "focus": "database migration"
}
```

Conversation summarization is currently REPL-first and agent-tool driven. The HTTP API does not expose a summarize endpoint in the current router.

#### `GET /api/v1/sessions/{id}/messages`

Reads message history for one surfaced session. Hidden background sessions are treated as not found on this user-facing endpoint.

List all messages in a session, ordered chronologically.

**Response `200`**

```json
{
  "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "messages": [
    {
      "id": "m1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
      "role": "user",
      "content": "List the files in the current directory",
      "tool_calls": null,
      "tool_call_id": null,
      "created_at": "2026-02-16T14:30:05+00:00"
    },
    {
      "id": "m2a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
      "role": "assistant",
      "content": "I'll list the files for you.",
      "tool_calls": "[{\"id\":\"call_abc\",\"name\":\"list_dir\",\"arguments\":{\"path\":\".\"}}]",
      "tool_call_id": null,
      "created_at": "2026-02-16T14:30:07+00:00"
    },
    {
      "id": "m3a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
      "role": "tool",
      "content": "README.md\nsrc/\ntests/\ncomposer.json",
      "tool_calls": null,
      "tool_call_id": "call_abc",
      "created_at": "2026-02-16T14:30:08+00:00"
    },
    {
      "id": "m4a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
      "role": "assistant",
      "content": "Here are the files in the current directory:\n\n- README.md\n- src/\n- tests/\n- composer.json",
      "tool_calls": null,
      "tool_call_id": null,
      "created_at": "2026-02-16T14:30:10+00:00"
    }
  ],
  "count": 4
}
```

#### `POST /api/v1/sessions/{id}/messages`

Send a prompt to the agent. This is the **core endpoint** for interacting with Coqui.

By default, the response is a **Server-Sent Event (SSE) stream** that delivers real-time updates as the agent works (tool calls, results, content, etc.). Append `?stream=false` for a blocking JSON response.

**Request Body**

```json
{
  "prompt": "What files are in the src directory?",
  "attachments": [{ "content_ref": "f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6", "mime_type": "image/png" }]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `prompt` | string | Yes | The user prompt to send to the agent |
| `attachments` | object[] | No | Typed `attachment.json` references (`{content_ref, mime_type}`) to content-addressed blobs from prior uploads (see [Files](#files)) |

When `attachments` are provided, each `content_ref` is resolved from the content-addressed store and attached to the message. Image blobs (JPEG, PNG, GIF, WebP) are sent to the LLM as vision content. Text and document blobs are read and injected as context blocks in the prompt. An unknown `content_ref` is rejected with `404 content_not_found`.

Closed sessions reject new prompts with `409 session_closed`. The same read-only guard also applies to message deletion and the other session-scoped mutation endpoints. This gives clients a clean handoff state after a personaScoped conversation has been archived and closed.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `stream` | string | `"true"` | Set to `"false"` for a blocking JSON response |

**Response `200` (SSE Stream)**

The response uses `Content-Type: text/event-stream`. Each event follows the SSE format:

```
event: <event_type>
data: <json_payload>

```

Events are separated by a blank line. The stream ends when the `complete` event is sent and the connection closes.

Session title generation is now queued after the interactive turn completes. The live SSE stream is not kept open for title generation, so clients should treat session titles as eventually consistent and refresh session detail later if they need the generated title.

**SSE Event Types**

| Event | Description | Data Shape |
|-------|-------------|------------|
| `agent_start` | Agent turn has begun | `{}` |
| `iteration` | Agent loop iteration | `{"number": 1}` |
| `reasoning` | Model thinking/reasoning token | `{"content": "token"}` |
| `text_delta` | Streaming text token from LLM | `{"content": "token"}` |
| `tool_call` | Agent is calling a tool | `{"id": "call_abc", "tool": "list_dir", "arguments": {"path": "."}}` |
| `batch_start` | A parallel tool batch is starting | `{"count": 2}` or other batch metadata |
| `batch_end` | A parallel tool batch finished | `{"count": 2}` or other batch metadata |
| `tool_result` | Tool execution completed | `{"content": "...", "success": true}` |
| `child_start` | Child agent spawned | `{"role": "coder", "depth": 0}` |
| `child_end` | Child agent finished | `{"depth": 0}` |
| `review_start` | Automated review round started | `{"round": 1, "max_rounds": 2, "depth": 0}` |
| `review_end` | Automated review round finished | `{"round": 1, "verdict": "approved", "approved": true, "depth": 0}` |
| `done` | Agent turn content complete | `{"content": "Here are the files..."}` |
| `warning` | Non-fatal warning | `{"message": "Warning description"}` |
| `budget_warning` | Turn is nearing context budget exhaustion | `{"usage_percent": 92.5, "threshold_percent": 90.0}` |
| `summary` | Auto-summarization completed | `{"messages_summarized": 18, "tokens_saved": 5400, "auto": true}` |
| `memory_extraction` | Memory extraction completed | `{"memories_saved": 3, "source": "turn", "auto": true}` |
| `notification` | Pending workflow notification surfaced to the model | `{"kind": "task.completed", "title": "Build finished"}` |
| `question` | Agent asked the user a structured question (see [Questions](#questions)); the turn suspends until it is answered | The projected question frame carrying the required correlation id: `{"question_id": "q_ab12cd", "prompt": "...", "options": [{"value": "staging"}], "suggested": "staging"}`. Answer it via `POST /sessions/{id}/turns/{turnId}/answer`. |
| `loop_start` | Loop execution started | `{"loop_id": "loop-123"}` |
| `loop_iteration_start` | Loop iteration started | `{"loop_id": "loop-123", "iteration": 2}` |
| `loop_stage_start` | Loop stage started | `{"loop_id": "loop-123", "iteration": 2, "role": "coder"}` |
| `loop_stage_end` | Loop stage finished | `{"loop_id": "loop-123", "iteration": 2, "role": "coder"}` |
| `loop_iteration_end` | Loop iteration finished | `{"loop_id": "loop-123", "iteration": 2}` |
| `loop_complete` | Loop execution completed | `{"loop_id": "loop-123", "status": "completed"}` |
| `error` | An error occurred | `{"message": "Error description"}` |
| `complete` | Final event with full turn result | See below |

**`complete` Event Data**

The `complete` event carries the full turn result:

```json
{
  "content": "Here are the files in the src directory...",
  "iterations": 2,
  "prompt_tokens": 1250,
  "completion_tokens": 340,
  "total_tokens": 1590,
  "duration_ms": 4521,
  "tools_used": ["list_dir"],
  "child_agent_count": 0,
  "restart_requested": false,
  "iteration_limit_reached": false,
  "budget_exhausted": false,
  "context_usage": {
    "max_tokens": 128000,
    "reserved_tokens": 8192,
    "used_tokens": 24500,
    "usage_percent": 20.4,
    "available_tokens": 95308,
    "effective_budget": 119808,
    "breakdown": {
      "system": 5000,
      "memory": 1200,
      "user": 800,
      "assistant": 7000,
      "tool": 9000,
      "summary": 1500
    }
  },
  "file_edits": [
    {
      "file_path": "/workspace/src/Example.php",
      "operation": "update"
    }
  ],
  "review_feedback": null,
  "review_approved": null,
  "background_tasks": {
    "agents": [
      {
        "id": "task_123",
        "status": "running",
        "title": "Refactor auth",
        "role": "coder",
        "started_at": "2026-04-21T12:00:00+00:00",
        "created_at": "2026-04-21T11:59:30+00:00"
      }
    ],
    "tools": [],
    "total_count": 1
  },
  "error": null
}
```

Relevant fields for REPL-style footer rendering:

| Field | Type | Description |
|-------|------|-------------|
| `iterations` | int | Total model loop iterations used by the turn |
| `duration_ms` | int | End-to-end execution time in milliseconds |
| `prompt_tokens` | int | Estimated input token usage |
| `completion_tokens` | int | Estimated output token usage |
| `total_tokens` | int | Sum of prompt and completion tokens |
| `tools_used` | string[] | Unique tool names invoked during the turn |
| `child_agent_count` | int | Number of child agents spawned |
| `restart_requested` | bool | Whether the turn requested a Coqui restart |
| `iteration_limit_reached` | bool | Whether the turn stopped because the iteration cap was reached |
| `budget_exhausted` | bool | Whether the turn stopped because the context budget was exhausted |
| `context_usage` | object or `null` | Structured context-window usage data for frontend progress-bar rendering |
| `file_edits` | object[] or `null` | Files edited during the turn with operation type |
| `review_feedback` | string or `null` | Post-turn automated review feedback when available |
| `review_approved` | bool or `null` | Review verdict when post-turn review ran |
| `background_tasks` | object or `null` | Active background agent/tool summary. `started_at` and `created_at` are included so clients can compute elapsed durations. |
| `error` | string or `null` | Error summary when the turn fails |

**Example SSE Stream**

```
event: agent_start
data: {}

event: iteration
data: {"number":1}

event: tool_call
data: {"id":"call_abc","tool":"list_dir","arguments":{"path":"src"}}

event: tool_result
data: {"content":"Agent/\nApi/\nCommand/\nConfig/\n","success":true}

event: iteration
data: {"number":2}

event: text_delta
data: {"content":"Here"}

event: text_delta
data: {"content":" are"}

event: text_delta
data: {"content":" the files..."}

event: done
data: {"content":"Here are the files..."}

event: done
data: {"content":"Here are the directories inside `src/`:\n\n- Agent/\n- Api/\n- Command/\n- Config/"}

event: complete
data: {"content":"Here are the directories inside `src/`:\n\n- Agent/\n- Api/\n- Command/\n- Config/","iterations":2,"prompt_tokens":1250,"completion_tokens":340,"total_tokens":1590,"duration_ms":4521,"tools_used":["list_dir"],"child_agent_count":0,"restart_requested":false,"iteration_limit_reached":false,"budget_exhausted":false,"context_usage":{"max_tokens":128000,"reserved_tokens":8192,"used_tokens":24500,"usage_percent":20.4,"available_tokens":95308,"effective_budget":119808,"breakdown":{"system":5000,"memory":1200,"user":800,"assistant":7000,"tool":9000,"summary":1500}},"file_edits":[{"file_path":"/workspace/src/Example.php","operation":"update"}],"review_feedback":null,"review_approved":null,"background_tasks":{"agents":[{"id":"task_123","status":"running","title":"Refactor auth","role":"coder","started_at":"2026-04-21T12:00:00+00:00","created_at":"2026-04-21T11:59:30+00:00"}],"tools":[],"total_count":1},"error":null}

```

**Response `200` (Blocking JSON — `?stream=false`)**

When streaming is disabled, the server blocks until the agent completes and returns the full result:

```json
{
  "content": "Here are the files in the src directory...",
  "iterations": 2,
  "prompt_tokens": 1250,
  "completion_tokens": 340,
  "total_tokens": 1590,
  "duration_ms": 4521,
  "tools_used": ["list_dir"],
  "child_agent_count": 0,
  "restart_requested": false,
  "iteration_limit_reached": false,
  "budget_exhausted": false,
  "context_usage": {
    "max_tokens": 128000,
    "reserved_tokens": 8192,
    "used_tokens": 24500,
    "usage_percent": 20.4,
    "available_tokens": 95308,
    "effective_budget": 119808,
    "breakdown": {
      "system": 5000,
      "memory": 1200,
      "user": 800,
      "assistant": 7000,
      "tool": 9000,
      "summary": 1500
    }
  },
  "file_edits": [
    {
      "file_path": "/workspace/src/Example.php",
      "operation": "update"
    }
  ],
  "review_feedback": null,
  "review_approved": null,
  "background_tasks": {
    "agents": [
      {
        "id": "task_123",
        "status": "running",
        "title": "Refactor auth",
        "role": "coder",
        "started_at": "2026-04-21T12:00:00+00:00",
        "created_at": "2026-04-21T11:59:30+00:00"
      }
    ],
    "tools": [],
    "total_count": 1
  },
  "error": null
}
```

**Group session turn routing**

When the target session is group-enabled, message execution stays orchestrator-managed but fans out across the session members inside a single stored turn.

- Prompts without explicit member mentions default to all group members in stored order.
- `@name` narrows the responder set to the mentioned members in mention order.
- `@everyone` and `@group` expand to all eligible members.
- Historical message records and turn payloads expose `actor_name` and `actor_role` so clients can label who produced each assistant or tool message.

During SSE playback for API-origin turns, the replayable event stream and stored turn events include group lifecycle events such as `group_round_start`, `group_actor_start`, `group_actor_end`, and `group_round_end`, plus actor metadata on per-agent events, so clients can reconstruct the same responder-selection story shown in the REPL.

**Prompt Size Limit**

The `prompt` field is limited to **1 MiB** (1,048,576 bytes). Prompts exceeding this limit return a `413` error with code `payload_too_large`.

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `400` | `missing_field` | Missing or empty `prompt` field |
| `413` | `payload_too_large` | Prompt exceeds 1 MiB size limit |
| `404` | `session_not_found` | Session does not exist |
| `404` | `not_found` | Referenced file ID not found in this session |
| `409` | `agent_busy` | Session already has an active agent run |
| `409` | `session_closed` | Session is closed or archived and cannot be mutated |

#### `DELETE /api/v1/sessions/{id}/messages/{messageId}`

Delete a specific message from a session.

**Response `200`**

```json
{
  "deleted": true,
  "message_id": "m1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6"
}
```

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `404` | `session_not_found` | Session does not exist |
| `404` | `not_found` | Message not found |

### Files

Files are content-addressed blobs. An upload persists the bytes once (deduplicated by SHA-256) and returns a `content_ref`; a message references them by typed `attachments[]` of `{content_ref, mime_type}` for multimodal context. Images are sent to the LLM via vision APIs; text and document blobs are injected as context in the prompt. Content is immutable and shared — there is no per-session mutable file list, so the collection is create-and-read only (no list or delete endpoint).

**Supported MIME types:**

| Category | Types |
|----------|-------|
| Images | `image/jpeg`, `image/png`, `image/gif`, `image/webp` |
| Text | `text/plain`, `text/markdown`, `text/csv`, `text/html`, `text/xml`, `text/x-php`, `text/javascript` |
| Documents | `application/json`, `application/xml`, `application/pdf`, `application/x-yaml` |

**Limits:**

- Maximum blob size: **50 MiB** per upload (one blob per request)

#### `POST /api/v1/sessions/{id}/files`

Upload a single content blob to a session. Accepts either `multipart/form-data` (the first uploaded file is stored) or a raw binary request body (the bytes are the body, the MIME type is the request `Content-Type`).

The blob is stored content-addressed: the response is a CAP `content.json` object keyed by the SHA-256 of the bytes. Re-uploading identical bytes returns the existing object (the same `content_ref`).

Closed or archived sessions are read-only: the upload endpoint returns `409 session_closed` when clients try to add content to a historical session.

**Request**

Multipart:

```bash
curl -X POST http://127.0.0.1:3300/api/v1/sessions/{id}/files \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -F "files[]=@screenshot.png"
```

Raw binary:

```bash
curl -X POST http://127.0.0.1:3300/api/v1/sessions/{id}/files \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: text/markdown" \
  --data-binary @notes.md
```

**Response `201`**

```json
{
  "content_ref": "f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6",
  "mime_type": "image/png",
  "size": 245760,
  "sha256": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08",
  "created_at": "2026-02-16T14:30:05Z"
}
```

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `400` | `missing_field` | No multipart file and an empty request body |
| `400` | `validation_error` | Disallowed MIME type, or a failed multipart part |
| `404` | `session_not_found` | Session does not exist |
| `413` | `payload_too_large` | Blob exceeds the 50 MiB maximum |

#### `GET /api/v1/sessions/{id}/files/{ref}`

Download a content blob by its `content_ref`. Returns the stored bytes with appropriate headers.

**Response `200`**

Returns the full blob with:
- `Content-Type`: the blob's MIME type
- `Content-Length`: byte length
- `Accept-Ranges`: `bytes`

**Response `206` (partial)**

When the request carries a single `Range: bytes=a-b` header (`b` optional, defaults to the last byte), the response is the requested slice with:
- `Content-Range`: `bytes a-b/total`
- `Content-Length`: slice length
- `Accept-Ranges`: `bytes`

A malformed, multi-range, or unsatisfiable `Range` is ignored and the full blob is returned with `200`.

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `404` | `session_not_found` | Session does not exist |
| `404` | `content_not_found` | No blob for that `content_ref` |

### Turns

A turn represents a single request-response cycle within a session. Each turn contains the user prompt, agent response, token usage, timing, and tool usage metadata.

#### `GET /api/v1/sessions/{id}/turns`

Reads turn history for one surfaced session. Hidden background sessions are treated as not found on this user-facing endpoint.

List turns for a session, ordered by turn number.

Historical turn responses expose the same post-turn summary fields returned by the live `complete` event when that data is available. This lets clients reuse the same footer/progress rendering for both live and historical views.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | `50` | Max turns to return (capped at 200) |

**Response `200`**

```json
{
  "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "turns": [
    {
      "id": "t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
      "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
      "turn_number": 1,
      "user_prompt": "List the files in the current directory",
      "response_text": "Here are the files...",
      "content": "Here are the files...",
      "model": "openai/gpt-5",
      "prompt_tokens": 1250,
      "completion_tokens": 340,
      "total_tokens": 1590,
      "iterations": 2,
      "duration_ms": 4521,
      "tools_used": ["list_dir"],
      "child_agent_count": 0,
      "turn_process_id": "tp1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
      "restart_requested": false,
      "iteration_limit_reached": false,
      "budget_exhausted": false,
      "context_usage": {
        "max_tokens": 128000,
        "reserved_tokens": 8192,
        "used_tokens": 24500,
        "usage_percent": 20.4,
        "available_tokens": 95308,
        "effective_budget": 119808,
        "breakdown": {
          "system": 5000,
          "memory": 1200,
          "user": 800,
          "assistant": 7000,
          "tool": 9000,
          "summary": 1500
        }
      },
      "file_edits": [
        {
          "file_path": "/workspace/src/Example.php",
          "operation": "update"
        }
      ],
      "review_feedback": null,
      "review_approved": null,
      "background_tasks": null,
      "error": null,
      "created_at": "2026-02-16T14:30:05+00:00",
      "completed_at": "2026-02-16T14:30:10+00:00"
    }
  ],
  "count": 1
}
```

#### `GET /api/v1/sessions/{id}/turns/{turnId}`

Get a single turn with its associated messages. For API-origin turns, the detail response also includes an `events` array with the stored SSE event log so clients can replay or inspect the intermediate progress state after completion.

For group-enabled turns, the response also includes `actor_responses` in the top-level turn payload and `actor_name` / `actor_role` on nested messages. `actor_responses` preserves the grouped per-member reply order that was used to compose the final `content` field.

**Response `200`**

```json
{
  "id": "t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "turn_number": 1,
  "user_prompt": "List the files in the current directory",
  "response_text": "Here are the files...",
  "content": "Here are the files...",
  "model": "openai/gpt-5",
  "prompt_tokens": 1250,
  "completion_tokens": 340,
  "total_tokens": 1590,
  "iterations": 2,
  "duration_ms": 4521,
  "tools_used": ["list_dir"],
  "child_agent_count": 0,
  "turn_process_id": "tp1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "restart_requested": false,
  "iteration_limit_reached": false,
  "budget_exhausted": false,
  "context_usage": {
    "max_tokens": 128000,
    "reserved_tokens": 8192,
    "used_tokens": 24500,
    "usage_percent": 20.4,
    "available_tokens": 95308,
    "effective_budget": 119808,
    "breakdown": {
      "system": 5000,
      "memory": 1200,
      "user": 800,
      "assistant": 7000,
      "tool": 9000,
      "summary": 1500
    }
  },
  "file_edits": [
    {
      "file_path": "/workspace/src/Example.php",
      "operation": "update"
    }
  ],
  "review_feedback": null,
  "review_approved": null,
  "background_tasks": null,
  "actor_responses": [
    {
      "actor_name": "alex-hormozi",
      "actor_role": "orchestrator",
      "content": "Good morning. I can take the first pass.",
      "round": 1
    },
    {
      "actor_name": "trinity",
      "actor_role": "orchestrator",
      "content": "I agree with the plan and can review the follow-up.",
      "round": 1
    }
  ],
  "error": null,
  "created_at": "2026-02-16T14:30:05+00:00",
  "completed_at": "2026-02-16T14:30:10+00:00",
  "messages": [
    {
      "id": "m1...",
      "role": "user",
      "content": "List the files in the current directory",
      "tool_calls": null,
      "tool_call_id": null,
      "actor_name": null,
      "actor_role": null,
      "created_at": "2026-02-16T14:30:05+00:00"
    }
  ],
  "events": [
    {
      "id": 1,
      "event_type": "group_round_start",
      "data": {
        "round": 1,
        "responders": ["alex-hormozi", "trinity"],
        "max_rounds": 3,
        "selection_source": "default_all",
        "selection_rationale": "No explicit member mentions were provided, so all group members respond in stored order."
      },
      "created_at": "2026-02-16T14:30:06+00:00"
    },
    {
      "id": 2,
      "event_type": "iteration",
      "data": {
        "number": 1,
        "actor_name": "alex-hormozi",
        "actor_role": "orchestrator"
      },
      "created_at": "2026-02-16T14:30:09+00:00"
    }
  ]
}
```

#### `GET /api/v1/sessions/{id}/turns/{turnId}/events`

Get the replayable stored SSE event history for a turn without fetching the nested message payload. This is useful when the client wants to reconstruct live progress UI from historical runs but already has the turn summary and does not need message records.

For group-enabled turns, this endpoint exposes the same lifecycle events used during live playback. The most important event types are:

- `group_round_start` — selected responders for one coordination round, including `selection_source` and `selection_rationale`
- `group_actor_start` — the member currently beginning a response segment
- `group_actor_end` — the completed member segment plus its next-responder handoff metadata
- `group_round_end` — the aggregate next responder list chosen for the following round
- standard per-agent events such as `iteration`, `reasoning`, `tool_call`, `tool_result`, and `done`, each with `actor_name` and `actor_role` when the event came from a group member

**Response `200`**

```json
{
  "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "turn_id": "t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "events": [
    {
      "id": 1,
      "event_type": "group_round_start",
      "data": {
        "round": 1,
        "responders": ["alex-hormozi", "trinity"],
        "max_rounds": 3,
        "selection_source": "default_all",
        "selection_rationale": "No explicit member mentions were provided, so all group members respond in stored order."
      },
      "created_at": "2026-02-16T14:30:06+00:00"
    },
    {
      "id": 2,
      "event_type": "group_actor_start",
      "data": {
        "round": 1,
        "actor_name": "alex-hormozi",
        "actor_role": "orchestrator"
      },
      "created_at": "2026-02-16T14:30:10+00:00"
    }
  ],
  "count": 2
}
```

### Configuration

The HTTP API exposes configuration inspection, one narrow safe context-toggle mutation, plus dry-run validation. Broad config editing and role definition changes remain REPL-first.

#### `GET /api/v1/config`

Returns the full Coqui configuration. API keys in provider configs are masked as `"***"`.

**Response `200`**

```json
{
  "agents": {
    "defaults": {
      "workspace": "~/.coqui/.workspace",
      "model": {
        "primary": "openai/gpt-5"
      },
      "roles": {
        "orchestrator": "openai/gpt-5",
        "coder": "openai/gpt-5",
        "reviewer": "openai/gpt-5"
      }
    }
  },
  "models": {
    "mode": "merge",
    "providers": {
      "openai": {
        "baseUrl": "https://api.openai.com/v1",
        "api": "openai-completions",
        "apiKey": "***",
        "models": ["..."]
      }
    }
  }
}
```

#### `GET /api/v1/config/context`

Get the supported app-facing context settings from `openclaw.json` with their effective values, runtime defaults, field metadata, and current API restart state.

This endpoint is intentionally narrow. It only exposes the high-impact context settings that are safe to edit from client applications.

**Response `200`**

```json
{
  "context": {
    "conversationHistoryInSystemPrompt": false,
    "autoSummarizeMode": "token",
    "autoSummarizeThreshold": 64,
    "autoSummarizeTurnThreshold": 32,
    "autoSummarizeKeepRecent": 15
  },
  "defaults": {
    "conversationHistoryInSystemPrompt": false,
    "autoSummarizeMode": "token",
    "autoSummarizeThreshold": 64,
    "autoSummarizeTurnThreshold": 32,
    "autoSummarizeKeepRecent": 15
  },
  "fields": {
    "autoSummarizeMode": {
      "key": "autoSummarizeMode",
      "dot_key": "agents.defaults.context.autoSummarizeMode",
      "label": "Auto-Summarize Mode",
      "description": "Choose whether Coqui summarizes based on token budget, turn count, or only when you request it manually.",
      "type": "enum",
      "options": ["token", "turn", "manual"],
      "configured": false,
      "default": "token",
      "value": "token",
      "resettable": true,
      "restart_required": true,
      "requires_restart": true
    }
  },
  "restart": {
    "required": false,
    "reason": null,
    "source": null,
    "required_at": null,
    "context": [],
    "supported": true,
    "managed_by_launcher": true,
    "pid": 12345,
    "started_at": "2026-05-10T00:00:00Z"
  }
}
```

#### `PATCH /api/v1/config/context`

Update explicitly allowed context settings without reopening the full setup wizard.

This endpoint currently supports:

- `conversationHistoryInSystemPrompt`
- `autoSummarizeMode`
- `autoSummarizeThreshold`
- `autoSummarizeTurnThreshold`
- `autoSummarizeKeepRecent`

When enabled, Coqui keeps the normal replayed message history and also appends a final `Conversation History` system-prompt block built from the active stored messages.

Changes are written to `openclaw.json`, but the running process still needs a restart before the new settings affect turns.

Use the optional `reset` array to remove specific keys from `openclaw.json` and fall back to runtime defaults.

**Request Body**

```json
{
  "conversationHistoryInSystemPrompt": true,
  "autoSummarizeMode": "turn",
  "autoSummarizeTurnThreshold": 12,
  "autoSummarizeKeepRecent": 8
}
```

Reset selected values to defaults:

```json
{
  "reset": [
    "autoSummarizeMode",
    "autoSummarizeKeepRecent"
  ]
}
```

**Response `200`**

```json
{
  "context": {
    "conversationHistoryInSystemPrompt": true,
    "autoSummarizeMode": "turn",
    "autoSummarizeThreshold": 64,
    "autoSummarizeTurnThreshold": 12,
    "autoSummarizeKeepRecent": 8
  },
  "updated": [
    "conversationHistoryInSystemPrompt",
    "autoSummarizeMode",
    "autoSummarizeTurnThreshold",
    "autoSummarizeKeepRecent"
  ],
  "reset": [],
  "restart_required": true,
  "restart": {
    "required": true,
    "reason": "Agent context configuration changed. Restart the API server to apply the new behavior cleanly.",
    "source": "api.config.context.update",
    "required_at": "2026-05-10T00:00:00Z",
    "context": {
      "updated_keys": [
        "conversationHistoryInSystemPrompt",
        "autoSummarizeMode",
        "autoSummarizeTurnThreshold",
        "autoSummarizeKeepRecent"
      ]
    },
    "supported": true,
    "managed_by_launcher": true,
    "pid": 12345,
    "started_at": "2026-05-10T00:00:00Z"
  }
}
```

#### `POST /api/v1/config/validate`

Dry-run validation of a config object without saving. Use this to validate config changes before committing them.

**Request Body**

The complete `openclaw.json` content.

**Response `200`** — valid:

```json
{
  "valid": true
}
```

**Response `200`** — invalid:

```json
{
  "valid": false,
  "errors": [
    "agents.defaults.model.primary is required and must be a non-empty string",
    "agents.defaults.mounts[0].access must be \"ro\" or \"rw\""
  ]
}
```

#### `GET /api/v1/config/roles`

Returns all roles with full metadata. The response merges three layers:

1. **System roles** (e.g. `orchestrator`) — always present, `is_system: true`, `editable: false`.
2. **Config roles** — defined in `openclaw.json` under `agents.defaults.roles`.
3. **Custom roles** — user-created role files in `roles/`.

Supports two optional picker-oriented query parameters:

- `persona={name}` resolves models and role overrides for a specific persona and filters out roles disallowed by that persona's `preferences.json` role policy.
- `selectable=true` excludes template-only roles such as internal utility roles.

**Response `200`**

```json
{
  "roles": [
    {
      "name": "orchestrator",
      "model": "openai/gpt-5",
      "display_name": "Orchestrator",
      "description": "Primary system role with full tool access...",
      "access_level": "full",
      "is_builtin": true,
      "is_system": true,
      "editable": false
    },
    {
      "name": "coder",
      "model": "openai/gpt-5",
      "display_name": "Coder",
      "description": "Writes and refactors code",
      "access_level": "full",
      "is_builtin": false,
      "is_system": false,
      "editable": true
    }
  ],
  "count": 2,
  "persona": null,
  "selectable_only": false
}
```

#### `GET /api/v1/roles`

App-facing alias for `GET /api/v1/config/roles`.

Unlike the config route, this alias defaults to `selectable=true` so picker UIs get switchable roles by default.

#### `GET /api/v1/config/roles/{name}`

Get a single role with full details. System roles return metadata without instructions. Custom roles include the full instruction text.

Supports `?persona={name}` to resolve persona-specific role overrides and effective models.

**Response `200`** (custom role):

```json
{
  "name": "coder",
  "display_name": "Coder",
  "description": "Writes and refactors code",
  "version": 1,
  "access_level": "full",
  "is_builtin": false,
  "is_system": false,
  "editable": true,
  "model": "openai/gpt-5",
  "instructions": "You are a coding specialist...",
  "persona": null,
  "persona_override": false,
  "selectable": true
}
```

#### `GET /api/v1/roles/{name}`

App-facing alias for `GET /api/v1/config/roles/{name}`.

**Response `404`**

```json
{
  "error": "Role 'nonexistent' not found",
  "code": "role_not_found"
}
```

Role creation, updates, and deletion are REPL-only operations in the current API design.

#### `GET /api/v1/config/personas`

Lists discovered personas so clients can offer a persona picker instead of manual text entry.

**Response `200`**

```json
{
  "personas": [
    {
      "name": "caelum",
      "display_name": "Caelum",
      "description": "A calm companion.",
      "model": "anthropic/claude-sonnet-4-20250514",
      "is_default": true,
      "allowed_roles": ["analyst", "orchestrator"],
      "role_restrictions": {
        "allow": ["orchestrator", "analyst"],
        "deny": []
      },
      "has_role_restrictions": true
    },
    {
      "name": "trinity",
      "display_name": "Trinity",
      "description": "A precise hacker and guide.",
      "model": null,
      "is_default": false,
      "allowed_roles": ["analyst", "orchestrator"],
      "role_restrictions": {
        "allow": [],
        "deny": []
      },
      "has_role_restrictions": false
    }
  ],
  "count": 2,
  "default_persona": "caelum"
}
```

#### `GET /api/v1/config/persona-preferences/schema`

Returns the curated, app-facing schema for the first-class persona preferences editor.

This endpoint exists so the app can render domain-specific sections and controls without exposing raw `preferences.json` structure or internal inspection fields directly.

**Response `200`**

```json
{
  "version": 1,
  "sections": [
    {
      "id": "communication_style",
      "label": "Communication Style",
      "description": "How the persona speaks, collaborates, and frames feedback.",
      "fields": [
        {
          "id": "response_style",
          "label": "Response Style",
          "storage_path": "prompt_directives.response_style",
          "input": "suggested_text",
          "description": "Choose how the persona should sound in normal replies.",
          "suggestions": [
            "structured and measured",
            "brief and exact",
            "commercial and outcome-first"
          ]
        }
      ]
    },
    {
      "id": "planning_reasoning",
      "label": "Planning and Reasoning",
      "fields": []
    },
    {
      "id": "capabilities_tools",
      "label": "Capabilities and Tools",
      "fields": []
    },
    {
      "id": "roles_autonomy",
      "label": "Roles and Autonomy",
      "fields": [
        {
          "id": "allow_roles",
          "input": "multi_select",
          "options": [
            {"value": "analyst", "label": "Analyst"},
            {"value": "orchestrator", "label": "Orchestrator"}
          ]
        }
      ]
    }
  ],
  "deferred": {
    "advanced_editor": true,
    "unsupported_fields_hidden": true
  }
}
```

#### `GET /api/v1/config/personas/{name}`

Return a single persona record with picker-friendly policy details.

**Response `200`**

```json
{
  "name": "caelum",
  "display_name": "Caelum",
  "description": "A calm companion.",
  "model": "anthropic/claude-sonnet-4-20250514",
  "is_default": true,
  "allowed_roles": ["analyst", "orchestrator"],
  "role_restrictions": {
    "allow": ["orchestrator", "analyst"],
    "deny": []
  },
  "has_role_restrictions": true,
  "preferences": {
    "is_valid": true,
    "validation_errors": [],
    "features": {
      "artifacts": true,
      "projects": true,
      "loops": true,
      "background_tasks": true
    },
    "prompt_sections": {
      "soul": true,
      "backstory": true,
      "context": true,
      "base": true,
      "memory": true,
      "preferences": true,
      "tools": true,
      "security": true,
      "done": true,
      "deferred_toolkits": true,
      "project_context": true
    },
    "roles": {
      "allow": ["orchestrator", "analyst"],
      "deny": []
    },
    "labels": []
  },
  "preference_values": {
    "prompt_directives": {
      "response_style": "structured and measured"
    },
    "behavior": {
      "planning_mode": "deliberate"
    },
    "prompts": {
      "features": {
        "artifacts": true,
        "projects": true,
        "loops": true,
        "background_tasks": true
      },
      "roles": {
        "allow": ["orchestrator", "analyst"],
        "deny": []
      }
    }
  },
  "preference_document": {
    "prompt_directives": {
      "response_style": "structured and measured"
    },
    "behavior": {
      "planning_mode": "deliberate"
    },
    "prompts": {
      "features": {
        "artifacts": true,
        "projects": true,
        "loops": true,
        "background_tasks": true
      },
      "roles": {
        "allow": ["orchestrator", "analyst"],
        "deny": []
      },
      "prompt_sections": {
        "tools": true
      }
    }
  },
  "soul": "# Caelum\n\nA calm companion."
}
```

`preferences` remains the inspection-oriented summary. `preference_values` is the curated value payload intended for app-side preference editors. `preference_document` is the raw persisted preference document for clients that need to merge curated edits without dropping unsupported keys.

**Response `404`** — persona not found.

#### `GET /api/v1/personas`

App-facing alias for `GET /api/v1/config/personas`.

#### `GET /api/v1/personas/{name}`

App-facing alias for `GET /api/v1/config/personas/{name}`.

#### `POST /api/v1/personas`

Create a new persona for app onboarding and persona-first setup flows.

This first phase-1 slice supports:

- `name`: required lowercase persona slug using letters, numbers, hyphens, or underscores
- `description`: optional shortcut used to generate a default `soul.md` when `soul` is omitted
- `soul`: optional full markdown body for `soul.md`
- `backstory`: optional markdown content written to `backstory.md`
- `preferences`: optional typed preferences payload saved to `preferences.json`

Provide either `description` or `soul`.

**Request Body**

```json
{
  "name": "nova",
  "description": "A bold collaborative strategist.",
  "backstory": "## Origins\n\nBuilt for focused strategic support.",
  "preferences": {
    "behavior": {
      "planning_mode": "structured"
    },
    "prompts": {
      "features": {
        "projects": false,
        "loops": true
      }
    }
  }
}
```

**Response `201`**

Returns the same persona detail shape as `GET /api/v1/personas/{name}`. The new persona is immediately visible in subsequent list and detail requests without a server restart.

**Response `409`** — persona already exists.

**Response `400`** — invalid name, invalid JSON body, invalid preferences, or missing `description`/`soul`.

#### `PATCH /api/v1/personas/{name}`

Update an existing persona for onboarding and persona management flows.

This initial update slice supports:

- `description`: rewrites the generated `soul.md` body while preserving existing frontmatter when present
- `soul`: replaces the `soul.md` body and preserves existing frontmatter unless the new markdown already includes its own frontmatter
- `backstory`: string to write `backstory.md`, or `null` to remove it
- `preferences`: typed preferences object to write `preferences.json`, or `null` to remove it

Persona renaming is not supported by this endpoint yet.

**Request Body**

```json
{
  "description": "A calmer guide for long-running conversations.",
  "backstory": "## Revisions\n\nUpdated during onboarding.",
  "preferences": {
    "prompts": {
      "features": {
        "projects": false,
        "loops": true
      }
    }
  }
}
```

**Response `200`**

Returns the same persona detail shape as `GET /api/v1/personas/{name}`.

**Response `400`** — invalid JSON, invalid preferences, unsupported rename attempt, or no supported fields.

**Response `404`** — persona not found.

#### `DELETE /api/v1/personas/{name}`

Delete a persona directory that is no longer needed.

This initial slice intentionally refuses to delete the configured default persona so the app cannot leave the workspace pointing at a broken default persona.

**Response `200`**

```json
{
  "deleted": true,
  "name": "trinity"
}
```

**Response `404`** — persona not found.

**Response `409`** — the requested persona is still the configured default persona.

> **Breaking change:** the persona-scoped backstory source-management routes (`GET`/`PUT`/`DELETE /api/v1/personas/{name}/backstory/entries`, `POST /api/v1/personas/{name}/backstory/folders`, and `GET /api/v1/personas/{name}/backstory`) have been removed from core. Source-file backstory generation now lives in the optional `coqui-toolkit-backstory` package. `PATCH /api/v1/personas/{name}` above still accepts a `backstory` field that writes `backstory.md` content directly.

#### `GET /api/v1/config/models`

Lists all configured models with resolved metadata. Results are enriched from saved model metadata and Coqui's shared fallback resolver.

**Response `200`**

```json
{
  "models": [
    {
      "provider": "openai",
      "id": "openai/gpt-5",
      "name": "gpt-5",
      "reasoning": false,
      "input": ["text"],
      "contextWindow": 272000,
      "maxTokens": 8192,
      "family": "gpt",
      "toolCalls": true,
      "vision": false,
      "thinking": false,
      "metadataSource": "provider-api"
    },
    {
      "provider": "anthropic",
      "id": "anthropic/claude-sonnet-4-20250514",
      "name": "claude-sonnet-4-20250514",
      "reasoning": true,
      "input": ["text"],
      "contextWindow": 200000,
      "maxTokens": 16000,
      "family": "claude",
      "toolCalls": true,
      "vision": false,
      "thinking": false,
      "metadataSource": "provider-api"
    }
  ],
  "count": 2,
  "primary": "openai/gpt-5"
}
```

### Credentials

Credential values are **never** returned by the API. Only key names and existence are exposed.

#### `GET /api/v1/credentials`

List all stored credential keys.

**Response `200`**

```json
{
  "credentials": [
    {
      "key": "OPENAI_API_KEY",
      "is_set": true
    },
    {
      "key": "BRAVE_API_KEY",
      "is_set": true
    }
  ],
  "count": 2
}
```

#### `POST /api/v1/credentials`

Set or update a credential. The value is stored in the workspace `.env` file and made available immediately via `putenv()`.

**Request Body**

```json
{
  "key": "BRAVE_API_KEY",
  "value": "BSA1234567890abcdef"
}
```

| Field | Type | Required | Validation |
|-------|------|----------|------------|
| `key` | string | Yes | Must be `UPPER_SNAKE_CASE` (e.g. `MY_API_KEY`) |
| `value` | string | Yes | The credential value |

**Response `201`**

```json
{
  "key": "BRAVE_API_KEY",
  "set": true
}
```

**Response `400`**

```json
{
  "error": "Invalid key format. Use UPPER_SNAKE_CASE (e.g. MY_API_KEY)",
  "code": "invalid_format"
}
```

#### `DELETE /api/v1/credentials/{key}`

Delete a credential.

**Response `200`**

```json
{
  "key": "BRAVE_API_KEY",
  "deleted": true
}
```

**Response `404`**

```json
{
  "error": "Credential not found",
  "code": "credential_not_found"
}
```

#### `GET /api/v1/credentials/requirements`

List all credential requirements declared by installed toolkit packages. Returns each credential's metadata (name, description, whether it's optional) merged with its current set-status.

This endpoint enables onboarding wizards and management UIs to show users which credentials are needed, where to obtain them, and which ones are already configured.

**Response `200`**

```json
{
  "requirements": [
    {
      "key": "OPENAI_API_KEY",
      "description": "OpenAI API key — get one at https://platform.openai.com/api-keys",
      "optional": false,
      "is_set": true
    },
    {
      "key": "BRAVE_SEARCH_API_KEY",
      "description": "Brave Search API key — free at https://brave.com/search/api/",
      "optional": false,
      "is_set": false
    },
    {
      "key": "GITHUB_TOKEN",
      "description": "Optional GitHub personal access token for enhanced rate limits",
      "optional": true,
      "is_set": false
    }
  ],
  "count": 3
}
```

| Field | Type | Description |
|-------|------|-------------|
| `key` | string | Environment variable name (UPPER_SNAKE_CASE) |
| `description` | string | Human-readable description including where to obtain the credential |
| `optional` | boolean | When `true`, missing credential does not block tool execution |
| `is_set` | boolean | Whether the credential is currently configured on the instance |

### Child Runs

Child runs track sub-agent (delegated) invocations within a session. A child run is a first-class, typed resource (`child-run.json`): it carries `role`, nullable `model` (null ⇒ inherit the parent's model), `prompt`, nullable `result`, a closed-set `status`, the split token triad (`prompt_tokens` / `completion_tokens` / `total_tokens`), `created_at`, and nullable `completed_at`.

`status` is one of `pending`, `running`, `completed`, `failed`, `cancelled`.

#### `GET /api/v1/sessions/{id}/child-runs`

List all child runs for a session, each as a `child-run.json` resource.

**Response `200`**

```json
{
  "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "child_runs": [
    {
      "id": "cr1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6",
      "parent_session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
      "parent_turn_id": null,
      "role": "coder",
      "model": "anthropic/claude-sonnet-4-20250514",
      "prompt": "Implement the ServerHandler class...",
      "result": "I've created the ServerHandler class...",
      "status": "completed",
      "prompt_tokens": 120,
      "completion_tokens": 45,
      "total_tokens": 165,
      "created_at": "2026-02-16T14:32:15Z",
      "completed_at": "2026-02-16T14:32:19Z"
    }
  ],
  "count": 1
}
```

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `404` | `session_not_found` | Session does not exist |

#### `POST /api/v1/sessions/{id}/child-runs`

Spawn a child run. This is a **gated Core operation**: only a top-level, full-access orchestrator session may spawn a child. Any other session (a delegated/child role, or a non-full access level) is rejected `403 forbidden` — children do not nest.

The child runs **synchronously** (sync-execute-then-report): the row is recorded `running`, the child is executed via the in-process child-agent path, and the terminal `completed`/`failed` transition (with `completed_at` and the token triad) is recorded before the response is written. The response is therefore `202` with an already-terminal `child-run.json` resource. A child that fails during execution still returns `202` with `status: "failed"` — the failure is captured in the resource, not surfaced as an HTTP error.

> **Note:** the HTTP-spawned child is **text-only** — it runs with its provider, role, and prompt but **no toolkits** (no filesystem, shell, edit, or memory tools). Its `result` is the model's response to the prompt, not the outcome of tool-driven work. This differs from the in-agent `spawn_agent` tool, whose child is granted an access-level toolkit graph. Toolkit-equipped HTTP child runs are out of scope for this endpoint's current revision.

**Request Body**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `role` | string | Yes | The child role to spawn (e.g. `coder`, `reviewer`). Resolved to a model via the role resolver. |
| `prompt` | string | Yes | The task prompt handed to the child. |

```json
{
  "role": "coder",
  "prompt": "Implement the ServerHandler class..."
}
```

**Response `202`** — a `child-run.json` resource (see the list example above) with a terminal `status`.

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `400` | `missing_field` | `role` or `prompt` is missing/empty |
| `403` | `forbidden` | The session is not a top-level, full-access orchestrator session |
| `404` | `session_not_found` | Session does not exist |

#### `GET /api/v1/sessions/{id}/child-runs/{childRunId}`

Fetch a single child run as a `child-run.json` resource.

**Response `200`** — the `child-run.json` resource.

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `404` | `session_not_found` | Session does not exist |
| `404` | `not_found` | No child run with that id belongs to the session |

### Server

Server endpoints provide runtime status, restart-state visibility, and database-level statistics. These are useful for monitoring and debugging the API server.

#### `GET /api/v1/server/info`

Runtime information including version, uptime, memory usage, and active workload.

**Response `200`**

```json
{
  "version": "0.5.0",
  "php_version": "8.4.2",
  "uptime_seconds": 3621,
  "active_sessions": 2,
  "restart": {
    "required": false,
    "reason": null,
    "source": null,
    "required_at": null,
    "context": [],
    "supported": true,
    "managed_by_launcher": true,
    "pid": 21093,
    "started_at": "2026-04-22T20:59:23Z"
  },
  "memory": {
    "usage_bytes": 52428800,
    "peak_bytes": 67108864
  },
  "tasks": {
    "active": 1,
    "pending": 0
  }
}
```

The `tasks` field is only present when the background task manager is enabled.

#### `POST /api/v1/server/restart`

Request a launcher-managed API restart. This endpoint is accepted only when the API process was started under `coqui` with restart support enabled.

**Response `202`**

```json
{
  "accepted": true,
  "restart": {
    "required": true,
    "reason": "API restart requested by operator.",
    "source": "api.server.restart",
    "required_at": "2026-04-22T20:59:23Z",
    "context": [],
    "supported": true,
    "managed_by_launcher": true,
    "pid": 16819,
    "started_at": "2026-04-22T20:58:24Z"
  }
}
```

When the process is not launcher-managed, the endpoint returns `409 restart_not_supported`.

#### `GET /api/v1/server/stats`

Database-level statistics from SQLite.

**Response `200`**

```json
{
  "database": {
    "sessions": 42,
    "messages": 1580,
    "turns": 210,
    "audit_entries": 890,
    "db_size_bytes": 2097152
  },
  "tables": {
    "ok": true,
    "missing": []
  }
}
```

The `tables` field validates that all expected database tables exist. If any are missing, `ok` is `false` and the table names are listed in `missing`.

### Projects

A project is a lightweight named working scope backed by a workspace directory (`projects/<slug>-<id>/`). Projects organize work across sessions, loops, and tasks. Sessions track an `active_project_id`, and loops resolve or auto-create a project for their run.

#### `GET /api/v1/projects`

List projects, optionally filtered by status.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `status` | string | `null` | Filter by `active`, `completed`, or `archived` |
| `limit` | int | `50` | Max projects to return (capped at 200) |

#### `POST /api/v1/projects`

Create a new project.

**Request Body**

```json
{
  "title": "Career Ops",
  "slug": "career-ops",
  "description": "Career workflow system"
}
```

**Response `201`**

```json
{
  "project": {
    "id": "proj_123",
    "title": "Career Ops",
    "slug": "career-ops",
    "status": "active"
  }
}
```

#### `GET /api/v1/projects/{idOrSlug}`

Get a project by ID or slug.

#### `PATCH /api/v1/projects/{idOrSlug}`

Update project fields.

Supported fields:

- `title`
- `description`
- `status`

**Response `200`**

Returns the same detail payload as `GET /api/v1/projects/{idOrSlug}`.

#### `DELETE /api/v1/projects/{idOrSlug}`

Permanently delete an archived project.

Projects must be archived before deletion. Deletion clears any session `active_project_id` references pointing at that project.

**Response `200`**

```json
{
  "deleted": true,
  "id": "proj_123"
}
```

#### `POST /api/v1/projects/{idOrSlug}/archive`

Archive a project.

**Response `200`**

Returns the same detail payload as `GET /api/v1/projects/{idOrSlug}` with `project.status = "archived"`.

#### `POST /api/v1/projects/{idOrSlug}/activate`

Activate a project.

**Response `200`**

Returns the same detail payload as `GET /api/v1/projects/{idOrSlug}` with `project.status = "active"`.

### Background Tasks

Background tasks run long-running agent work in separate processes. Each task gets its own dedicated session and runs via `bin/coqui task:run`. Tasks are managed by the `BackgroundTaskManager` which handles process lifecycle, concurrency limits, and crash recovery.

Task execution depends on the API server process being up and healthy. The REPL can create and monitor tasks, but it does not execute them itself.

For architecture details, see [BACKGROUND-TASKS.md](BACKGROUND-TASKS.md).

#### `POST /api/v1/tasks`

Create a new background task. The task is started immediately if under the concurrency limit, otherwise queued as pending.

**Request Body**

```json
{
  "prompt": "Refactor the authentication module",
  "role": "coder",
  "persona": "caelum",
  "title": "Auth refactor",
  "parent_session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "max_iterations": 25,
  "project_id": "p1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `prompt` | string | Yes | — | The task prompt (max 1 MiB) |
| `role` | string | No | `"orchestrator"` | Agent role for the task. Must be a known role. |
| `persona` | string | No | `null` | Explicit persona for the task session. Must exist under `personas/{name}/soul.md`. If `parent_session_id` is provided, it must match the parent session persona. |
| `title` | string | No | `null` | Human-readable title for the task |
| `parent_session_id` | string | No | `null` | Link the task to a parent session (must exist). The task inherits that session's persona when one is set. |
| `max_iterations` | int | No | `25` | Maximum agent iterations (1–100) |
| `project_id` | string | No | `null` | Attach the task to an existing project |

When `parent_session_id` is provided, it must refer to a writable session. Closed or archived parent sessions return `409 session_closed`.

**Response `201`**

```json
{
  "id": "t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "session_id": "s1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "status": "running",
  "prompt": "Refactor the authentication module",
  "role": "coder",
  "persona": "caelum",
  "title": "Auth refactor",
  "project_id": "p1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "created_at": "2026-02-16T14:30:00+00:00"
}
```

The `status` field is `"running"` if the task started immediately, or `"pending"` if queued due to the concurrency limit.

**Response `400`** — missing prompt:

```json
{
  "error": "Missing or empty \"prompt\" field",
  "code": "missing_field"
}
```

**Response `404`** — unknown role:

```json
{
  "error": "Unknown role \"nonexistent\". Use GET /api/v1/config/roles to see available roles.",
  "code": "role_not_found"
}
```

#### `GET /api/v1/tasks`

List background tasks, optionally filtered by status.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `status` | string | `null` | Filter by status: `pending`, `running`, `completed`, `failed`, `cancelled` |
| `limit` | int | `50` | Max tasks to return (capped at 200) |

**Response `200`**

```json
{
  "tasks": [
    {
      "id": "t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
      "session_id": "s1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
      "status": "running",
      "prompt": "Refactor the authentication module",
      "role": "coder",
      "title": "Auth refactor",
      "created_at": "2026-02-16T14:30:00+00:00"
    }
  ],
  "count": 1,
  "counts": {
    "pending": 0,
    "running": 1,
    "completed": 5,
    "failed": 0,
    "cancelled": 1
  }
}
```

#### `GET /api/v1/tasks/{id}`

Get detailed information about a specific task, including live process status.

**Response `200`**

```json
{
  "id": "t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "session_id": "s1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "status": "running",
  "prompt": "Refactor the authentication module",
  "role": "coder",
  "title": "Auth refactor",
  "project_id": "p1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "process_alive": true,
  "created_at": "2026-02-16T14:30:00+00:00",
  "completed_at": null
}
```

The `process_alive` field indicates whether the task's child process is still running.

**Response `404`**

```json
{
  "error": "Task not found",
  "code": "not_found"
}
```

#### `GET /api/v1/tasks/{id}/events`

Stream task lifecycle events via Server-Sent Events. The stream uses long-polling (1-second interval) and closes automatically when the task reaches a terminal state (`completed`, `failed`, or `cancelled`).

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `since_id` | int | `null` | Resume from a specific event ID (for fault tolerance) |

**Response `200`** (SSE stream)

```
event: connected
data: {"task_id":"t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6"}

id: 1
event: iteration
data: {"number":1}

id: 2
event: tool_call
data: {"tool":"read_file","args":{"path":"src/Auth.php"}}

id: 3
event: tool_result
data: {"tool":"read_file","success":true}

event: done
data: {"status":"completed"}
```

The stream supports resumption — if the client disconnects and reconnects with `?since_id=3`, only events after ID 3 are sent.

**Response `404`**

```json
{
  "error": "Task not found",
  "code": "not_found"
}
```

#### `POST /api/v1/tasks/{id}/input`

Inject user input into a running task's conversation. The input is queued and consumed by the task process on its next iteration. Only works for tasks with status `running`.

**Request Body**

```json
{
  "content": "Focus on the login handler first"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `content` | string | Yes | The input text to inject (cannot be empty, max 1 MiB) |

**Response `201`**

```json
{
  "id": "i1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "task_id": "t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "content": "Focus on the login handler first",
  "status": "queued"
}
```

**Response `409`** — task not running:

```json
{
  "error": "Cannot add input to task with status \"completed\" — task must be running",
  "code": "conflict"
}
```

#### `POST /api/v1/tasks/{id}/cancel`

Cancel a running or pending task. Running tasks receive `SIGTERM`; pending tasks are cancelled immediately.

**Response `200`**

```json
{
  "id": "t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "status": "cancelling",
  "message": "Cancellation signal sent"
}
```

**Response `409`** — task already in terminal state:

```json
{
  "error": "Task already in terminal state \"completed\"",
  "code": "conflict"
}
```

**Concurrency Configuration**

The maximum number of concurrent background tasks is configurable via `openclaw.json`:

```json
{
  "api": {
    "tasks": {
      "maxConcurrent": 1
    }
  }
}
```

Tasks exceeding the concurrency limit are queued as `pending` and started automatically when a slot becomes available.

### Artifacts

Artifacts are versioned content objects scoped to a session. They support a lifecycle (`draft` → `review` → `final`) and are used for structured planning, code generation, and handoff between roles.

Session-scoped artifact creation and mutation are available through the HTTP API. Closed or archived sessions still reject artifact writes with `409 session_closed`.

#### `POST /api/v1/sessions/{id}/artifacts`

Create a new artifact in a session.

**Request Body**

```json
{
  "title": "Database migration plan",
  "content": "## Steps\n1. ...",
  "type": "plan",
  "stage": "draft",
  "language": "markdown",
  "project_id": "proj_123",
  "tags": ["database", "migration"],
  "summary": "Initial migration rollout plan"
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `title` | string | Yes | — | Artifact title |
| `content` | string | Yes | — | Initial artifact content |
| `type` | string | No | `"code"` | Artifact category |
| `stage` | string | No | `"draft"` | `draft`, `review`, or `final` |
| `language` | string | No | `null` | Optional language or format hint |
| `filepath` | string | No | `null` | Optional workspace path |
| `metadata` | object | No | `{}` | Base metadata object |
| `tags` | array | No | — | Convenience shorthand for `metadata.tags` |
| `summary` | string | No | — | Convenience shorthand for `metadata.summary` |
| `project_id` | string | No | `null` | Link the artifact to a project |
| `persistent` | boolean | No | `false` | Keep the artifact outside normal session cleanup |

**Response `201`**

Returns the full current artifact object.

#### `GET /api/v1/sessions/{id}/artifacts`

List artifacts for a session with optional filters.

**Query Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `type` | string | Filter by artifact type (e.g. `code`, `plan`, `document`) |
| `stage` | string | Filter by lifecycle stage (e.g. `draft`, `review`, `final`) |

**Response `200`**

```json
{
  "artifacts": [
    {
      "id": "art_1a2b3c4d",
      "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
      "title": "Database migration plan",
      "type": "plan",
      "stage": "draft",
      "language": null,
      "filepath": null,
      "metadata": null,
      "version": 1,
      "created_by": "plan",
      "created_at": "2026-02-16T14:30:00Z",
      "updated_at": "2026-02-16T14:35:00Z"
    }
  ],
  "count": 1
}
```

#### `GET /api/v1/sessions/{id}/artifacts/{artifactId}`

Get a specific artifact with its current content.

**Response `200`**

Returns the full artifact object including content.

**Response `404`**

```json
{
  "error": "Artifact not found",
  "code": "not_found"
}
```

#### `PATCH /api/v1/sessions/{id}/artifacts/{artifactId}`

Patch artifact metadata, stage, links, or content.

If `content` is included, the artifact's content is replaced and its `version` counter is bumped (for filesystem-backed artifacts the canonical file is rewritten). Without `content`, only metadata changes.

**Request Body**

```json
{
  "title": "Database migration plan v2",
  "content": "## Updated Steps\n1. ...",
  "change_summary": "Added rollback notes",
  "stage": "review",
  "tags": ["database", "migration", "rollback"],
  "summary": "Expanded rollout plan"
}
```

**Response `200`**

Returns the full current artifact object.

#### `DELETE /api/v1/sessions/{id}/artifacts/{artifactId}`

Delete an artifact (and its canonical file, if filesystem-backed).

**Response `200`**

```json
{
  "deleted": true,
  "id": "art_1a2b3c4d"
}
```

### Questions

Structured questions let an agent ask the user one validated question via the `ask_user` tool. On an interactive API turn the question is streamed as a `question` SSE event (see the [message-send endpoint](#post-apiv1sessionsidmessages) SSE event table) and the turn suspends until it is answered over these endpoints; in a `block`-mode loop the question escalates the loop to `blocked` (see [LOOPS.md](LOOPS.md)). These routes are **CORE authenticated** (never public) and never behind the optional `questions` profile. See [QUESTIONS.md](QUESTIONS.md) for the full feature guide.

#### `GET /api/v1/sessions/{id}/questions`

List the session's **pending** (unanswered) questions.

**Response `200`**

```json
{
  "questions": [
    {
      "id": "q_ab12cd",
      "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
      "request": {
        "id": "q_ab12cd",
        "prompt": "Overwrite the existing config file?",
        "format": "single_select",
        "options": [{"label": "Yes"}, {"label": "No"}],
        "allow_other": false,
        "suggested": {"selected": ["No"], "text": null},
        "header": null
      },
      "responder_kind": "suspending",
      "status": "pending",
      "answer": null,
      "loop_id": null,
      "stage_id": null
    }
  ]
}
```

#### `POST /api/v1/sessions/{id}/questions/{questionId}/answer`

Answer a pending question. The body carries the answer in the shape the question expects: `selected` labels for selects, `text` for free-text (or the "Other" value when `allow_other` is set).

**Request Body**

```json
{
  "selected": ["No"],
  "text": null
}
```

| Field | Type | Description |
|-------|------|-------------|
| `selected` | string[] | Chosen option labels (empty for free-text / Other). |
| `text` | string | Free-text answer, or the "Other" value on a select. |

**Response `200`**

```json
{ "answered": true }
```

Answering a `block`-mode loop question reopens the blocked loop stage (the escalation is cleared, the rework breaker is reset, and the answer is injected into the reopened stage prompt).

**Error responses**

| Status | Code | Meaning |
|--------|------|---------|
| `404` | `question_not_found` | Question not found in this session. |
| `409` | `conflict` | Question already answered (no longer pending). |
| `422` | `question_invalid_answer` | Answer is not valid for this question (fails `QuestionResponse::isValidFor`). |

#### `POST /api/v1/sessions/{id}/turns/{turnId}/answer`

Answer the pending question raised by an interactive turn — the **Core answer path** a client reaches after receiving a `question` SSE event. The event's `data.question_id` correlates the frame to the pending question; the client answers by posting the answer body to the turn. This route is CORE (never behind the optional `questions` profile), so answering a turn's blocking question never requires that capability.

The request body is identical to the question-id answer endpoint above (`selected` / `text`), and both endpoints share a single validate-and-record path.

**Request Body**

```json
{
  "selected": ["No"],
  "text": null
}
```

**Response `200`**

```json
{ "answered": true }
```

**Error responses**

| Status | Code | Meaning |
|--------|------|---------|
| `404` | `not_found` | No question is pending for this turn. |
| `409` | `conflict` | The turn's question is already answered. |
| `422` | `validation_error` | Answer is not valid for this question (fails `QuestionResponse::isValidFor`). |

### Schedules

Schedules enable autonomous, timer-driven execution via cron-style expressions. The API server evaluates due schedules every 60 seconds and creates background tasks automatically. A circuit breaker auto-disables schedules after consecutive failures.

Schedule evaluation only happens inside the API server event loop. If the API server is not running, schedules remain persisted but no due work is dispatched.

Filesystem-backed schedules synced from `workspace/schedules/*.json` remain read-only from mutation endpoints. When a schedule comes from a JSON file, update, delete, enable, disable, and trigger requests return `409 conflict` with the source path.

#### `POST /api/v1/schedules`

Create a schedule.

The request body is the strict CAP authoring shape: `name`, `cron`, `persona_id`, and a kind-discriminated `action`. Any other field is rejected `422 validation_error`. `action` is a typed union:

- `{"kind": "turn", "prompt": "..."}` — dispatch a one-shot turn each tick.
- `{"kind": "loop", "definition_name": "research"}` — dispatch a loop definition each tick.

A `loop` action without `definition_name`, a `turn` action without `prompt`, or an unknown `kind` is rejected `422 validation_error`.

**Request Body**

```json
{
  "name": "daily-review",
  "cron": "0 9 * * 1-5",
  "persona_id": "01J00000000000000000000P01",
  "action": { "kind": "turn", "prompt": "Review recent changes." }
}
```

**Response `201`** — the created ScheduledTask object.

```json
{
  "id": "a1b2c3d4",
  "name": "daily-review",
  "cron": "0 9 * * 1-5",
  "persona_id": "01J00000000000000000000P01",
  "action": { "kind": "turn", "prompt": "Review recent changes." },
  "status": "enabled",
  "last_run_at": null,
  "next_run_at": "2026-02-17T09:00:00Z",
  "created_at": "2026-02-10T12:00:00Z",
  "updated_at": "2026-02-10T12:00:00Z"
}
```

The scheduler advertises the cron dialect it parses (a standard 5-field POSIX/Vixie expression) via instance-info `schedules.dialect: "posix-5field"` so a client can judge whether a `cron` string is portable.

#### `GET /api/v1/schedules`

List all schedules with optional filters.

**Query Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `enabled` | `0` or `1` | Filter by enabled/disabled status |
| `created_by` | string | Filter by creator (e.g. `"agent"`, `"api"`) |

**Response `200`**

Each item is a ScheduledTask object. Results are cursor-paginated (`items` / `next_cursor`).

```json
{
  "items": [
    {
      "id": "a1b2c3d4",
      "name": "daily-review",
      "cron": "0 9 * * 1-5",
      "persona_id": "01J00000000000000000000P01",
      "action": { "kind": "turn", "prompt": "Review recent changes." },
      "status": "enabled",
      "last_run_at": "2026-02-16T09:00:00Z",
      "next_run_at": "2026-02-17T09:00:00Z",
      "created_at": "2026-02-10T12:00:00Z",
      "updated_at": "2026-02-16T09:01:00Z"
    }
  ],
  "next_cursor": null,
  "stats": {
    "total": 3,
    "enabled": 2,
    "disabled": 1,
    "total_runs": 42
  }
}
```

#### `GET /api/v1/schedules/upcoming`

List enabled schedules that are due within a bounded window.

**Query Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `hours` | integer | Look-ahead window in hours. Defaults to `24`, max `720`. |

**Response `200`**

```json
{
  "schedules": [
    {
      "id": "a1b2c3d4",
      "name": "daily-review",
      "next_run_at": "2026-02-17T09:00:00Z",
      "enabled": 1
    }
  ],
  "count": 1,
  "hours": 24
}
```

#### `GET /api/v1/schedules/stats`

Return aggregate schedule counts without fetching the full schedule list.

**Response `200`**

```json
{
  "total": 3,
  "enabled": 2,
  "disabled": 1,
  "total_runs": 42
}
```

#### `GET /api/v1/schedules/{id}`

Get a schedule by ID.

**Response `200`** — full schedule object.

**Response `404`** — schedule not found.

#### `GET /api/v1/schedules/{id}/runs`

List recent background task runs triggered by a schedule.

**Query Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `limit` | integer | Maximum runs to return. Defaults to `20`, max `100`. |

**Response `200`**

```json
{
  "schedule": {
    "id": "a1b2c3d4",
    "name": "daily-review"
  },
  "runs": [
    {
      "id": "task_123",
      "session_id": "sess_123",
      "status": "completed",
      "title": "Weekday review",
      "result": "Review complete",
      "error": null,
      "metadata": {
        "source": "schedule"
      },
      "created_at": "2026-02-17T09:00:00Z",
      "completed_at": "2026-02-17T09:01:00Z"
    }
  ],
  "count": 1,
  "counts": {
    "completed": 1
  }
}
```

**Response `404`** — schedule not found.

#### `PATCH /api/v1/schedules/{id}`

Update a mutable schedule (partial). The strict CAP patch shape accepts any subset of `name`, `cron`, `persona_id`, `action`, and `status` (`enabled` | `disabled`); at least one field is required. Any other field is rejected `422 validation_error`. When `action` is supplied it is re-validated as the kind-discriminated union. `status` routes through the same enable/disable path that recomputes `next_run_at` (the dedicated action endpoints below remain available).

**Request Body**

```json
{
  "cron": "0 10 * * 1-5",
  "action": { "kind": "loop", "definition_name": "research" }
}
```

**Response `200`** — the updated ScheduledTask object.

**Response `409`** — filesystem-backed schedule.

#### `DELETE /api/v1/schedules/{id}`

Delete a mutable schedule.

**Response `200`**

```json
{
  "deleted": true
}
```

**Response `409`** — filesystem-backed schedule.

#### `POST /api/v1/schedules/{id}/enable`

Enable a mutable schedule and recompute its next run time.

**Response `200`** — updated schedule object.

#### `POST /api/v1/schedules/{id}/disable`

Disable a mutable schedule.

**Response `200`** — updated schedule object.

#### `POST /api/v1/schedules/{id}/trigger`

Force a mutable schedule to run on the next scheduler tick.

**Response `200`**

```json
{
  "schedule": {
    "id": "a1b2c3d4",
    "name": "daily-review",
    "next_run_at": "2026-02-16T09:15:00Z"
  },
  "message": "Schedule will fire on the next API scheduler tick."
}
```

**Response `409`** — filesystem-backed schedule.

### Loops

Loops are fully automated, multi-iteration workflows that string together existing agent roles in sequence. Each role processes the output of the previous one, repeating until a termination condition is met.

Loop stage advancement only happens while the API server is running. `LoopManager` advances stages asynchronously and spawns the background tasks that execute them.

#### `POST /api/v1/loops`

Create and start a loop.

Use `session_id` when the loop should inherit the session's active project and downstream persona context. Use `project_id` or `project_slug` to pin the loop to a project directly. When no project is supplied, the loop resolves or auto-creates one for its run.

When `session_id` is provided, it must refer to a writable session. Closed or archived sessions return `409 session_closed`.

**Headless start.** Omit `session_id` (send just `{definition, goal}`, optionally a project) to start a loop with no conversation. Coqui auto-provisions a hidden, loop-owned work-scope session whose active project is the loop's project, so cross-stage artifacts stay project-scoped exactly as they do for chat-started loops. Such loops record `metadata.origin: "headless"` (vs `"conversation"` when a `session_id` is supplied); see the `origin`/`headless` fields on the loop read endpoints below.

**Request Body**

```json
{
  "definition": "harness",
  "goal": "Implement loop lifecycle endpoints",
  "session_id": "sess_123",
  "parameters": {
    "subject": "loop lifecycle API"
  },
  "max_iterations": 3
}
```

**Response `201`**

```json
{
  "loop": {
    "id": "abc123",
    "definition_name": "harness",
    "goal": "Implement loop lifecycle endpoints",
    "session_id": "sess_123",
    "project_id": "proj_123",
    "status": "running",
    "current_iteration": 1,
    "current_stage": 0,
    "max_iterations": 3,
    "metadata": {
      "dispatch": {
        "status": "pending"
      }
    }
  },
  "iteration": {
    "id": "iter123",
    "loop_id": "abc123",
    "iteration_number": 1,
    "status": "running"
  },
  "stages": [
    {
      "id": "stage123",
      "iteration_id": "iter123",
      "stage_index": 0,
      "role": "plan",
      "status": "pending"
    }
  ]
}
```

#### `GET /api/v1/loops`

List all loops with optional status filter.

**Query Parameters**

| Parameter | Type | Description |
| --- | --- | --- |
| `status` | string | Filter by status: `running`, `paused`, `completed`, `failed`, `cancelled` |
| `headless` | boolean | Filter by origin: `true`/`1` returns only headless loops, `false`/`0` only conversation loops |

Each returned loop carries a derived `headless` boolean (`true` when the loop was started with no conversation session).

**Response `200`**

```json
{
  "loops": [
    {
      "id": "abc123",
      "definition": "harness",
      "goal": "Implement a new caching layer",
      "status": "running",
      "current_iteration": 2,
      "current_stage": 1,
      "headless": false,
      "created_at": "2026-02-16T14:00:00Z",
      "updated_at": "2026-02-16T14:30:00Z"
    }
  ],
  "count": 1,
  "active": 1
}
```

#### `GET /api/v1/loops/active/count`

Return the number of currently running loops.

**Response `200`**

```json
{
  "active": 1
}
```

#### `GET /api/v1/loops/definitions`

List available loop definitions.

**Response `200`**

```json
{
  "definitions": [
    {
      "name": "harness",
      "builtin": true,
      "description": "Generator-evaluator pattern",
      "parameters": [
        {
          "name": "subject",
          "description": "What to work on",
          "required": true
        }
      ],
      "roles": [
        {
          "role": "plan",
          "prompt": "Plan work for {{subject}}.",
          "max_iterations": null
        },
        {
          "role": "reviewer",
          "prompt": "Review progress for {{subject}}.",
          "max_iterations": null
        }
      ],
      "termination": {
        "type": "evaluation_bound",
        "value": {
          "criteria": "Explicit approval required",
          "max_review_rounds": 4
        }
      }
    }
  ],
  "count": 1
}
```

Each entry's `builtin` flag is `true` when a definition of that name ships in
`config/loops/` (seeded into the workspace on boot). Built-ins are editable and
deletable like any other definition; a deleted built-in re-seeds on the next
boot.

#### `GET /api/v1/loops/definitions/{name}`

Get one loop definition. The response is the stored authoring file plus a
server-assigned `version` token (the on-disk file never stores `version`).

**Response `200`** — the definition object, including `version`.

**Errors**

- `400 validation_error` — `{name}` is not a valid definition name.
- `404 not_found` — no definition with that name exists.

`{name}` must match `^[a-z0-9][a-z0-9_-]*$` (the name becomes a filename, so
this is a strict path-traversal guard).

#### `PUT /api/v1/loops/definitions/{name}`

The single write path for a loop definition. It branches on the optimistic-
concurrency precondition headers — a precondition is mandatory:

- `If-None-Match: *` — **create**; fails with `409 conflict` if a definition
  with that name already exists.
- `If-Match: <version>` — **update**; fails with `409 version_conflict` if the
  stored `version` differs, or `404 content_not_found` if it does not exist.
- neither header — `409 conflict` (a precondition is required).

The path `{name}` is authoritative and becomes the filename
(`workspace/loops/{name}.json`). The body is a strict authoring shape: the
server-owned `version` is assigned on create and incremented on each update, so
a body carrying `version` (or any other server-owned field) is rejected.

**Request**

```json
{
  "name": "my-loop",
  "description": "My custom loop",
  "roles": [
    { "role": "plan", "prompt": "Plan work for {{subject}}." }
  ],
  "termination_condition": { "type": "iteration_bound", "value": 3 }
}
```

**Response `201`** (create) or `200` (update) — the stored definition, including
its current `version`.

**Errors**

- `422 validation_error` — invalid `{name}`, an invalid structure (e.g. empty
  `roles`, missing `termination_condition`), or a body carrying a server-owned
  field such as `version`.
- `409 conflict` — the definition already exists (on create), or no precondition
  header was supplied.
- `409 version_conflict` — the `If-Match` version does not match the stored one.
- `404 content_not_found` — `If-Match` update of a definition that does not exist.

#### `DELETE /api/v1/loops/definitions/{name}`

Delete a loop definition file. Deleting a built-in removes it from the
workspace; it re-seeds from `config/loops/` on the next boot.

**Response `200`**

```json
{ "deleted": true, "name": "my-loop" }
```

**Errors**

- `400 validation_error` — invalid `{name}`.
- `404 not_found` — no definition with that name exists.

#### `GET /api/v1/loops/{id}/history`

Get the full iteration timeline for a loop, including stage-level results.

**Response `200`**

```json
{
  "loop": {
    "id": "abc123",
    "status": "completed"
  },
  "history": [
    {
      "id": "iter123",
      "iteration_number": 1,
      "status": "needs_rework",
      "duration_seconds": 120,
      "stage_count": 2,
      "completed_stage_count": 1,
      "stages": [
        {
          "id": "stage123",
          "role": "plan",
          "status": "completed"
        },
        {
          "id": "stage124",
          "role": "reviewer",
          "status": "failed"
        }
      ]
    }
  ],
  "count": 1
}
```

**Response `404`** — loop not found.

#### `GET /api/v1/loops/{id}/metrics`

Return aggregate counts and timing summaries for a loop.

**Response `200`**

```json
{
  "loop_id": "abc123",
  "status": "completed",
  "current_iteration": 2,
  "duration_seconds": 300,
  "iterations": {
    "total": 2,
    "by_status": {
      "needs_rework": 1,
      "completed": 1
    }
  },
  "stages": {
    "total": 4,
    "by_status": {
      "completed": 3,
      "failed": 1
    },
    "by_role": {
      "plan": 2,
      "reviewer": 2
    }
  },
  "timings": {
    "total_iteration_seconds": 240,
    "average_iteration_seconds": 120,
    "iteration_timings": [
      {
        "iteration_number": 1,
        "duration_seconds": 120
      }
    ]
  }
}
```

**Response `404`** — loop not found.

#### `GET /api/v1/loops/{id}/live`

Return a rich, poll-friendly snapshot of an in-flight loop: where it is, what
it is running right now, how much it has consumed, a per-stage breakdown, and a
newest-first feed of recent activity. This is a read-model composed from
existing loop, task, turn, and event data — it makes no changes to the loop.

Intended for dashboards and status pollers. It is poll-based; clients refresh on
an interval. For push-based updates, pair it with the
[`GET /api/v1/loops/{id}/events`](#get-apiv1loopsidevents) SSE stream below and
refetch this snapshot on each nudge.

**Response `200`**

```json
{
  "loop": {
    "id": "abc123",
    "definition_name": "harness",
    "goal": "Implement a new caching layer",
    "status": "running",
    "project_id": null,
    "work_scope_session_id": null,
    "started_at": "2026-07-11T10:00:00Z",
    "last_activity_at": "2026-07-11T10:03:00Z",
    "completed_at": null,
    "deadline": "2026-07-11T10:10:00Z"
  },
  "position": {
    "current_iteration": 1,
    "max_iterations": 4,
    "current_stage_index": 1,
    "current_stage_role": "reviewer",
    "stages_per_iteration": 2
  },
  "current_stage": {
    "stage_id": "stage789",
    "iteration_number": 1,
    "stage_index": 1,
    "role": "reviewer",
    "model": "claude-sonnet-5",
    "status": "running",
    "task_id": "task456",
    "session_id": "sess456",
    "started_at": "2026-07-11T10:02:30Z",
    "last_heartbeat_at": "2026-07-11T10:03:00Z",
    "latest_activity": {
      "type": "tool_call",
      "summary": "Called read",
      "timestamp": "2026-07-11T10:03:00Z"
    }
  },
  "budget": {
    "tokens": { "prompt": 100, "completion": 50, "total": 150 },
    "iterations": { "used": 1, "max": 4 },
    "time": {
      "elapsed_seconds": 180,
      "deadline": "2026-07-11T10:10:00Z",
      "remaining_seconds": 420
    }
  },
  "stages": [
    {
      "iteration_number": 1,
      "stage_index": 0,
      "role": "plan",
      "model": "claude-sonnet-5",
      "status": "completed",
      "tokens": { "prompt": 100, "completion": 50, "total": 150 },
      "duration_ms": 1200,
      "tools_used": ["grep", "read"],
      "result_summary": "planned",
      "started_at": "2026-07-11T10:00:05Z",
      "completed_at": "2026-07-11T10:02:25Z",
      "task_id": "task123"
    }
  ],
  "recent_events": [
    {
      "timestamp": "2026-07-11T10:03:00Z",
      "stage_id": "stage789",
      "role": "reviewer",
      "type": "tool_call",
      "summary": "Called read"
    }
  ]
}
```

`current_stage` is `null` when no stage is currently running. Fields that have
no data yet are `null` (e.g. `model` and zeroed `tokens` for a stage whose first
turn has not completed). `time.remaining_seconds` is `null` when the loop has no
deadline.

**Response `404`** — loop not found.

#### `GET /api/v1/loops/{id}/events`

Server-Sent Events stream of lightweight nudges for a running loop, so a client can watch progress without fixed-interval polling. The stream carries **signals only** — on each nudge the client re-fetches `GET /loops/{id}/live` for the full snapshot (that endpoint stays the single source of truth).

**Response `200`** — `Content-Type: text/event-stream`. Unknown loop → `404`.

| Event | Fires when | `data` |
|---|---|---|
| `connected` | on open | `{"loop_id": "<id>"}` |
| `stage_changed` | iteration/stage advanced, or lifecycle status changed (incl. pause/resume) | `{"iteration": N, "stage_index": N, "status": "<status>"}` |
| `activity` | new task events, position unchanged (coalesced to one per tick) | `{"cursor": <max_event_id>}` |
| `done` | loop reached `completed`/`failed`/`cancelled` — stream then closes | `{"status": "<status>"}` |

Each frame carries an SSE `id:` (the activity cursor) so reconnecting clients resume via `Last-Event-ID`; a reconnect re-syncs by refetching `/live`. `role`/`model`/budget are intentionally not in the payload — read them from `/live`.

#### `GET /api/v1/loops/{id}`

Get detailed loop status including current iteration and stage information. The loop object carries `origin` (`headless` or `conversation`).

**Response `200`**

```json
{
  "loop": {
    "id": "abc123",
    "definition_name": "harness",
    "goal": "Implement a new caching layer",
    "status": "running",
    "current_iteration": 2,
    "current_stage": 1,
    "origin": "conversation",
    "configuration": "{...}",
    "metadata": {
      "dispatch": {
        "status": "running"
      }
    }
  },
  "iteration": {
    "id": "iter456",
    "loop_id": "abc123",
    "iteration_number": 2,
    "status": "running"
  },
  "stages": [
    {
      "id": "stage123",
      "iteration_id": "iter456",
      "stage_index": 0,
      "role": "plan",
      "status": "completed"
    },
    {
      "id": "stage124",
      "iteration_id": "iter456",
      "stage_index": 1,
      "role": "reviewer",
      "status": "pending"
    }
  ]
}
```

#### `PATCH /api/v1/loops/{id}`

Update operator-editable loop fields without redefining the loop configuration.

Supported fields:

- `goal`
- `max_iterations`
- `metadata`
- `labels` (stored under `metadata.labels`)

`max_iterations` may be set to `null` to clear the override. Active loops can be edited, but `max_iterations` cannot be lowered below the current iteration number.

**Request Body**

```json
{
  "goal": "Ship loop edit and delete support",
  "max_iterations": 4,
  "metadata": {
    "dispatch": {
      "operator_note": "Keep the patch scope narrow."
    }
  },
  "labels": ["backend", "app-api"]
}
```

**Response `200`**

Returns the same normalized loop state payload as `GET /api/v1/loops/{id}`.

**Response `409`**

Returned when `max_iterations` is lower than the loop's current iteration.

#### `DELETE /api/v1/loops/{id}`

Delete a terminal loop and all of its iterations and stages.

Running and paused loops cannot be deleted.

**Response `200`**

```json
{
  "deleted": true,
  "id": "abc123"
}
```

**Response `409`**

Returned when the loop is still `running` or `paused`.

#### `POST /api/v1/loops/{id}/pause`

Pause a running loop.

**Response `200`**

```json
{
  "id": "abc123",
  "status": "paused"
}
```

**Response `409`** — loop is not currently running.

#### `POST /api/v1/loops/{id}/resume`

Resume a paused loop.

**Response `200`**

```json
{
  "id": "abc123",
  "status": "running"
}
```

**Response `409`** — loop is not currently paused.

#### `POST /api/v1/loops/{id}/stop`

Cancel a running or paused loop.

**Response `200`**

```json
{
  "id": "abc123",
  "status": "cancelled"
}
```

**Response `409`** — loop is already terminal.

#### `POST /api/v1/loops/{id}/skip-stage`

Skip the first non-completed stage on the current iteration and reopen the loop so the manager can continue from the next pending stage.

This is an operator recovery action for loops that are no longer actively running. Running stages cannot be skipped because there is still work in flight.

**Response `200`**

Returns the same normalized loop state payload as `GET /api/v1/loops/{id}`.

**Response `409`**

Returned when:

- the loop is still `running`
- there is no current iteration to recover
- there is no non-completed stage left to skip
- the current actionable stage is already `running`

#### `GET /api/v1/loops/{id}/iterations`

List all iterations for a loop.

**Response `200`**

```json
{
  "iterations": [
    {
      "id": "iter123",
      "loop_id": "abc123",
      "iteration_number": 1,
      "status": "completed",
      "started_at": "2026-02-16T14:00:00Z",
      "completed_at": "2026-02-16T14:15:00Z"
    }
  ],
  "count": 1
}
```

#### `GET /api/v1/loops/{id}/iterations/{iterationId}`

Get a specific iteration with all its stage details.

**Response `200`**

```json
{
  "id": "iter123",
  "loop_id": "abc123",
  "iteration_number": 1,
  "status": "completed",
  "stages": [
    {
      "id": "stage123",
      "stage_index": 0,
      "role": "plan",
      "status": "completed",
      "output": "## Implementation Plan\n1. ...",
      "started_at": "2026-02-16T14:00:00Z",
      "completed_at": "2026-02-16T14:05:00Z"
    }
  ],
  "started_at": "2026-02-16T14:00:00Z",
  "completed_at": "2026-02-16T14:15:00Z"
}
```

#### `POST /api/v1/loops/{id}/iterations/{iterationId}/retry`

Reset the latest failed or needs-rework iteration back to a runnable state.

This clears the iteration's stage execution records, restores the loop to `running`, and leaves dispatch metadata in a pending state so the loop manager can resume from stage `0` on the next tick.

**Response `200`**

Returns the same normalized loop state payload as `GET /api/v1/loops/{id}`.

**Response `409`**

Returned when:

- the target iteration is not the latest iteration
- the loop is still `running`
- the iteration is not in `failed` or `needs_rework`

### Audit Log

The audit log records approval decisions and questions — auto-approvals, approvals, denials, blocks, and structured questions — **not** all tool or API activity. Secrets are redacted at write time before any entry is stored, so both `arguments` and `reason` are already sanitized when read back. These endpoints are read-only; there is no export endpoint.

#### `GET /api/v1/audit`

List audit entries across all sessions, filtered and paginated. Entries are ordered newest first.

**Query Parameters**

| Parameter | Type | Description |
| --- | --- | --- |
| `session_id` | string | Filter to a single session |
| `tool_name` | string | Filter by tool name (exact match) |
| `action` | string | Filter by action (exact match), e.g. `auto_approved`, `approved`, `denied`, `blocked`, `question_asked`, `question_answered` |
| `after` | string | Only entries at or after this timestamp (inclusive, `>=`). Any string parseable as a date/time; a non-parseable value returns `400 validation_error` |
| `before` | string | Only entries strictly before this timestamp (exclusive, `<`). Same parsing and error behavior as `after` |
| `limit` | integer | Maximum entries to return. Clamped to `1`–`500`; defaults to `100` |
| `offset` | integer | Number of entries to skip; floored at `0`; defaults to `0` |

**Response `200`**

```json
{
  "entries": [
    {
      "id": 128,
      "session_id": "sess_123",
      "turn_id": "turn_456",
      "tool_name": "shell",
      "action": "approved",
      "reason": "User approved shell execution",
      "arguments": {
        "command": "ls -la"
      },
      "created_at": "2026-02-16T14:30:00Z"
    }
  ],
  "total": 1,
  "limit": 100,
  "offset": 0
}
```

`entries` holds the current page; `total` is the count of all entries matching the filters, ignoring `limit`/`offset`. Each entry's `arguments` is the decoded JSON object; if the stored payload cannot be decoded it is returned as `{"_raw": "..."}`.

**Response `400`**

Returned when `after` or `before` is not a parseable timestamp (`validation_error`).

#### `GET /api/v1/sessions/{id}/audit`

Session-scoped convenience view. Identical to `GET /api/v1/audit` except the session is taken from the path; a `session_id` query parameter is ignored. All other filters (`tool_name`, `action`, `after`, `before`, `limit`, `offset`) apply.

**Response `200`**

Same envelope as the global route, with an added `session_id` key echoing the path segment:

```json
{
  "session_id": "sess_123",
  "entries": [],
  "total": 0,
  "limit": 100,
  "offset": 0
}
```

**Response `404`**

Returned when the session does not exist (`session_not_found`).

Both routes require authentication; requests without a valid API key return `401 unauthorized`, like every other authenticated route.

## Toolkit Management

Toolkit visibility controls which tools appear in the agent's context window and how they are represented. Each toolkit (Composer package) and each individual tool can be set to one of three visibility tiers:

| Tier | Description |
|------|-------------|
| `enabled` | Full tool schema in the agent's context (default) |
| `stub` | Minimal schema in context; agent discovers full details via `tool_search` |
| `disabled` | Tool not instantiated; invisible to the agent |

Visibility state is persisted in `workspace/toolkit-visibility.json`. Packages or tools not listed in that file default to `enabled`.

### Protected Tools

Some tools have fixed visibility floors and cannot be demoted below a certain tier:

| Constant | Tools | Restriction |
|----------|-------|-------------|
| `ALWAYS_ENABLED` | `tool_search`, `credentials` | Can never be stubbed or disabled |
| `CANNOT_DISABLE` | `spawn_agent`, `vision_analyze`, `restart_coqui` | Can be stubbed, but never disabled |

Requests that violate these guards return `403 Forbidden`.

---

#### `GET /api/v1/toolkits`

List all registered toolkit packages and individual tools with their current visibility.

**Response `200`**

```json
{
  "toolkits": [
    {
      "package": "coquibot/core-toolkit",
      "classes": ["CoquiBot\\CoreToolkit\\CoreToolkit"],
      "visibility": "enabled",
      "tokens": 1250
    },
    {
      "package": "acme/vision-toolkit",
      "classes": ["Acme\\Vision\\VisionToolkit"],
      "visibility": "stub",
      "tokens": 340
    }
  ],
  "tools": [
    {
      "name": "spawn_agent",
      "visibility": "enabled",
      "protected": "cannot_disable"
    },
    {
      "name": "tool_search",
      "visibility": "enabled",
      "protected": "always_enabled"
    },
    {
      "name": "php_execute",
      "visibility": "enabled",
      "protected": null
    }
  ],
  "prompt_tokens": 4250,
  "tool_tokens": 1830,
  "total_tokens": 6080
}
```

| Field | Type | Description |
|-------|------|-------------|
| `toolkits[].tokens` | int | Estimated token count for this toolkit's guidelines and tool schemas |
| `prompt_tokens` | int | Estimated token count for the system prompt text |
| `tool_tokens` | int | Estimated token count for all tool schemas (standalone + toolkit) |
| `total_tokens` | int | Sum of `prompt_tokens` and `tool_tokens` |

The `protected` field is `"always_enabled"`, `"cannot_disable"`, or `null`.

---

#### `POST /api/v1/toolkits/visibility`

Set the visibility of a package or an individual tool.

**Request Body**

```json
{
  "target": "package",
  "name": "acme/vision-toolkit",
  "visibility": "stub"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `target` | string | Yes | `"package"` or `"tool"` |
| `name` | string | Yes | Package name (e.g. `vendor/pkg`) or tool name (e.g. `spawn_agent`) |
| `visibility` | string | Yes | `"enabled"`, `"stub"`, or `"disabled"` |

**Response `200`** — success:

```json
{
  "target": "package",
  "name": "acme/vision-toolkit",
  "visibility": "stub"
}
```

**Response `400`** — missing or invalid fields:

```json
{
  "error": "Missing required fields: target, name, visibility",
  "code": "bad_request"
}
```

**Response `403`** — guard violation (e.g. attempting to disable `spawn_agent`):

```json
{
  "error": "Tool \"spawn_agent\" cannot be disabled",
  "code": "forbidden"
}
```

---

### MCP Servers

The MCP client engine and this API surface ship in Coqui core by default (`CoquiBot\Coqui\Mcp\*`) — every endpoint below works fully without any additional package installed, with one exception: `POST /api/v1/mcp/servers/{name}/auth` (browser-based OAuth) requires the optional [`coquibot/coqui-toolkit-mcp-client`](https://github.com/AgentCoqui/coqui-toolkit-mcp-client) package. When that package is not installed, calling the `/auth` endpoint returns an error explaining that OAuth requires the management toolkit.

MCP server management is available over HTTP and uses the same shared MCP runtime service as the optional `mcp` tool and `/mcp` REPL command (when the management toolkit is installed). These endpoints are intended for operator flows such as adding or updating servers, linking credentials, inspecting discovered tools, and verifying connectivity.

The API keeps MCP auditing live-only for now: each server snapshot includes the latest connection attempt, latest connectivity test result, durations, timestamps, the current `loadingMode`, and last discovered tool count, but does not persist a historical delivery-style audit ledger.

Server snapshots now also include an optional operator-facing `description`. This is intended for app and dashboard UX where a short human label helps distinguish multiple MCP definitions. `PATCH /api/v1/mcp/servers/{name}` can also rename a server by sending a new `name` value.

#### `GET /api/v1/mcp/servers`

List configured MCP servers with current live status and audit fields.

#### `POST /api/v1/mcp/servers`

Create a new MCP server definition.

**Request Body**

```json
{
  "name": "github",
  "description": "Primary GitHub tools",
  "command": "npx",
  "args": ["-y", "@modelcontextprotocol/server-github"]
}
```

The response includes the normalized server snapshot plus a `runtime.applied` field. Values are currently `live` or `next_turn`.

#### `GET /api/v1/mcp/servers/{name}`

Return one MCP server snapshot including description, command, args, env links, live connection state, discovered tool count, and the current live audit fields.

#### `PATCH /api/v1/mcp/servers/{name}`

Update an existing MCP server. The patch body may rename the server, update its description, or change its command and args.

**Request Body**

```json
{
  "name": "fetch-prod",
  "description": "Production fetch server",
  "command": "uvx",
  "args": ["mcp-server-fetch"]
}
```

#### `DELETE /api/v1/mcp/servers/{name}`

Remove a configured MCP server definition.

#### `POST /api/v1/mcp/servers/{name}/enable`

Enable a disabled MCP server.

#### `POST /api/v1/mcp/servers/{name}/disable`

Disable and disconnect an MCP server.

#### `POST /api/v1/mcp/servers/{name}/promote`

Force one MCP server toolkit to load eagerly on future turns. The response includes the refreshed server snapshot, `loading_mode`, and `runtime.applied`.

#### `POST /api/v1/mcp/servers/{name}/demote`

Force one MCP server toolkit to stay deferred on future turns. The response includes the refreshed server snapshot, `loading_mode`, and `runtime.applied`.

#### `POST /api/v1/mcp/servers/{name}/auto`

Return one MCP server toolkit to automatic budget-gated loading. The response includes the refreshed server snapshot, `loading_mode`, and `runtime.applied`.

#### `POST /api/v1/mcp/servers/{name}/connect`

Connect a configured server immediately and return the refreshed snapshot plus `duration_ms`.

#### `POST /api/v1/mcp/servers/{name}/disconnect`

Disconnect a configured server immediately.

#### `POST /api/v1/mcp/servers/{name}/refresh`

Reconnect and refresh one server's discovered tool list.

#### `POST /api/v1/mcp/servers/{name}/test`

Run a lightweight connectivity test by reconnecting and verifying tool discovery. The response returns the refreshed server snapshot and `duration_ms`.

#### `GET /api/v1/mcp/servers/{name}/tools`

List the discovered MCP tool definitions for one server, including namespaced tool names and input schema metadata.

#### `GET /api/v1/mcp/tools/search?query=<term>&server=<optional-name>`

Search discovered MCP tool names and descriptions. This is an operator-facing inspection endpoint and complements the agent-facing `tool_search` runtime behavior.

#### `POST /api/v1/mcp/servers/{name}/env`

Create or update one server env link.

**Request Body**

```json
{
  "key": "GITHUB_TOKEN",
  "value": "ghp_xxx"
}
```

For secret-link-only flows, send `placeholder` instead of `value`.

#### `POST /api/v1/mcp/servers/{name}/auth`

Run browser-based OAuth for one server and store the resulting access token as an env link. **Requires the optional `coquibot/coqui-toolkit-mcp-client` package.** Core ships no OAuth implementation; when the toolkit is not installed, this endpoint returns an error stating that OAuth requires the management toolkit.

**Request Body**

```json
{
  "auth_url": "https://auth.example.com/authorize",
  "token_url": "https://auth.example.com/token",
  "client_id": "coqui-mcp",
  "scopes": ["repo", "read:user"]
}
```

MCP server mutations hot-apply where possible and do not require a full API restart. Loading-mode changes affect future agent assembly, so they take effect on the next turn even when the config write itself is applied immediately.

---

#### `GET /api/v1/server/prompt`

Return the fully constructed system prompt that the agent would receive on its next turn, together with tool and toolkit counts plus prompt-source metadata. Useful for debugging context size, inspecting which files are contributing to the prompt, and tracking which folders are consuming the prompt budget.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `role` | string | `orchestrator` | Role scope to resolve before rendering the prompt preview |
| `persona` | string | `null` | Optional persona scope to apply while rendering the prompt preview |
| `session_id` | string | `null` | Optional session to inspect. When `conversationHistoryInSystemPrompt` is enabled, Coqui uses this to render the real `Conversation History` block with stored timestamps, summary/full markers, tool markers, and actor-backed `@speaker` labels |

**Response `200`**

```json
{
  "persona": "caelum",
  "role": "orchestrator",
  "resolved_model": "ollama/qwen3:latest",
  "prompt": "You are Coqui, an autonomous AI agent...\n\n## Available Tools\n...",
  "tool_count": 42,
  "toolkit_count": 7,
  "prompt_tokens": 4250,
  "tool_tokens": 1830,
  "total_tokens": 6080,
  "toolkit_breakdown": [
    {
      "name": "MemoryToolkit",
      "class": "CoquiBot\\Coqui\\Toolkit\\MemoryToolkit",
      "guidelines_tokens": 320,
      "tools_tokens": 480,
      "total_tokens": 800
    }
  ],
  "prompt_sources": {
    "files": [
      {
        "scope": "project",
        "path": "prompts/base.md",
        "tokens": 510,
        "size_bytes": 2921,
        "last_modified_at": "2026-04-18T20:45:00+00:00",
        "section_count": 1,
        "sections": [
          {
            "id": "prompt.base",
            "title": "Base Prompt",
            "group": "identity",
            "tokens": 510
          }
        ]
      }
    ],
    "folders": [
      {
        "scope": "project",
        "path": "prompts",
        "tokens": 2170,
        "file_count": 6,
        "size_bytes": 11842,
        "last_modified_at": "2026-04-18T20:45:00+00:00"
      }
    ],
    "synthetic": [
      {
        "source_type": "generated",
        "source": null,
        "label": "Core Memories",
        "tokens": 160,
        "section_count": 1,
        "sections": [
          {
            "id": "context.core-memories",
            "title": "Core Memories",
            "group": "memory",
            "tokens": 160
          }
        ]
      }
    ],
    "file_backed_tokens": 4090,
    "synthetic_tokens": 160,
    "last_modified_at": "2026-04-18T20:45:00+00:00"
  }
}
```

When `session_id` is omitted, prompt inspection renders the static role/persona/toolkit context only. When it is provided, session-aware prompt inspection can include the active conversation-history section and corresponding prompt-budget section. This is the same session-aware path used by `/prompt export` in the REPL.

| Field | Type | Description |
|-------|------|-------------|
| `persona` | string\|null | Explicit persona scope used to render the prompt preview |
| `role` | string | Effective role used to render the prompt preview |
| `resolved_model` | string\|null | Exact resolved model string for the requested `role` + `persona` scope |
| `prompt` | string | Full rendered system prompt text |
| `tool_count` | int | Number of tools currently in the agent's context (enabled + stub) |
| `toolkit_count` | int | Number of toolkit packages contributing tools |
| `prompt_tokens` | int | Estimated token count for the system prompt text |
| `tool_tokens` | int | Estimated token count for all tool schemas (standalone + toolkit) |
| `total_tokens` | int | Sum of `prompt_tokens` and `tool_tokens` |
| `toolkit_breakdown` | array | Per-toolkit token breakdown with guidelines and tool schema counts |
| `budget` | object | Full prompt budget snapshot, including prompt sections and loading decisions |
| `prompt_sources` | object | File, folder, and synthetic-source breakdown for prompt token usage |

#### `GET /api/v1/server/commands`

Return the runtime slash-command catalog that powers REPL help output. This is the HTTP equivalent of `/help` for clients that want to expose command discovery or contextual help without scraping documentation.

**Response `200`**

```json
{
  "sections": [
    {
      "name": "Context & Inspection",
      "commands": [
        {
          "name": "/prompt",
          "usage": "/prompt [export]",
          "description": "Show the rendered system prompt, source breakdowns, or export it to the workspace.",
          "help_description": "Show the rendered system prompt, source breakdowns, or export it to the workspace.",
          "aliases": [],
          "first_arguments": ["export"],
          "section": "Context & Inspection"
        }
      ]
    }
  ],
  "commands": [
    {
      "name": "/help",
      "usage": "/help",
      "description": "Show the command reference.",
      "help_description": "Show the command reference.",
      "aliases": [],
      "first_arguments": [],
      "section": "System & Exit"
    }
  ],
  "count": 31
}
```

| Field | Type | Description |
|-------|------|-------------|
| `sections` | array | Commands grouped by the same help-section headings used in the REPL |
| `commands` | array | Flat list of command metadata for search/filter UIs |
| `count` | int | Total number of commands returned |

> **Breaking change:** `GET /api/v1/server/backstory` has been removed from core. It returned generated `backstory.md` content and source-manifest metadata for the source-file backstory generator, which now lives in the optional `coqui-toolkit-backstory` package. `PATCH /api/v1/personas/{name}` still accepts a `backstory` field that writes `backstory.md` content directly.

## Middleware

### Rate Limiting

The API enforces per-IP rate limiting using an in-memory token bucket. When the limit is exceeded, requests receive `429 Too Many Requests`.

**Default:** 30 requests per 60 seconds per IP.

Configure via `openclaw.json`:

```json
{
  "api": {
    "rateLimit": {
      "maxRequests": 30,
      "windowSeconds": 60
    }
  }
}
```

**Response headers** (on all requests):

| Header | Description |
|--------|-------------|
| `X-RateLimit-Limit` | Maximum requests allowed per window |
| `X-RateLimit-Remaining` | Remaining requests in current window |

**Rate limited response (`429`):**

```json
{
  "error": "Rate limit exceeded. Try again later.",
  "code": "rate_limited"
}
```

The response includes a `Retry-After` header with the number of seconds to wait.

Exempt endpoints: `GET /api/v1/health`, `OPTIONS` (preflight).

### Request Size Limit

Request bodies are limited to **50 MiB** (52,428,800 bytes). Requests exceeding this limit receive `413 Payload Too Large`.

```json
{
  "error": "Request body too large. Maximum size: 52428800 bytes",
  "code": "payload_too_large"
}
```

Only `POST`, `PUT`, and `PATCH` requests are checked. Prompt-bearing fields such as session message prompts, task prompts, and task follow-up input enforce their own stricter **1 MiB** per-field limit.

### Content-Type Enforcement

All `POST`, `PUT`, and `PATCH` requests must include a `Content-Type` header containing `application/json`. Missing or incorrect content types receive `415 Unsupported Media Type`.

```json
{
  "error": "Content-Type must be application/json",
  "code": "unsupported_media_type"
}
```

## CORS

The server includes CORS headers on all responses. By default, all origins are allowed (`*`). Restrict origins with the `--cors-origin` flag:

```bash
coqui --api-only --cors-origin "http://localhost:3000,https://myapp.com"
```

Preflight `OPTIONS` requests are handled automatically with a `204` response.

## Safety

The API server enforces the same layered safety model as the terminal REPL:

1. **Catastrophic Blacklist** — hardcoded patterns that always block destructive commands (`rm -rf /`, `shutdown`, fork bombs, etc.). Cannot be bypassed.
2. **Script Sanitizer** — static analysis of generated PHP code. Blocks `eval`, `exec`, `system`, etc. Disabled with `--unsafe`.
3. **Auto-Approval** — in API mode, tool executions are auto-approved (no interactive prompt). The catastrophic blacklist still applies.

## Concurrency

Each prompt submission runs inside a PHP Fiber. The ReactPHP event loop remains responsive while agent turns execute. Only one agent run per session is allowed at a time — concurrent requests to the same session return `409 Conflict`.

## REPL Command Mapping

The API overlaps with the REPL, but it does **not** mirror every slash command. The current HTTP surface focuses on session execution plus read-heavy inspection.

The `/mcp` REPL command itself comes from the optional `coquibot/coqui-toolkit-mcp-client` package (see [docs/COMMANDS.md](COMMANDS.md)), but every API equivalent listed below is core and works whether or not that package is installed.

| REPL Command | API Equivalent | Notes |
|---|---|---|
| `/new` | `POST /api/v1/sessions` | Creates a new session |
| `/sessions` | `GET /api/v1/sessions` | Lists all sessions |
| `/history` | `GET /api/v1/sessions/{id}/messages` | Lists all messages in a session |
| `/model` | `GET /api/v1/config/models` | Lists available models and current config |
| `/config` | `GET /api/v1/config` | Returns current configuration (sanitized) |
| `/config history on\|off` | `PATCH /api/v1/config/context` | Toggles conversation-history system-prompt mode and requires restart |
| `/tasks` | `GET /api/v1/tasks` | Lists background tasks |
| `/task <id>` | `GET /api/v1/tasks/{id}` | Gets task detail |
| `/task-cancel <id>` | `POST /api/v1/tasks/{id}/cancel` | Cancels a running or pending task |
| `/projects` | `GET /api/v1/projects` | Lists projects |
| `—` | `GET /api/v1/server/info` | Returns server runtime capabilities and status |
| `/toolkits` | `GET /api/v1/toolkits` | Lists all toolkit packages and tools with visibility |
| `/toolkits enable <pkg>` | `POST /api/v1/toolkits/visibility` | Sets package or tool visibility to enabled |
| `/toolkits stub <pkg>` | `POST /api/v1/toolkits/visibility` | Sets package or tool visibility to stub |
| `/toolkits disable <pkg>` | `POST /api/v1/toolkits/visibility` | Sets package or tool visibility to disabled |
| `/mcp list` | `GET /api/v1/mcp/servers` | Lists configured MCP servers with live status |
| `/mcp status <name>` | `GET /api/v1/mcp/servers/{name}` | Shows one MCP server snapshot |
| `/mcp tools <name>` | `GET /api/v1/mcp/servers/{name}/tools` | Lists discovered MCP tools for one server |
| `/mcp search <query> [server]` | `GET /api/v1/mcp/tools/search` | Searches discovered MCP tools |
| `/mcp add <name> <command> [args...]` | `POST /api/v1/mcp/servers` | Creates an MCP server definition |
| `/mcp update <name> <command> [args...]` | `PATCH /api/v1/mcp/servers/{name}` | Updates an MCP server definition |
| `/mcp remove <name>` | `DELETE /api/v1/mcp/servers/{name}` | Deletes an MCP server definition |
| `/mcp enable <name>` | `POST /api/v1/mcp/servers/{name}/enable` | Enables an MCP server |
| `/mcp disable <name>` | `POST /api/v1/mcp/servers/{name}/disable` | Disables an MCP server |
| `/mcp promote <name>` | `POST /api/v1/mcp/servers/{name}/promote` | Forces eager loading for one MCP server toolkit |
| `/mcp demote <name>` | `POST /api/v1/mcp/servers/{name}/demote` | Forces deferred loading for one MCP server toolkit |
| `/mcp auto <name>` | `POST /api/v1/mcp/servers/{name}/auto` | Returns one MCP server toolkit to automatic loading |
| `/mcp connect <name>` | `POST /api/v1/mcp/servers/{name}/connect` | Connects an MCP server immediately |
| `/mcp disconnect <name>` | `POST /api/v1/mcp/servers/{name}/disconnect` | Disconnects an MCP server immediately |
| `/mcp refresh <name>` | `POST /api/v1/mcp/servers/{name}/refresh` | Reconnects and refreshes MCP tools |
| `/mcp test <name>` | `POST /api/v1/mcp/servers/{name}/test` | Tests MCP connectivity and tool discovery |
| `/mcp set-env <name> <ENV_KEY>` | `POST /api/v1/mcp/servers/{name}/env` | Links a secret or env placeholder to an MCP server |
| `/help` | `GET /api/v1/server/commands` | Returns the runtime slash-command catalog |
| `/prompt` | `GET /api/v1/server/prompt` | Outputs the fully constructed system prompt |
| persona preference schema | `GET /api/v1/config/persona-preferences/schema` | Returns the curated app-facing preference editor schema |
| `/budget` | `GET /api/v1/server/budget` | Returns prompt and toolkit budget info |
| `/audit` | `GET /api/v1/audit` | Lists audit entries (approval decisions and questions) with filters |
| `/loops` | `GET /api/v1/loops` | Lists all loops with status and progress |
| `/loops definitions` | `GET /api/v1/loops/definitions` | Shows available loop definitions |
| `/loops status <id>` | `GET /api/v1/loops/{id}` | Detailed status of a specific loop |
| `/loops pause <id>` | `POST /api/v1/loops/{id}/pause` | Pauses a running loop |
| `/loops resume <id>` | `POST /api/v1/loops/{id}/resume` | Resumes a paused loop |
| `/loops stop <id>` | `POST /api/v1/loops/{id}/stop` | Cancels a running or paused loop |
| `/loops skip-stage <id>` | `POST /api/v1/loops/{id}/skip-stage` | Skips the current blocked stage and reopens the loop |
| `/loops retry <id> <iterationId>` | `POST /api/v1/loops/{id}/iterations/{iterationId}/retry` | Retries the latest failed iteration |
| `/schedules` | `GET /api/v1/schedules` | Lists schedules |
| `/schedules status <id>` | `GET /api/v1/schedules/{id}` | Shows schedule details |
| `/schedules enable <id>` | `POST /api/v1/schedules/{id}/enable` | Enables a mutable schedule |
| `/schedules disable <id>` | `POST /api/v1/schedules/{id}/disable` | Disables a mutable schedule |
| `/schedules trigger <id>` | `POST /api/v1/schedules/{id}/trigger` | Forces a mutable schedule to run on the next tick |

Mutating REPL workflows such as `/config edit`, `/roles update`, and most schedule management remain REPL-first by design. The narrow `PATCH /api/v1/config/context` route is the exception for safe context toggles that still require restart.

## Quick Reference

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/api/v1/health` | No | Server liveness check |
| `GET` | `/api/v1/sessions` | Yes | List sessions |
| `POST` | `/api/v1/sessions` | Yes | Create session |
| `GET` | `/api/v1/sessions/{id}` | Yes | Get session |
| `PATCH` | `/api/v1/sessions/{id}` | Yes | Update session metadata |
| `DELETE` | `/api/v1/sessions/{id}` | Yes | Delete session |
| `GET` | `/api/v1/sessions/{id}/project` | Yes | Get the session active project |
| `PATCH` | `/api/v1/sessions/{id}/project` | Yes | Set or clear the session active project |
| `GET` | `/api/v1/sessions/{id}/messages` | Yes | List messages |
| `POST` | `/api/v1/sessions/{id}/messages` | Yes | Send prompt (`SSE` by default, `?stream=false` for blocking) |
| `DELETE` | `/api/v1/sessions/{id}/messages/{messageId}` | Yes | Delete a message |
| `POST` | `/api/v1/sessions/{id}/files` | Yes | Upload a content blob (multipart or raw binary) |
| `GET` | `/api/v1/sessions/{id}/files/{ref}` | Yes | Download a blob by `content_ref` (Range-aware) |
| `GET` | `/api/v1/sessions/{id}/turns` | Yes | List turns |
| `GET` | `/api/v1/sessions/{id}/turns/{turnId}` | Yes | Get turn with messages |
| `GET` | `/api/v1/sessions/{id}/turns/{turnId}/events` | Yes | List replayable turn events |
| `GET` | `/api/v1/sessions/{id}/child-runs` | Yes | List child runs |
| `POST` | `/api/v1/sessions/{id}/child-runs` | Yes | Spawn a child run (gated: top-level full-access only; `202`, sync-execute) |
| `GET` | `/api/v1/sessions/{id}/child-runs/{childRunId}` | Yes | Get a single child run |
| `GET` | `/api/v1/config` | Yes | Get config (sanitized) |
| `GET` | `/api/v1/config/context` | Yes | Get the supported app-facing context settings with defaults, metadata, and restart state |
| `PATCH` | `/api/v1/config/context` | Yes | Update supported context settings such as `conversationHistoryInSystemPrompt` and auto-summarize controls |
| `POST` | `/api/v1/config/validate` | Yes | Validate a candidate config payload |
| `GET` | `/api/v1/config/roles` | Yes | List all roles |
| `GET` | `/api/v1/config/roles/{name}` | Yes | Get role detail |
| `GET` | `/api/v1/config/models` | Yes | List available models |
| `GET` | `/api/v1/personas` | Yes | List discovered personas for app pickers |
| `GET` | `/api/v1/personas/{name}` | Yes | Get persona detail |
| `POST` | `/api/v1/personas` | Yes | Create a persona |
| `PATCH` | `/api/v1/personas/{name}` | Yes | Update soul, backstory, and preferences for a persona |
| `DELETE` | `/api/v1/personas/{name}` | Yes | Delete a non-default persona |
| `GET` | `/api/v1/credentials` | Yes | List credential keys |
| `GET` | `/api/v1/credentials/requirements` | Yes | List declared credential requirements |
| `POST` | `/api/v1/credentials` | Yes | Set a credential |
| `DELETE` | `/api/v1/credentials/{key}` | Yes | Delete a credential |
| `POST` | `/api/v1/tasks` | Yes | Create background task |
| `GET` | `/api/v1/tasks` | Yes | List tasks |
| `GET` | `/api/v1/tasks/{id}` | Yes | Get task detail |
| `POST` | `/api/v1/projects` | Yes | Create project |
| `GET` | `/api/v1/projects` | Yes | List projects |
| `GET` | `/api/v1/projects/{idOrSlug}` | Yes | Get project detail |
| `PATCH` | `/api/v1/projects/{idOrSlug}` | Yes | Update project |
| `DELETE` | `/api/v1/projects/{idOrSlug}` | Yes | Delete archived project |
| `POST` | `/api/v1/projects/{idOrSlug}/archive` | Yes | Archive project |
| `POST` | `/api/v1/projects/{idOrSlug}/activate` | Yes | Activate project |
| `GET` | `/api/v1/tasks/{id}/events` | Yes | Stream task events (SSE) |
| `POST` | `/api/v1/tasks/{id}/input` | Yes | Inject input into running task |
| `POST` | `/api/v1/tasks/{id}/cancel` | Yes | Cancel a task |
| `GET` | `/api/v1/projects` | Yes | List projects |
| `GET` | `/api/v1/projects/{idOrSlug}` | Yes | Get project detail |
| `GET` | `/api/v1/server/stats` | Yes | Database and server statistics |
| `GET` | `/api/v1/server/info` | Yes | Server capabilities and commands |
| `POST` | `/api/v1/server/restart` | Yes | Restart a launcher-managed API process |
| `GET` | `/api/v1/server/commands` | Yes | Get runtime slash-command metadata (`/help` equivalent) |
| `GET` | `/api/v1/server/prompt` | Yes | Get the rendered system prompt, optionally session-aware via `session_id` |
| `GET` | `/api/v1/server/budget` | Yes | Get prompt and toolkit budget state, optionally session-aware via `session_id` |
| `GET` | `/api/v1/toolkits` | Yes | List toolkits and tools with visibility |
| `POST` | `/api/v1/toolkits/visibility` | Yes | Set package or tool visibility |
| `GET` | `/api/v1/mcp/servers` | Yes | List MCP servers with live status and audit fields |
| `POST` | `/api/v1/mcp/servers` | Yes | Create an MCP server |
| `GET` | `/api/v1/mcp/servers/{name}` | Yes | Get one MCP server snapshot |
| `PATCH` | `/api/v1/mcp/servers/{name}` | Yes | Update one MCP server |
| `DELETE` | `/api/v1/mcp/servers/{name}` | Yes | Delete one MCP server |
| `POST` | `/api/v1/mcp/servers/{name}/enable` | Yes | Enable an MCP server |
| `POST` | `/api/v1/mcp/servers/{name}/disable` | Yes | Disable an MCP server |
| `POST` | `/api/v1/mcp/servers/{name}/promote` | Yes | Force eager loading for one MCP server |
| `POST` | `/api/v1/mcp/servers/{name}/demote` | Yes | Force deferred loading for one MCP server |
| `POST` | `/api/v1/mcp/servers/{name}/auto` | Yes | Return one MCP server to automatic loading |
| `POST` | `/api/v1/mcp/servers/{name}/connect` | Yes | Connect an MCP server |
| `POST` | `/api/v1/mcp/servers/{name}/disconnect` | Yes | Disconnect an MCP server |
| `POST` | `/api/v1/mcp/servers/{name}/refresh` | Yes | Refresh discovered MCP tools |
| `POST` | `/api/v1/mcp/servers/{name}/test` | Yes | Test MCP connectivity |
| `GET` | `/api/v1/mcp/servers/{name}/tools` | Yes | List discovered tools for one MCP server |
| `GET` | `/api/v1/mcp/tools/search` | Yes | Search discovered MCP tools |
| `POST` | `/api/v1/mcp/servers/{name}/env` | Yes | Link env config to an MCP server |
| `POST` | `/api/v1/mcp/servers/{name}/auth` | Yes | Run browser-based OAuth for an MCP server (requires optional `coquibot/coqui-toolkit-mcp-client`) |
| `GET` | `/api/v1/schedules` | Yes | List schedules |
| `GET` | `/api/v1/schedules/{id}` | Yes | Get schedule |
| `POST` | `/api/v1/schedules` | Yes | Create schedule |
| `PATCH` | `/api/v1/schedules/{id}` | Yes | Update mutable schedule |
| `DELETE` | `/api/v1/schedules/{id}` | Yes | Delete mutable schedule |
| `POST` | `/api/v1/schedules/{id}/enable` | Yes | Enable mutable schedule |
| `POST` | `/api/v1/schedules/{id}/disable` | Yes | Disable mutable schedule |
| `POST` | `/api/v1/schedules/{id}/trigger` | Yes | Force mutable schedule to run on next tick |
| `POST` | `/api/v1/loops` | Yes | Create and start a loop |
| `GET` | `/api/v1/loops` | Yes | List loops |
| `GET` | `/api/v1/loops/definitions` | Yes | List loop definitions |
| `GET` | `/api/v1/loops/definitions/{name}` | Yes | Get one loop definition (with version) |
| `PUT` | `/api/v1/loops/definitions/{name}` | Yes | Create (If-None-Match: *) or update (If-Match: version) a loop definition |
| `DELETE` | `/api/v1/loops/definitions/{name}` | Yes | Delete a loop definition |
| `GET` | `/api/v1/loops/{id}` | Yes | Get loop details |
| `PATCH` | `/api/v1/loops/{id}` | Yes | Update editable loop fields |
| `DELETE` | `/api/v1/loops/{id}` | Yes | Delete a terminal loop |
| `POST` | `/api/v1/loops/{id}/pause` | Yes | Pause a running loop |
| `POST` | `/api/v1/loops/{id}/resume` | Yes | Resume a paused loop |
| `POST` | `/api/v1/loops/{id}/stop` | Yes | Cancel a running or paused loop |
| `POST` | `/api/v1/loops/{id}/skip-stage` | Yes | Skip the current actionable non-running stage |
| `GET` | `/api/v1/loops/{id}/iterations` | Yes | List loop iterations |
| `GET` | `/api/v1/loops/{id}/iterations/{iterationId}` | Yes | Get iteration with stages |
| `POST` | `/api/v1/loops/{id}/iterations/{iterationId}/retry` | Yes | Retry the latest failed iteration |
| `GET` | `/api/v1/audit` | Yes | List audit entries with filters and pagination |
| `GET` | `/api/v1/sessions/{id}/audit` | Yes | List audit entries for one session |
| `POST` | `/api/v1/sessions/{id}/artifacts` | Yes | Create artifact |
| `GET` | `/api/v1/sessions/{id}/artifacts` | Yes | List artifacts |
| `GET` | `/api/v1/sessions/{id}/artifacts/{artifactId}` | Yes | Get artifact |
| `PATCH` | `/api/v1/sessions/{id}/artifacts/{artifactId}` | Yes | Update artifact metadata or content |
| `DELETE` | `/api/v1/sessions/{id}/artifacts/{artifactId}` | Yes | Delete artifact |
| `GET` | `/api/v1/sessions/{id}/questions` | Yes | List pending structured questions |
| `POST` | `/api/v1/sessions/{id}/questions/{questionId}/answer` | Yes | Answer a structured question by id |
| `POST` | `/api/v1/sessions/{id}/turns/{turnId}/answer` | Yes | Answer a turn's pending question (Core answer path) |

Mutation-heavy workflows for roles, summarization, and update continue to live primarily in the REPL and agent tool layer. API restart now exposes explicit restart-state metadata over HTTP for app clients.
