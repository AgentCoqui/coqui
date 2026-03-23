# Coqui HTTP API

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
| `role_builtin` | 409 | Cannot modify a built-in role |
| `role_reserved` | 409 | Cannot create a role with a reserved name |
| `unauthorized` | 401 | Missing or invalid API key |
| `forbidden` | 403 | Access denied |
| `rate_limited` | 429 | Too many requests |
| `payload_too_large` | 413 | Request body exceeds size limit |
| `unsupported_media_type` | 415 | Content-Type must be application/json |
| `internal_error` | 500 | Internal server error |

HTTP status codes follow standard conventions.

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
  "active_sessions": 1
}
```

### Sessions

A session is a persistent conversation context. Messages and turns are scoped to a session.

#### `GET /api/v1/sessions`

List sessions, ordered by most recently updated.

**Query Parameters**

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | `50` | Max sessions to return (capped at 200) |

**Response `200`**

```json
{
  "sessions": [
    {
      "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
      "model_role": "orchestrator",
      "model": "openai/gpt-5",
      "created_at": "2026-02-16T14:30:00+00:00",
      "updated_at": "2026-02-16T15:45:12+00:00",
      "token_count": 12450
    }
  ],
  "count": 1
}
```

#### `POST /api/v1/sessions`

Create a new session.

**Request Body**

```json
{
  "model_role": "orchestrator"
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `model_role` | string | No | `"orchestrator"` | Role to resolve the model from config. Must be a known role. |

**Response `201`**

```json
{
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5"
}
```

**Response `400`** — Unknown role:

```json
{
  "error": "Unknown model_role 'nonexistent'. Available roles: orchestrator, coder",
  "code": "validation_error"
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
  "created_at": "2026-02-16T14:30:00+00:00",
  "updated_at": "2026-02-16T15:45:12+00:00",
  "token_count": 12450
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

Update session metadata. Currently supports renaming the session title.

**Request Body**

```json
{
  "title": "My refactoring session"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `title` | string | No | New session title (cannot be empty) |

**Response `200`**

Returns the updated session object:

```json
{
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5",
  "title": "My refactoring session",
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

#### `DELETE /api/v1/sessions/{id}`

Delete a session and all its associated data.

**Response `200`**

```json
{
  "deleted": true,
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6"
}
```

### Messages

Messages are the conversation records within a session. Each message has a role (`user`, `assistant`, or `tool`).

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
| `tool_result` | Tool execution completed | `{"content": "...", "success": true}` |
| `child_start` | Child agent spawned | `{"role": "coder", "depth": 0}` |
| `child_end` | Child agent finished | `{"depth": 0}` |
| `done` | Agent turn content complete | `{"content": "Here are the files..."}` |
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
  "error": null
}
```

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
data: {"content":"Here are the directories inside `src/`:\n\n- Agent/\n- Api/\n- Command/\n- Config/","iterations":2,"prompt_tokens":1250,"completion_tokens":340,"total_tokens":1590,"duration_ms":4521,"tools_used":["list_dir"],"child_agent_count":0,"restart_requested":false,"error":null}

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
  "error": null
}
```

**Prompt Size Limit**

The `prompt` field is limited to **100 KB** (102,400 bytes). Prompts exceeding this limit return a `400` error with code `validation_error`.

**Error Responses**

| Status | Code | Condition |
|--------|------|-----------|
| `400` | `missing_field` | Missing or empty `prompt` field |
| `400` | `validation_error` | Prompt exceeds 100 KB size limit |
| `404` | `session_not_found` | Session does not exist |
| `404` | `not_found` | Referenced file ID not found in this session |
| `409` | `agent_busy` | Session already has an active agent run |

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
      "model": "openai/gpt-5",
      "prompt_tokens": 1250,
      "completion_tokens": 340,
      "total_tokens": 1590,
      "iterations": 2,
      "duration_ms": 4521,
      "tools_used": "[\"list_dir\"]",
      "child_agent_count": 0,
      "created_at": "2026-02-16T14:30:05+00:00",
      "completed_at": "2026-02-16T14:30:10+00:00"
    }
  ],
  "count": 1
}
```

#### `GET /api/v1/sessions/{id}/turns/{turnId}`

Get a single turn with its associated messages.

**Response `200`**

```json
{
  "id": "t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "turn_number": 1,
  "user_prompt": "List the files in the current directory",
  "response_text": "Here are the files...",
  "model": "openai/gpt-5",
  "prompt_tokens": 1250,
  "completion_tokens": 340,
  "total_tokens": 1590,
  "iterations": 2,
  "duration_ms": 4521,
  "tools_used": "[\"list_dir\"]",
  "child_agent_count": 0,
  "created_at": "2026-02-16T14:30:05+00:00",
  "completed_at": "2026-02-16T14:30:10+00:00",
  "messages": [
    {
      "id": "m1...",
      "role": "user",
      "content": "List the files in the current directory",
      "tool_calls": null,
      "tool_call_id": null,
      "created_at": "2026-02-16T14:30:05+00:00"
    }
  ]
}
```

### Configuration

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

#### `PUT /api/v1/config`

Write the full `openclaw.json` configuration. The body must be valid JSON. The file is pretty-printed before writing. Config changes are auto-detected by the REPL — no restart required.

**Request Body**

The complete `openclaw.json` content:

```json
{
  "agents": {
    "defaults": {
      "model": {
        "primary": "openai/gpt-5"
      },
      "roles": {
        "orchestrator": "openai/gpt-5",
        "coder": "anthropic/claude-sonnet-4-20250514"
      }
    }
  }
}
```

**Response `200`**

```json
{
  "success": true,
  "path": "/path/to/openclaw.json"
}
```

**Response `400`** — invalid JSON:

```json
{
  "error": "Invalid JSON",
  "details": "Syntax error"
}
```

**Response `400`** — validation errors:

```json
{
  "error": "Config validation failed",
  "code": "validation_error",
  "details": [
    "agents.defaults.model.primary must be in \"provider/model\" format, got: invalid",
    "agents.defaults.mounts[0].alias must not contain path separators"
  ]
}
```

#### `POST /api/v1/config/validate`

Dry-run validation of a config object without saving. Use this to validate config changes before committing them.

**Request Body**

The complete `openclaw.json` content (same format as `PUT /api/v1/config`).

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
  "count": 2
}
```

#### `GET /api/v1/config/roles/{name}`

Get a single role with full details. System roles return metadata without instructions. Custom roles include the full instruction text.

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
  "instructions": "You are a coding specialist..."
}
```

