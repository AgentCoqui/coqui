# Coqui HTTP API

The Coqui HTTP API provides programmatic access to Coqui's AI agent capabilities. It enables headless operation, remote session management, and real-time streaming of agent responses via Server-Sent Events (SSE).

The API is built on ReactPHP and runs as a long-lived PHP process. It shares the same core engine as the terminal REPL but without any terminal I/O dependency.

## Starting the Server

```bash
# Default: localhost:3300
php bin/coqui api

# Custom host and port
php bin/coqui api --host 0.0.0.0 --port 3000

# With a specific config file
php bin/coqui api --config /path/to/openclaw.json

# With CORS origins restricted
php bin/coqui api --cors-origin "http://localhost:3000,https://app.example.com"

# Docker
make docker-api              # port 3300
make docker-api PORT=3000    # custom port
```

### CLI Options

| Option | Short | Default | Description |
|--------|-------|---------|-------------|
| `--port` | | `3300` | Port to listen on |
| `--host` | | `127.0.0.1` | Host to bind to |
| `--config` | `-c` | `./openclaw.json` | Path to openclaw.json config |
| `--workdir` | `-w` | Current directory | Working directory (project root) |
| `--unsafe` | | `false` | Disable script sanitization (dangerous) |
| `--no-auth` | | `false` | Run without API key authentication (forces 127.0.0.1 binding) |
| `--cors-origin` | | `*` | Allowed CORS origins (comma-separated) |

## Authentication

When an API key is configured, all requests (except `GET /api/health` and `OPTIONS`) must include the key in the `Authorization` header.

```
Authorization: Bearer <your-api-key>
```

### Configuring the API Key

The server resolves the API key from these sources (first match wins):

1. `api.key` field in `openclaw.json`
2. `COQUI_API_KEY` environment variable
3. `COQUI_API_KEY` in the workspace `.env` file

If no key is found, the server **refuses to start**. This is a security-by-default policy.

To run without authentication during local development, use:

```bash
php bin/coqui api --no-auth
```

The `--no-auth` flag forces binding to `127.0.0.1` regardless of the `--host` option.

You can generate an API key automatically by running `coqui setup`.

### Error Responses

Unauthenticated requests receive:

```json
{
  "error": "Missing Authorization header",
  "code": "unauthorized"
}
```

## Base URL

All endpoints are prefixed with `/api`. The default base URL is:

```
http://127.0.0.1:3300
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

#### `GET /api/health`

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

#### `GET /api/sessions`

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

#### `POST /api/sessions`

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

#### `GET /api/sessions/{id}`

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

#### `DELETE /api/sessions/{id}`

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

#### `GET /api/sessions/{id}/messages`

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

#### `POST /api/sessions/{id}/messages`

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

#### `POST /api/sessions/{id}/files`

Upload one or more files to a session. Uses `multipart/form-data` encoding.

**Request**

Send files as form fields named `files[]`. Multiple files can be uploaded in a single request.

```bash
curl -X POST http://127.0.0.1:3300/api/sessions/{id}/files \
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

#### `GET /api/sessions/{id}/files`

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

#### `GET /api/sessions/{id}/files/{fileId}`

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

#### `DELETE /api/sessions/{id}/files/{fileId}`

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

#### `GET /api/sessions/{id}/turns`

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

#### `GET /api/sessions/{id}/turns/{turnId}`

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

#### `GET /api/config`

Returns the full Coqui configuration. API keys in provider configs are masked as `"***"`.

**Response `200`**

```json
{
  "agents": {
    "defaults": {
      "workspace": ".workspace",
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

#### `GET /api/config/roles`

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

#### `GET /api/config/roles/{name}`

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

#### `POST /api/config/roles`

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

#### `PATCH /api/config/roles/{name}`

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

#### `DELETE /api/config/roles/{name}`

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

#### `GET /api/config/models`

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

#### `GET /api/credentials`

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

#### `POST /api/credentials`

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

#### `DELETE /api/credentials/{key}`

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

Exempt endpoints: `GET /api/health`, `OPTIONS` (preflight).

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

## Quick Reference

| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| `GET` | `/api/health` | No | Server liveness check |
| `GET` | `/api/sessions` | Yes | List sessions |
| `POST` | `/api/sessions` | Yes | Create session |
| `GET` | `/api/sessions/{id}` | Yes | Get session |
| `DELETE` | `/api/sessions/{id}` | Yes | Delete session |
| `GET` | `/api/sessions/{id}/messages` | Yes | List messages |
| `POST` | `/api/sessions/{id}/messages` | Yes | Send prompt (SSE stream) |
| `POST` | `/api/sessions/{id}/files` | Yes | Upload files (multipart) |
| `GET` | `/api/sessions/{id}/files` | Yes | List uploaded files |
| `GET` | `/api/sessions/{id}/files/{fileId}` | Yes | Download a file |
| `DELETE` | `/api/sessions/{id}/files/{fileId}` | Yes | Delete a file |
| `GET` | `/api/sessions/{id}/turns` | Yes | List turns |
| `GET` | `/api/sessions/{id}/turns/{turnId}` | Yes | Get turn with messages |
| `GET` | `/api/config` | Yes | Get config (sanitized) |
| `GET` | `/api/config/roles` | Yes | List all roles |
| `GET` | `/api/config/roles/{name}` | Yes | Get role detail |
| `POST` | `/api/config/roles` | Yes | Create custom role |
| `PATCH` | `/api/config/roles/{name}` | Yes | Update custom role |
| `DELETE` | `/api/config/roles/{name}` | Yes | Delete custom role |
| `GET` | `/api/config/models` | Yes | List available models |
| `GET` | `/api/credentials` | Yes | List credential keys |
| `POST` | `/api/credentials` | Yes | Set a credential |
| `DELETE` | `/api/credentials/{key}` | Yes | Delete a credential |
