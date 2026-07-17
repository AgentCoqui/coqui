---
title: Roles Reference
description: Built-in role definitions, access levels, role-to-model mapping, custom role creation, and frontmatter schema
---

# Roles Reference

Roles control how Coqui agents behave. Each role defines an access level, a category, available toolkits, iteration budget, and instruction set. The orchestrator delegates work to child agents by spawning them with a specific role.

A role's `category` and `description` drive selection: they are shown grouped in the `/roles` table and in the `/role` listing, and they are surfaced to the model in the `spawn_agent` tool description so an agent can pick the right specialist without opening the file.

The orchestrator is the only role that uses the full orchestrator prompt stack, including `prompts/soul.md`. When you switch the main session to a specialized role with `/role <name>`, Coqui uses that role's markdown instructions instead of layering them on top of the soul. Spawned child agents follow the same rule and use role instructions directly.

## Access Levels

Every role has an `access_level` that determines what the agent can do:

| Level | Filesystem | Shell | Tools | Use Case |
| --- | --- | --- | --- | --- |
| `full` | Read + Write | All allowed commands | All (per toolkit filter) | Implementation, code generation |
| `readonly` | Read only | None | Non-mutating tools | Planning, review, analysis |
| `readonly-shell` | Read only | Read-only commands only (`grep`, `cat`, `head`, `tail`, `wc`, `ls`, `uniq`, `diff`) | Non-mutating tools | Codebase exploration |
| `minimal` | None | None | None (or very restricted) | Single-shot LLM tasks (titles, summaries) |

## Built-in Roles

### orchestrator

The default role. Receives user messages directly, delegates specialized work to child agents via `spawn_agent`, and coordinates multi-step workflows.

| Property | Value |
| --- | --- |
| Category | `system` |
| Access Level | `full` |
| Max Iterations | Global default (configurable via `agents.defaults.maxIterations`) |
| Toolkits | `+*` |

Activate: This is the default role. Switch back with `/role orchestrator` or `/role reset`.

### coder

Expert developer that translates intent into working, tested code. Searches the codebase before building, verifies everything, ships fast, and prefers `php_execute` for ad hoc PHP validation instead of shelling out.

| Property | Value |
| --- | --- |
| Category | `build` |
| Access Level | `full` |
| Max Iterations | `48` |
| Toolkits | All enabled |

Activate: `/role coder` or delegated via `spawn_agent(role: "coder")`.

Behavior note: use `php_execute` for inline PHP experiments, quick calculations, debugging, and snippet validation. Use shell or composer tools for repository-wide commands such as `composer test`, `composer analyse`, Pest, and PHPStan.

### assistant

Automation-focused assistant that breaks down complex tasks into structured plans and delegates to specialist agents. Clarifies ambiguous requests before acting.

| Property | Value |
| --- | --- |
| Category | `general` |
| Access Level | `readonly` |
| Max Iterations | Global default |
| Toolkits | All enabled |

Activate: `/role assistant` or delegated via `spawn_agent(role: "assistant")`.

### plan

Researches the codebase and creates detailed, multi-step implementation plans as versioned artifacts. Works through a `draft` → `review` → `final` artifact lifecycle. Never writes code directly.

| Property | Value |
| --- | --- |
| Category | `plan` |
| Access Level | `readonly` |
| Max Iterations | `30` |
| Toolkits | `+*, -ShellToolkit, -MemoryToolkit, -php_execute` |

Activate: `/role plan` or delegated via `spawn_agent(role: "plan")`.

### reviewer

Strict code evaluator that judges quality, catches hallucinations, and verifies that tests pass. Checks correctness, security, quality, and performance.

| Property | Value |
| --- | --- |
| Category | `review` |
| Access Level | `readonly` |
| Max Iterations | `15` |
| Toolkits | `+*, -MemoryToolkit, -php_execute` |

Activate: `/role reviewer` or delegated via `spawn_agent(role: "reviewer")`.

### explorer

Fast, read-only codebase analyst. Investigates specific areas using filesystem reads and shell search commands (`grep`, `find`, `cat`, etc.) and returns structured findings.

| Property | Value |
| --- | --- |
| Category | `explore` |
| Access Level | `readonly-shell` |
| Max Iterations | `20` |
| Toolkits | `+*, -MemoryToolkit, -spawn_agent, -php_execute` |

