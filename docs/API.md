# Coqui HTTP API

The Coqui HTTP API provides programmatic access to Coqui's AI agent capabilities. It enables headless operation, remote session management, and real-time streaming of agent responses via Server-Sent Events (SSE).

The API is built on ReactPHP and runs as a long-lived PHP process. It shares the same core engine as the terminal REPL but without any terminal I/O dependency.

## Starting the Server

```bash
# Default: localhost:8080
php bin/coqui api

# Custom host and port
php bin/coqui api --host 0.0.0.0 --port 3000

# With a specific config file
php bin/coqui api --config /path/to/openclaw.json

# With CORS origins restricted
php bin/coqui api --cors-origin "http://localhost:3000,https://app.example.com"

# Docker
make api                   # port 8080
make api-port PORT=3000    # custom port
```

### CLI Options

| Option | Short | Default | Description |
|--------|-------|---------|-------------|
| `--port` | | `8080` | Port to listen on |
| `--host` | | `127.0.0.1` | Host to bind to |
| `--config` | `-c` | `./openclaw.json` | Path to openclaw.json config |
| `--workdir` | `-w` | Current directory | Working directory (project root) |
| `--unsafe` | | `false` | Disable script sanitization (dangerous) |
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

If no key is found, the server runs **without authentication** (suitable for local-only use).

### Error Responses

Unauthenticated requests receive:

```json
{
  "error": {
    "code": 401,
    "message": "Missing Authorization header"
  }
}
```

## Base URL

All endpoints are prefixed with `/api`. The default base URL is:

```
http://127.0.0.1:8080
```

## Content Type

All request bodies must be JSON with `Content-Type: application/json`.  
All responses return `Content-Type: application/json` unless noted otherwise.

## Error Format

All error responses use a consistent shape:

```json
{
  "error": "Human-readable error description"
}
```

HTTP status codes follow standard conventions: `400` for bad input, `401` for auth failures, `404` for not found, `409` for conflicts, `500` for server errors.

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
| `model_role` | string | No | `"orchestrator"` | Role to resolve the model from config |

**Response `201`**

```json
{
  "id": "a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6",
  "model_role": "orchestrator",
  "model": "openai/gpt-5"
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
  "error": "Session not found"
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
  "prompt": "What files are in the src directory?"
}
```

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `prompt` | string | Yes | The user prompt to send to the agent |

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

**Error Responses**

| Status | Condition |
|--------|-----------|
| `400` | Missing or empty `prompt` field |
| `404` | Session not found |
| `409` | Session already has an active agent run |

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

Returns the role-to-model mappings.

**Response `200`**

```json
{
  "roles": {
    "orchestrator": "openai/gpt-5",
    "coder": "openai/gpt-5",
    "reviewer": "openai/gpt-5"
  },
  "available": ["orchestrator", "coder", "reviewer"]
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
  "error": "Invalid key format. Use UPPER_SNAKE_CASE (e.g. MY_API_KEY)"
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
  "error": "Credential not found"
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
| `GET` | `/api/sessions/{id}/turns` | Yes | List turns |
| `GET` | `/api/sessions/{id}/turns/{turnId}` | Yes | Get turn with messages |
| `GET` | `/api/config` | Yes | Get config (sanitized) |
| `GET` | `/api/config/roles` | Yes | Get role mappings |
| `GET` | `/api/config/models` | Yes | List available models |
| `GET` | `/api/credentials` | Yes | List credential keys |
| `POST` | `/api/credentials` | Yes | Set a credential |
| `DELETE` | `/api/credentials/{key}` | Yes | Delete a credential |
