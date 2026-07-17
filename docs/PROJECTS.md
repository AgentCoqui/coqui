---
title: Projects
description: "Cross-session working scopes: creation, lifecycle status, active project context injection, workspace directories, agent tools, REPL commands, and cleanup"
---

# Projects

Lightweight, cross-session working scopes for organizing development work.

## Overview

A **project** is a named working scope backed by a workspace directory. It groups related work under a slug and an auto-generated directory at `workspace/projects/<slug>-<id>/`. Projects persist across sessions.

When a project is **active**, its context is injected into the system prompt so all agents know what they're working on and where their files live.

## Creating a Project

### Via Agent Tool

```
project_create(title: "Auth System", slug: "auth", description: "User authentication and authorization")
```

This creates a project record and an auto-generated workspace directory at `workspace/projects/auth-{id}/`.

### Via REPL

Projects are created through agent tools only — there is no `/projects create` REPL command. Use the agent or the plan role.

## Project Lifecycle

| Status | Description |
| --- | --- |
| `active` | Work in progress |
| `completed` | Work finished |
| `archived` | Retained for reference |

Update via `project_update(id, status: "completed")`.

## Active Project Context

Set the active project with `/projects auth` or `project_switch(slug: "auth")`. Clear with `/projects clear` or `project_switch(slug: "clear")`.

When active, the system prompt gains an `# ACTIVE PROJECT` section containing:

- Project name, slug, status, and description
- Workspace directory path

This gives agents ambient awareness without requiring explicit lookups. In the REPL, the active project is shown in the visible `You [project]` label before each input cycle.

Active project state is per-session and restores automatically when resuming a session.

## Agent Tools

The `ProjectToolkit` provides the project tool surface:

| Tool | Description |
| --- | --- |
| `project_create` | Create a project with title, slug, description |
| `project_list` | List projects with optional status filter |
| `project_get` | Get project details |
| `project_update` | Update title, description, or status |
| `project_delete` | Delete one project or all projects; clears active-project session references |
| `project_switch` | Switch or clear the active project |

`project_switch` is only available when the agent has a session context (orchestrator and background tasks).

## REPL Commands

| Command | Description |
| --- | --- |
| `/projects` | List all projects (active project marked with `●`) |
| `/projects active` | Filter to active projects |
| `/projects completed` | Filter to completed projects |
| `/projects <slug>` | Set as active project |
| `/projects clear` | Unset active project |

## Cleanup

Projects can be removed directly from the agent tool surface:

```
project_delete(id: "auth")
project_delete(id: "all")
project_delete(id: "auth", delete_directory: true)
```

- `project_delete` always removes the database record and clears any session `active_project_id` references pointing at that project
- `delete_directory: true` additionally removes `workspace/projects/<directory>` for the project
- Project directory deletion is optional and intended for full cleanup workflows
- Deleting a project does not automatically delete related artifacts; find them with `artifact_list(project_id: ...)` and remove each with `artifact_delete(id: ...)` — there is no bulk delete-by-project

## Recommended Workflow

For substantial tasks (multi-file features, new systems):

1. **Create a project** — `project_create(title, slug)`
2. **Switch to it** — `/projects <slug>` (injects context into prompts)
3. **Create a plan artifact** — `artifact_create(type: "plan")` with the plan role; the active project's link is inherited, so there is no `project_id` argument
4. **Implement** — switch to coder role and work against the project's directory
5. **Finalize** — mark the project `completed` via `project_update(id, status: "completed")` when done

## Related

- [DATA_FLOW.md](DATA_FLOW.md) — How all components connect
- [ARTIFACTS.md](ARTIFACTS.md) — Artifacts as files: retention and plan handoff