Activate: Delegated via `spawn_agent(role: "explorer")`.

### muse

Creative divergent thinking agent for brainstorming, associative exploration, and ideation. Generates many ideas without evaluating them — finds unexpected connections using metaphor, analogy, and lateral thinking. Produces sketch artifacts for rough idea capture. Cannot fail — all creative output is valid.

| Property | Value |
| --- | --- |
| Category | `create` |
| Access Level | `readonly-shell` |
| Max Iterations | `32` |
| Toolkits | `+*, -ShellToolkit, -php_execute` |

Activate: `/role muse` or delegated via `spawn_agent(role: "muse")`. Also used as the divergence stage in the `diverge-converge` and `reflection` loops.

### philosopher

Reflective synthesis agent for examining assumptions, shifting perspectives, and finding meaning. Steps back from execution to ask questions that open new directions. Produces hypothesis artifacts for testable ideas with rationale. Outputs understanding, not action items.

| Property | Value |
| --- | --- |
| Category | `reflect` |
| Access Level | `readonly` |
| Max Iterations | `24` |
| Toolkits | `+*, -ShellToolkit, -php_execute` |

Activate: `/role philosopher` or delegated via `spawn_agent(role: "philosopher")`. Also used as the synthesis stage in the `diverge-converge` and `reflection` loops.

### vision

Single-shot image analyzer. Accepts images from file paths, URLs, or base64 data and returns structured descriptions covering subject, details, and context.

| Property | Value |
| --- | --- |
| Category | `system` |
| Access Level | `minimal` |
| Max Iterations | `5` |
| Toolkits | `-*` (no tools) |

Activate: Called internally by the `vision_analyze` tool. Not typically switched to directly.

### title-generator *(template)*

Generates concise 3-8 word session titles from conversation content. Internal utility role — not shown in role selection.

| Property | Value |
| --- | --- |
| Category | `system` |
| Access Level | `minimal` |
| Max Iterations | `5` |
| Toolkits | `-*` (no tools) |

### identity-curator *(template)*

Autonomous identity-maintenance agent. Reviews recent conversations and curates the persistent memory entries that form an identity scaffold (developmental, relational, and phenomenological context). It is a **template** role — hidden from `/role` and `spawn_agent` — and is meant to be driven by an autonomous maintenance process rather than selected interactively. Its capability is preserved for that use; customize it with `/role edit identity-curator`.

| Property | Value |
| --- | --- |
| Category | `system` |
| Access Level | `full` |
| Max Iterations | `64` |
| Toolkits | All enabled |

## Evaluation and Reflection

`reviewer` is the single evaluative role: it renders pass/fail judgments on code (`VERDICT: APPROVED` / `NEEDS_CHANGES`). `philosopher` is **not** an evaluator — it works in meaning and coherence, not pass/fail, and is the reflection counterpart to `muse`'s divergence. There is intentionally no separate session-grading or learning role; those subsystems were removed.

## Role-to-Model Mapping

By default, all roles use the primary model configured in `openclaw.json`. You can assign specific models to roles for cost optimization:

```json
{
    "agents": {
        "defaults": {
            "model": {
                "primary": "anthropic/claude-sonnet-4-20250514"
            },
            "roles": {
                "coder": "anthropic/claude-sonnet-4-20250514",
                "reviewer": "openai/gpt-4.1",
                "explorer": "ollama/qwen3:8b",
                "vision": "openai/gpt-4.1"
            }
        }
    }
}
```

Resolution priority: role frontmatter `model:` field → `agents.defaults.roles.<name>` in `openclaw.json` → primary model.

## Creating Custom Roles

Custom roles are `.md` files with YAML frontmatter. Place them in your workspace's `roles/` directory (default: `~/.coqui/.workspace/roles/`). They are auto-discovered on startup.

### Frontmatter Schema

```yaml
---
name: my-role
display_name: My Custom Role
description: What this role does
version: 1
access_level: full
max_iterations: 30
toolkits: "+*, -MemoryToolkit"
---
```

