# Personality Profiles

Coqui supports multiple personality profiles — distinct identities the agent can adopt while sharing the same underlying memories and toolkits.

## Overview

A **profile** is a directory under `profiles/` in the workspace containing a `soul.md` file and optional prompt overrides. When a profile is active:

- The profile's `soul.md` replaces the default soul prompt, shaping the agent's personality, tone, and values.
- Optional `soul.md` frontmatter can define a profile-level default model.
- Optional `backstory.md` provides persistent identity context (origin, milestones, narrative) loaded after soul.md.
- Optional `preferences.json` defines behavioral settings (prompt directives and code-level config).
- Optional prompt overrides (`base.md`, `security.md`, `done.md`, `tools/*.md`) replace or supplement defaults.
- Optional role overrides in `profiles/{name}/roles/*.md` replace workspace role files for that profile only.
- Optional `samples/responses/` directory holds example responses for fidelity verification.
- All child agents spawned during the session also receive the profile's identity preamble.
- Memories saved during a profiled session are tagged with the profile ID. Profile-tagged memories are only visible to that profile; untagged (legacy) memories remain visible to all.
- Each profile switch creates a new session (conversation-scoped identity).

## File Structure

```text
~/.coqui/.workspace/
└── profiles/
    ├── caelum/
    │   ├── soul.md              # Required: core identity prompt
    │   ├── backstory.md         # Optional: persistent identity context
    │   ├── preferences.json     # Optional: behavioral settings
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

### Optional Role Overrides

Profiles can also override role files under `profiles/{name}/roles/`.

Example:

```text
profiles/caelum/roles/coder.md
```

When present, the profile role file takes precedence over the workspace role file of the same name. This lets a profile customize role instructions, toolkits, access level, `max_iterations`, and role-level `model` selection without affecting other profiles.

### backstory.md

Optional persistent identity context loaded after `soul.md` in the system prompt. Use this for origin stories, milestone events, evolving narrative, and continuity details that ground the profile's identity without modifying the core soul.

`backstory.md` can be written manually or **generated automatically** from a `backstory/` source directory. See [Backstory Generator](#backstory-generator) below.

Content is rendered between soul and the memory block in the prompt composition order:

```
soul → backstory → memories → preferences → body → deferred → project
```

Headings in `backstory.md` are downshifted one level (e.g., `#` becomes `##`) to maintain prompt hierarchy.

### preferences.json

Optional behavioral settings file with two sections:

```json
{
  "prompt_directives": {
    "response_style": "concise and measured",
    "formatting": "prefer markdown tables over lists",
    "emotional_range": "warm but not effusive"
  },
  "behavior": {
    "temperature_hint": 0.7
  }
}
```

- **prompt_directives**: Key-value pairs rendered as a `## Preferences` section in the system prompt.
- **behavior**: Code-level settings accessible via `ProfilePreferences::getBehavior()`. These are not rendered into the prompt — they are available for runtime configuration.

### samples/responses/

Optional directory containing example responses as `.md` files. These are discovered by `ProfileDiscovery::listResponseSamples()` and can be used for fidelity verification — checking whether agent output matches the profile's intended voice and style.

Files are sorted alphabetically. Only `.md` files are included.

## Backstory Generator

The backstory generator assembles `backstory.md` automatically from a `backstory/` source directory inside the profile. This lets you maintain backstory content as individual files — organized by topic, timeline, or any structure — and have them combined into a single prompt-ready document.

### Source Directory Layout

```text
profiles/caelum/
├── soul.md
├── backstory.md          ← generated output
├── .backstory-manifest.json  ← change-detection manifest
└── backstory/            ← source files
    ├── 01-origin.md
    ├── 02-milestones.csv
    ├── 03-values.yaml
    ├── personality/
    │   ├── 01-traits.txt
    │   └── 02-quirks.json
    └── timeline.md
```

### Supported File Types

| Extension | Treatment |
| --- | --- |
| `.txt` | Included as plain text with UTF-8, UTF-16, and common legacy encodings normalized to UTF-8 |
| `.md`, `.mdx` | Passed through as-is after text normalization |
| `.json` | Wrapped in a ` ```json ` code fence (validates JSON) |
| `.yaml`, `.yml` | Wrapped in a ` ```yaml ` code fence |
| `.csv`, `.tsv` | Converted to a markdown table |
| `.html`, `.htm` | Sanitized and converted to markdown via `league/html-to-markdown` |
| `.xml` | Rendered as a markdown outline for simple documents, otherwise wrapped in a ` ```xml ` code fence |
| `.rtf` | Converted to plain text with conservative RTF control-word stripping |
| Common code files | Wrapped in fenced code blocks with language hints and never executed |
| `.pdf` | Text extracted via `smalot/pdfparser` |
| `.docx` | Text extracted via `phpoffice/phpword` |

Code file support covers common text-based source extensions such as `.php`, `.js`, `.ts`, `.jsx`, `.tsx`, `.py`, `.rb`, `.java`, `.c`, `.cpp`, `.cs`, `.go`, `.rs`, `.sh`, `.zsh`, `.ps1`, `.sql`, `.css`, `.scss`, `.less`, and similar formats.

HTML, XML, RTF, and code files are always treated as read-only input. Coqui converts them into markdown or fenced text for inclusion in `backstory.md`; it does not execute scripts, formulas, or embedded code while generating the backstory.

### Sort Order

Files are sorted using a **numbered-first natural sort**:

1. Files with numeric prefixes (e.g., `01-intro.txt`) sort first, in natural order
2. Unnumbered files follow alphabetically
3. Files at each directory level appear before subdirectory contents
4. Hidden files and directories (starting with `.`) are skipped

### Change Detection

A `.backstory-manifest.json` file tracks SHA-256 hashes of all source files. Generation is skipped when the content hash matches, making startup fast even with hundreds of source files.

### Auto-Regeneration

At startup, Coqui checks if the active profile's backstory needs regeneration. If source files have changed since the last build, `backstory.md` is regenerated automatically before the first turn.

### REPL Commands

```bash
/backstory              # Show backstory generation status and file summary
/backstory generate     # Force regeneration regardless of change detection
/backstory failed       # Show files that failed extraction with error details
```

The `/prompt` command also includes a backstory summary line when a manifest exists.

## Memory Profile Filtering

When a profile is active, memories are tagged with the profile ID on save. This enables profile-scoped memory:

- **Profile-tagged memories** are only visible when that profile is active.
- **Untagged (legacy) memories** remain visible to all profiles.
- **Search, list, and summary** operations automatically filter by the active profile.
- **No profile active**: all memories are visible (no filtering).

This means each profile builds its own memory layer on top of the shared base.

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
- **Profile-scoped memories**: Memories saved during a profiled session are tagged with that profile. Each profile sees its own memories plus shared (untagged) ones. This prevents one profile's learned patterns from leaking into another's context.
- **Profile ≠ Role**: Profiles affect the soul/identity layer. Roles affect the capability/access layer. Both can be combined.
- **Layered identity files**: soul.md defines who the profile is; backstory.md provides narrative continuity; preferences.json tunes behavior. Keeping these separate lets each evolve independently.

## Creating a Profile

1. Create a directory: `mkdir -p ~/.coqui/.workspace/profiles/my-profile`
2. Write a `soul.md` file defining the personality
3. Optionally add `backstory.md`, `preferences.json`, and `samples/responses/*.md`
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
