---
title: Structured Questions
description: "The ask_user tool: answer formats, suggested defaults, per-surface responders, the on_question policy for non-interactive loops, and the audit trail"
---

# Structured Questions

Structured questions let an agent pause and ask the user **one** well-formed question — with a fixed answer shape and a required best-guess default — and receive a *validated* answer back. They replace ad-hoc "type your answer in free-form prose" prompts with a small, typed contract that every surface (REPL, HTTP API, autonomous loop) understands.

The single entry point is the `ask_user` tool. Where and how the question is rendered is decided by the runtime, not the agent: the same tool call surfaces as an interactive REPL prompt, an API suspend-and-ask over SSE, or a loop escalation, depending on which **responder** the runtime wired.

## Answer formats

`ask_user` supports exactly three formats (v1):

| `format` | Meaning | `options` | `allow_other` |
| --- | --- | --- | --- |
| `single_select` | Pick exactly one option | Required | Optional |
| `multi_select` | Pick zero or more options | Required | Optional |
| `free_text` | Type a free-text answer | Must be omitted | N/A |

`allow_other` (selects only, default `false`) lets the user supply a free-text value that is not one of the listed options — surfaced as an "Other…" choice in the REPL and accepted as a `text` value by the answer endpoint.

Each option is either a bare string (used directly as the label) or an object `{"label": "...", "description": "..."}`. The **label is the answer identifier** — a select answer echoes the chosen label(s) back in `selected`.

## The `ask_user` tool

| Parameter | Type | Required | Description |
| --- | --- | --- | --- |
| `prompt` | string | Yes | The question text. |
| `format` | enum | Yes | `single_select` \| `multi_select` \| `free_text`. |
| `options` | array | Selects only | Choices: objects `{"label","description"?}` or bare strings. Omit for `free_text`. |
| `suggested` | object | Yes | Best-guess default: `{"selected":["label"]}` for selects or `{"text":"..."}` for free-text. Must be a valid answer for the question. |
| `header` | string | No | Short chip/label shown above the prompt. |
| `allow_other` | bool | Selects only | Allow a free-text "Other" answer. |

On success the tool returns `{"answered": true, "selected": [...], "text": ...}`. Invalid input (missing `prompt`, a select with no `options`, `free_text` carrying `options`, a `suggested` that is not valid for the question, etc.) returns a tool error describing the problem — validation lives entirely in `QuestionRequest` construction and `QuestionResponse::isValidFor()`, the single validation authority for the whole feature.

### Worked examples

**Yes/no — a two-option `single_select`:**

```json
{
  "prompt": "Overwrite the existing config file?",
  "format": "single_select",
  "options": ["Yes", "No"],
  "suggested": {"selected": ["No"]}
}
```

**`multi_select` with descriptions and Other:**

```json
{
  "prompt": "Which environments should this deploy target?",
  "format": "multi_select",
  "options": [
    {"label": "staging", "description": "Pre-prod smoke test"},
    {"label": "production", "description": "Live traffic"}
  ],
  "allow_other": true,
  "suggested": {"selected": ["staging"]}
}
```

**`free_text`:**

```json
{
  "prompt": "What should the release be named?",
  "format": "free_text",
  "suggested": {"text": "v0.0.27"}
}
```

Yes/no is always a two-option `single_select`. There is no batching — ask **one** question per call and ask sequentially if you need several answers.

## `suggested` semantics

Every `ask_user` call **must** carry a `suggested` best-guess answer that is itself valid for the question. It serves two purposes:

- **Interactive surfaces** (REPL, API) pre-select it as the default, so the user can accept with a keystroke.
- **Non-interactive `default` policy** (see below) auto-takes it verbatim with no human in the loop.

Because `suggested` is validated at construction time, a malformed default fails the tool call early rather than silently degrading later.

## Responders — who answers, per surface

The runtime wires exactly one responder into the `ask_user` tool for a given execution context — the same way it wires an execution policy. The toolkit itself is context-agnostic.

| Responder | Active when | Behavior |
| --- | --- | --- |
| `InteractiveQuestionResponder` | REPL turn | Renders the question synchronously via the console and block-waits for a valid answer. Always resolves inline. |
| `SuspendingQuestionResponder` | Interactive HTTP API turn (runs in a `turn:run` child process) | Persists the question, emits a `question` SSE turn-event, then block-polls the database for the answer the REST answer endpoint records. Bounded by a timeout and an optional cancellation token so a never-answered question cannot hang. |
| `PolicyQuestionResponder` | Loop stage / background task | Applies the loop's `on_question` policy (`block` or `default`) — no interactive prompt. |

If no responder is wired for a context, the `ask_user` tool is simply not present — existing loops and turns that never call `ask_user` are unaffected.

## Non-interactive loops: the `on_question` policy

A loop definition carries an `on_question` field (`block` | `default`, default `block`) that decides what happens when a stage calls `ask_user`. See [LOOPS.md](LOOPS.md) for where it sits in the definition file.

- **`block` (default)** — the stage escalates the whole loop to the `blocked` state, carrying the question as the escalation payload, and the `ask_user` call returns a hard-STOP sentinel (`QUESTION_BLOCKED`) that instructs the agent to stop immediately and take no further action. The stage is later re-run from the start with the operator's answer injected.
- **`default`** — the agent's `suggested` answer is auto-taken and logged, and the stage continues without interruption.

Role-level `on_question` override is **not** supported in v1 — the policy is set per loop definition only.

### How a blocked question is surfaced and resolved

When a `block`-mode loop stage asks a question:

1. The loop transitions to `blocked` (an actionable `loop.blocked` escalation), exactly like the circuit-breaker escalation, so the existing blocked-loop UI and notifications surface it.
2. An operator lists pending questions with `GET /api/v1/sessions/{id}/questions` and answers with `POST /api/v1/sessions/{id}/questions/{questionId}/answer`.
3. Answering a block-mode loop question **reopens** the blocked stage (like a retry): the escalation is cleared, the rework breaker is reset, and the answer is staged for one-shot injection into the reopened stage prompt. The stage re-runs from the start.

Both endpoints are CORE authenticated. Their request/response shapes and status codes are documented in [API.md](API.md).

## Audit trail

Every question is persisted to the `questions` table and mirrored into the approval-style audit trail:

- `question_asked` — logged when the question is presented (any responder).
- `question_answered` — logged when a valid answer is recorded.

Q&A is persisted **only** to `audit_log` and the `questions` table — never to memory or artifacts.

## v1 limits

- One question per `ask_user` call; no batching.
- No formats beyond `single_select`, `multi_select`, and `free_text` (plus `allow_other` on selects).
- No role-level `on_question` override — the policy is per loop definition.
- Q&A is confined to `audit_log` + the `questions` table.
- A `block`-mode `ask_user` from a plain (non-loop) background task has no operator resume path — the question stays pending and the task halts; `ask_user` block-mode resolution is supported for loops (and live REPL/API turns), not fire-and-forget background tasks.

## See also

- [API.md](API.md) — the `GET`/`POST` questions endpoints and the `question` SSE turn-event.
- [LOOPS.md](LOOPS.md) — the `on_question` loop-definition field and the `blocked`/unblock lifecycle.