**Response `404`**

```json
{
  "error": "Role 'nonexistent' not found",
  "code": "role_not_found"
}
```

#### `POST /api/v1/config/roles`

Create a new custom role.

**Request Body**

```json
{
  "name": "debugger",
  "display_name": "Debugger",
  "description": "Specializes in finding and fixing bugs",
  "access_level": "full",
  "model": "anthropic/claude-sonnet-4-20250514",
  "instructions": "You are a debugging specialist..."
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Unique role name (cannot be a reserved name) |
| `instructions` | string | Yes | System prompt for the role |
| `display_name` | string | No | Human-readable name (defaults to capitalized name) |
| `description` | string | No | Brief description |
| `access_level` | string | No | `full`, `readonly`, or `minimal` (default: `readonly`) |
| `model` | string | No | Model override for this role |

**Response `201`**

Returns the created role properties with instructions.

**Response `409`** — reserved name:

```json
{
  "error": "Role name 'orchestrator' is reserved and cannot be created",
  "code": "role_reserved"
}
```

**Response `409`** — already exists:

```json
{
  "error": "Role 'coder' already exists",
  "code": "conflict"
}
```

#### `PATCH /api/v1/config/roles/{name}`

Update an existing custom role. All fields are optional — only provided fields are changed.

**Request Body**

```json
{
  "description": "Updated description",
  "instructions": "Updated system prompt..."
}
```

System roles cannot be modified:

```json
{
  "error": "System role 'orchestrator' cannot be modified",
  "code": "role_builtin"
}
```

#### `DELETE /api/v1/config/roles/{name}`

Delete a custom role.

**Response `200`**

```json
{
  "deleted": true,
  "name": "debugger"
}
```

System and built-in roles cannot be deleted:

```json
{
  "error": "System role 'orchestrator' cannot be deleted",
  "code": "role_builtin"
}
```

#### `GET /api/v1/config/models`

Lists all available models from all configured providers.

**Response `200`**

```json
{
  "models": [
    {
      "provider": "openai",
      "id": "openai/gpt-5",
      "name": "gpt-5",
      "reasoning": false,
      "input": ["text"]
    },
    {
      "provider": "anthropic",
      "id": "anthropic/claude-sonnet-4-20250514",
      "name": "claude-sonnet-4-20250514",
      "reasoning": true,
      "input": ["text"]
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

Server endpoints provide runtime status and database-level statistics. These are useful for monitoring and debugging the API server.

#### `GET /api/v1/server/info`

Runtime information including version, uptime, memory usage, and active workload.

**Response `200`**

```json
{
  "version": "0.5.0",
  "php_version": "8.4.2",
  "uptime_seconds": 3621,
  "active_sessions": 2,
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

### Background Tasks

Background tasks run long-running agent work in separate processes. Each task gets its own dedicated session and runs via `bin/coqui task:run`. Tasks are managed by the `BackgroundTaskManager` which handles process lifecycle, concurrency limits, and crash recovery.

For architecture details, see [BACKGROUND-TASKS.md](BACKGROUND-TASKS.md).

#### `POST /api/v1/tasks`

Create a new background task. The task is started immediately if under the concurrency limit, otherwise queued as pending.

**Request Body**

```json
{
  "prompt": "Refactor the authentication module",
  "role": "coder",
  "title": "Auth refactor",
  "parent_session_id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "max_iterations": 25
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `prompt` | string | Yes | — | The task prompt (max 100 KB) |
| `role` | string | No | `"orchestrator"` | Agent role for the task. Must be a known role. |
| `title` | string | No | `null` | Human-readable title for the task |
| `parent_session_id` | string | No | `null` | Link the task to a parent session (must exist) |
| `max_iterations` | int | No | `25` | Maximum agent iterations (1–100) |

**Response `201`**

```json
{
  "id": "t1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "session_id": "s1a2b3c4d5e6f7g8h9i0j1k2l3m4n5o6",
  "status": "running",
  "prompt": "Refactor the authentication module",
  "role": "coder",
  "title": "Auth refactor",
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
| `content` | string | Yes | The input text to inject (cannot be empty) |

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

Create a single todo.

**Request Body**

```json
{
  "title": "Implement authentication module",
  "priority": "high",
  "artifact_id": "abc123",
  "parent_id": null,
  "notes": "See auth spec"
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `title` | string | Yes | — | Task description (max 200 chars) |
| `priority` | string | No | `"medium"` | `high`, `medium`, or `low` |
| `artifact_id` | string | No | `null` | Link to an artifact |
| `parent_id` | string | No | `null` | Parent todo ID (for subtasks) |
| `notes` | string | No | `null` | Additional context |

**Response `201`**

```json
{
  "id": "a1b2c3d4",
  "title": "Implement authentication module"
}
```

**Response `400`** — validation error:

```json
{
  "error": "Title is required",
  "code": "validation_error"
}
```

#### `POST /api/v1/sessions/{id}/todos/bulk`

Create multiple todos in a single request. Max 25 items per call.

**Request Body**

```json
{
  "items": [
    {"title": "Step 1: Design schema", "priority": "high", "notes": "See RFC"},
    {"title": "Step 2: Implement store", "priority": "medium"},
    {"title": "Step 3: Add API endpoints", "priority": "medium"}
  ],
  "artifact_id": "plan-abc123"
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `items` | array | Yes | — | Array of todo objects (max 25) |
| `items[].title` | string | Yes | — | Task description (max 200 chars) |
| `items[].priority` | string | No | `"medium"` | `high`, `medium`, or `low` |
| `items[].notes` | string | No | `null` | Additional context |
| `artifact_id` | string | No | `null` | Link all created todos to this artifact |

**Response `201`**

```json
{
  "ids": ["a1b2c3d4", "e5f6g7h8", "i9j0k1l2"],
  "count": 3
}
```

**Response `400`** — too many items:

```json
{
  "error": "Maximum 25 items per bulk create",
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
  "todo": {
    "id": "a1b2c3d4",
    "title": "Implement authentication module",
    "status": "in_progress",
    "priority": "high",
    "subtasks": []
  }
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
| `title` | string | No | New title (max 200 chars) |
| `status` | string | No | `pending`, `in_progress`, `completed`, `cancelled` |
| `priority` | string | No | `high`, `medium`, `low` |
| `notes` | string | No | Updated notes |

**Response `200`**

```json
{
  "updated": true
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
| `updates[].title` | string | No | New title (max 200 chars) |
| `updates[].notes` | string | No | Updated notes |

**Response `200`**

```json
{
  "updated_count": 2
}
```

#### `POST /api/v1/sessions/{id}/todos/{todoId}/complete`

Mark a todo as completed.

**Response `200`**

```json
{
  "completed": true
}
```

#### `DELETE /api/v1/sessions/{id}/todos/{todoId}`

Delete a todo and all its subtasks.

**Response `200`**

```json
{
  "deleted": true
}
```

### Schedules

Schedules enable autonomous, timer-driven execution via cron-style expressions. The API server evaluates due schedules every 60 seconds and creates background tasks automatically. A circuit breaker auto-disables schedules after consecutive failures.

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

#### `POST /api/v1/schedules`

Create a new schedule.

**Request Body**

```json
{
  "name": "daily-review",
  "schedule_expression": "0 9 * * 1-5",
  "prompt": "Review recent changes in the codebase",
  "role": "coder",
  "max_iterations": 30,
  "timezone": "America/New_York",
  "description": "Weekday code review",
  "max_failures": 5
}
```

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `name` | string | Yes | — | Unique name (lowercase, hyphens, underscores, max 100 chars) |
| `schedule_expression` | string | Yes | — | 5-field cron expression or `@once` |
| `prompt` | string | Yes | — | Task prompt (max 50,000 chars) |
| `role` | string | No | `"orchestrator"` | Agent role for the task |
| `max_iterations` | int | No | `48` | Max iterations per run (1–100) |
| `timezone` | string | No | `"UTC"` | IANA timezone for cron evaluation |
| `description` | string | No | `null` | Human-readable description |
| `max_failures` | int | No | `3` | Circuit breaker threshold (1–100) |

**Response `201`** — schedule created with computed `next_run_at`.

**Response `400`** — validation error (invalid cron, duplicate name, invalid timezone).

#### `GET /api/v1/schedules/{id}`

Get a schedule by ID.

**Response `200`** — full schedule object.

**Response `404`** — schedule not found.

#### `PATCH /api/v1/schedules/{id}`

Update a schedule. Only provided fields are changed.

**Request Body** — any subset of: `schedule_expression`, `prompt`, `role`, `max_iterations`, `enabled`, `timezone`, `description`, `max_failures`.

**Response `200`** — updated schedule object.

**Response `400`** — validation error.

**Response `404`** — schedule not found.

#### `DELETE /api/v1/schedules/{id}`

Delete a schedule permanently.

**Response `200`**

```json
{
  "deleted": true
}
```

**Response `404`** — schedule not found.

#### `POST /api/v1/schedules/{id}/trigger`

Immediately execute a schedule, creating a background task without waiting for the next cron tick.

**Response `200`**

```json
{
  "triggered": true,
  "task_id": "t1a2b3c4d5e6f7g8"
}
```

**Response `404`** — schedule not found.

#### `POST /api/v1/schedules/{id}/enable`

Enable a disabled schedule and reset its failure counter.

**Response `200`**

```json
{
  "enabled": true
}
```

#### `POST /api/v1/schedules/{id}/disable`

Disable a schedule. The schedule is preserved and can be re-enabled later.

**Response `200`**

```json
{
  "disabled": true
}
```

### Webhooks

Webhooks receive signed HTTP POST requests from external services and automatically spawn background tasks. Signature verification supports GitHub, Slack, and generic HMAC schemes.

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

**Request Body** — any subset of: `name`, `description`, `source`, `prompt_template`, `role`, `max_iterations`, `enabled`, `event_filter`.

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
      "status": "accepted",
      "source_ip": "140.82.115.1",
      "created_at": "2026-02-16T14:30:00Z"
    }
  ]
}
```

Delivery statuses: `accepted`, `rejected_disabled`, `rejected_signature`, `rejected_event`, `rejected_empty`, `rejected_too_large`.

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

Return the fully constructed system prompt that the agent would receive on its next turn, together with tool and toolkit counts. Useful for debugging context size and inspecting which tools are active.

**Response `200`**

```json
{
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
  ]
}
```

| Field | Type | Description |
|-------|------|-------------|
| `prompt` | string | Full rendered system prompt text |
| `tool_count` | int | Number of tools currently in the agent's context (enabled + stub) |
| `toolkit_count` | int | Number of toolkit packages contributing tools |
| `prompt_tokens` | int | Estimated token count for the system prompt text |
| `tool_tokens` | int | Estimated token count for all tool schemas (standalone + toolkit) |
| `total_tokens` | int | Sum of `prompt_tokens` and `tool_tokens` |
| `toolkit_breakdown` | array | Per-toolkit token breakdown with guidelines and tool schema counts |

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

Request bodies are limited to **1 MB** (1,048,576 bytes). Requests exceeding this limit receive `413 Payload Too Large`.

```json
{
  "error": "Request body too large. Maximum size: 1048576 bytes",
  "code": "payload_too_large"
}
```

Only `POST`, `PUT`, and `PATCH` requests are checked.

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

Every REPL slash command has an API equivalent, allowing dashboards and client apps to provide the same functionality.

| REPL Command | API Equivalent | Notes |
|---|---|---|
| `/new` | `POST /api/v1/sessions` | Creates a new session |
| `/sessions` | `GET /api/v1/sessions` | Lists all sessions |
| `/resume <id>` | `POST /api/v1/sessions/{id}/messages` | Send a message to an existing session |
| `/history` | `GET /api/v1/sessions/{id}/messages` | Lists all messages in a session |
| `/model` | `GET /api/v1/config/models` | Lists available models and current config |
| `/config show` | `GET /api/v1/config` | Returns current configuration (sanitized) |
| `/config edit` | `PUT /api/v1/config` | Writes the full configuration |
| `/tasks` | `GET /api/v1/tasks` | Lists background tasks |
| `/task <id>` | `GET /api/v1/tasks/{id}` | Gets task detail |
| `/task-cancel <id>` | `POST /api/v1/tasks/{id}/cancel` | Cancels a running or pending task |
| `/restart` | `POST /api/v1/server/restart` | Triggers a graceful server restart |
| `/update` | `POST /api/v1/server/update` | Checks for and applies dependency updates |
| `/quit` | — | N/A — the API server is managed by the launcher or process manager |
| `/help` | `GET /api/v1/server/info` | Returns available commands and server capabilities |
| `/toolkits` | `GET /api/v1/toolkits` | Lists all toolkit packages and tools with visibility |
| `/toolkits enable <pkg>` | `POST /api/v1/toolkits/visibility` | Sets package or tool visibility to enabled |
| `/toolkits stub <pkg>` | `POST /api/v1/toolkits/visibility` | Sets package or tool visibility to stub |
| `/toolkits disable <pkg>` | `POST /api/v1/toolkits/visibility` | Sets package or tool visibility to disabled |
| `/prompt` | `GET /api/v1/server/prompt` | Outputs the fully constructed system prompt |

## Quick Reference

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/api/v1/health` | No | Server liveness check |
| `GET` | `/api/v1/sessions` | Yes | List sessions |
| `POST` | `/api/v1/sessions` | Yes | Create session |
| `GET` | `/api/v1/sessions/{id}` | Yes | Get session |
| `PATCH` | `/api/v1/sessions/{id}` | Yes | Update session (title) |
| `DELETE` | `/api/v1/sessions/{id}` | Yes | Delete session |
| `GET` | `/api/v1/sessions/{id}/messages` | Yes | List messages |
| `POST` | `/api/v1/sessions/{id}/messages` | Yes | Send prompt (SSE stream) |
| `DELETE` | `/api/v1/sessions/{id}/messages/{messageId}` | Yes | Delete a message |
| `POST` | `/api/v1/sessions/{id}/files` | Yes | Upload files (multipart) |
| `GET` | `/api/v1/sessions/{id}/files` | Yes | List uploaded files |
| `GET` | `/api/v1/sessions/{id}/files/{fileId}` | Yes | Download a file |
| `DELETE` | `/api/v1/sessions/{id}/files/{fileId}` | Yes | Delete a file |
| `GET` | `/api/v1/sessions/{id}/turns` | Yes | List turns |
| `GET` | `/api/v1/sessions/{id}/turns/{turnId}` | Yes | Get turn with messages |
| `GET` | `/api/v1/sessions/{id}/child-runs` | Yes | List child agent runs |
| `GET` | `/api/v1/config` | Yes | Get config (sanitized) |
| `PUT` | `/api/v1/config` | Yes | Update config (full write) |
| `GET` | `/api/v1/config/roles` | Yes | List all roles |
| `GET` | `/api/v1/config/roles/{name}` | Yes | Get role detail |
| `POST` | `/api/v1/config/roles` | Yes | Create custom role |
| `PATCH` | `/api/v1/config/roles/{name}` | Yes | Update custom role |
| `DELETE` | `/api/v1/config/roles/{name}` | Yes | Delete custom role |
| `GET` | `/api/v1/config/models` | Yes | List available models |
| `GET` | `/api/v1/credentials` | Yes | List credential keys |
| `POST` | `/api/v1/credentials` | Yes | Set a credential |
| `DELETE` | `/api/v1/credentials/{key}` | Yes | Delete a credential |
| `POST` | `/api/v1/tasks` | Yes | Create background task |
| `GET` | `/api/v1/tasks` | Yes | List tasks |
| `GET` | `/api/v1/tasks/{id}` | Yes | Get task detail |
| `GET` | `/api/v1/tasks/{id}/events` | Yes | Stream task events (SSE) |
| `POST` | `/api/v1/tasks/{id}/input` | Yes | Inject input into running task |
| `POST` | `/api/v1/tasks/{id}/cancel` | Yes | Cancel a task |
| `POST` | `/api/v1/server/restart` | Yes | Trigger graceful restart |
| `POST` | `/api/v1/server/update` | Yes | Check and apply updates |
| `GET` | `/api/v1/server/stats` | Yes | Database and server statistics |
| `GET` | `/api/v1/server/info` | Yes | Server capabilities and commands |
| `GET` | `/api/v1/toolkits` | Yes | List toolkits and tools with visibility |
| `POST` | `/api/v1/toolkits/visibility` | Yes | Set package or tool visibility |
| `GET` | `/api/v1/server/prompt` | Yes | Get the rendered system prompt |
| `GET` | `/api/v1/schedules` | Yes | List schedules |
| `POST` | `/api/v1/schedules` | Yes | Create schedule |
| `GET` | `/api/v1/schedules/{id}` | Yes | Get schedule |
| `PATCH` | `/api/v1/schedules/{id}` | Yes | Update schedule |
| `DELETE` | `/api/v1/schedules/{id}` | Yes | Delete schedule |
| `POST` | `/api/v1/schedules/{id}/trigger` | Yes | Trigger schedule immediately |
| `POST` | `/api/v1/schedules/{id}/enable` | Yes | Enable schedule |
| `POST` | `/api/v1/schedules/{id}/disable` | Yes | Disable schedule |
| `POST` | `/api/v1/webhooks/incoming/{name}` | No* | Receive webhook (signature-verified) |
| `GET` | `/api/v1/webhooks` | Yes | List webhook subscriptions |
| `POST` | `/api/v1/webhooks` | Yes | Create webhook subscription |
| `GET` | `/api/v1/webhooks/{id}` | Yes | Get webhook |
| `PUT` | `/api/v1/webhooks/{id}` | Yes | Update webhook |
| `DELETE` | `/api/v1/webhooks/{id}` | Yes | Delete webhook |
| `POST` | `/api/v1/webhooks/{id}/rotate` | Yes | Rotate signing secret |
| `GET` | `/api/v1/webhooks/{id}/deliveries` | Yes | List delivery logs |
