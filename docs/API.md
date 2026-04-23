# Coqui HTTP API

> **REPL-first**: The terminal REPL is Coqui's primary interface. The API provides the stable application-facing execution and inspection surface. User-facing read and monitoring workflows are documented here. Most loop, schedule, and config-editing control flows remain REPL-first or tool-driven, while launcher-managed API restarts and channel CRUD are now exposed over HTTP with explicit restart-state metadata.

The Coqui HTTP API provides programmatic access to Coqui's AI agent capabilities. It enables headless operation, remote session management, and real-time streaming of agent responses via Server-Sent Events (SSE).

The API is built on ReactPHP and runs as a long-lived PHP process. It shares the same core engine as the terminal REPL but without any terminal I/O dependency.

## Starting the Server

```bash
# Default: localhost:3300
php bin/coqui api

# Listen on all interfaces (accessible from other devices on your network)
php bin/coqui api --host 0.0.0.0

# Custom host and port
php bin/coqui api --host 0.0.0.0 --port 3000

# With a specific config file
php bin/coqui api --config /path/to/openclaw.json

# With CORS origins restricted
php bin/coqui api --cors-origin "http://localhost:3000,https://app.example.com"

# Via the launcher
./bin/coqui-launcher --api-only --host 0.0.0.0

# Via environment variable
COQUI_API_HOST=0.0.0.0 ./bin/coqui-launcher

# Via Make
make api HOST=0.0.0.0
make api HOST=0.0.0.0 PORT=3000

# Docker (already binds to 0.0.0.0 inside the container)
make docker-api              # port 3300
make docker-api PORT=3000    # custom port
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
php bin/coqui api --host 0.0.0.0
./bin/coqui-launcher --host 0.0.0.0
COQUI_API_HOST=0.0.0.0 ./bin/coqui-launcher
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
File upload endpoints accept `Content-Type: multipart/form-data`.
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
| `validation_error` | 400 | Invalid input data |
| `missing_field` | 400 | Required field not provided |
| `invalid_format` | 400 | Field value has wrong format |
| `conflict` | 409 | Resource already exists |
| `agent_busy` | 409 | Session already has an active agent run |
| `profile_session_active` | 409 | A profiled session is already active and the client must confirm closure before creating or reassigning a fresh one |
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
3. Inspect session state, turns, tasks, artifacts, todos, schedules, loops, and server status through read-oriented endpoints.

### Conversation Model

- **Session** — the durable conversation container.
- **Turn** — one prompt/response cycle within a session.
- **Message** — the stored user, assistant, and tool records produced during a turn.

### Recommended Integration Flow

1. If your client exposes personalities, call `GET /api/v1/profiles` first.
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
2. Capture the returned file IDs.
3. Pass those IDs in the `files` array when sending a message.

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
  },
  "channels": {
    "total": 1,
    "enabled": 1,
    "ready": 1,
    "errors": 0,
    "active_runtimes": 1,
    "registered_drivers": 3
  }
}
```

### Sessions

A session is a persistent conversation context. Messages and turns are scoped to a session.

Profile-scoped sessions enforce a single active interactive conversation per profile. `POST /api/v1/sessions/resolve` keeps the newest active profiled session and archives/closes older duplicates automatically. `POST /api/v1/sessions` remains the fresh-conversation endpoint, but when a profiled session is already active it returns `409 profile_session_active` until the client explicitly confirms closure of that active profiled session.

Group sessions are orchestrator-managed interactive sessions identified by a normalized member composition. Create or resolve them by setting `group_enabled=true` and passing a `members` array. `POST /api/v1/sessions/resolve` reuses the active group session for the same member composition, while `POST /api/v1/sessions` forces a fresh one and returns `409 group_session_active` unless the client explicitly confirms closure of the conflicting active composition.

Session lifecycle fields have distinct meanings:

- `closed` is the mutability state. Closed sessions are terminal and read-only: prompts, message deletion, file uploads/deletes, project reassignment, session metadata updates, and session-bound task or loop attachment are rejected with `409 session_closed`.
- `archived` is the discovery state. Archived sessions are hidden from active listings by default but remain fully inspectable through the read endpoints.
- In the current profile-rollover flow, archived sessions are also closed. A session may be closed without being archived if Coqui needs a terminal but still explicitly visible record.

Channel-backed interactive sessions are still first-class visible sessions. Their payloads include `channel_bound: true` plus a `channel` object describing the bound channel instance. Ordinary app-style session resolution intentionally skips channel-backed sessions so a normal "resume latest chat" flow does not land inside a Signal, Telegram, or Discord conversation lane.

#### `GET /api/v1/sessions`

