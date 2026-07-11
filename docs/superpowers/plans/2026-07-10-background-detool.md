# Background Tasks De-Tool Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the agent-facing background-task tooling (`BackgroundTaskToolkit`, `start_background_tool`/`BackgroundToolExecutor`, and the `childBackgroundTasks` path) while keeping the entire background-task execution substrate that loops, schedules, webhooks, and the `/tasks` API run on.

**Architecture:** The LLM stops creating raw background tasks; async work funnels through loops (`loop_start`). This is a removal of the toolkit layer only — the substrate (`BackgroundTaskManager`, `BackgroundTaskRecordStore`, `TaskRunCommand` prompt path, `SessionStorage::createTask`, `/tasks` API, observers, retry/escalate actions) is untouched because loops execute on it (`LoopManager::createTask`). Removal is done reference-first (wiring out the toolkit so it is unreferenced, keeping the suite green), then the orphaned files are deleted, then guidelines/docs are updated to point async work at `loop_start`.

**Tech Stack:** PHP 8.4, Pest (`composer test`), PHPStan level 8 (`composer analyse`).

**Spec:** `docs/superpowers/specs/2026-07-10-phase1-cuts-design.md` (Item 4).

## Global Constraints

- PHP 8.4, `declare(strict_types=1);`, `final` by default, one class per file, 4-space indent.
- Branch off `origin/feat/phase1-cuts` (= `main` + the batch spec/plans): `git fetch origin && git checkout -b feat/background-detool origin/feat/phase1-cuts`. (Base carries no code changes over main — only the planning docs.)
- **Never `git add -A` or `git add .`** — two intentional unstaged edits (`.gitignore` modified, `.vscode/settings.json` deleted) MUST stay unstaged. Stage only exact paths.
- Every commit message ends with: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- Both `composer test` and `composer analyse` must be green before every commit.
- **KEEP the substrate — do NOT touch:** `src/Api/BackgroundTaskManager.php`, `src/Storage/BackgroundTaskRecordStore.php`, `src/Observer/BackgroundTaskObserver.php`, `src/Contract/BackgroundTaskSummary.php`, `src/Notification/RetryBackgroundTaskAction.php`, `src/Notification/EscalateLoopFailureAction.php`, `src/Api/Handler/TaskHandler.php`, the `TaskRunCommand` prompt/agent path, `SessionStorage::createTask`, `CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS`, `OpenClawConfig::getBackgroundTaskMaxIterations()`, and the `background_tasks` DB table (its `tool_name`/`tool_arguments` columns become vestigial — leave them).
- **Merge order:** this is PR 2 of 3 (Channels → Background → Composer/Packagist). It deletes `BackgroundToolExecutor.php`, which the Composer/Packagist PR would otherwise have to edit — so land this **before** the Composer/Packagist PR.

---

### Task 1: Wire out the toolkit and delete orphaned files

**Files — modify (excise references):**
- `src/Agent/OrchestratorAgent.php` — remove the `BackgroundTaskToolkit` import (line ~64) and the gate-map entry `'BackgroundTaskToolkit' => 'background-tasks'` (line ~174), plus any registration/instantiation.
- `src/Agent/OrchestratorDependencies.php` — remove the `backgroundTaskToolkit` field (line ~62) and import (line ~32).
- `src/Agent/AgentRunner.php` — remove the `BackgroundTaskToolkit` construction (line ~976) and import (line ~52).
- `src/Tool/SpawnAgentTool.php` — remove the `BackgroundTaskToolkit` import (line ~39), the `childBackgroundTasks` attach-block in `buildToolkits` (lines ~444–460, guarded by `isFeatureEnabled('background_tasks')` + `isChildBackgroundTasksEnabled()`), the `isChildBackgroundTasksEnabled()` method, and the `agents.defaults.childBackgroundTasks` read (line ~528). Keep the rest of `SpawnAgentTool`.
- `src/Config/SetupWizard.php` — remove `configureChildBackgroundTasks()` and all `childBackgroundTasks` references (lines ~86, 110, 245, 247, 295, 334, 353–356).
- `src/Command/TaskRunCommand.php` — remove the `BackgroundToolExecutor` import (line ~8) and the entire `tool_name` branch (the `$toolName = $task['tool_name']` path at lines ~108, 273–385). Keep the prompt/agent branch.

**Files — modify (tests):**
- `tests/Unit/Tool/SpawnAgentToolTest.php` — remove/adjust cases that assert `childBackgroundTasks` attaches `BackgroundTaskToolkit` to children.

**Files — delete:**
- `src/Toolkit/BackgroundTaskToolkit.php`
- `src/Agent/BackgroundToolExecutor.php`
- `tests/Unit/Toolkit/BackgroundTaskToolkitTest.php`

**Interfaces produced:** the agent tools `start_background_task`, `task_status`, `list_tasks`, `cancel_task`, `start_background_tool` no longer exist. `agents.defaults.childBackgroundTasks` config key is no longer read. No new symbols.

- [ ] **Step 1: Create the branch and confirm clean tree**

```bash
git fetch origin
git checkout -b feat/background-detool origin/feat/phase1-cuts
git status --short   # expect only: M .gitignore, D .vscode/settings.json
```

- [ ] **Step 2: Enumerate the reference set (work-list)**

```bash
grep -rn "BackgroundTaskToolkit\|BackgroundToolExecutor\|childBackgroundTasks\|start_background_tool\|isChildBackgroundTasksEnabled" src/ tests/ --include="*.php"
```

- [ ] **Step 3: Excise references in the "Files — modify" list**

