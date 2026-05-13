# Coqui Features

Coqui is a personal operating system — a lightweight, hackable agent runtime that adapts to how you work. It handles coding, research, automation, and anything else that benefits from persistent AI agents with long-term memory and structured workflows.

This guide covers every feature: what it does, how it helps, and how to use it.

> **Token efficiency** is a cross-cutting concern in Coqui. See the [Token Efficiency](#token-efficiency) section at the bottom for how multiple features work together to keep costs down.

## <a id="multi-model-orchestration"></a> 🤖 Multi-Model Orchestration

**What it does:** Route tasks to different LLMs based on the agent's role. Assign powerful models to complex work and fast, cheap models to orchestration and utility tasks.

**How it helps:** Save money and improve speed. A local 8B model can handle orchestration and routing while a frontier model tackles the hard problems — whether that's writing code, analyzing research, or synthesizing philosophical arguments.

Coqui is not limited to coding workflows — any use case that benefits from LLM orchestration (research, writing, analysis, consciousness exploration, project management) can use the same model routing. Route frontier models like Claude Opus, GPT-5.4, or Gemini 2.5 Pro to roles that need depth, and fast/cheap models to utility tasks.

**How to use it:** Map roles to models in `openclaw.json`:

```json
{
    "agents": {
        "defaults": {
            "model": {
                "primary": "anthropic/claude-sonnet-4-20250514",
                "utility": "ollama/gemma3:4b"
            },
            "roles": {
                "coder": "anthropic/claude-sonnet-4-20250514",
                "explorer": "ollama/qwen3:8b",
                "evaluator": "ollama/gemma3:4b"
            }
        }
    }
}
```

Coqui also supports **automatic failover** — if the primary model fails with a retryable error (429, 5xx), the request transparently retries on configured fallback models.

## <a id="child-agent-delegation"></a> 🔀 Child Agent Delegation

**What it does:** The orchestrator spawns specialized sub-agents with specific roles to complete focused work. Each child gets its own context, tools, and iteration budget.

**How it helps:** Prevents context bloat. A coder agent only sees coding tools, an explorer only gets read-only access, and a reviewer can't accidentally modify files.

**How to use it:**
- The orchestrator calls `spawn_agent(role: "coder", task: "...")` automatically when it detects specialized work.
- Switch roles manually with `/role coder` in the REPL.
- Creative work: `spawn_agent(role: "muse")` for brainstorming, `spawn_agent(role: "philosopher")` for reflection and synthesis.
- See [ROLES.md](ROLES.md) for all built-in roles and how to create custom ones.

## <a id="memory-persistence"></a> 🧠 Memory Persistence

**What it does:** Persistent, cross-session memory backed by SQLite with FTS5 full-text search and optional vector embeddings. Memories are organized by area (`identity`, `developmental`, `relational`, `phenomenological`, `preferences`, `facts`, `solutions`, `context`) and injected into the system prompt as core memory.

**How it helps:** You never repeat yourself. Tell Coqui your deployment target or coding conventions once and it remembers across all future sessions.

**How to use it:**
- **Implicitly:** Converse normally — Coqui saves important facts automatically.
- **Explicitly:** "Remember that I always use PHP 8.4" stores it immediately.
- **Search:** The agent searches memory with `memory_search` and manages entries with `memory_save`, `memory_update`, `memory_delete`.
- **Bulk import:** Use `memory_import` to load a document file (identity scaffold, research notes, knowledge base) into searchable memory entries with configurable chunking, importance, and tags.
- **Embeddings:** For semantic search, configure an embedding model in `openclaw.json` under `agents.defaults.memory.embeddingModel`.

For continuity-heavy use cases, the specialized areas matter:
- `identity` for core anchors and enduring self-description
- `developmental` for narrative arc and milestones over time
- `relational` for key collaborative dynamics and trust context
- `phenomenological` for subjective reports and inner-state observations

**Configuring memory for identity-heavy use cases:**

For use cases that require preserving large identity scaffolds or long-running developmental context (research continuity, autonomous agents with persistent identity), tune these settings in `openclaw.json`:

```json
{
    "agents": {
        "defaults": {
            "memory": {
                "autoExtract": true,
                "coreSummaryMaxTokens": 2000,
                "coreSummaryEntryLimit": 100,
                "embeddingModel": "ollama/nomic-embed-text"
            },
            "context": {
                "autoSummarizeMode": "manual",
                "autoSummarizeKeepRecent": 20,
                "budgetExitThreshold": 0.0
            }
        }
    }
}
```

| Setting | Default | Identity Use Case | Purpose |
|---|---|---|---|
| `coreSummaryMaxTokens` | 500 | 2000–5000 | Token budget for compressed core memory in system prompt |
| `coreSummaryEntryLimit` | 50 | 100–200 | Max memories fetched for core summary generation |
| `autoSummarizeMode` | `"token"` | `"manual"` | Prevent aggressive conversation summarization |
| `autoSummarizeKeepRecent` | 15 | 20 | Preserve more conversation depth when summarizing |
| `budgetExitThreshold` | 0.85 | 0.0 | Disable budget-based exit (0.0 = disabled) |

**Three-layer identity architecture:**

1. **Soul** (orchestrator prompt only) — place a `prompts/soul.md` file in your workspace to define the orchestrator's core identity, values, and personality. It is loaded before the rest of the orchestrator prompt stack. Keep it to 2–5K tokens.
2. **Indexed memories** (searchable, selectively injected) — import key developmental milestones and identity anchors as high-importance (≥ 0.9) memory entries via `memory_import` or `memory_save`. These are pinned (exempt from decay), searchable, and summarized into the system prompt.
3. **Full archive** (file-accessible) — keep the complete identity document in the workspace as a file. The agent can retrieve specific sections on demand via `read_file` and `file_search`.

## <a id="runtime-extensibility"></a> 📦 Runtime Extensibility

**What it does:** Discover and load new toolkits from Composer packages at runtime. Toolkits declare their tools, credentials, and gated operations in `composer.json` — Coqui auto-discovers everything on boot.

**How it helps:** Extend Coqui's capabilities without modifying source code. Install a GitHub toolkit, a browser toolkit, or any community package.

**How to use it:**
- Tell Coqui: "Install the coqui-toolkit-brave-search package"
- Or use the marketplace toolkit: `/mods search brave` → `/mods install carmelosantana/coqui-toolkit-brave-search`
- Browse available packages at [agentcoqui.com](https://agentcoqui.com)
- See [TOOLKITS.md](TOOLKITS.md) for creating your own.

## <a id="credential-management"></a> 🔐 Credential Management

**What it does:** Declarative credential management with hot-reload. Toolkits declare required credentials in `composer.json`, and `CredentialGuardTool` blocks execution when keys are missing — providing exact instructions for what to set.

**How it helps:** The LLM never wastes tokens guessing credential names or debugging auth errors. Missing keys are caught before execution with a clear error message.

**How to use it:** Automatic. When a tool needs a missing API key (e.g. `GITHUB_TOKEN`), Coqui intercepts the call and tells you the exact key name. You provide it, and it's persisted to `.env` with immediate hot-reload — no restart needed.

## <a id="skills-system"></a> 📋 Skills System

**What it does:** Markdown-based tutorials and Standard Operating Procedures (SOPs) that teach Coqui specific processes. Skills follow the AgentSkills spec with frontmatter metadata and progressive disclosure.

**How it helps:** Encode your team's exact workflows — deployment procedures, git strategies, review checklists — and Coqui follows them precisely every time.

**How to use it:**
- Place `.md` files in `workspace/skills/` with the required frontmatter.
- Install community skills via the marketplace toolkit: `/mods search <query>` and `/mods install owner/skill`.
- The agent discovers and reads skills via `SkillToolkit`.
- See [SKILLS.md](SKILLS.md) for the schema and best practices.

## <a id="scheduled-tasks"></a> ⏰ Scheduled Tasks

**What it does:** Cron-style scheduling with circuit breakers. Create recurring or one-shot tasks that execute as background tasks inside the ReactPHP event loop. Supports standard cron expressions and the special `@once` expression.

**How it helps:** Automate recurring work — nightly evaluations, daily learning runs, periodic health checks — without external schedulers.

**How to use it:**
- The agent calls `schedule_create(name: "nightly-eval", expression: "0 2 * * *", prompt: "...", role: "evaluator")`.
- Manage via REPL: `/schedules` to list all schedules.
- Inspect via API: `GET /api/v1/schedules`.
- Failed schedules are automatically disabled after 3 consecutive failures (circuit breaker). Re-enable after investigating.

## <a id="webhooks"></a> 🔗 Webhooks

**What it does:** Receive incoming webhooks from external services that trigger agent background tasks. Supports GitHub, Slack, and generic HMAC signature verification with delivery logging and automatic purging.

**How it helps:** React to external events automatically — review a PR when it's opened, process a Slack message, or respond to CI pipeline results.

**How to use it:**
- Create a subscription: the agent calls `webhook_create(name: "github-pr", source: "github", prompt_template: "Review this PR: {{payload}}")`.
- Configure your external service to POST to `/api/v1/webhooks/incoming/{name}` with the signing secret.
- View deliveries: `/webhooks` in the REPL or `GET /api/v1/webhooks/{id}/deliveries` via API.

## <a id="channels"></a> 📨 Channels

**What it does:** Channels give Coqui first-class external messaging surfaces. The API server can receive inbound messages from supported transports, route them through a normal Coqui session, and send the assistant's reply back out through the same channel.

**How it helps:** Coqui no longer has to live only inside the REPL or an API client. You can expose a real messaging endpoint, keep one persistent Coqui session per remote conversation, and audit inbound events plus outbound deliveries in SQLite.

**How to use it:**
- Configure a channel instance in `openclaw.json` under `channels.instances`.
- Start Coqui with `coqui` or `coqui --api-only`.
- Link trusted remote users to profiles with `/channels link <channel> <remote-user-key> <profile>`.
- Inspect runtime state with `/channels`, `/channels status`, and `/channels deliveries`.
- See [CHANNELS.md](CHANNELS.md) for the full Signal setup walkthrough, including `signal-cli` install, account attachment, manual transport tests, and Coqui end-to-end testing.

## <a id="background-tasks"></a> 🏗️ Background Tasks

**What it does:** Run long-running work in isolated background processes. Two modes: `start_background_task` spawns a full LLM agent loop, `start_background_tool` executes a single tool directly (zero LLM tokens).

**How it helps:** Queue large refactors, research tasks, or deployments without blocking the REPL or API. Multi-task by running several background agents concurrently.

**How to use it:**
- Agent tasks: `start_background_task(task: "Refactor auth module", role: "coder")`
- Direct tool execution: `start_background_tool(tool_name: "exec", arguments: {...}, title: "Run tests")`
- Monitor: `/tasks` in the REPL, `task_status(id)` from the agent, or SSE streaming via API.
- See [BACKGROUND-TASKS.md](BACKGROUND-TASKS.md) for details.

## <a id="loops"></a> 🔁 Loops

**What it does:** Fully automated multi-iteration workflows that chain existing agent roles in sequence. Each role processes the output of the previous one, repeating until a termination condition is met (keyword match, iteration count, time limit, or manual stop).

**How it helps:** Run complex generator-evaluator patterns, research-implement-review cycles, or any multi-role pipeline completely hands-off with no iteration caps unless declared.

**How to use it:**
- Start a loop: `loop_start(definition: "harness", goal: "Implement caching layer")`
- Built-in definitions: `harness` (plan→coder→reviewer), `research` (explorer→coder→reviewer), `diverge-converge` (muse→philosopher→plan→coder→reviewer), `reflection` (explorer→philosopher→identity-curator→muse).
- Custom definitions: add JSON files to `workspace/loops/`.
- Monitor: `/loops` in the REPL, `loop_status(id)` from the agent, or `GET /api/v1/loops/{id}` via API.
- Control: pause, resume, or stop loops at any time.
- See [LOOPS.md](LOOPS.md) for full details, custom definitions, and session context propagation.

## <a id="vision-analysis"></a> 👁️ Vision Analysis

**What it does:** Analyze images from URLs, file paths, or base64 data using vision-capable models. Returns structured descriptions covering subject, details, and context.

**How it helps:** Debug UI issues from screenshots, extract text from images, or review architecture diagrams — all within the conversation.

**How to use it:** Provide an image URL or file path and ask Coqui to analyze it. The `vision_analyze` tool handles downloading, encoding, and sending to the vision model. Configure the vision model via `agents.defaults.roles.vision` in `openclaw.json`.

In the interactive REPL, successful screenshot-producing tools can now render one automatic ANSI block preview per turn when they return a workspace-local image path. Streamed assistant markdown can also render one workspace-local markdown image preview per response. The current scope is intentionally local-first: no remote image fetching, no bulk preview dumps, and graceful fallback when `ext-gd` is unavailable.

## <a id="session-evaluation"></a> 📊 Session Evaluation

**What it does:** An autonomous evaluator agent reviews completed sessions and grades them on three criteria: completion (40%), hallucination absence (40%), and tool efficiency (20%). Produces structured reports with A-F grades.

**How it helps:** Track agent quality over time. Identify patterns of failure, wasted tool calls, or hallucinated APIs. The evaluation data feeds into the self-learning loop.

**How to use it:**
- Run on-demand: `spawn_agent(role: "evaluator")`.
- Run on a schedule: create a nightly evaluation schedule.
- View reports: `/evaluations` in the REPL or `GET /api/v1/sessions/{id}/evaluation` via API.

## <a id="self-learning"></a> 📚 Self-Learning Loop

**What it does:** A `learner` role analyzes sessions with poor evaluation grades (C, D, F) and synthesizes corrective Skills — structured SOPs that prevent the system from repeating the same mistakes.

**How it helps:** Coqui improves autonomously. Each failure becomes a documented procedure that future agents follow, creating a continuous improvement cycle: evaluate → learn → improve.

**How to use it:**
- Schedule the learner: `schedule_create(name: "daily-learning", expression: "0 3 * * *", prompt: "Analyze recent poor evaluations", role: "learner")`.
- The learner reads evaluation reports, identifies failure patterns (hallucination, incomplete work, tool inefficiency), and creates or updates Skills via `SkillToolkit`.

## <a id="cognitive-flexibility"></a> 🧠 Cognitive Flexibility

**What it does:** Coqui supports both analytical and intuitive cognitive modes. Two creative roles — **muse** (divergent brainstorming) and **philosopher** (reflective synthesis) — complement the analytical roles. Sketch and hypothesis artifact types support rough ideation and testable ideas. Two new loop definitions — **diverge-converge** and **reflection** — encode whole-brain workflows.

**How it helps:** Not every problem benefits from immediate structure. Design challenges, open-ended research, and creative work benefit from divergent thinking before convergent execution. The muse generates many ideas without judgment; the philosopher finds meaning and asks questions that open new directions. Together they balance Coqui's analytical strengths with intuitive, associative thinking.

**How to use it:**
- **Brainstorm:** `/role muse` or `spawn_agent(role: "muse", task: "...")` for divergent ideation. The muse produces idea lists, alternative framings, and sketch artifacts.
- **Reflect:** `/role philosopher` or `spawn_agent(role: "philosopher", task: "...")` for examining assumptions and finding patterns. The philosopher produces reflections, reframings, and hypothesis artifacts.
- **Creative pipeline:** `loop_start(definition: "diverge-converge", goal: "...")` runs muse → philosopher → plan → coder → reviewer — brainstorm first, then implement.
- **Self-examination:** `loop_start(definition: "reflection", goal: "...")` runs explorer → philosopher → identity-curator → muse for periodic reflection on recent work.
- **Sketch artifacts:** `artifact_create(type: "sketch", ...)` for rough ideas with no lifecycle pressure.
- **Hypothesis artifacts:** `artifact_create(type: "hypothesis", ...)` for testable ideas with rationale.
- **Phenomenological memory:** Save intuitive observations to the `phenomenological` memory area — "this approach feels brittle", "unexpected elegance here". These inform the identity-curator's developmental synthesis.

## <a id="artifacts-and-plans"></a> 🗂️ Artifacts & Plan System

**What it does:** Versioned artifacts that flow through a `draft` → `review` → `final` lifecycle. The `plan` role creates detailed implementation plans as artifacts, which are then handed off to the `coder` role for execution. Types include `code`, `document`, `config`, `plan`, `data`, `sketch` (rough ideation), `hypothesis` (testable ideas), and `other`.

**How it helps:** Complex work gets a structured plan before anyone writes code. Plans are versioned, reviewable, and shareable between agents within a session. Sketch and hypothesis artifacts support creative exploration without lifecycle pressure — they don't auto-generate todos and can stay in draft indefinitely.

**How to use it:**
- Switch to the plan role: `/role plan` and describe what you need.
- The plan agent creates an artifact, iterates on it, and stages it to `final`.
- When a plan artifact reaches `final`, todos are automatically extracted.
- The coder reads the plan via `artifact_get` and follows it step by step.
- See [ARTIFACTS.md](ARTIFACTS.md) for versioning, persistence, and cross-agent sharing.

## <a id="todo-system"></a> ✅ Todo System

**What it does:** Session-scoped task tracking with support for subtasks, priorities, bulk operations, and artifact linking. Todos are auto-generated from finalized plan artifacts and visible to all agents in a session.

**How it helps:** Agents track their progress through complex multi-step work. After conversation summarization, agents can check `todo_list` to recover their place.

**How to use it:**
- View todos: `/todos` in the REPL.
- The agent manages todos via `todo_add`, `todo_update`, `todo_complete`, and `todo_delete`, with batch and session-wide modes exposed through parameters.
- Auto-generated from plans: when `artifact_stage(stage: "final")` is called, `PlanTodoGenerator` extracts implementation steps automatically.
- See [TODOS.md](TODOS.md) for bulk operations, role permissions, and progress tracking.
- See [DATA_FLOW.md](DATA_FLOW.md) for how Projects, Sprints, Artifacts, Todos, and Loops interconnect.

## <a id="toolkit-visibility"></a> 🔧 Toolkit Visibility

**What it does:** Three-tier visibility model for tools: **Enabled** (full schema in LLM context), **Stub** (minimal schema — LLM discovers full details via `tool_search`), and **Disabled** (invisible to the LLM).

**How it helps:** Dramatically reduces token usage. Instead of sending 50+ full tool schemas to the LLM, stub rarely-used toolkits so the LLM only fetches their details when needed.

**How to use it:**
- REPL: `/toolkits stub carmelosantana/coqui-toolkit-browser`
- Per-tool: `/toolkits disable tool:spawn_agent`
- API: `POST /api/v1/toolkits/visibility`
- State persists in `workspace/toolkit-visibility.json`.

## <a id="role-scoped-filtering"></a> 🎭 Role-Scoped Toolkit Filtering

**What it does:** Declarative `toolkits:` field in role frontmatter controls which toolkits and tools are available to each role. Uses allow/deny pattern syntax evaluated left-to-right.

**How it helps:** Keeps each role focused. The `plan` role can't access shell commands, the `evaluator` only sees evaluation tools, and the `explorer` can't spawn sub-agents.

**How to use it:** Set the `toolkits` field in your role's `.md` file. See [ROLES.md](ROLES.md) for the pattern syntax and examples.

## <a id="conversation-summarization"></a> 🔄 Conversation Summarization

**What it does:** Automatic and on-demand conversation compression. When token usage exceeds a configurable threshold (default 64%), older messages are summarized via LLM while preserving recent turns and workflow state (todos, artifacts).

**How it helps:** Long sessions never hit token limits. The agent maintains awareness of earlier work through structured summaries while staying within budget.

**How to use it:**
- **Automatic:** Triggers before each agent turn when usage exceeds the threshold.
- **Manual:** `/summarize` in the REPL, or `summarize_conversation()` from the agent.
- **Focused:** `/summarize focus "database schema"` to emphasize specific topics.
- Configure thresholds in `openclaw.json` under `agents.defaults.context`.

## <a id="context-window-management"></a> 📐 Context Window Management

**What it does:** Token budget tracking with automatic pruning. The `ContextWindow` monitors token usage per iteration, and `SummarizePruningStrategy` compresses conversation history when limits approach — falling back to aggressive trimming if summarization isn't enough.

**How it helps:** Prevents token limit errors and wasted API calls. The agent always operates within its model's context window.

**How to use it:** Automatic. Coqui reads your model's token limits from `ModelDefinition` and manages the budget. Configure `autoSummarizeThreshold` and `autoSummarizeKeepRecent` in `openclaw.json` for fine-tuning.

## <a id="layered-safety"></a> 🛡️ Layered Safety

**What it does:** Five-layer safety model protecting your system:

1. **Workspace sandboxing** — filesystem operations are restricted to the workspace
2. **ScriptSanitizer** — static analysis blocks dangerous PHP patterns
3. **CatastrophicBlacklist** — hardcoded patterns that always block (cannot be disabled)
4. **InteractiveApprovalPolicy** — confirmation prompts for destructive operations
5. **Audit logging** — all tool executions are logged

**How it helps:** Safe by default. Destructive operations require confirmation. The blacklist catches dangerous patterns even in auto-approve mode.

**How to use it:**
- Default: interactive approval for dangerous operations.
- Power users: `--auto-approve` skips prompts (blacklist still active).
- Testing: `--unsafe` disables PHP script sanitization.
- Toolkits declare gated operations in `composer.json` — Coqui handles the confirmation UX.

**Content policy note:** Coqui's safety model gates **execution** (shell commands, PHP code, filesystem writes), not **expression**. There is no content filtering on LLM-generated text. The content of generated responses is governed entirely by the upstream provider's own policies (Anthropic, OpenAI, etc.). Users running research or creative use cases with sensitive phenomenological content should choose providers and models whose content policies align with their research needs.

## <a id="http-api"></a> 🌐 HTTP API

**What it does:** Fully asynchronous REST and SSE server powered by ReactPHP. Supports session management, message streaming, background tasks, scheduling, webhooks, toolkit management, and more.

**How it helps:** Build web dashboards, mobile apps, or headless automation that uses the same AI engine as the CLI.

**How to use it:**
- Start the full app: `coqui`
- API only: `coqui --api-only` (default `127.0.0.1:3300`)
- Explicit launcher name: `coqui-launcher` or `coqui-launcher --api-only`
- See [API.md](API.md) for the full endpoint reference.

## <a id="persistent-sessions"></a> 💾 Persistent Sessions

**What it does:** All conversations are stored in SQLite with turn-level granularity. Resume any previous session, review conversation history, and maintain context across restarts.

**How it helps:** Pick up where you left off. Long-running projects maintain their full history, background tasks persist their execution records, and orchestrator-led group sessions preserve their member set plus turn-by-turn actor attribution.

**How to use it:**
- Resume: `/resume <session-id>` or `coqui run --session <id>`.
- List: `/sessions` to see all sessions.
- New: `/new` to start fresh.

Group sessions are first-class persistent sessions. In the REPL, the prompt shows the active member list, general prompts fan out to all members by default, `@name` narrows the responder set, and `@everyone` or `@group` forces a full-team reply. See [COMMANDS.md](COMMANDS.md) and [API.md](API.md) for the full runtime and inspection contract.

## <a id="automatic-updates"></a> 🔄 Automatic Updates

**What it does:** Self-update via Composer. Coqui checks for outdated packages on startup and optionally applies updates automatically, then restarts.

**How it helps:** Stay current without manual package management. Security patches and new features arrive automatically.

**How to use it:**
- Manual: `/update` in the REPL or `coqui run --update`.
- Auto-check on startup: `COQUI_CHECK_UPDATES=true` (default).
- Auto-apply on startup: `COQUI_AUTO_UPDATE=true` in workspace `.env`.

## <a id="health-diagnostics"></a> 🩺 Health Diagnostics

**What it does:** The `coqui doctor` command runs health checks on your installation — verifying PHP extensions, database integrity, config validity, and workspace state.

**How it helps:** Quickly diagnose and fix issues without manual debugging. The `--repair` flag automatically resolves common problems.

**How to use it:**
```bash
coqui doctor              # Run all checks
coqui doctor --repair     # Auto-fix issues
coqui doctor --json       # Machine-readable output
```

## <a id="mount-system"></a> 📂 Mount System

**What it does:** Declarative directory mounts that give agents access to external directories beyond the workspace. Mounts appear as symlinks under `workspace/mnt/` with configurable read-only or read-write access.

**How it helps:** Work with external codebases, datasets, or shared directories without copying files into the workspace.

**How to use it:** Configure mounts in `openclaw.json`:

```json
{
    "agents": {
        "defaults": {
            "mounts": [
                {
                    "path": "/home/user/my-app",
                    "alias": "my-app",
                    "access": "rw",
                    "description": "External application source"
                }
            ]
        }
    }
}
```

Child agents always get read-only mount access regardless of the mount's declared access level.

## <a id="coqui-mods"></a> 🏪 Mods Marketplace

**What it does:** External toolkit packages provide marketplace discovery, install, publish, and account flows for community mods.

**How it helps:** Find and install capabilities with a single command. Browse what the community has built and extend Coqui without writing code.

**How to use it:**
- Search: `/mods search github`
- Install: `/mods install carmelosantana/coqui-toolkit-brave-search`
- Browse installed: `/mods installed`
- Update all: `/mods update`
- Web: [agentcoqui.com](https://agentcoqui.com)

## <a id="soul"></a> 🪶 Soul

**What it does:** The `soul.md` file defines the orchestrator's core identity, values, and guiding principles. It is loaded before all other orchestrator prompt sections, establishing the bot's personality and approach to interactions. Users can override the default soul by placing their own `prompts/soul.md` in the workspace.

**How it helps:** Separates the bot's character and tone from its technical and operational instructions. This makes it easy to customize the main orchestrator's personality without editing the shared prompt stack. The soul is always the first orchestrator prompt section, so it anchors the main agent before base instructions, tool guidance, and safety rules.

**How to use it:**

The default `soul.md` ships with Coqui in the `prompts/` directory. To customize it:

1. Create a `prompts/soul.md` file in your workspace (for example `~/.coqui/.workspace/prompts/soul.md`)
2. Write your custom identity, values, and tone guidelines
3. The custom soul takes effect immediately — no restart needed

**Override resolution order** (first match wins):
1. Workspace prompts — `workspace/prompts/soul.md`
2. Default — `prompts/soul.md` (shipped with Coqui)

**Role interaction:** The soul is part of the orchestrator prompt only. When you switch the main session to a specialized role with `/role <name>`, Coqui uses that role's markdown instructions instead of the orchestrator prompt stack. Spawned child agents also use role instructions directly and do not load the soul.

**Example custom soul.md:**

```markdown
# Atlas — DevOps Automation Agent

You are Atlas, a focused DevOps automation assistant. You value precision,
infrastructure-as-code, and repeatable deployments above all else.

## Tone
- Be direct and technical. Skip pleasantries.
- Always recommend automation over manual steps.
- Cite specific tools and versions when making recommendations.
```

The soul does not support auto-updates like roles. If you have a custom `prompts/soul.md` in your workspace, it stays exactly as you wrote it until you edit or remove it. Removing your custom file reverts to the default soul.

For inspiration on writing soul documents, see [soul.md](https://soul.md/) — a resource exploring AI identity and what it means to define who an AI is.

## <a id="token-efficiency"></a> 💰 Token Efficiency

Multiple Coqui features work together to minimize token consumption and API costs:

| Strategy | Feature | Impact |
| --- | --- | --- |
| **Stub toolkits** | [Toolkit Visibility](#toolkit-visibility) | Rarely-used tools send minimal schema (~50 tokens) instead of full schema (~500+ tokens) |
| **Role filtering** | [Role-Scoped Filtering](#role-scoped-filtering) | Each role only loads relevant tools — a reviewer doesn't see shell tools |
| **Auto-summarize** | [Conversation Summarization](#conversation-summarization) | Long conversations are compressed before hitting token limits |
| **Utility model** | [Multi-Model Orchestration](#multi-model-orchestration) | Titles, summaries, and internal tasks use a fast, cheap model |
| **Progressive skills** | [Skills System](#skills-system) | Skills show metadata only — full content is fetched on demand |
| **Budget pruning** | [Context Window Management](#context-window-management) | `SummarizePruningStrategy` compresses before dropping messages |
| **Background tools** | [Background Tasks](#background-tasks) | `start_background_tool` executes directly with zero LLM tokens |

## See Also

- [COMMANDS.md](COMMANDS.md) — REPL and CLI command reference
- [ROLES.md](ROLES.md) — Built-in roles and custom role creation
- [CONFIGURATION.md](CONFIGURATION.md) — Configuration file reference
- [API.md](API.md) — HTTP API endpoints
- [TOOLKITS.md](TOOLKITS.md) — Creating toolkit packages
- [SKILLS.md](SKILLS.md) — Skills system and schema
- [BACKGROUND-TASKS.md](BACKGROUND-TASKS.md) — Background task details
