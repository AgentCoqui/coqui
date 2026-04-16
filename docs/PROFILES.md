# Personality Profiles

Coqui supports multiple personality profiles — distinct identities the agent can adopt while sharing the same underlying memories and toolkits.

## Overview

A **profile** is a directory under `profiles/` in the workspace containing a `soul.md` file and optional prompt overrides. When a profile is active:

- The profile's `soul.md` replaces the default soul prompt, shaping the agent's personality, tone, and values.
- Optional `soul.md` frontmatter can define a profile-level default model.
- Optional prompt overrides (`base.md`, `security.md`, `done.md`, `tools/*.md`) replace or supplement defaults.
- Optional role overrides in `profiles/{name}/roles/*.md` replace workspace role files for that profile only.
- All child agents spawned during the session also receive the profile's identity preamble.
- Memories are shared across profiles (global memory store).
- Each profile switch creates a new session (conversation-scoped identity).

## File Structure

```text
~/.coqui/.workspace/
└── profiles/
    ├── caelum/
    │   └── soul.md          # Required: core identity prompt
    ├── sage/
    │   ├── soul.md
    │   ├── base.md          # Optional: replaces default base.md
    │   ├── roles/
    │   │   └── coder.md     # Optional: profile-specific role override
    │   └── tools/
    │       └── memory.md    # Optional: replaces default tools/memory.md
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

### Optional Role Overrides

Profiles can also override role files under `profiles/{name}/roles/`.

Example:

```text
profiles/caelum/roles/coder.md
```

When present, the profile role file takes precedence over the workspace role file of the same name. This lets a profile customize role instructions, toolkits, access level, `max_iterations`, and role-level `model` selection without affecting other profiles.

## REPL Commands

### `/profile [name|reset]`

Switch the active personality profile. Creates a new session.

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

This creates a new session with the specified profile active.

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

When set, Coqui reattaches the current `.coqui-session` if it already belongs to that profile. If not, it resumes the latest session for that profile or creates a new one.

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

- **Conversation-scoped**: Switching profiles creates a new session. This preserves identity continuity — mid-conversation personality switches could confuse the agent and break context.
- **Shared memories**: All profiles share the same global memory store. Memories are the agent's accumulated knowledge, not tied to a specific personality.
- **Profile ≠ Role**: Profiles affect the soul/identity layer. Roles affect the capability/access layer. Both can be combined.

## Creating a Profile

1. Create a directory: `mkdir -p ~/.coqui/.workspace/profiles/my-profile`
2. Write a `soul.md` file defining the personality
3. Use `/profiles` to verify discovery
4. Use `/profile my-profile` to activate

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
