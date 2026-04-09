# Building Applications with the Coqui API

This guide is for application developers integrating with Coqui over HTTP.

[API.md](API.md) is the canonical endpoint reference. Use this document for integration patterns, client behavior, and practical guidance about how the API behaves in production.

## Start Here

Read these documents in this order:

1. [API.md](API.md) for the complete routed HTTP surface.
2. This guide for client workflow, streaming, concurrency, and integration choices.
3. [REPL-API-DIVERGENCES.md](REPL-API-DIVERGENCES.md) for features that intentionally remain REPL-only.

## Mental Model

The HTTP API is session-based.

- A session is a persistent conversation.
- A turn is one prompt-response cycle within that session.
- Messages are the stored conversation records created during a turn.
- Turns may use tools, spawn child agents, and emit live events before producing a final result.

The most common application flow is:

1. Create a session.
2. Send prompts to that session.
3. Consume either SSE events or a blocking JSON response.
4. Inspect turns, messages, artifacts, todos, or task status as needed.
5. Delete the session when the conversation is no longer needed.

## Server Startup and Auth

Start the API server with:

```bash
coqui api
```

Common flags:

| Flag | Default | Description |
| --- | --- | --- |
| `--host` | `127.0.0.1` | Bind address |
| `--port` | `3300` | Port |
| `--config` | auto-detect | Path to `openclaw.json` |
| `--workdir` | current directory | Working directory |
| `--workspace` | auto-resolved | Workspace override |
| `--unsafe` | `false` | Disable PHP script sanitization |
| `--cors-origin` | `*` | Allowed CORS origins |

Authentication expectations:

- `GET /api/v1/health` is public.
- Most other endpoints require `Authorization: Bearer ...`.
- The API key can come from config or `COQUI_API_KEY`.
- Local development may allow relaxed auth when bound to `127.0.0.1`, but production clients should always send a Bearer token.

Example:

```bash
curl -H "Authorization: Bearer YOUR_API_KEY" http://127.0.0.1:3300/api/v1/sessions
```

## Recommended Client Workflow

### 1. Create a session

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"model_role":"orchestrator"}' \
  http://127.0.0.1:3300/api/v1/sessions
```

### 2. Send a prompt

Default behavior is SSE streaming:

```bash
curl -N \
  -X POST \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"prompt":"Summarize the project structure"}' \
  http://127.0.0.1:3300/api/v1/sessions/SESSION_ID/messages
```

Use blocking mode when your client does not want event streaming:

```bash
curl -X POST \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"prompt":"Summarize the project structure"}' \
  "http://127.0.0.1:3300/api/v1/sessions/SESSION_ID/messages?stream=false"
```

### 3. Inspect persisted state

Useful follow-up endpoints:

- `GET /api/v1/sessions/{id}/messages`
- `GET /api/v1/sessions/{id}/turns`
- `GET /api/v1/sessions/{id}/turns/{turnId}`
- `GET /api/v1/sessions/{id}/child-runs`
- `GET /api/v1/sessions/{id}/artifacts`
- `GET /api/v1/sessions/{id}/todos`

## Streaming Behavior

The message endpoint streams Server-Sent Events by default.

Important behavior:

- Streams begin with a `connected` event.
- Intermediate events reflect agent execution, not just final text.
- Streams end with `complete`.
- If another prompt is already running for the same session, the API returns `409 agent_busy`.

Common event types:

| Event | Meaning |
| --- | --- |
| `connected` | SSE stream established |
| `agent_start` | Agent turn has started |
| `iteration` | Agent loop iteration |
| `tool_call` | Tool execution started |
| `tool_result` | Tool execution finished |
| `reasoning` | Provider reasoning delta when available |
| `text_delta` | Streaming response text chunk |
| `done` | Final assistant content is available |
| `complete` | Turn is fully finished with metadata |

`complete` is the event most clients should treat as authoritative final state.

## Blocking Mode

`?stream=false` returns a single JSON payload after the turn finishes.

Use it when:

- your client environment does not support SSE well,
- you only need final output,
- you prefer a simple request/response integration.

Use SSE when:

- you want live UI updates,
- you want tool progress,
- you need to surface agent activity in real time.

## Concurrency Rules

Coqui can process different sessions concurrently, but only one active run is allowed per session.

- Session A and Session B can both run at the same time.
- Session A cannot accept a second prompt until its current turn completes.
- Busy-session collisions return `409` with code `agent_busy`.

Client recommendation:

1. Treat each session as a serialized conversation lane.
2. Queue prompts per session on the client side.
3. Use separate sessions for separate conversations or tabs.

## File Upload Workflow

Use session file uploads when your client needs to attach images or documents to a prompt.

Flow:

1. Upload via `POST /api/v1/sessions/{id}/files`.
2. Capture the returned file IDs.
3. Pass those IDs in the `files` array when sending a message.

Example:

```bash
curl -X POST http://127.0.0.1:3300/api/v1/sessions/$SESSION_ID/files \
  -H "Authorization: Bearer $API_KEY" \
  -F "files[]=@screenshot.png"
```

Then:

```bash
curl -X POST http://127.0.0.1:3300/api/v1/sessions/$SESSION_ID/messages \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "Describe this screenshot",
    "files": ["FILE_ID"]
  }'
```

## Error Handling

Error responses use a consistent envelope:

```json
{
  "error": "Human-readable message",
  "code": "machine_readable_code"
}
```

Client guidance:

1. Branch on `code`, not the human message.
2. Handle `409 agent_busy` explicitly.
3. In SSE mode, treat `complete` as the source of truth for final status.
4. Keep retry logic conservative for long-running prompt submissions.

Common cases:

| Status | Code | Meaning |
| --- | --- | --- |
| `401` | `unauthorized` | Missing or invalid API key |
| `404` | `session_not_found` | Unknown session ID |
| `409` | `agent_busy` | Session already has an active turn |
| `413` | `payload_too_large` | Request body too large |
| `429` | `rate_limited` | Rate limit exceeded |
| `500` | `internal_error` | Unexpected server failure |

## What the HTTP API Does Not Cover

The API is intentionally narrower than the REPL.

Notable REPL-first areas include:

- configuration writes,
- role creation and editing,
- schedule mutation,
- loop mutation,
- artifact mutation,
- todo mutation,
- summarize, restart, and update workflows.

That boundary is intentional. The HTTP API is optimized for application integration, session execution, and read-heavy inspection.

## Integration Checklist

Before shipping a client, verify that it:

1. Stores one session ID per conversation.
2. Queues prompts per session to avoid `agent_busy` conflicts.
3. Supports either SSE or blocking mode deliberately.
4. Handles auth failures and rate limits explicitly.
5. Reads [API.md](API.md) instead of re-deriving routes from examples.
6. Uses [REPL-API-DIVERGENCES.md](REPL-API-DIVERGENCES.md) when a desired capability is not exposed over HTTP.