| Field | Required | Type | Default | Description |
| --- | --- | --- | --- | --- |
| `name` | Yes | string | | Lowercase, alphanumeric + hyphens |
| `display_name` | Yes | string | | Human-readable name |
| `description` | Yes | string | | One-line description |
| `version` | No | integer | `1` | Version number for update tracking |
| `access_level` | Yes | string | | `full`, `readonly`, `readonly-shell`, or `minimal` |
| `category` | No | string | `general` | Free-form grouping label for selection (e.g. `build`, `plan`, `review`, `explore`, `create`, `reflect`, `system`) |
| `is_builtin` | No | boolean | `false` | Reserved for built-in roles |
| `is_template` | No | boolean | `false` | Hides from role selection UI |
| `ignore_updates` | No | boolean | `false` | Skip built-in update notifications |
| `model` | No | string | | Override model for this role (e.g. `openai/gpt-4.1`) |
| `max_iterations` | No | integer | Global default | Per-role iteration limit. `0` = unlimited |
| `toolkits` | No | string | | Toolkit filter pattern (see below) |

### Toolkit Filter Patterns

The `toolkits` field controls which toolkits and tools are available to the role. Rules are comma-separated, evaluated left-to-right, last match wins.

| Pattern | Meaning |
| --- | --- |
| `+*` | Allow all toolkits by default |
| `-*` | Deny all toolkits by default |
| `+ToolkitName` | Allow a specific toolkit (matches class basename) |
| `-ToolkitName` | Deny a specific toolkit |
| `+tool_name` | Allow a specific tool |
| `-tool_name` | Deny a specific tool |
| `+vendor/package` | Allow by Composer package name |
| `-vendor/package` | Deny by Composer package name |

Examples:
- `"+*"` — allow everything (default behavior)
- `"-*"` — deny everything (for single-shot roles)
- `"+*, -ShellToolkit, -php_execute"` — allow all except shell and PHP execution
- `"-*, +MyToolkit"` — deny all, only allow a specific toolkit

> **Note:** `tool_search` and `credentials` are always enabled regardless of toolkit filters.

### Roles and Internal Coupling

Role files intentionally know very little about Coqui internals. There are three places a role can reference an internal concept, all of them stable, documented surfaces — a custom role never needs to know about implementation classes beyond these:

1. **`toolkits:` filter** — matches a toolkit class basename (`MemoryToolkit`), a Composer package name (`vendor/package`), or a tool name (`php_execute`). The tool-name and package-name forms are the preferred way to scope a role without depending on class names; the class-basename form is supported for convenience. This filter is the single intended coupling point between a role and the toolkit layer (`RoleToolkitResolver`).
2. **Tool names in the body** — instructions refer to capabilities by tool name (`memory_save`, `artifact_create`, `memory_search`), never by class. Keep it that way: tool names are the public contract.
3. **Memory areas and artifact types in the body** — a few roles name memory areas (`identity`, `developmental`, `phenomenological`) or artifact types (`sketch`, `hypothesis`, `plan`). These are conventions, not enforced enums; treat them as suggested vocabulary.

When authoring a custom role, prefer tool names and the `toolkits:` filter over any assumption about internal structure.

### Writing Role Instructions

The markdown body after the frontmatter contains the instructions the LLM receives. Best practices:

1. **Start with identity** — "You are a..." establishes the agent's persona
2. **Define workflows** — numbered steps for how the agent should approach tasks
3. **Set constraints** — explicit rules about what the agent should and should not do
4. **Include examples** — show expected tool usage patterns or output formats

### Role Iteration Budget

Agents are informed of their iteration budget in the system prompt. Each iteration is one LLM call (which may include multiple tool calls). Guidelines:

- `5` — single-shot tasks (title generation, image analysis)
- `15-20` — read-only analysis and review
- `30-48` — complex multi-step implementation
- `0` — unlimited (use with caution; background tasks are always capped at 100)

## Role Update Tracking

Built-in roles are seeded from `config/roles/` to your workspace on first boot. When Coqui updates a built-in role:

- **Unmodified roles** (you haven't edited them) are updated automatically
- **Modified roles** (you've customized the workspace copy) trigger a notification — you choose whether to apply the update
- **Ignored roles** (set via `/roles ignore <name>`) skip update checks entirely

Manage updates with `/roles update [name]`, `/roles ignore <name>`, and `/roles unignore <name>`. Backups are saved to `workspace/backups/roles/` before any update.

## See Also

- [COMMANDS.md](COMMANDS.md) — REPL and CLI command reference
- [FEATURES.md](FEATURES.md) — Complete feature reference
- [CONFIGURATION.md](CONFIGURATION.md) — Configuration file reference
