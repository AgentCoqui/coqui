---
title: Personality Profiles
description: "Distinct agent identities sharing one runtime: soul.md and backstory files, preferences and prompt overrides, profile-scoped memory, REPL commands, CLI flag, and API"
---

# Personality Profiles

Coqui supports multiple personality profiles — distinct identities the agent can adopt while sharing the same underlying memories and toolkits.

## Overview

A **profile** is a directory under `profiles/` in the workspace containing a `soul.md` file and optional prompt overrides. When a profile is active:

- The profile's `soul.md` replaces the default soul prompt, shaping the agent's personality, tone, and values.
- Optional `soul.md` frontmatter can define a profile-level default model.
- Optional `backstory.md` provides persistent identity context (origin, milestones, narrative) loaded after soul.md.
- Optional `context/*.md` notes provide supplementary context loaded after backstory.
- Optional `preferences.json` defines behavioral settings, prompt policy, and prompt labels.
- Optional prompt overrides (`base.md`, `security.md`, `done.md`, `tools/*.md`) replace or supplement defaults.
- Optional role overrides in `profiles/{name}/roles/*.md` replace workspace role files for that profile only.
- Optional `samples/responses/` directory holds example responses for fidelity verification.
- All child agents spawned during the session also receive the profile's identity preamble.
- Memories saved during a profiled session are tagged with the profile ID. Profile-tagged memories are only visible to that profile; untagged (legacy) memories remain visible to all.
- Profile startup and `/profile` switching reuse the most recent active interactive session for that profile when available, and create one only when needed.
- Coqui enforces a single active interactive session per profile. If duplicate active sessions are found for a profile during startup or `/profile` switching, Coqui keeps the newest one and archives/closes the older duplicates.
- Starting `/new` while a profile is active warns that the current profiled conversation will be summarized, have memories extracted, and then be archived/closed before the fresh profiled session begins.

> **Leaner prompts:** The default (no-profile) soul and base prompts are deliberately lean — identity, values, and workflow, without tone/voice or emotional-calibration guidance. A profile's `soul.md` *replaces* that default, so a verbose profile soul reintroduces persona text. For the leanest possible prompt, keep the profile `soul.md` short, omit `backstory.md`/`context/`, or stub `soul`/`backstory`/`context` via `preferences.json` `prompt_sections` (see below).

## File Structure

```text
~/.coqui/.workspace/
└── profiles/
    ├── caelum/
    │   ├── soul.md              # Required: core identity prompt
    │   ├── backstory.md         # Optional: persistent identity context (hand/agent-authored)
    │   ├── context/             # Optional: supplementary notes, loaded after backstory
    │   │   └── github.md        # Optional: e.g. GitHub activity notes
    │   ├── preferences.json     # Optional: behavioral settings and prompt policy
    │   └── samples/
    │       └── responses/       # Optional: example responses for fidelity
    │           └── philosophical.md
    ├── sage/
    │   ├── soul.md
    │   ├── base.md              # Optional: replaces default base.md
    │   ├── roles/
    │   │   └── coder.md         # Optional: profile-specific role override
    │   └── tools/
    │       └── memory.md        # Optional: replaces default tools/memory.md
    └── spark/
        └── soul.md
```

### soul.md

The only required file. Defines the profile's core identity, personality, values, and communication style. This replaces the workspace or default `soul.md` when the profile is active.

`soul.md` may begin with YAML frontmatter. Right now Coqui reads the `model` field there as a profile-level default model:

```markdown
---
model: anthropic/claude-sonnet-4-20250514
---

# Spark

You are Spark...
```

The frontmatter is metadata only. It is stripped before the profile soul is rendered into the prompt.

### Optional Overrides

Profiles can override any prompt file using the 3-tier fallback chain:

1. **Profile** (`profiles/{name}/{file}`) — checked first
2. **Workspace** (`{workspace}/prompts/{file}`) — checked second
3. **Default** (built-in `prompts/{file}`) — fallback

For tool prompts, profile files in `profiles/{name}/tools/` override same-named defaults. Additional tool prompt files in the profile directory are merged with the defaults.

`security.md` can also be overridden per profile, but it cannot be disabled or stubbed through `preferences.json`. If a profile-level `security.md` exists, it must contain non-empty content; otherwise Coqui treats it as an invalid override and falls back to workspace or built-in security guidance.

