# Structured Questions to the User — Design

**Date:** 2026-07-14
**Status:** Approved (design) — pending spec review
**Branch:** `feat/structured-questions`
**Brief:** #6 — a first-class "ask the user a structured question" capability, one representation for both the REPL and the HTTP API.

## Problem

Coqui has no way for an agent to ask the user a *structured* question mid-turn. It can pause a tool call for a yes/no approval, but not solicit an arbitrary answer (pick one of N, pick several, or free text). And the two surfaces are asymmetric:

- **REPL** approvals prompt synchronously via `InteractiveApprovalPolicy` + `SymfonyStyle`.
- **API** turns run under `AutoApprovalPolicy` (`AgentFiberExecutor`) — they **auto-decide**; there is no suspend-and-ask-a-human path.

So there is no shared "ask a human and wait for a validated answer" mechanism. This brief builds one: a single `QuestionRequest` representation, an `ask_user` tool the model calls, and responders that render it in the REPL, suspend-and-ask over the API, or apply a policy when no human is present (loops / background tasks).

## Goals

- One **`QuestionRequest`** representation, rendered by surface-specific responders — no REPL/API drift.
- Support three answer formats: **single-select**, **multi-select**, **free-text** (with an optional "Other" escape hatch on selects). Yes/no is single-select with two options.
- Every question carries a **suggested answer** (enables non-interactive `default` mode and pre-selects a default in every UI).
- A **non-interactive policy** for loops/background tasks: `block` (escalate via the #3 `blocked`/retry mechanism) or `default` (auto-take the suggestion). Per-loop, default `block`.
- Answers are **validated** against the question before returning to the agent.
- Audit every question + answer, mirroring approval logging.

## Non-goals

- No formats beyond the three (no date/number/slider widgets). Future extension.
- **One question per `ask_user` call** in v1 (the model can ask sequentially). Batching 1–N questions is a clean future extension, deliberately deferred.
- No persistence of Q&A as memory or artifacts — the answer returns to the turn (and to `audit_log`); durable capture is the agent's job via existing memory/artifact tools if warranted.
- No change to the tool-approval mechanism — questions are a sibling of approvals on the shared suspend/resume substrate, not a replacement.

## Design

### Shared representation (`src/Contract/`)

- **`QuestionFormat`** (enum): `SingleSelect | MultiSelect | FreeText`.
- **`QuestionOption`** (readonly): `{ label: string, description: ?string }`. `label` is the answer identifier.
- **`QuestionRequest`** (readonly):
  - `id: string` — correlates the async answer (API/loop modes).
  - `prompt: string`, `header: ?string` (short chip label).
  - `format: QuestionFormat`.
  - `options: list<QuestionOption>` — required for selects, empty for free-text.
  - `allowOther: bool` — selects only; appends a free-text "Other" choice.
  - `suggested: QuestionResponse` — **required**; the agent's best-guess default.
  - `fromArray()` / `toArray()` for JSON (SSE payloads, persistence).
- **`QuestionResponse`** (readonly): `{ selected: list<string>, text: ?string }`.
  - single-select → `selected` has one label (or `text` set when Other); multi-select → 0+ labels; free-text → `text` set, `selected` empty.
  - `isValidFor(QuestionRequest): bool` — the single validation authority (see Validation).

### The `ask_user` tool (`src/Toolkit/QuestionToolkit.php`)

A new focused core toolkit exposing one tool, `ask_user`, available to agents broadly (REPL, API, loop stages):

```
ask_user(prompt, format, options?, suggested, header?, allow_other?)
```

It validates params, builds a `QuestionRequest`, hands it to the wired `QuestionResponderInterface`, and returns the validated `QuestionResponse` as tool result content the model reads. The tool is **context-agnostic** — it never knows whether it is in a REPL, an API turn, or a loop; the runtime wires the responder, exactly as it wires the execution policy today.

### Responder abstraction (`src/Question/`)

```php
interface QuestionResponderInterface {
    public function ask(QuestionRequest $question): QuestionResponse; // validated
}
```

Three implementations, mirroring the `ExecutionPolicyFactory` Interactive-vs-Auto split:

1. **`InteractiveQuestionResponder`** (REPL) — renders via `SymfonyStyle`: `choice()` for single-select, multi-select selection for multi, `ask()` for free-text; "Other" adds a choice that triggers `ask()`; the `suggested` answer is the pre-selected default. Returns synchronously. Mirrors `InteractiveApprovalPolicy`.
2. **`SuspendingQuestionResponder`** (API) — the interactive API turn runs out-of-process in a dedicated `bin/coqui turn:run` child process (`TurnRunCommand`), not on a suspendable fiber (`AgentFiberExecutor::scheduleFiberResumption` is an explicit v1 placeholder — the fiber runs to completion). So "suspend-and-ask" is realized by **blocking the child process on a bounded DB poll**: `ask()` persists the `QuestionRequest`, emits a typed `question` turn-event (`SessionStorage::appendTurnEvent`) that `MessageHandler`'s SSE poller streams over the open turn stream, then block-polls the `questions` table until the REST answer endpoint records a validated answer — returning it inline. Blocking is safe because it is a per-turn child process, not the API event loop. The poll is bounded by a timeout and the turn's cancellation token (SIGTERM via `AgentTurnManager::cancel`) so a never-answered question cannot hang the process. Mirrors the existing out-of-process + `task_inputs` DB-poll input-injection substrate rather than `Fiber::suspend`.
3. **`PolicyQuestionResponder`** (loops / background) — no live human. Consults the loop's `on_question` policy:
   - **`block`** — halt the stage to `blocked` (reusing #3's status + `escalation` block), carrying the `QuestionRequest` as the escalation payload. The operator answers via the REST answer endpoint (format-validated); the stage resumes with the `QuestionResponse`. (The #3 `loop_control(retry, note)` path remains for plain stuck-loop unblocks; question-blocks use the format-aware answer path.)
   - **`default`** — immediately return `question.suggested`; the stage continues uninterrupted; a one-line "auto-answered with suggestion" note is logged and surfaced.

The runtime resolves which responder to wire the same way it resolves the execution policy (REPL runtime → Interactive; API turn → Suspending; loop/background → Policy).

### REST answer endpoint (`src/Api/Handler/QuestionHandler.php`)

- `GET /api/v1/sessions/{id}/questions` — the pending question(s) for a session (also surfaced live via the SSE `question` event).
- `POST /api/v1/sessions/{id}/questions/{questionId}/answer` — body `{ selected?: string[], text?: string }`; validated against the pending `QuestionRequest`; resumes the suspended turn **or** the blocked loop stage. Core route (authenticated) — wired manually in `ApiCommand::registerRoutes`.

### Loop integration (extends #3)

- **`LoopDefinition`** gains `on_question: block | default` (default `block`; a role-level override is a future extension, not v1).
- `LoopExecutor`/`LoopManager`: when a stage agent calls `ask_user`, the wired `PolicyQuestionResponder` applies `on_question`. `block` reuses the `blocked` status + `escalation` payload from #3; `default` returns the suggestion and continues. `block` questions are answered via the REST answer endpoint above, which resumes the stage exactly like a retry.

### Open question for the planning phase

**How a `block`-mode stage resumes** depends on whether loop stages already execute on the same suspendable fiber path as API turns:

- If they do → block mode is `SuspendingQuestionResponder` behavior **plus** flipping the loop to `blocked` for visibility; the REST answer endpoint resumes the suspended stage turn exactly like an API turn. (Preferred — the agent's `ask_user` call returns the answer inline and it keeps reasoning.)
- If loop stages run to completion as discrete tasks → block mode halts the stage and, on answer, **reopens** the iteration à la #3's retry, with the answer injected into the reopened stage.

Both produce the same user-visible behavior (loop `blocked` → operator answers → stage proceeds with the answer). The plan must verify which path loop stages take (`LoopExecutor`/`LoopManager` vs `AgentFiberExecutor`) and pick accordingly. This is the one genuine implementation unknown.

**Resolved during planning (2026-07-14):** Loop stages run as **discrete background-task processes** — `LoopManager::advanceLoop()` → `SessionStorage::createTask()` → `bin/coqui task:run` (`TaskRunCommand`, its own process) → `LoopManager::reconcile()` polls task status. They are **not** fiber-suspendable (nor is the live API turn — see `SuspendingQuestionResponder` above). Therefore block mode takes the **second branch: reopen the iteration à la #3's retry.** A block-mode `ask_user` persists the question, writes it into the loop's `metadata.escalation` and flips the loop to `blocked` (reusing `LoopExecutor::escalateBlocked()`'s writes), and halts the stage by returning a hard-STOP sentinel `ToolResult` to the agent. On the operator's REST answer (validated by `QuestionResponse::isValidFor`, `422` on mismatch), the iteration is reopened exactly like a #3 retry (`resetStagesForIteration` + `resetIterationForRetry` + loop→`running`) and the answer is injected into the reopened stage prompt as an `## Answer to Your Earlier Question` section (the analogue of the existing `pending_guidance` injection). This is DB-durable and restart-safe; the inline block-poll alternative was rejected for loops (holds a task process open, loses its wait on any API restart).

### Validation (single authority: `QuestionResponse::isValidFor`)

- **single-select**: exactly one `selected` label that exists in `options`; or `text` set when `allowOther`.
- **multi-select**: every `selected` label exists in `options` (0+); `text` set only with `allowOther`.
- **free-text**: `text` non-null; `selected` empty.
- Invalid answers are rejected at the tool boundary (REPL re-prompts; the REST endpoint returns `422`; `PolicyQuestionResponder.default` validates `suggested` at construction).

### Audit

Each asked question and its answer is written to `audit_log` (question id, prompt, format, chosen answer, responder kind), mirroring how approval decisions are logged.

## Component summary

| Component | Location | Note |
|-----------|----------|------|
| `QuestionFormat`, `QuestionOption`, `QuestionRequest`, `QuestionResponse` | `src/Contract/` | shared representation + validation |
| `QuestionResponderInterface` | `src/Contract/` | one method: `ask()` |
| `InteractiveQuestionResponder` | `src/Question/` | REPL (SymfonyStyle) |
| `SuspendingQuestionResponder` | `src/Question/` | API (fiber suspend + SSE + resume) |
| `PolicyQuestionResponder` | `src/Question/` | loop/background (`block`\|`default`) |
| `ask_user` tool | `src/Toolkit/QuestionToolkit.php` | context-agnostic model surface |
| Responder wiring | REPL bootstrap, `AgentFiberExecutor`, `LoopExecutor` | mirror execution-policy resolution |
| `QuestionHandler` + routes | `src/Api/Handler/`, `ApiCommand::registerRoutes` | GET pending / POST answer |
| SSE `question` event | `SseStream` / `AgentTurnManager` | live surfacing |
| `on_question` field | `LoopDefinition`, `LoopExecutor`/`LoopManager` | default `block` |
| Docs + source map | `docs/` (new QUESTIONS section), `config/source.json` | |

## Testing (TDD)

- `QuestionRequest`/`QuestionResponse` serialize/round-trip; `isValidFor` across all three formats + Other + reject cases.
- `InteractiveQuestionResponder` with a scripted `SymfonyStyle` input → correct answer per format; suggested is the default.
- `SuspendingQuestionResponder`: `ask()` suspends the fiber; resuming with an answer returns the validated `QuestionResponse`; invalid answer is rejected.
- `PolicyQuestionResponder`: `default` returns `suggested`; `block` produces a `blocked` stage with the question as escalation payload.
- `ask_user` tool: builds the request, delegates, returns the answer; param validation (bad format, missing options for a select, missing/invalid suggested).
- REST: `POST .../answer` validates against the pending question (`422` on mismatch) and resumes the turn/stage; `GET .../questions` lists pending.
- Loop end-to-end: `on_question: block` → stage blocked → answer endpoint resumes it; `on_question: default` → suggestion applied, stage continues; auto-answer logged.
- Back-compat: existing turns/loops without `ask_user` are unaffected; no configured responder in a non-interactive path defaults to `block`.
- Audit rows written for asked/answered questions.

## Definition of Done

- Shared representation + `isValidFor`, the three responders, the `ask_user` tool, the REST answer endpoint + SSE event, and the loop `on_question` field implemented via TDD.
- `composer test` and `composer analyse` green.
- Docs (a QUESTIONS section, plus `docs/API.md` for the endpoint) and `config/source.json` updated.
- One question per `ask_user` call; batching and role-level `on_question` explicitly deferred.
