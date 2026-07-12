# Data Flow

How Projects, Artifacts, and Loops work together to manage complex workflows in Coqui.

```mermaid
erDiagram
    Project ||--o{ Artifact : "scopes"
    Project ||--o{ Loop : "auto-created by"
    Artifact ||--o{ ArtifactVersion : "versioned"
    Loop ||--o{ LoopIteration : "repeats"
    LoopIteration ||--o{ LoopStage : "role sequence"
    Session ||--o{ Artifact : "owns"
    Session ||--o{ Loop : "linked to"

    Project {
        text id PK
        text slug UK
        text title
        text status "active | completed | archived"
        text directory "workspace/projects/slug-hash/"
    }
    Artifact {
        text id PK
        text session_id FK
        text project_id FK
        text type "code | document | config | plan | data | other"
        text stage "draft → review → final"
        int version
        bool persistent
    }
    ArtifactVersion {
        text id PK
        text artifact_id FK
        int version
        text content
    }
    Loop {
        text id PK
        text session_id FK
        text project_id FK
        text definition_name
        text goal
        text status "running | paused | completed | failed | cancelled"
    }
    LoopIteration {
        text id PK
        text loop_id FK
        int iteration_number
        text status
    }
    LoopStage {
        text id PK
        text iteration_id FK
        int stage_index
        text role
        text status
    }
    Session {
        text id PK
        text active_project_id FK
    }
```

## Component Overview

| Component | Purpose | Scope | Storage |
| --- | --- | --- | --- |
| [Projects](PROJECTS.md) | Named working scope backed by a workspace directory | Persistent | `projects` table |
| [Artifacts](ARTIFACTS.md) | Structured outputs (plans, docs, code) — files on disk for filesystem-backed types, indexed in the DB | Session or persistent | `artifacts` table + canonical files under `projects/{dir}/artifacts/` |
| [Loops](LOOPS.md) | Automated multi-role iteration cycles | Session-linked | `loops` + `loop_iterations` + `loop_stages` tables |

All tables reside in a shared SQLite database (WAL mode) managed by `SessionStorage`.

## Data Relationships

```
Project (active | completed | archived)
│   working directory: workspace/projects/slug-hash/
├── Artifact: plan (draft → review → final)
├── Artifact: loop_output (from loop stage)
└── Loop (harness: plan → coder → reviewer)
    ├── Iteration 1
    │   ├── Stage: plan
    │   ├── Stage: coder
    │   └── Stage: reviewer → "NEEDS CHANGES"
    └── Iteration 2
        ├── Stage: plan
        ├── Stage: coder
        └── Stage: reviewer → "APPROVED" (loop completes)
```

### Foreign Key Map

| From | Column | To | Cascade |
| --- | --- | --- | --- |
| `artifacts` | `session_id` | `sessions.id` | — |
| `artifacts` | `project_id` | `projects.id` | — |
| `loops` | `session_id` | `sessions.id` | — |
| `loops` | `project_id` | `projects.id` | — |
| `loop_iterations` | `loop_id` | `loops.id` | CASCADE delete |
| `loop_stages` | `iteration_id` | `loop_iterations.id` | CASCADE delete |

## Common Workflow Patterns

### 1. Plan-Implement-Review (Manual)

The most common pattern for structured development:

```
User → /role plan → "Build a caching layer"
                ↓
    Plan agent: artifact_create(type: "plan")
                ↓ discovery, design, iteration
    Plan agent: artifact_stage("review")
                ↓ user approves
    Plan agent: artifact_stage("final")
                ↓
User → /role coder
                ↓
    Coder agent: reads the finalized plan artifact
    Coder agent: implements each step
                ↓
User → /role reviewer (or spawn_agent)
                ↓
    Reviewer: reads artifacts, provides feedback
```

### 2. Automated Harness Loop

Fully automated plan-implement-review cycles:

```
Agent: loop_start(definition: "harness", goal: "Build caching layer")
                ↓
    Iteration 1:
        plan agent → creates implementation plan
        coder agent → implements the plan
        reviewer agent → evaluates, responds "NEEDS CHANGES"
                ↓ (termination not met, continue)
    Iteration 2:
        plan agent → reviews feedback, adjusts plan
        coder agent → makes revisions
        reviewer agent → responds "APPROVED"
                ↓ (termination met, loop completes)
```

### 3. Project-Scoped Workflow

For work spanning multiple sessions, scope it to a project so artifacts persist and share a working directory:

```
Plan agent: project_create(title: "Auth System", slug: "auth")
                ↓ project becomes active, workspace dir created
Plan agent: artifact_create(type: "plan", project_id)
                ↓ iterates on plan
Plan agent: artifact_stage("final")
                ↓
Coder agent: reads the finalized plan artifact, implements
                ↓
Reviewer agent: reviews artifacts, provides feedback
                ↓ when work is done
project_update(id, status: "completed")
```

## Session Propagation

All components share data through **session ID propagation**. When one agent spawns another, the parent's session ID flows to the child so all agents in a workflow see the same artifacts and project context.

| Spawn Method | Session Propagation |
| --- | --- |
| `spawn_agent` | Parent's `sessionId` → child's `ArtifactToolkit`, `ProjectToolkit` |
| `loop_start` | Orchestrator's `sessionId` → `loops.session_id` → `LoopStageResult.sessionId` → stage agent toolkits |
| Background task (`POST /api/v1/tasks`, loops, schedules) | `parent_session_id` → new child task session (separate session, but can read parent artifacts) |
| `/role switch` | Same session throughout |

## Active Project Context

When a project is active (set via `/projects <slug>` or `project_switch`), its metadata and working directory are injected into the system prompt. This gives all agents ambient awareness of the current project without explicit tool calls.

See [PROJECTS.md](PROJECTS.md#active-project-context) for details.

## Cleanup Lifecycle

| Component | Cleanup Trigger | Behavior |
| --- | --- | --- |
| Session-scoped artifacts | Session deletion | Deleted with session (non-persistent only) |
| Persistent artifacts | Manual deletion | Survive session deletion when `project_id` is set |
| Loops | Manual or loop completion | Loop records persist; stages cascade with iterations |

## Further Reading

- [PROJECTS.md](PROJECTS.md) — Projects as working scopes: creation and lifecycle
- [ARTIFACTS.md](ARTIFACTS.md) — Versioned artifacts: staging, persistence, and plan handoff
- [LOOPS.md](LOOPS.md) — Automated workflows: definitions, termination, and session context
- [AGENTS.md](../AGENTS.md) — Full architecture reference for all systems