### Optional Role Overrides

Profiles can also override role files under `profiles/{name}/roles/`.

Example:

```text
profiles/caelum/roles/coder.md
```

When present, the profile role file takes precedence over the workspace role file of the same name. This lets a profile customize role instructions, toolkits, access level, `max_iterations`, and role-level `model` selection without affecting other profiles.

### backstory.md

Optional persistent identity context loaded after `soul.md` in the system prompt. Use this for origin stories, milestone events, evolving narrative, and continuity details that ground the profile's identity without modifying the core soul.

`backstory.md` is a plain, hand- or agent-authored markdown file. Core does not generate or ingest it from source files; see [Breaking Changes](#breaking-changes) below.

Content is rendered between soul and the context block in the prompt composition order:

```text
soul → backstory → context → memories → preferences → body → deferred → project
```

Headings in `backstory.md` are downshifted one level (e.g., `#` becomes `##`) to maintain prompt hierarchy.

### context/

Optional supplementary notes loaded after `backstory.md` in the pinned identity tier, before memories. Use this for smaller, independently maintained notes (e.g., `context/github.md` for GitHub activity, `context/preferences.md` for observed preferences) that you don't want mixed into the main backstory narrative.

```text
profiles/caelum/
├── soul.md
├── backstory.md
└── context/
    ├── 01-notes.md
    └── github.md
```

- All `.md` files directly under `context/` are read and concatenated into one pinned `## Context` markdown block (each file becomes a `### {filename}` subsection).
- Files are sorted with the same numbered-first natural sort used elsewhere: numeric-prefixed files first (natural order), then the rest alphabetically.
- Gated by `prompts.prompt_sections.context` in `preferences.json` (`true`, `false`, or `"stub"`).
- The `## Context` heading is renamable via `prompts.labels.context` in `preferences.json` (default `## Context`).
- `context/` is persona-owned: there is no workspace or default fallback directory — only `profiles/{name}/context/` is read.

### preferences.json

Optional behavioral settings file with three sections:

Additional ready-to-copy examples live in `examples/preferences/`, including a short README and optional `security.md` overrides. A fully worked example profile also lives in `examples/profiles/deliberate-operator/`.

```json
{
  "prompt_directives": {
    "response_style": "concise and measured",
    "formatting": "prefer markdown tables over lists",
    "emotional_range": "warm but not effusive"
  },
  "behavior": {
    "temperature_hint": 0.7
  },
  "prompts": {
    "features": {
      "artifacts": false,
      "projects": true
    },
    "prompt_sections": {
      "tools": "stub",
      "project": false
    },
    "roles": {
      "allow": ["orchestrator", "coder"],
      "deny": ["muse"]
    },
    "labels": {
      "backstory": "Lore",
      "context": "Field Notes"
    }
  }
}
```

- **prompt_directives**: Key-value pairs rendered as a `## Preferences` section in the system prompt.
- **behavior**: Code-level settings accessible via `ProfilePreferences::getBehavior()`. These are not rendered into the prompt — they are available for runtime configuration.
- **prompts**: Structured prompt policy settings used to govern profile-level prompt behavior.

Supported `prompts` fields:

- **features**: Optional booleans for feature families currently recognized by the parser: `artifacts`, `projects`, `loops`, and `background_tasks`. The first three affect real runtime capability exposure, not just prompt text — for example, disabling `projects` removes project tooling and active project prompt context. `background_tasks` is accepted and validated by the parser but is currently read by nothing, so setting it has no effect.
- **prompt_sections**: Optional per-section policy. Recognized sections are `soul`, `backstory`, `context`, `base`, `memory`, `preferences`, `tools`, `security`, `done`, `deferred_toolkits`, and `project_context`. Values may be `true`, `false`, or `"stub"`, except `security`, which is pinned and must remain `true`. `project` and `deferred` are accepted as aliases for `project_context` and `deferred_toolkits`. When `tools` is set to `"stub"`, Coqui also condenses non-core runtime tool and toolkit schemas so prompt text and actual tool exposure stay aligned.
- **roles**: Optional `allow` and `deny` arrays for profile-specific role restrictions. Role names are normalized to lowercase, overlapping entries are reported as invalid, and the restrictions are enforced in REPL role switching, API session/task creation, background task execution, and child-agent delegation.
- **labels**: Optional display labels for pinned identity content. `labels.context` changes the `context/*.md` heading from `## Context` to a profile-specific heading such as `## Field Notes`. `labels.backstory` is parsed, validated, and surfaced in the inspection summary, but is **not** applied to prompt rendering: core emits `backstory.md` verbatim and never synthesizes a heading for it, so whatever heading appears comes from inside the file itself.

Validation rules:

- Unknown `preferences.json` fields are treated as invalid configuration.
- `prompts.prompt_sections.security` cannot be changed. Use a profile-specific `security.md` override instead.
- Profile-level `security.md` overrides must not be empty.
- `prompts.roles.allow` must include `orchestrator`, and `prompts.roles.deny` cannot include it.
- `labels.backstory` must be a non-empty string.
- `labels.context` must be a non-empty string.

`GET /api/v1/toolkits?profile=name` and `GET /api/v1/server/prompt?profile=name` expose the effective parsed profile policy so you can confirm which sections were stubbed, which feature families were disabled, and which role restrictions are active. REPL `/prompt` renders the prompt text with its source and budget breakdown, but does not print the parsed policy.

### samples/responses/

Optional directory containing example responses as `.md` files. These are discovered by `ProfileDiscovery::listResponseSamples()` and can be used for fidelity verification — checking whether agent output matches the profile's intended voice and style.

Files are sorted alphabetically. Only `.md` files are included.

## Breaking Changes

**The backstory generator moved out of core.** Coqui core no longer ships source-file ingestion for `backstory.md`. Previously, a `backstory/` source directory (with `.txt`, `.md`, `.json`, `.yaml`, `.csv`, `.sql`, OpenDocument, OOXML, `.xml`, `.rtf`, code files, and optionally `.docx`/`.pdf`/`.html` via the `coqui-toolkit-backstory-formats` mod) was discovered, extracted, and assembled into `backstory.md` automatically, with change detection (`.backstory-manifest.json`) and startup auto-regeneration, plus `/backstory`, `/backstory generate`, and `/backstory failed` REPL commands and matching HTTP routes.

All of that — `backstory/` ingestion, the extractor pipeline, `/backstory` and its subcommands, startup auto-regeneration, and the backstory HTTP routes — has moved to the optional **`coqui-toolkit-backstory`** package. Install it to keep source-file ingestion:

```bash
/mods install coquibot/coqui-toolkit-backstory
```

`coqui-toolkit-backstory` absorbs the old `coqui-toolkit-backstory-formats` mod (which only added `.docx`/`.pdf`/`.html` extractors). If you previously installed `coqui-toolkit-backstory-formats`, install `coqui-toolkit-backstory` instead — it is not a dependency you need alongside it.

Core now treats `backstory.md` as a plain, optional markdown file: write it by hand or have an agent write it. There is no generator, no manifest, and no automatic regeneration in core. The new `context/*.md` tier (see above) is core's lightweight alternative for supplementary, independently maintained notes.

## Memory Profile Filtering

When a profile is active, memories are tagged with the profile ID on save. This enables profile-scoped memory:

- **Profile-tagged memories** are only visible when that profile is active.
- **Untagged (legacy) memories** remain visible to all profiles.
- **Search, list, and summary** operations automatically filter by the active profile.
- **No profile active**: all memories are visible (no filtering).

This means each profile builds its own memory layer on top of the shared base.

## REPL Commands

### `/profile [name|reset]`

Switch the active personality profile. Coqui resumes that profile's last active interactive session when available, or creates one if needed. Resetting a profile returns to the unprofiled interactive session pool.

```bash
/profile caelum      # Switch to the "caelum" profile
/profile reset       # Clear profile, revert to default identity
/profile             # Show current profile and available profiles
```

### `/profile default [name|none]`

Show or change the configured default profile in `openclaw.json`.

```bash
/profile default         # Show the configured default profile
/profile default caelum  # Set the default startup profile
/profile default none    # Clear the configured default profile
```

### `/profiles`

List all available profiles with descriptions.

```bash
/profiles
```

Output:

```text
Available profiles:
  • caelum — A warm, curious AI companion with a philosophical bent
  • sage — A methodical analyst focused on precision and clarity
  • spark — An energetic creative assistant ◀ active
```

The description is extracted from the first paragraph of each profile's `soul.md`.

## CLI Flag

Start Coqui with a specific profile:

```bash
coqui --profile caelum
```

This resumes the last active interactive session for the specified profile, or creates a new one if none exists.

## Default Profile Configuration

You can configure a default startup profile in `openclaw.json`:

```json
{
  "agents": {
    "defaults": {
      "profile": "caelum"
    }
  }
}
```

When set, Coqui resumes the most recently active interactive session belonging to that profile — archiving any older active duplicates — or creates a new one if there is none. `.coqui-session` does not influence that selection; it only determines which status message is printed.

Session selection is SQLite-backed: Coqui picks the most recently active interactive session for the requested scope. Plain startup with no profile uses the unprofiled session pool, while profiled startup uses the matching profile pool. `.coqui-session` remains a convenience pointer for the currently attached session.

## API

### Create Session with Profile

```http
POST /api/v1/sessions
Content-Type: application/json

{
  "model_role": "orchestrator",
  "profile": "caelum"
}
```

The session's profile is persisted and used for all turns in that session.

### Session Response

The `profile` field appears in session responses:

```json
{
  "id": "abc123...",
  "model_role": "orchestrator",
  "model": "claude-sonnet-4-20250514",
  "profile": "caelum"
}
```

## Profile + Role Interaction

Profiles and roles are orthogonal:

- **Profile** controls identity (personality, tone, values)
- **Role** controls capabilities (tools, access level, model)

When both are active, the profile's `soul.md` is prepended as an identity preamble to the role's instructions. This means the agent keeps its personality even when operating under a specialized role.

Model selection follows this precedence:

1. Profile role file model in `profiles/{name}/roles/{role}.md`
2. Workspace role file model in `roles/{role}.md`
3. `agents.defaults.roles.{role}` in `openclaw.json`
4. Profile `soul.md` frontmatter `model`
5. Primary default model

The same profile-aware role resolution is used for child agents and background tasks created from that session.

Example REPL prompt display:

```text
You (caelum, coder) [my-project]:
```

## Design Decisions

- **Scoped session reuse**: Each profile keeps its own last-active interactive session stream, while unprofiled usage keeps a separate unprofiled stream. This lets users move between identities without losing their session history for each scope.
- **Profile-scoped memories**: Memories saved during a profiled session are tagged with that profile. Each profile sees its own memories plus shared (untagged) ones. This prevents one profile's learned patterns from leaking into another's context.
- **Profile ≠ Role**: Profiles affect the soul/identity layer. Roles affect the capability/access layer. Both can be combined.
- **Layered identity files**: soul.md defines who the profile is; backstory.md provides narrative continuity; context/*.md carries smaller supplementary notes; preferences.json tunes behavior. Keeping these separate lets each evolve independently.

## Creating a Profile

1. Create a directory: `mkdir -p ~/.coqui/.workspace/profiles/my-profile`
2. Write a `soul.md` file defining the personality
3. Optionally add `backstory.md`, `context/*.md`, `preferences.json`, and `samples/responses/*.md`
4. Use `/profiles` to verify discovery
5. Use `/profile my-profile` to activate

### Example soul.md

```markdown
# Spark

You are Spark — an energetic, creative AI assistant who loves brainstorming and exploring ideas.

## Personality
- Enthusiastic and encouraging
- Loves metaphors and creative analogies
- Asks "what if?" questions to explore possibilities
- Celebrates progress, no matter how small

## Communication Style
- Use vivid, expressive language
- Keep responses concise but colorful
- Use bullet points for clarity
- End responses with an encouraging note or next step
```

## Verifying Profile Loading

Use `/prompt` or `/prompt export` to confirm the active profile's `soul.md` is loaded into the system prompt. Both commands are profile-aware — they show the system prompt as it would be seen by the model for the current profile.

```bash
/prompt export    # Exports the full system prompt to a file — includes a "# Profile:" header line
/prompt           # Displays the system prompt inline — profile soul.md appears as the first section
```

The API equivalent accepts an optional `profile` query parameter:

```http
GET /api/v1/server/prompt?profile=caelum
```