List sessions, ordered by most recently updated.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | `50` | Max sessions to return (capped at 200) |
| `status` | string | `"active"` | Filter by lifecycle state: `active`, `closed`, `archived`, or `all` |
| `include_closed` | bool | `false` | Legacy alias for `status=all` when `status` is omitted |
| `profile` | string | unset | Filter by profile scope. Use a profile name like `caelum` or `none` for unprofiled sessions only |

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
      "channel_bound": true,
      "channel": {
        "instance_id": "channel_123",
        "name": "signal-primary",
        "driver": "signal",
        "display_name": "Signal Primary"
      },
      "created_at": "2026-02-16T14:30:00+00:00",
      "updated_at": "2026-02-16T15:45:12+00:00",
      "token_count": 12450
    }
  ],
  "count": 1,
  "status": "active",
  "profile": null,
  "counts": {
    "active": 1,
    "closed": 3,
    "archived": 3,
    "total": 4
  }
}
```

Use `GET /api/v1/sessions?status=archived` to browse historical conversations without making them active again. Once you have a session ID, the normal read endpoints such as `GET /api/v1/sessions/{id}`, `GET /api/v1/sessions/{id}/messages`, and `GET /api/v1/sessions/{id}/turns` continue to work for archived history.

Use `GET /api/v1/sessions?profile=caelum&status=all` to browse a single profile scope, or `GET /api/v1/sessions?profile=none&status=all` to browse only unprofiled sessions.

#### `POST /api/v1/sessions`

Create a new session.

This endpoint always creates a fresh session. For REPL-style "resume the last active interactive session for this scope or create one" behavior, use `POST /api/v1/sessions/resolve`.

**Request Body**

```json
{
  "model_role": "orchestrator",
  "profile": "caelum",
  "confirm_close_active_profile_session": true
}
```

Group-session requests use the same endpoint with a group scope instead of `profile`:

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
| `profile` | string | No | `null` | Personality profile name. Must match a `profiles/{name}/soul.md` in the workspace. |
| `group_enabled` | bool | No | `false` | When `true`, create a group session instead of a single-profile or unprofiled session. Group sessions must remain orchestrator-managed. |
| `members` | array<string> | When `group_enabled=true` | — | Group member profile names. Must be unique, known profiles. |
| `group_max_rounds` | int | No | `3` | Max same-turn coordination rounds for a group session. Minimum `1`. |
| `confirm_close_active_profile_session` | bool | No | `false` | Required when `profile` already has an active interactive session and the client explicitly wants to close/archive it before starting a fresh one |
| `confirm_close_active_group_session` | bool | No | `false` | Required when the requested group composition already has another active interactive session and the client explicitly wants to close/archive it before forcing a fresh one |

**Response `201`**

```json
{
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "profile": "caelum",
  "active_project_id": null
}
```

**Response `201`** — group session created:

```json
{
  "id": "b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "profile": null,
  "group_enabled": 1,
  "group_max_rounds": 4,
  "group_members": [
    {
      "profile": "caelum",
      "position": 0
    },
    {
      "profile": "nova",
      "position": 1
    }
  ],
  "active_project_id": null
}
```

**Response `409`** — active profiled session must be confirmed first:

```json
{
  "error": "Profile \"caelum\" already has an active session. Confirm closure before starting or reassigning a fresh session.",
  "code": "profile_session_active",
  "details": {
    "profile": "caelum",
    "active_session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
    "active_session_count": 1,
    "confirm_field": "confirm_close_active_profile_session"
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

- Omit `profile` to target the unprofiled interactive session pool.
- Pass `profile` to target a profile-specific interactive session pool.
- Hidden worker sessions and visible channel-backed sessions are excluded from ordinary reuse.
- If multiple active interactive sessions exist for the same profile, Coqui keeps the newest one and archives/closes the older duplicates before responding.

**Request Body**

```json
{
  "model_role": "orchestrator",
  "profile": "caelum"
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
| `profile` | string | No | `null` | Personality profile scope. Omit to resolve the unprofiled session pool. |
| `group_enabled` | bool | No | `false` | When `true`, resolve the group-session pool for the supplied member composition. |
| `members` | array<string> | When `group_enabled=true` | — | Group member profile names. Order is normalized, so the same set resolves the same active session. |
| `group_max_rounds` | int | No | `3` | Used only when a new group session must be created. Existing sessions keep their stored round cap. |

**Response `200`** — existing session reused:

```json
{
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "profile": "caelum",
  "active_project_id": null,
  "created": false
}
```

**Response `201`** — new session created:

```json
{
  "id": "b1c2d3e4f5a6b7c8d9e0f1a2b3c4d5e6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "profile": null,
  "group_enabled": 1,
  "group_max_rounds": 4,
  "group_members": [
    {
      "profile": "caelum",
      "position": 0
    },
    {
      "profile": "nova",
      "position": 1
    }
  ],
  "active_project_id": null,
  "created": true
}
```

#### `GET /api/v1/sessions/{id}`

Get session details.

**Response `200`**

```json
{
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "profile": "caelum",
  "status": "active",
  "is_closed": 0,
  "is_archived": 0,
  "closed_at": null,
  "archived_at": null,
  "closure_reason": null,
  "channel_bound": true,
  "channel": {
    "instance_id": "channel_123",
    "name": "signal-primary",
    "driver": "signal",
    "display_name": "Signal Primary"
  },
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
    "profile": "caelum",
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
    },
    "todos": {
      "total": 5,
      "pending": 1,
      "in_progress": 1,
      "completed": 3,
      "cancelled": 0
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

Update session metadata. Supports renaming the title, updating the role, changing the profile scope for non-group sessions, and updating `group_max_rounds` for existing group sessions.

Closed or archived sessions are read-only. This endpoint returns `409 session_closed` when clients try to modify them.

**Request Body**

```json
{
  "title": "My refactoring session",
  "profile": "caelum",
  "confirm_close_active_profile_session": true
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
| `profile` | string | No | Set or clear the session profile (`""` clears it) |
| `group_max_rounds` | int | No | Update the round cap for an existing group session |
| `confirm_close_active_profile_session` | bool | No | Required when reassigning an active session into a profile that already has another active interactive session |

Group-session constraints:

- `model_role` must remain `orchestrator`.
- `profile` cannot be assigned to a group session.
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
      "profile": "caelum",
      "position": 0
    },
    {
      "profile": "nova",
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
  "profile": "iris",
  "confirm_close_active_group_session": true
}
```

#### `DELETE /api/v1/sessions/{id}/members/{profile}`

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

Compress older conversation history into a concise summary, preserving recent turns and workflow state (todos, artifacts).

**Request Body**

```json
{
  "keep_recent": 10,
  "focus": "database migration"
}
```

Conversation summarization is currently REPL-first and agent-tool driven. The HTTP API does not expose a summarize endpoint in the current router.

#### `GET /api/v1/sessions/{id}/messages`

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
  "files": ["a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `prompt` | string | Yes | The user prompt to send to the agent |
| `files` | string[] | No | Array of file IDs from prior uploads (see [Files](#files)) |

When `files` are provided, the referenced uploads are attached to the message. Image files (JPEG, PNG, GIF, WebP) are sent to the LLM as vision content. Text and document files are read and injected as context blocks in the prompt.

Closed sessions reject new prompts with `409 session_closed`. The same read-only guard also applies to message deletion and the other session-scoped mutation endpoints. This gives clients a clean handoff state after a profiled conversation has been archived and closed.

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
| `loop_start` | Loop execution started | `{"loop_id": "loop-123"}` |
| `loop_iteration_start` | Loop iteration started | `{"loop_id": "loop-123", "iteration": 2}` |
| `loop_stage_start` | Loop stage started | `{"loop_id": "loop-123", "iteration": 2, "role": "coder"}` |
| `loop_stage_end` | Loop stage finished | `{"loop_id": "loop-123", "iteration": 2, "role": "coder"}` |
| `loop_iteration_end` | Loop iteration finished | `{"loop_id": "loop-123", "iteration": 2}` |
| `loop_complete` | Loop execution completed | `{"loop_id": "loop-123", "status": "completed"}` |
| `title` | Session title generated after the turn | `{"title": "Refactor auth flow"}` |
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

Files are session-scoped uploads that can be attached to messages for multimodal context. Images are sent to the LLM via vision APIs; text and document files are injected as context in the prompt.

**Supported MIME types:**

| Category | Types |
|----------|-------|
| Images | `image/jpeg`, `image/png`, `image/gif`, `image/webp` |
| Text | `text/plain`, `text/markdown`, `text/csv`, `text/html`, `text/xml`, `text/x-php`, `text/javascript` |
| Documents | `application/json`, `application/xml`, `application/pdf`, `application/x-yaml` |

**Limits:**

- Maximum file size: **50 MiB** per file
- Maximum files per request: **20**

#### `POST /api/v1/sessions/{id}/files`

Upload one or more files to a session. Uses `multipart/form-data` encoding.

Closed or archived sessions are read-only. `POST` and `DELETE` file endpoints return `409 session_closed` when clients try to mutate historical sessions.

**Request**

Send files as form fields named `files[]`. Multiple files can be uploaded in a single request.

```bash
curl -X POST http://127.0.0.1:3300/api/v1/sessions/{id}/files \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -F "files[]=@screenshot.png" \
  -F "files[]=@notes.txt"
```

**Response `201`**

```json
{
  "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "files": [
    {
      "id": "f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6",
      "original_name": "screenshot.png",
      "mime_type": "image/png",
      "size": 245760,
      "is_image": true,
      "created_at": "2026-02-16T14:30:05+00:00"
    },
    {
      "id": "f2a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6",
      "original_name": "notes.txt",
      "mime_type": "text/plain",
      "size": 1024,
      "is_image": false,
      "created_at": "2026-02-16T14:30:05+00:00"
    }
  ],
  "count": 2
}
```

If some files succeed and others fail, the response includes both:

```json
{
  "session_id": "...",
  "files": [{ "id": "...", "..." : "..." }],
  "count": 1,
  "errors": [
    {
      "file": "malware.exe",
      "error": "File type \"application/x-msdownload\" is not allowed"
    }
  ]
}
```

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `400` | `missing_field` | No files in the request |
| `404` | `session_not_found` | Session does not exist |
| `413` | `payload_too_large` | More than 20 files in a single request |

#### `GET /api/v1/sessions/{id}/files`

List all uploaded files for a session.

**Response `200`**

```json
{
  "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "files": [
    {
      "id": "f1a2b3c4d5e6f7a8b9c0d1e2f3a4b5c6",
      "original_name": "screenshot.png",
      "mime_type": "image/png",
      "size": 245760,
      "is_image": true,
      "created_at": "2026-02-16T14:30:05+00:00"
    }
  ],
  "count": 1
}
```

#### `GET /api/v1/sessions/{id}/files/{fileId}`

Download a specific file. Returns the raw file content with appropriate headers.

**Response `200`**

Returns the file binary with:
- `Content-Type`: the file's MIME type
- `Content-Length`: file size in bytes
- `Content-Disposition`: `inline; filename="original_name.ext"`

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `404` | `session_not_found` | Session does not exist |
| `404` | `not_found` | File not found |

#### `DELETE /api/v1/sessions/{id}/files/{fileId}`

Delete a specific uploaded file.

**Response `200`**

```json
{
  "deleted": true
}
```

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `404` | `session_not_found` | Session does not exist |
| `404` | `not_found` | File not found |

### Turns

A turn represents a single request-response cycle within a session. Each turn contains the user prompt, agent response, token usage, timing, and tool usage metadata.

#### `GET /api/v1/sessions/{id}/turns`

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

The HTTP API exposes configuration inspection plus dry-run validation. Mutating config and role definitions remains REPL-only.

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

- `profile={name}` resolves models and role overrides for a specific profile and filters out roles disallowed by that profile's `preferences.json` role policy.
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
  "profile": null,
  "selectable_only": false
}
```

#### `GET /api/v1/roles`

App-facing alias for `GET /api/v1/config/roles`.

Unlike the config route, this alias defaults to `selectable=true` so picker UIs get switchable roles by default.

#### `GET /api/v1/config/roles/{name}`

Get a single role with full details. System roles return metadata without instructions. Custom roles include the full instruction text.

Supports `?profile={name}` to resolve profile-specific role overrides and effective models.

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
  "profile": null,
  "profile_override": false,
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

#### `GET /api/v1/config/profiles`

Lists discovered profiles so clients can offer a profile picker instead of manual text entry.

**Response `200`**

```json
{
  "profiles": [
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
  "default_profile": "caelum"
}
```

#### `GET /api/v1/config/profiles/{name}`

Return a single profile record with picker-friendly policy details.

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
      "todos": true,
      "background_tasks": true
    },
    "prompt_sections": {
      "soul": true,
      "backstory": true,
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
  "soul": "# Caelum\n\nA calm companion."
}
```

**Response `404`** — profile not found.

#### `GET /api/v1/profiles`

App-facing alias for `GET /api/v1/config/profiles`.

#### `GET /api/v1/profiles/{name}`

App-facing alias for `GET /api/v1/config/profiles/{name}`.

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

Child runs track sub-agent invocations within a session. When the orchestrator spawns a child agent (e.g., `coder`, `reviewer`), the run is recorded with its role, model, prompt, result, and token usage.

#### `GET /api/v1/sessions/{id}/child-runs`

List all child agent runs for a session.

**Response `200`**

```json
{
  "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "child_runs": [
    {
      "id": "cr1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
      "parent_iteration": 3,
      "agent_role": "coder",
      "model": "anthropic/claude-sonnet-4-20250514",
      "prompt": "Implement the ServerHandler class...",
      "result": "I've created the ServerHandler class...",
      "token_count": 2450,
      "created_at": "2026-02-16T14:32:15+00:00"
    }
  ],
  "count": 1
}
```

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `404` | `session_not_found` | Session does not exist |

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

Request a launcher-managed API restart. This endpoint is accepted only when the API process was started under `coqui-launcher` with restart support enabled.

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

### Projects & Sprints

Projects organize work across sessions. Sprints break project work into ordered chunks and provide stable identifiers for loops, tasks, and todo workflows.

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

Get a project by ID or slug. The response includes summary sprint counts and the currently active sprint, if any.

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

#### `GET /api/v1/projects/{idOrSlug}/sprints`

List sprints for one project.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `status` | string | `null` | Optional sprint status filter |

#### `POST /api/v1/projects/{idOrSlug}/sprints`

Create a sprint inside a project.

**Request Body**

```json
{
  "title": "MVP Sprint",
  "acceptance_criteria": "Core app shell is navigable.",
  "contract_artifact_id": "artifact_contract_123",
  "max_review_rounds": 4
}
```

**Response `201`**

```json
{
  "sprint": {
    "id": "spr_123",
    "project_id": "proj_123",
    "title": "MVP Sprint",
    "status": "planned",
    "max_review_rounds": 4
  },
  "project": {
    "id": "proj_123",
    "slug": "career-ops"
  }
}
```

#### `GET /api/v1/sprints/{id}`

Get a sprint by ID, including its parent project summary.

#### `PATCH /api/v1/sprints/{id}`

Update editable sprint fields.

Supported fields:

- `title`
- `acceptance_criteria`
- `contract_artifact_id`
- `last_session_id`
- `max_review_rounds`

**Response `200`**

Returns the same detail payload as `GET /api/v1/sprints/{id}`.

#### `DELETE /api/v1/sprints/{id}`

Delete a sprint while it is still in the `planned` state.

**Response `200`**

```json
{
  "deleted": true,
  "id": "spr_123"
}
```

#### `POST /api/v1/sprints/{id}/start`

Transition a sprint from `planned` to `in_progress`.

#### `POST /api/v1/sprints/{id}/submit-review`

Transition a sprint from `in_progress` to `review`.

#### `POST /api/v1/sprints/{id}/complete`

Transition a sprint from `review` to `complete`.

#### `POST /api/v1/sprints/{id}/reject`

Transition a sprint from `review` to `rejected`.

Optional request body:

```json
{
  "reviewer_notes": "Needs stronger acceptance coverage."
}
```

All sprint action routes return the same detail payload as `GET /api/v1/sprints/{id}`.

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
  "profile": "caelum",
  "title": "Auth refactor",
  "parent_session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "max_iterations": 25,
  "project_id": "p1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "sprint_id": "s1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `prompt` | string | Yes | — | The task prompt (max 1 MiB) |
| `role` | string | No | `"orchestrator"` | Agent role for the task. Must be a known role. |
| `profile` | string | No | `null` | Explicit profile for the task session. Must exist under `profiles/{name}/soul.md`. If `parent_session_id` is provided, it must match the parent session profile. |
| `title` | string | No | `null` | Human-readable title for the task |
| `parent_session_id` | string | No | `null` | Link the task to a parent session (must exist). The task inherits that session's profile when one is set. |
| `max_iterations` | int | No | `25` | Maximum agent iterations (1–100) |
| `project_id` | string | No | `null` | Attach the task to an existing project |
| `sprint_id` | string | No | `null` | Attach the task to an existing sprint. When provided, the sprint must exist and belong to the specified project if `project_id` is also set. |

When `parent_session_id` is provided, it must refer to a writable session. Closed or archived parent sessions return `409 session_closed`.

**Response `201`**

```json
{
  "id": "t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "session_id": "s1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "status": "running",
  "prompt": "Refactor the authentication module",
  "role": "coder",
  "profile": "caelum",
  "title": "Auth refactor",
  "project_id": "p1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "sprint_id": "s1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
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
  "sprint_id": "s1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
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

### Todos

Session-scoped task tracking. Todos are linked to a session and optionally to an artifact and/or parent todo for subtask hierarchies.

#### `GET /api/v1/sessions/{id}/todos`

List todos for a session with optional filters.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `status` | string | `null` | Filter: `pending`, `in_progress`, `completed`, `cancelled` |
| `artifact_id` | string | `null` | Filter by linked artifact |
| `parent_id` | string | `null` | Filter by parent todo (for subtasks) |

**Response `200`**

```json
{
  "todos": [
    {
      "id": "a1b2c3d4",
      "session_id": "s1a2b3c4",
      "title": "Implement authentication module",
      "status": "pending",
      "priority": "high",
      "artifact_id": null,
      "parent_id": null,
      "created_by": "plan",
      "completed_by": null,
      "notes": "See auth spec in artifact abc123",
      "sort_order": 1,
      "created_at": "2026-02-16T14:30:00+00:00",
      "updated_at": "2026-02-16T14:30:00+00:00",
      "completed_at": null
    }
  ],
  "count": 1
}
```

#### `POST /api/v1/sessions/{id}/todos`

Create a single session-scoped todo.

**Request Body**

```json
{
  "title": "Implement authentication module",
  "priority": "high",
  "artifact_id": "abc123",
  "parent_id": null,
  "sprint_id": "sprint_123",
  "notes": "See auth spec",
  "sort_order": 3
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `title` | string | Yes | — | Task description |
| `priority` | string | No | `"medium"` | `high`, `medium`, or `low` |
| `artifact_id` | string | No | `null` | Link to an artifact |
| `parent_id` | string | No | `null` | Parent todo ID (for subtasks) |
| `sprint_id` | string | No | `null` | Link the todo to a sprint |
| `notes` | string | No | `null` | Additional context |
| `sort_order` | integer | No | auto | Override the default sort order |

**Response `201`**

```json
{
  "id": "a1b2c3d4",
  "session_id": "s1a2b3c4",
  "title": "Implement authentication module",
  "status": "pending",
  "priority": "high",
  "artifact_id": "abc123",
  "parent_id": null,
  "sprint_id": "sprint_123",
  "notes": "See auth spec",
  "sort_order": 3,
  "subtasks": []
}
```

**Response `400`** — validation error:

```json
{
  "error": "Title is required",
  "code": "validation_error"
}
```

#### `GET /api/v1/sessions/{id}/todos/stats`

Get aggregate statistics for session todos.

**Response `200`**

```json
{
  "total": 10,
  "pending": 3,
  "in_progress": 2,
  "completed": 4,
  "cancelled": 1
}
```

#### `GET /api/v1/sessions/{id}/todos/{todoId}`

Get a specific todo with its subtasks.

**Response `200`**

```json
{
  "id": "a1b2c3d4",
  "title": "Implement authentication module",
  "status": "in_progress",
  "priority": "high",
  "subtasks": []
}
```

**Response `404`**

```json
{
  "error": "Todo not found",
  "code": "not_found"
}
```

#### `PATCH /api/v1/sessions/{id}/todos/{todoId}`

Update a todo's fields.

**Request Body**

```json
{
  "status": "in_progress",
  "priority": "high",
  "notes": "Started working on this"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `title` | string | No | New title |
| `status` | string | No | `pending`, `in_progress`, `completed`, `cancelled` |
| `priority` | string | No | `high`, `medium`, `low` |
| `notes` | string | No | Updated notes |
| `artifact_id` | string or `null` | No | Relink or clear the linked artifact |
| `parent_id` | string or `null` | No | Relink or clear the parent todo |
| `sprint_id` | string or `null` | No | Relink or clear the sprint |
| `sort_order` | integer | No | Override the current sort order |

**Response `200`**

```json
{
  "id": "a1b2c3d4",
  "status": "in_progress",
  "priority": "high",
  "subtasks": []
}
```

#### `PATCH /api/v1/sessions/{id}/todos/bulk`

Update multiple todos in a single request. Max 25 items per call.

**Request Body**

```json
{
  "updates": [
    {"id": "a1b2c3d4", "status": "completed"},
    {"id": "e5f6g7h8", "status": "in_progress", "priority": "high"}
  ]
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `updates` | array | Yes | Array of update objects (max 25) |
| `updates[].id` | string | Yes | Todo ID to update |
| `updates[].status` | string | No | New status |
| `updates[].priority` | string | No | New priority |
| `updates[].title` | string | No | New title |
| `updates[].notes` | string or `null` | No | Updated notes |

**Response `200`**

```json
{
  "updated_count": 2
}
```

#### `POST /api/v1/sessions/{id}/todos/reorder`

Set explicit sort orders for multiple todos.

**Request Body**

```json
{
  "ordering": [
    {"id": "a1b2c3d4", "sort_order": 1},
    {"id": "e5f6g7h8", "sort_order": 2}
  ]
}
```

**Response `200`**

```json
{
  "reordered_count": 2
}
```

#### `POST /api/v1/sessions/{id}/todos/{todoId}/complete`

Mark a todo as completed. Optional request fields: `completed_by`, `notes`.

**Response `200`**

```json
{
  "id": "a1b2c3d4",
  "status": "completed",
  "subtasks": []
}
```

#### `POST /api/v1/sessions/{id}/todos/{todoId}/reopen`

Reopen a completed or cancelled todo back to `pending`.

**Response `200`**

```json
{
  "id": "a1b2c3d4",
  "status": "pending",
  "subtasks": []
}
```

#### `POST /api/v1/sessions/{id}/todos/{todoId}/cancel`

Cancel a pending or in-progress todo.

**Response `200`**

```json
{
  "id": "a1b2c3d4",
  "status": "cancelled",
  "subtasks": []
}
```

#### `DELETE /api/v1/sessions/{id}/todos/{todoId}`

Delete a todo and all its subtasks.

**Response `200`**

```json
{
  "deleted": true,
  "id": "a1b2c3d4"
}
```

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
  "sprint_id": "sprint_123",
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
| `sprint_id` | string | No | `null` | Link the artifact to a sprint |
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

If `content` is included, the patch creates a new artifact version. Without `content`, metadata-only changes stay on the current version row.

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

#### `GET /api/v1/sessions/{id}/artifacts/{artifactId}/versions`

List all versions of an artifact.

**Response `200`**

```json
{
  "artifact_id": "art_1a2b3c4d",
  "versions": [
    {
      "id": "ver_123",
      "version": 1,
      "content": "## Steps\n1. ...",
      "change_summary": "Initial version",
      "created_by": "plan",
      "created_at": "2026-02-16T14:30:00Z"
    },
    {
      "version": 2,
      "content": "## Updated Steps\n1. ...",
      "change_summary": "Added error handling steps",
      "created_by": "coder",
      "created_at": "2026-02-16T15:00:00Z"
    }
  ],
  "count": 2
}
```

#### `POST /api/v1/sessions/{id}/artifacts/{artifactId}/versions`

Create a new artifact version directly.

**Request Body**

```json
{
  "content": "## Updated Steps\n1. ...",
  "change_summary": "Added rollback notes",
  "title": "Database migration plan v2",
  "stage": "review"
}
```

**Response `200`**

Returns the full current artifact object.

#### `POST /api/v1/sessions/{id}/artifacts/{artifactId}/versions/{versionId}/restore`

Restore an older artifact version by version row id.

**Response `200`**

Returns the full current artifact object with a newly created version containing the restored content.

#### `DELETE /api/v1/sessions/{id}/artifacts/{artifactId}`

Delete an artifact and its version history.

**Response `200`**

```json
{
  "deleted": true,
  "id": "art_1a2b3c4d"
}
```

### Schedules

Schedules enable autonomous, timer-driven execution via cron-style expressions. The API server evaluates due schedules every 60 seconds and creates background tasks automatically. A circuit breaker auto-disables schedules after consecutive failures.

Schedule evaluation only happens inside the API server event loop. If the API server is not running, schedules remain persisted but no due work is dispatched.

Filesystem-backed schedules synced from `workspace/schedules/*.json` remain read-only from mutation endpoints. When a schedule comes from a JSON file, update, delete, enable, disable, and trigger requests return `409 conflict` with the source path.

#### `POST /api/v1/schedules`

Create a schedule.

**Request Body**

```json
{
  "name": "daily-review",
  "schedule_expression": "0 9 * * 1-5",
  "prompt": "Review recent changes.",
  "role": "orchestrator",
  "max_iterations": 12,
  "timezone": "UTC",
  "max_failures": 5
}
```

**Response `201`**

```json
{
  "schedule": {
    "id": "a1b2c3d4",
    "name": "daily-review",
    "schedule_expression": "0 9 * * 1-5",
    "prompt": "Review recent changes.",
    "role": "orchestrator",
    "max_iterations": 12,
    "enabled": 1,
    "created_by": "api",
    "timezone": "UTC",
    "max_failures": 5
  }
}
```

#### `GET /api/v1/schedules`

List all schedules with optional filters.

**Query Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `enabled` | `0` or `1` | Filter by enabled/disabled status |
| `created_by` | string | Filter by creator (e.g. `"agent"`, `"api"`) |

**Response `200`**

```json
{
  "schedules": [
    {
      "id": "a1b2c3d4",
      "name": "daily-review",
      "schedule_expression": "0 9 * * 1-5",
      "prompt": "Review recent changes...",
      "role": "orchestrator",
      "max_iterations": 48,
      "enabled": 1,
      "timezone": "UTC",
      "next_run_at": "2026-02-17T09:00:00Z",
      "last_run_at": "2026-02-16T09:00:00Z",
      "last_task_id": "t1a2b3c4",
      "last_status": "completed",
      "run_count": 5,
      "failure_count": 0,
      "max_failures": 3,
      "created_at": "2026-02-10T12:00:00Z",
      "updated_at": "2026-02-16T09:01:00Z"
    }
  ],
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

Update a mutable schedule.

Use this endpoint for definition fields such as `name`, `description`, `schedule_expression`, `prompt`, `role`, `max_iterations`, `timezone`, and `max_failures`. Use the action endpoints below for enabled state changes.

**Response `200`** — updated schedule object.

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

Use `session_id` when the loop should inherit the session's active project and downstream profile context. Use `project_id` or `project_slug` to pin the loop to a project directly. Use `sprint_id` to bind the first iteration to an existing sprint; when only `sprint_id` is supplied, the loop inherits that sprint's project automatically.

When `session_id` is provided, it must refer to a writable session. Closed or archived sessions return `409 session_closed`.

**Request Body**

```json
{
  "definition": "harness",
  "goal": "Implement loop lifecycle endpoints",
  "session_id": "sess_123",
  "parameters": {
    "subject": "loop lifecycle API"
  },
  "max_iterations": 3,
  "sprint_id": "spr_123"
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
    "sprint_id": "spr_123",
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

#### `GET /api/v1/loops/{id}`

Get detailed loop status including current iteration and stage information.

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

### Channels

Channels provide first-class outbound user communication surfaces such as Signal, Telegram, and Discord. The API server owns live channel runtimes; the REPL can edit config and inspect stored state, but only the API server performs runtime reconciliation and transport work. Channel mutations now return a `restart` payload when operator action is required so clients can keep the API and persisted config in sync.

#### `GET /api/v1/channels`

List configured channel instances with joined runtime health.

**Query Parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `enabled` | bool | `null` | Filter to enabled or disabled instances |
| `driver` | string | `null` | Filter by driver name |

#### `POST /api/v1/channels`

Create a channel instance definition. The response includes the updated channel plus a `restart` object when the API should be restarted to reload runtimes against the persisted config cleanly.

**Request Body**

```json
{
  "name": "signal-primary",
  "driver": "signal",
  "displayName": "Signal Primary",
  "defaultProfile": "caelum",
  "boundSessionId": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "settings": {
    "transport": "signal-cli"
  }
}
```

Supported fields: `name`, `driver`, `enabled`, `displayName`, `defaultProfile`, `boundSessionId`, `settings`, `allowedScopes`, and `security`.

`boundSessionId` is optional. When set, the channel instance routes all inbound conversations into one existing visible interactive session instead of creating per-conversation interactive sessions.

**Response `201`**

```json
{
  "channel": {
    "id": "channel_123",
    "name": "signal-primary",
    "driver": "signal",
    "display_name": "Signal Primary",
    "default_profile": "caelum",
    "bound_session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
  },
  "restart": {
    "required": true,
    "reason": "channel_configuration_changed"
  }
}
```

#### `GET /api/v1/channels/drivers`

List registered built-in and external channel drivers with capability metadata.

#### `GET /api/v1/channels/{id}`

Get one channel instance by id or name.

**Response `200`**

```json
{
  "channel": {
    "id": "channel_123",
    "name": "signal-primary",
    "driver": "signal",
    "display_name": "Signal Primary",
    "enabled": true,
    "default_profile": "caelum",
    "bound_session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
    "worker_status": "running",
    "ready": true,
    "summary": "Signal runtime ready."
  }
}
```

#### `PATCH /api/v1/channels/{id}`

Update any mutable channel fields from the create payload. Successful mutations return the updated channel plus restart metadata when a clean API restart is required.

Clients may send either `boundSessionId` or `bound_session_id` in update payloads. The response always normalizes the stored field to `bound_session_id`.

#### `DELETE /api/v1/channels/{id}`

Remove a configured channel instance. Successful mutations return restart metadata when the API should be restarted to fully reconcile runtime state.

#### `POST /api/v1/channels/{id}/enable`

Enable a channel instance and return restart metadata when the API should be restarted to reload runtimes cleanly.

#### `POST /api/v1/channels/{id}/disable`

Disable a channel instance and return restart metadata when the API should be restarted to reload runtimes cleanly.

#### `POST /api/v1/channels/{id}/test`

Force a reconcile/tick cycle and return the refreshed channel row.

#### `GET /api/v1/channels/{id}/health`

Return the stored runtime health snapshot for one channel.

#### `GET /api/v1/channels/{id}/links`

List channel identity links.

#### `POST /api/v1/channels/{id}/links`

Create a channel identity link.

**Request Body**

```json
{
  "remote_user_key": "signal:+15551234567",
  "profile": "caelum"
}
```

#### `DELETE /api/v1/channels/{id}/links/{linkId}`

Delete a channel identity link.

#### `GET /api/v1/channels/{id}/conversations`

List stored channel conversation records for one instance.

#### `GET /api/v1/channels/{id}/events`

List stored inbound event records for one instance.

#### `GET /api/v1/channels/{id}/deliveries`

List stored delivery records for one instance.

### Webhooks

Webhooks receive signed HTTP POST requests from external services and automatically spawn background tasks. Signature verification supports GitHub, Slack, and generic HMAC schemes.

Incoming webhook delivery is an API-server responsibility. The REPL can manage webhook records through commands and tools, but it does not host an HTTP listener.

#### `POST /api/v1/webhooks/incoming/{name}`

Receive an incoming webhook delivery. This is the endpoint external services send payloads to.

**Headers** — signature header depends on the webhook's source type:
- GitHub: `X-Hub-Signature-256`
- Slack: `X-Slack-Signature` + `X-Slack-Request-Timestamp`
- Generic: `X-Webhook-Signature`, `X-Signature`, or `Authorization: Bearer <secret>`

**Request Body** — raw payload (JSON or other). Maximum 1 MB.

**Response `200`**

```json
{
  "accepted": true,
  "task_id": "t1a2b3c4d5e6f7g8"
}
```

**Response `400`** — disabled webhook, empty body, payload too large.

**Response `401`** — invalid signature.

**Response `404`** — unknown webhook name.

#### `GET /api/v1/webhooks`

List all webhook subscriptions. Secrets are masked in responses.

**Response `200`**

```json
{
  "webhooks": [
    {
      "id": "w1a2b3c4",
      "name": "github-push",
      "description": "Handles GitHub push events",
      "source": "github",
      "secret": "abc1****5678",
      "prompt_template": "A push was made: {{payload}}",
      "role": "orchestrator",
      "profile": "caelum",
      "max_iterations": 48,
      "enabled": 1,
      "event_filter": "push,pull_request",
      "trigger_count": 12,
      "created_at": "2026-02-10T12:00:00Z"
    }
  ],
  "stats": {
    "total": 2,
    "enabled": 1,
    "disabled": 1,
    "total_triggers": 15
  }
}
```

#### `POST /api/v1/webhooks`

Create a webhook subscription. Returns the signing secret (shown only once in full).

**Request Body**

```json
{
  "name": "github-push",
  "prompt_template": "Review this push: {{payload}}",
  "source": "github",
  "role": "coder",
  "profile": "caelum",
  "event_filter": "push,pull_request",
  "description": "GitHub push handler",
  "max_iterations": 30
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `name` | string | Yes | — | Unique name (alphanumeric, hyphens, underscores) |
| `prompt_template` | string | Yes | — | Template with `{{payload}}`, `{{event_type}}`, `{{summary}}` placeholders |
| `source` | string | No | `"generic"` | Verification scheme: `generic`, `github`, or `slack` |
| `role` | string | No | `"orchestrator"` | Agent role for triggered tasks |
| `profile` | string | No | `null` | Profile assigned to triggered task sessions. Must exist under `profiles/{name}/soul.md`. |
| `event_filter` | string | No | `null` | Comma-separated event types to accept |
| `description` | string | No | `null` | Human-readable description |
| `max_iterations` | int | No | `48` | Max iterations per triggered task (1–100) |

**Response `201`** — webhook object with full secret.

**Response `400`** — validation error (duplicate name, invalid source).

#### `GET /api/v1/webhooks/{id}`

Get a webhook subscription. Secret is masked.

**Response `200`** — webhook object.

**Response `404`** — webhook not found.

#### `PUT /api/v1/webhooks/{id}`

Update a webhook subscription.

**Request Body** — any subset of: `name`, `description`, `source`, `prompt_template`, `role`, `profile`, `max_iterations`, `enabled`, `event_filter`.

**Response `200`** — updated webhook object (secret masked).

**Response `400`** — validation error.

**Response `404`** — webhook not found.

#### `DELETE /api/v1/webhooks/{id}`

Delete a webhook subscription and all its delivery logs.

**Response `200`**

```json
{
  "deleted": true
}
```

**Response `404`** — webhook not found.

#### `POST /api/v1/webhooks/{id}/rotate`

Rotate the signing secret. Returns the new secret in full. Update the external service configuration immediately.

**Response `200`**

```json
{
  "rotated": true,
  "new_secret": "a1b2c3d4e5f6..."
}
```

**Response `404`** — webhook not found.

#### `GET /api/v1/webhooks/{id}/deliveries`

List recent delivery logs for a webhook.

**Query Parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `limit` | int | `50` | Max deliveries to return (1–100) |

**Response `200`**

```json
{
  "deliveries": [
    {
      "id": "d1a2b3c4",
      "webhook_id": "w1a2b3c4",
      "event_type": "push",
      "payload_summary": "{\"ref\": \"refs/heads/main\", ...}",
      "task_id": "t1a2b3c4",
      "status": "delivered",
      "source_ip": "140.82.115.1",
      "created_at": "2026-02-16T14:30:00Z"
    }
  ]
}
```

Delivery statuses currently include `delivered`, `test_delivered`, `filtered`, `rejected_disabled`, `rejected_signature`, `rejected_empty`, and `rejected_too_large`.

#### `GET /api/v1/webhooks/{id}/deliveries/{deliveryId}`

Fetch one delivery log entry for a webhook. If the delivery spawned a background task, the linked task record is included alongside the delivery metadata.

**Response `200`**

```json
{
  "delivery": {
    "id": "d1a2b3c4",
    "webhook_id": "w1a2b3c4",
    "event_type": "push",
    "task_id": "t1a2b3c4",
    "status": "delivered"
  },
  "task": {
    "id": "t1a2b3c4",
    "status": "pending",
    "role": "orchestrator"
  }
}
```

**Response `404`** — webhook or delivery not found.

#### `POST /api/v1/webhooks/{id}/test`

Create a synthetic delivery for a webhook using the real prompt-rendering and background-task dispatch path.

This endpoint is useful for validating prompt templates, routing, and profile assignment without waiting for an external service to send a live event. Test deliveries are logged with `status: test_delivered` and do not increment the webhook trigger counters.

**Request Body**

```json
{
  "event_type": "pull_request",
  "payload": {
    "repository": {
      "full_name": "carmelo/coqui"
    },
    "sender": {
      "login": "carmelo"
    }
  }
}
```

Both fields are optional. When omitted, `event_type` defaults to `test` and a minimal synthetic payload is generated automatically.

**Response `200`**

```json
{
  "status": "accepted",
  "delivery_id": "d1a2b3c4",
  "task_id": "t1a2b3c4",
  "session_id": "s1a2b3c4",
  "event_type": "pull_request",
  "prompt_preview": "Handle pull_request from carmelo/coqui"
}
```

**Response `404`** — webhook not found.

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

#### `GET /api/v1/server/prompt`

Return the fully constructed system prompt that the agent would receive on its next turn, together with tool and toolkit counts plus prompt-source metadata. Useful for debugging context size, inspecting which files are contributing to the prompt, and tracking which folders are consuming the prompt budget.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `role` | string | `orchestrator` | Role scope to resolve before rendering the prompt preview |
| `profile` | string | `null` | Optional profile scope to apply while rendering the prompt preview |

**Response `200`**

```json
{
  "profile": "caelum",
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

| Field | Type | Description |
|-------|------|-------------|
| `profile` | string\|null | Explicit profile scope used to render the prompt preview |
| `role` | string | Effective role used to render the prompt preview |
| `resolved_model` | string\|null | Exact resolved model string for the requested `role` + `profile` scope |
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

#### `GET /api/v1/server/backstory`

Return the generated `backstory.md` content and the manifest metadata for a profile, including per-file token counts, folder rollups, unsupported files, and regeneration status.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `profile` | string | `null` | Profile to inspect. When omitted, the endpoint returns an explicit `available: false` payload because unprofiled sessions do not have a backstory. |

**Response `200`** — profiled backstory:

```json
{
  "profile": "caelum",
  "available": true,
  "reason": null,
  "source_folder": "profiles/caelum/backstory",
  "generated_backstory_path": "profiles/caelum/backstory.md",
  "source_folder_exists": true,
  "has_generated_backstory": true,
  "generated_at": "2026-04-18T20:50:00+00:00",
  "last_modified_at": "2026-04-18T20:49:10+00:00",
  "content_hash": "sha256:abc123...",
  "needs_regeneration": false,
  "total_files": 3,
  "supported_file_count": 2,
  "successful_file_count": 2,
  "unsupported_file_count": 1,
  "failed_file_count": 0,
  "total_tokens": 820,
  "total_size_bytes": 5821,
  "content": "## Backstory\n\n### File: /intro.md\n...",
  "files": [
    {
      "path": "profiles/caelum/backstory/intro.md",
      "relative_path": "intro.md",
      "size_bytes": 211,
      "token_estimate": 164,
      "status": "ok",
      "error": null,
      "modified_at": "2026-04-18T20:49:10+00:00",
      "sha256": "..."
    }
  ],
  "folders": [
    {
      "path": "",
      "total_tokens": 492,
      "total_size_bytes": 401,
      "file_count": 1,
      "unsupported_file_count": 1,
      "failed_file_count": 0,
      "last_modified_at": "2026-04-18T20:49:10+00:00"
    }
  ],
  "unsupported_files": [
    {
      "path": "profiles/caelum/backstory/image.png",
      "relative_path": "image.png",
      "extension": "png",
      "reason": "Unsupported file type",
      "size_bytes": 2048,
      "modified_at": "2026-04-18T20:48:00+00:00",
      "sha256": "..."
    }
  ],
  "errors": []
}
```

**Response `200`** — no active profile:

```json
{
  "profile": null,
  "available": false,
  "reason": "no_active_profile",
  "content": null,
  "files": [],
  "folders": [],
  "unsupported_files": [],
  "errors": []
}
```

**Response `400`** — unknown profile:

```json
{
  "error": "Unknown profile \"missing\".",
  "code": "validation_error"
}
```

**Response `500`** — if prompt construction fails:

```json
{
  "error": "Failed to build system prompt: <reason>",
  "code": "internal_error"
}
```

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
php bin/coqui api --cors-origin "http://localhost:3000,https://myapp.com"
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

| REPL Command | API Equivalent | Notes |
|---|---|---|
| `/new` | `POST /api/v1/sessions` | Creates a new session |
| `/sessions` | `GET /api/v1/sessions` | Lists all sessions |
| `/history` | `GET /api/v1/sessions/{id}/messages` | Lists all messages in a session |
| `/model` | `GET /api/v1/config/models` | Lists available models and current config |
| `/config` | `GET /api/v1/config` | Returns current configuration (sanitized) |
| `/tasks` | `GET /api/v1/tasks` | Lists background tasks |
| `/task <id>` | `GET /api/v1/tasks/{id}` | Gets task detail |
| `/task-cancel <id>` | `POST /api/v1/tasks/{id}/cancel` | Cancels a running or pending task |
| `/projects` | `GET /api/v1/projects` | Lists projects |
| `/sprints` | `GET /api/v1/projects/{idOrSlug}/sprints` | Lists sprints for a project |
| `—` | `GET /api/v1/server/info` | Returns server runtime capabilities and status |
| `/toolkits` | `GET /api/v1/toolkits` | Lists all toolkit packages and tools with visibility |
| `/toolkits enable <pkg>` | `POST /api/v1/toolkits/visibility` | Sets package or tool visibility to enabled |
| `/toolkits stub <pkg>` | `POST /api/v1/toolkits/visibility` | Sets package or tool visibility to stub |
| `/toolkits disable <pkg>` | `POST /api/v1/toolkits/visibility` | Sets package or tool visibility to disabled |
| `/channels` | `GET /api/v1/channels` | Lists channels with runtime state |
| `/channels drivers` | `GET /api/v1/channels/drivers` | Lists registered channel drivers |
| `/channels status <id>` | `GET /api/v1/channels/{id}` | Shows channel details |
| `/channels health <id>` | `GET /api/v1/channels/{id}/health` | Shows channel health |
| `/channels enable <id>` | `POST /api/v1/channels/{id}/enable` | Enables a channel instance |
| `/channels disable <id>` | `POST /api/v1/channels/{id}/disable` | Disables a channel instance |
| `/channels delete <id>` | `DELETE /api/v1/channels/{id}` | Deletes a channel instance |
| `/channels links <id>` | `GET /api/v1/channels/{id}/links` | Lists identity links for a channel |
| `/channels deliveries <id>` | `GET /api/v1/channels/{id}/deliveries` | Lists delivery records for a channel |
| `/help` | `GET /api/v1/server/commands` | Returns the runtime slash-command catalog |
| `/prompt` | `GET /api/v1/server/prompt` | Outputs the fully constructed system prompt |
| `/backstory` | `GET /api/v1/server/backstory?profile=<name>` | Returns generated backstory content and source breakdowns |
| `/budget` | `GET /api/v1/server/budget` | Returns prompt and toolkit budget info |
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
| `/webhooks status <id>` | `GET /api/v1/webhooks/{id}` | Shows webhook details |
| `/webhooks deliveries <id>` | `GET /api/v1/webhooks/{id}/deliveries` | Shows recent delivery logs |
| `/webhooks delivery <id> <deliveryId>` | `GET /api/v1/webhooks/{id}/deliveries/{deliveryId}` | Shows one delivery log with linked task details |
| `/webhooks test <id>` | `POST /api/v1/webhooks/{id}/test` | Dispatches a synthetic webhook delivery |
| `/webhooks enable <id>` | `PUT /api/v1/webhooks/{id}` | Enables a webhook subscription |
| `/webhooks disable <id>` | `PUT /api/v1/webhooks/{id}` | Disables a webhook subscription |
| `/webhooks delete <id>` | `DELETE /api/v1/webhooks/{id}` | Deletes a webhook subscription |
| `/webhooks rotate <id>` | `POST /api/v1/webhooks/{id}/rotate` | Rotates a webhook signing secret |

Mutating REPL workflows such as `/config edit`, `/roles update`, and most schedule management remain REPL-first by design.

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
| `POST` | `/api/v1/sessions/{id}/files` | Yes | Upload files (multipart) |
| `GET` | `/api/v1/sessions/{id}/files` | Yes | List uploaded files |
| `GET` | `/api/v1/sessions/{id}/files/{fileId}` | Yes | Download a file |
| `DELETE` | `/api/v1/sessions/{id}/files/{fileId}` | Yes | Delete a file |
| `GET` | `/api/v1/sessions/{id}/turns` | Yes | List turns |
| `GET` | `/api/v1/sessions/{id}/turns/{turnId}` | Yes | Get turn with messages |
| `GET` | `/api/v1/sessions/{id}/turns/{turnId}/events` | Yes | List replayable turn events |
| `GET` | `/api/v1/sessions/{id}/child-runs` | Yes | List child agent runs |
| `GET` | `/api/v1/config` | Yes | Get config (sanitized) |
| `POST` | `/api/v1/config/validate` | Yes | Validate a candidate config payload |
| `GET` | `/api/v1/config/roles` | Yes | List all roles |
| `GET` | `/api/v1/config/roles/{name}` | Yes | Get role detail |
| `GET` | `/api/v1/config/models` | Yes | List available models |
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
| `GET` | `/api/v1/projects/{idOrSlug}/sprints` | Yes | List project sprints |
| `POST` | `/api/v1/projects/{idOrSlug}/sprints` | Yes | Create sprint |
| `GET` | `/api/v1/sprints/{id}` | Yes | Get sprint detail |
| `PATCH` | `/api/v1/sprints/{id}` | Yes | Update sprint |
| `DELETE` | `/api/v1/sprints/{id}` | Yes | Delete planned sprint |
| `POST` | `/api/v1/sprints/{id}/start` | Yes | Start sprint |
| `POST` | `/api/v1/sprints/{id}/submit-review` | Yes | Submit sprint for review |
| `POST` | `/api/v1/sprints/{id}/complete` | Yes | Complete sprint |
| `POST` | `/api/v1/sprints/{id}/reject` | Yes | Reject sprint |
| `GET` | `/api/v1/tasks/{id}/events` | Yes | Stream task events (SSE) |
| `POST` | `/api/v1/tasks/{id}/input` | Yes | Inject input into running task |
| `POST` | `/api/v1/tasks/{id}/cancel` | Yes | Cancel a task |
| `GET` | `/api/v1/projects` | Yes | List projects |
| `GET` | `/api/v1/projects/{idOrSlug}` | Yes | Get project detail |
| `GET` | `/api/v1/projects/{idOrSlug}/sprints` | Yes | List sprints for a project |
| `GET` | `/api/v1/sprints/{id}` | Yes | Get sprint detail |
| `GET` | `/api/v1/evaluations` | Yes | List saved evaluation reports |
| `GET` | `/api/v1/evaluations/stats` | Yes | Get evaluation aggregates |
| `GET` | `/api/v1/evaluations/{id}` | Yes | Get evaluation detail |
| `GET` | `/api/v1/server/stats` | Yes | Database and server statistics |
| `GET` | `/api/v1/server/quality` | Yes | Quality and health summary |
| `GET` | `/api/v1/server/info` | Yes | Server capabilities and commands |
| `POST` | `/api/v1/server/restart` | Yes | Restart a launcher-managed API process |
| `GET` | `/api/v1/server/commands` | Yes | Get runtime slash-command metadata (`/help` equivalent) |
| `GET` | `/api/v1/server/prompt` | Yes | Get the rendered system prompt |
| `GET` | `/api/v1/server/backstory` | Yes | Get generated backstory content and manifest metadata |
| `GET` | `/api/v1/server/budget` | Yes | Get prompt and toolkit budget state |
| `GET` | `/api/v1/toolkits` | Yes | List toolkits and tools with visibility |
| `POST` | `/api/v1/toolkits/visibility` | Yes | Set package or tool visibility |
| `GET` | `/api/v1/channels` | Yes | List channels with runtime state |
| `POST` | `/api/v1/channels` | Yes | Create a channel instance |
| `GET` | `/api/v1/channels/drivers` | Yes | List registered channel drivers |
| `GET` | `/api/v1/channels/{id}` | Yes | Get one channel instance |
| `PATCH` | `/api/v1/channels/{id}` | Yes | Update a channel instance |
| `DELETE` | `/api/v1/channels/{id}` | Yes | Delete a channel instance |
| `POST` | `/api/v1/channels/{id}/enable` | Yes | Enable a channel instance |
| `POST` | `/api/v1/channels/{id}/disable` | Yes | Disable a channel instance |
| `POST` | `/api/v1/channels/{id}/test` | Yes | Reconcile and refresh a channel instance |
| `GET` | `/api/v1/channels/{id}/health` | Yes | Get channel health |
| `GET` | `/api/v1/channels/{id}/links` | Yes | List channel identity links |
| `POST` | `/api/v1/channels/{id}/links` | Yes | Create a channel identity link |
| `DELETE` | `/api/v1/channels/{id}/links/{linkId}` | Yes | Delete a channel identity link |
| `GET` | `/api/v1/channels/{id}/conversations` | Yes | List stored channel conversations |
| `GET` | `/api/v1/channels/{id}/events` | Yes | List stored inbound channel events |
| `GET` | `/api/v1/channels/{id}/deliveries` | Yes | List stored channel deliveries |
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
| `POST` | `/api/v1/webhooks/incoming/{name}` | No* | Receive webhook (signature-verified) |
| `GET` | `/api/v1/webhooks` | Yes | List webhook subscriptions |
| `POST` | `/api/v1/webhooks` | Yes | Create webhook subscription |
| `GET` | `/api/v1/webhooks/{id}` | Yes | Get webhook |
| `PUT` | `/api/v1/webhooks/{id}` | Yes | Update webhook |
| `DELETE` | `/api/v1/webhooks/{id}` | Yes | Delete webhook |
| `POST` | `/api/v1/webhooks/{id}/rotate` | Yes | Rotate signing secret |
| `GET` | `/api/v1/webhooks/{id}/deliveries` | Yes | List delivery logs |
| `GET` | `/api/v1/webhooks/{id}/deliveries/{deliveryId}` | Yes | Get one delivery log with linked task details |
| `POST` | `/api/v1/webhooks/{id}/test` | Yes | Dispatch a synthetic test delivery |
| `POST` | `/api/v1/sessions/{id}/artifacts` | Yes | Create artifact |
| `GET` | `/api/v1/sessions/{id}/artifacts` | Yes | List artifacts |
| `GET` | `/api/v1/sessions/{id}/artifacts/{artifactId}` | Yes | Get artifact |
| `PATCH` | `/api/v1/sessions/{id}/artifacts/{artifactId}` | Yes | Update artifact metadata or content |
| `DELETE` | `/api/v1/sessions/{id}/artifacts/{artifactId}` | Yes | Delete artifact |
| `GET` | `/api/v1/sessions/{id}/artifacts/{artifactId}/versions` | Yes | List artifact versions |
| `POST` | `/api/v1/sessions/{id}/artifacts/{artifactId}/versions` | Yes | Create artifact version |
| `POST` | `/api/v1/sessions/{id}/artifacts/{artifactId}/versions/{versionId}/restore` | Yes | Restore artifact version |
| `POST` | `/api/v1/sessions/{id}/todos` | Yes | Create todo |
| `GET` | `/api/v1/sessions/{id}/todos` | Yes | List todos |
| `GET` | `/api/v1/sessions/{id}/todos/stats` | Yes | Get todo statistics |
| `PATCH` | `/api/v1/sessions/{id}/todos/bulk` | Yes | Bulk update todos |
| `POST` | `/api/v1/sessions/{id}/todos/reorder` | Yes | Reorder todos |
| `GET` | `/api/v1/sessions/{id}/todos/{todoId}` | Yes | Get todo detail |
| `PATCH` | `/api/v1/sessions/{id}/todos/{todoId}` | Yes | Update todo |
| `DELETE` | `/api/v1/sessions/{id}/todos/{todoId}` | Yes | Delete todo |
| `POST` | `/api/v1/sessions/{id}/todos/{todoId}/complete` | Yes | Complete todo |
| `POST` | `/api/v1/sessions/{id}/todos/{todoId}/reopen` | Yes | Reopen todo |
| `POST` | `/api/v1/sessions/{id}/todos/{todoId}/cancel` | Yes | Cancel todo |

Mutation-heavy workflows for roles, summarization, and update continue to live primarily in the REPL and agent tool layer. API restart and channel CRUD now expose explicit restart-state metadata over HTTP for app clients.