Remove the toolkit wiring, the `childBackgroundTasks` block + method + config read, and the `tool_name` branch in `TaskRunCommand`. Adjust `SpawnAgentToolTest.php`.

- [ ] **Step 4: Delete the orphaned toolkit + executor + toolkit test**

```bash
git rm src/Toolkit/BackgroundTaskToolkit.php src/Agent/BackgroundToolExecutor.php \
       tests/Unit/Toolkit/BackgroundTaskToolkitTest.php
```

- [ ] **Step 5: Run PHPStan and fix every dangling reference**

Run: `composer analyse`
Expected: `[OK] No errors`. Any undefined `BackgroundTaskToolkit`/`BackgroundToolExecutor` reference is a missed excision — fix and repeat.

- [ ] **Step 6: Run the full suite; confirm the SUBSTRATE stays green**

Run: `composer test`
Expected: all green — and specifically these substrate tests still pass (do NOT modify them):
`tests/Unit/Api/Handler/TaskHandlerTest.php`, `tests/Unit/Observer/BackgroundTaskObserverTest.php`, `tests/Unit/Contract/BackgroundTaskSummaryTest.php`, `tests/Unit/Agent/LoopExecutorTest.php`, `tests/Unit/Api/LoopManagerTest.php`, `tests/Unit/Api/Handler/LoopHandlerTest.php`, `tests/Unit/Api/ScheduleManagerTest.php`.
If a substrate test fails, you removed too much — restore the substrate symbol.

- [ ] **Step 7: Sanity-check the substrate is intact**

Run:
```bash
grep -rn "createTask\|BackgroundTaskManager\|BACKGROUND_TASK_MAX_ITERATIONS" src/Api/LoopManager.php src/Api/Handler/TaskHandler.php src/Command/TaskRunCommand.php
```
Expected: still present (loops/schedules/webhooks/API create tasks; the cap is still enforced).

- [ ] **Step 8: Commit**

```bash
git add -u src/ tests/
git status --short   # verify .gitignore and .vscode/settings.json are NOT staged
git commit -m "$(cat <<'EOF'
refactor(background-tasks): de-tool the agent-facing toolkit

Removes BackgroundTaskToolkit (start_background_task, task_status, list_tasks,
cancel_task, start_background_tool), BackgroundToolExecutor, the tool_name task
branch, and the childBackgroundTasks path. The execution substrate
(BackgroundTaskManager, RecordStore, TaskRunCommand prompt path, /tasks API,
createTask) is unchanged — loops, schedules, and webhooks still run on it.
Agent async work funnels through loop_start.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Redirect guidance to loops; update docs and source map

**Files:**
- Modify: `src/Toolkit/LoopToolkit.php` (guidelines) — add a line that ad-hoc async work uses `loop_start(definition: "goal-driven", goal: …)`.
- Modify: any orchestrator guidance/system-prompt section that told the agent to "run in the background" — repoint to loops. (Search: `grep -rn "background" src/Agent/*.php src/Prompt* 2>/dev/null`.)
- Rewrite: `docs/BACKGROUND-TASKS.md` — agents no longer create background tasks directly; the substrate powers loops/schedules/webhooks and the `/tasks` API; agent-driven async goes through loops.
- Modify: `docs/FEATURES.md`, `docs/TOOLKITS.md` (remove the background-task toolkit from agent toolkits), `docs/CONFIGURATION.md` (note `agents.defaults.childBackgroundTasks` is removed/inert).
- Modify: `config/source.json` — remove `BackgroundTaskToolkit` and `BackgroundToolExecutor` entries.

- [ ] **Step 1: Update the LoopToolkit guidance**

Add to the `guidelines()` text a short pointer:
```
For ad-hoc background work, start a goal-driven loop:
loop_start(definition: "goal-driven", goal: "<what to accomplish>").
Loops run concurrently with the conversation and govern/verify their own progress.
```

- [ ] **Step 2: Repoint any "run in background" orchestrator guidance to loops**

- [ ] **Step 3: Rewrite `docs/BACKGROUND-TASKS.md` and update FEATURES/TOOLKITS/CONFIGURATION**

- [ ] **Step 4: Prune `config/source.json`**

Remove the two entries (`BackgroundTaskToolkit`, `BackgroundToolExecutor`).

- [ ] **Step 5: Verify green + no stale toolkit references**

```bash
composer test && composer analyse
grep -rn "BackgroundTaskToolkit\|BackgroundToolExecutor\|start_background_tool\|childBackgroundTasks" src/ tests/ config/source.json docs/
```
Expected: suite green, PHPStan clean, grep empty.

- [ ] **Step 6: Commit**

```bash
git add src/Toolkit/LoopToolkit.php src/Agent/ docs/ config/source.json
git status --short
git commit -m "$(cat <<'EOF'
docs(background-tasks): route agent async work through loops

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

- **Spec coverage:** delete list ✓ (Task 1 Step 4), reference-excise list incl. childBackgroundTasks + tool_name branch ✓ (Task 1 Step 3), substrate keep-list guarded ✓ (Global Constraints + Task 1 Steps 6–7), guidance→loops + docs + source.json ✓ (Task 2).
- **Placeholder scan:** none — exact files/commands/grep throughout.
- **Substrate safety:** explicit keep-list and a substrate-green gate (Step 6) plus a positive presence grep (Step 7) prevent over-removal. `BACKGROUND_TASK_MAX_ITERATIONS` / `getBackgroundTaskMaxIterations()` retained (still used by `TaskHandler`/`TaskRunCommand`).
- **Type consistency:** no new symbols; only removals.

**Handoff:** developed on `feat/background-detool`; the user reviews and merges. Do not push or open the PR without confirmation.
