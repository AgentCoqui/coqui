# Coqui Skills

A skill is a reusable set of instructions that teaches Coqui how to handle specific tasks. Skills are plain Markdown files with structured metadata — no code required. When a user's request matches a skill, the agent loads the instructions and follows them.

This guide covers everything you need to create, organize, and use skills effectively.

## Table of Contents

- [What Is a Skill?](#what-is-a-skill)
- [Quick Start](#quick-start)
- [The SKILL.md Format](#the-skillmd-format)
- [Frontmatter Reference](#frontmatter-reference)
- [Body Content](#body-content)
- [Creating Skills](#creating-skills)
- [Optional Directories](#optional-directories)
- [Progressive Disclosure](#progressive-disclosure)
- [Naming Rules](#naming-rules)
- [Validation](#validation)
- [Skill Tools](#skill-tools)
- [How Discovery Works](#how-discovery-works)
- [Best Practices](#best-practices)
- [Examples](#examples)
- [Troubleshooting](#troubleshooting)

## What Is a Skill?

A skill is a directory inside `workspace/skills/` containing a `SKILL.md` file. The file has two parts:

1. **YAML frontmatter** — structured metadata (name, description, license, etc.)
2. **Markdown body** — detailed instructions the agent follows when the skill is activated

Skills implement the [AgentSkills specification](https://agentskills.io/specification), an open standard for teaching AI agents reusable behaviors.

```
┌─────────────────────────────────────────────────────┐
│                   How Skills Work                   │
│                                                     │
│  1. Boot: scan workspace/skills/*/SKILL.md          │
│  2. Load frontmatter only (name + description)      │
│  3. Inject skill summaries into system prompt       │
│  4. User asks a question                            │
│  5. Agent matches request → skill description       │
│  6. Agent calls skill_read("skill-name")            │
│  7. Full instructions load into context             │
│  8. Agent follows the instructions                  │
└─────────────────────────────────────────────────────┘
```

**Skills vs. Toolkits:** Toolkits are PHP code that adds executable tools (API calls, file operations, computations). Skills are instructions that guide the agent's behavior — they don't execute code, they shape how the agent thinks and responds.

| | Skills | Toolkits |
|---|---|---|
| Format | Markdown (SKILL.md) | PHP (ToolkitInterface) |
| Purpose | Behavior and instructions | Executable capabilities |
| Requires code? | No | Yes |
| Installed via | Copy to `workspace/skills/` | `composer require` |
| Runs code? | No (prompt-only) | Yes (tool callbacks) |

## Quick Start

### Using the Built-in Tool

Ask Coqui to create a skill:

```
You: Create a skill for reviewing PHP code with a focus on type safety

Coqui: skill_create(
    name: "php-code-review",
    description: "Review PHP code for type safety issues, missing type declarations, and incorrect use of mixed/null types. Use when asked to review PHP code or check types.",
    instructions: "# PHP Code Review\n\nFocus on type safety..."
)
```

The skill is immediately available — no restart needed.

### Manual Creation

1. Create a directory in `workspace/skills/`:

```bash
mkdir -p workspace/skills/code-review
```

2. Create `SKILL.md`:

```markdown
---
name: code-review
description: Review code for quality, readability, and potential bugs. Use when asked to review, audit, or critique code.
---

# Code Review

When reviewing code, follow this checklist:

## Structure
- Single responsibility per function/class
- Appropriate abstraction level
- No dead code or unused imports

## Quality
- Clear naming (variables, functions, classes)
- Consistent formatting
- Adequate error handling

## Security
- Input validation on all external data
- No hardcoded secrets
- Parameterized queries for database access

## Output Format
Present findings as a numbered list, grouped by severity:
1. **Critical** — bugs, security issues
2. **Warning** — code smells, potential problems
3. **Suggestion** — style improvements, readability
```

3. Restart Coqui or create the skill while Coqui is running (the `skill_create` tool invalidates the cache automatically).

## The SKILL.md Format

Every `SKILL.md` file has two sections separated by `---` delimiters:

```markdown
---
name: my-skill
description: What this skill does and when to use it.
---

# Instructions

The markdown body with detailed instructions...
```

The frontmatter uses YAML syntax. The body uses Markdown. Both are required (though the body can be minimal).

### Minimal Example

```markdown
---
name: commit-messages
description: Write clear, conventional commit messages. Use when asked to write or improve a commit message.
---

# Commit Messages

Write commit messages in imperative mood with a 72-character subject line.

Format: `<type>(<scope>): <description>`

Types: feat, fix, docs, style, refactor, test, chore
```

### Full Example

```markdown
---
name: pdf-processing
description: Extract text and tables from PDF files, fill forms, and merge documents. Use when working with PDF documents.
license: Apache-2.0
compatibility: Requires poppler-utils (pdftotext, pdfinfo)
metadata:
  author: example-org
  version: "2.1"
allowed-tools: Bash(pdftotext:*) Bash(pdfinfo:*) Read
---

# PDF Processing

Step-by-step instructions for handling PDF tasks...
```

## Frontmatter Reference

| Field | Required | Type | Max Length | Description |
|---|---|---|---|---|
| `name` | Yes | string | 64 | Kebab-case identifier. Must match the directory name. |
| `description` | Yes | string | 1024 | What the skill does and when to use it. |
| `license` | No | string | — | License name or reference to a bundled LICENSE file. |
| `compatibility` | No | string | 500 | Environment requirements (tools, packages, network). |
| `metadata` | No | map | — | Arbitrary key-value pairs (author, version, tags). |
| `allowed-tools` | No | string | — | Space-delimited list of pre-approved tools. Experimental. |

### `name`

The skill's unique identifier. This is how the agent references the skill in tool calls.

```yaml
name: data-analysis
```

Rules:
- 1–64 characters
- Lowercase alphanumeric and hyphens only (`a-z`, `0-9`, `-`)
- Must not start or end with a hyphen
- Must not contain consecutive hyphens (`--`)
- Must exactly match the parent directory name

```
✅ name: code-review        (directory: code-review/)
✅ name: pdf-processing     (directory: pdf-processing/)
✅ name: my-tool-v2         (directory: my-tool-v2/)

❌ name: Code-Review        (uppercase)
❌ name: -code-review       (leading hyphen)
❌ name: code--review       (consecutive hyphens)
❌ name: code_review        (underscores)
❌ name: review             (directory: code-review/) ← mismatch
```

### `description`

The most important field. This is what the agent reads at startup to decide whether a skill matches a user's request. Write it for the agent, not for humans.

```yaml
# Good — specific, includes trigger keywords
description: Extract text and tables from PDF files, fill PDF forms, and merge multiple PDFs. Use when working with PDF documents or when the user mentions PDFs, forms, or document extraction.

# Bad — too vague for the agent to match
description: Helps with documents.
```

Tips:
- Start with what the skill does (the capability)
- End with when to use it (the trigger)
- Include keywords the user might say
- 1–1024 characters

### `license`

Optional. Specify the license for your skill, especially if you plan to share it.

```yaml
license: MIT
license: Apache-2.0
license: Proprietary. See LICENSE.txt for terms.
```

### `compatibility`

Optional. Only include this if your skill has genuine environment requirements. Most skills don't need it.

```yaml
compatibility: Requires git and docker
compatibility: Designed for Coqui with PHP 8.4+
compatibility: Needs network access for API calls
```

1–500 characters when provided.

### `metadata`

Optional. A flat key-value map for additional properties. Values must be strings (quote numbers).

```yaml
metadata:
  author: carmelo
  version: "1.0"
  category: development
```

Use reasonably unique key names to avoid conflicts if skills are shared.

### `allowed-tools`

Optional and experimental. A space-delimited list of tools the skill is pre-approved to use without confirmation. Support varies by implementation — Coqui currently stores this field but does not enforce it.

```yaml
allowed-tools: Bash(git:*) Read Write
```

## Body Content

The markdown body after the frontmatter is where the real instructions live. When the agent activates a skill via `skill_read`, this entire body is loaded into context.

There are no format restrictions. Write whatever helps the agent perform the task effectively.

### Recommended Sections

```markdown
---
name: api-integration
description: ...
---

# API Integration

Brief overview of what this skill covers.

## When to Use

Specific triggers and scenarios.

## Step-by-Step

1. First, check if...
2. Then, do...
3. Finally, verify...

## Examples

### Input
User asks: "Connect to the weather API"

### Expected Behavior
1. Check for API credentials
2. Make a test request
3. Parse the response
4. Present the data in a readable format

## Edge Cases

- If the API returns a 429, wait and retry
- If credentials are missing, ask the user

## Output Format

Present results as a markdown table with columns: ...
```

### What Makes Good Instructions

- **Be specific.** Vague instructions produce vague results.
- **Include examples.** Show the agent what good output looks like.
- **Cover edge cases.** Anticipate what can go wrong.
- **Define output format.** Tell the agent exactly how to structure responses.
- **Use imperative voice.** "Do X" is clearer than "You should consider X."

## Creating Skills

### With skill_create (Recommended)

The `skill_create` tool validates the name, creates the directory, writes the `SKILL.md`, and invalidates the discovery cache so the skill is immediately available.

```
skill_create(
    name: "git-workflow",
    description: "Manage git branches, commits, and PRs following conventional patterns. Use when asked about git workflow, branching, or commit conventions.",
    instructions: "# Git Workflow\n\n## Branch Naming\n\n...",
    license: "MIT"
)
```

### Manually

Create the directory and file yourself:

```bash
mkdir -p workspace/skills/git-workflow
cat > workspace/skills/git-workflow/SKILL.md << 'EOF'
---
name: git-workflow
description: Manage git branches, commits, and PRs following conventional patterns.
license: MIT
---

# Git Workflow

Instructions here...
EOF
```

Restart Coqui to trigger discovery, or use the `skill_list` tool after a restart.

### From a Template

Copy the example skill from the Coqui source:

```bash
cp -r examples/say-hello workspace/skills/say-hello
```

Edit the `SKILL.md` to customize it for your use case.

## Optional Directories

Skills can include additional files beyond `SKILL.md`:

```
my-skill/
├── SKILL.md            # Required — frontmatter + instructions
├── scripts/            # Executable code the agent can run
│   ├── extract.py
│   └── validate.sh
├── references/         # Additional documentation loaded on demand
│   ├── REFERENCE.md
│   └── api-spec.md
└── assets/             # Static resources
    ├── template.json
    └── schema.yaml
```

### scripts/

Executable code that supports the skill. Scripts should:
- Be self-contained or clearly document their dependencies
- Include helpful error messages
- Handle edge cases gracefully

Reference scripts from the body using relative paths:

```markdown
Run the extraction script:
scripts/extract.py --input document.pdf --output text.txt
```

### references/

Additional documentation the agent can read when needed. Keep individual reference files focused and small — the agent loads these on demand, so smaller files mean less context usage.

```markdown
See [the API reference](references/api-spec.md) for endpoint details.
```

### assets/

Static resources: templates, schemas, sample data, images. Reference them with relative paths from the skill root.

## Progressive Disclosure

Skills use a three-tier loading strategy to minimize context usage:

| Tier | What Loads | When | Token Cost |
|---|---|---|---|
| **Metadata** | `name` + `description` | Boot (every skill) | ~100 tokens per skill |
| **Instructions** | Full SKILL.md body | `skill_read` activation | < 5000 tokens recommended |
| **Resources** | scripts/, references/, assets/ | Explicitly referenced | Varies |

This means:
- **At startup**, Coqui only reads the frontmatter of each skill. Ten installed skills cost roughly 1,000 tokens of context.
- **When activated**, the full body loads. Keep it under 500 lines (~5000 tokens).
- **Supporting files** load only when the instructions reference them and the agent decides to read them.

Design your skills with this in mind. Put the essential instructions in the SKILL.md body. Move detailed reference material, large examples, and lookup tables into `references/` or `assets/`.

## Naming Rules

Skill names follow strict rules to ensure consistency and prevent conflicts:

| Rule | Example |
|---|---|
| Lowercase only | `code-review` not `Code-Review` |
| Alphanumeric + hyphens | `my-skill-v2` not `my_skill_v2` |
| No leading hyphen | `review` not `-review` |
| No trailing hyphen | `review` not `review-` |
| No consecutive hyphens | `code-review` not `code--review` |
| Max 64 characters | Keep it concise |
| Matches directory name | `skills/code-review/` → `name: code-review` |

## Validation

Coqui validates skills at multiple points:

1. **Discovery time** — `SkillParser` checks frontmatter structure and required fields. Invalid skills are silently skipped (they don't break boot).
2. **Creation time** — `skill_create` validates the name format and field lengths before writing.
3. **Manual validation** — Use `SkillParser::validate()` to check a skill directory against the spec.

### Validation Checks

| Check | Rule |
|---|---|
| SKILL.md exists | Must be present as `SKILL.md` or `skill.md` |
| Frontmatter present | Must start with `---` and have a closing `---` |
| `name` present | Non-empty string |
| `name` format | Lowercase, alphanumeric + hyphens, no leading/trailing/consecutive hyphens |
| `name` length | ≤ 64 characters |
| `name` matches directory | Directory name must equal the `name` value |
| `description` present | Non-empty string |
| `description` length | ≤ 1024 characters |
| `compatibility` length | ≤ 500 characters (if provided) |
| No unknown fields | Only `name`, `description`, `license`, `compatibility`, `metadata`, `allowed-tools` |

### What Happens on Invalid Skills

- During **boot discovery**, invalid skills are silently skipped. This prevents a single malformed `SKILL.md` from breaking the entire skill system.
- During **`skill_create`**, validation errors are returned as `ToolResult::error()` with specific messages. The directory is not created.
- `SkillParser::validate()` returns an array of error strings — empty means valid.

## Skill Tools

Coqui provides three built-in tools for skill management:

### skill_list

Lists all discovered skills with their names, descriptions, and paths.

```
skill_list()
```

Returns a formatted list or "No skills installed." if the skills directory is empty.

### skill_read

Activates a skill by loading its full markdown body into the agent's context. This is the primary mechanism for using skills.

```
skill_read(name: "code-review")
```

The agent calls this when it determines a user's request matches a skill's description. The loaded instructions guide the agent's response.

### skill_create

Creates a new skill directory with a valid `SKILL.md`. Validates the name format and field lengths. Invalidates the discovery cache so the skill is immediately available.

```
skill_create(
    name: "my-skill",
    description: "What it does and when to use it.",
    instructions: "# My Skill\n\nDetailed instructions...",
    license: "MIT",
    compatibility: "Requires docker"
)
```

| Parameter | Required | Description |
|---|---|---|
| `name` | Yes | Kebab-case skill name (must follow naming rules) |
| `description` | Yes | What the skill does and when to use it (max 1024 chars) |
| `instructions` | Yes | Markdown body content — the detailed instructions |
| `license` | No | License name (e.g. "MIT", "Apache-2.0") |
| `compatibility` | No | Environment requirements (max 500 chars) |

## How Discovery Works

### Boot Sequence

During startup, `BootManager` initializes skill discovery as part of the boot process:

```
loadConfig → blacklist → roleResolver → initializeWorkspace
    → discoverSkills → discoverToolkits
```

The `discoverSkills` step:
1. Creates a `SkillDiscovery` instance pointed at `workspace/skills/`
2. Calls `ensureSkillsDir()` to create the directory if it doesn't exist
3. `SkillDiscovery` is passed to `AgentRunner` → `OrchestratorAgent`

### System Prompt Injection

`OrchestratorAgent` calls `SkillDiscovery::buildPromptSummary()` to generate an XML block listing all discovered skills. This is injected into the system prompt via the `{{available_skills}}` placeholder:

```xml
<available-skills>
<skill>
<name>code-review</name>
<description>Review code for quality...</description>
</skill>
<skill>
<name>git-workflow</name>
<description>Manage git branches...</description>
</skill>
</available-skills>
```

If no skills are installed, the placeholder resolves to `"No skills installed."`.

### Registration

When `OrchestratorAgent` initializes its tools, it registers `SkillToolkit` (providing `skill_list`, `skill_read`, `skill_create`) alongside the other built-in toolkits.

### File Layout

```
workspace/
└── skills/
    ├── code-review/
    │   └── SKILL.md
    ├── git-workflow/
    │   ├── SKILL.md
    │   └── references/
    │       └── branching-model.md
    └── say-hello/
        └── SKILL.md
```

### Cache Invalidation

`SkillDiscovery` caches results after the first scan. The cache is invalidated:
- When `skill_create` creates a new skill (calls `invalidateCache()`)
- On restart (a new `SkillDiscovery` instance is created)

## Best Practices

### Writing Effective Skills

**Be specific about triggers.** The description is the agent's primary signal for deciding when to use a skill. Include the exact words and phrases a user might say.

```yaml
# Good
description: Generate SQL queries from natural language descriptions. Use when asked to write SQL, create queries, query a database, or translate English to SQL.

# Bad
description: Helps with databases.
```

**Structure instructions clearly.** Use headings, numbered steps, and examples. The agent processes structured text more reliably than prose.

**Include examples.** Show input/output pairs so the agent knows what good results look like.

**Define output format.** If you want results in a specific format (table, list, JSON), say so explicitly.

**Cover edge cases.** Tell the agent what to do when things go wrong or when input is ambiguous.

### Keeping Skills Lightweight

- Keep `SKILL.md` under 500 lines
- Move reference material to `references/`
- Move templates and data to `assets/`
- Move executable scripts to `scripts/`
- Each installed skill costs ~100 tokens at boot (metadata only)

### Organizing Skills

- One skill per concern — don't combine unrelated behaviors
- Use descriptive names: `php-code-review` not `review`
- Group related skills by naming convention: `git-workflow`, `git-hooks`, `git-bisect`
- Keep the skills directory flat (no nesting of skill directories)

### Reusable Patterns

Good candidates for skills:
- Code review checklists for specific languages or frameworks
- Commit message and PR description conventions
- Deployment workflows and runbooks
- Data transformation recipes
- API integration patterns
- Documentation templates
- Testing strategies for specific domains

Poor candidates for skills:
- One-off tasks you'll never repeat
- Tasks that need executable code (use a toolkit instead)
- Simple facts or lookups (just tell the agent directly)

## Examples

### Say Hello (Prompt-Only)

The simplest skill — pure instructions, no supporting files.

**Location:** [`examples/say-hello/`](../examples/say-hello/)

```markdown
---
name: say-hello
description: Greet users warmly in various languages and styles. Use when asked to say hello, greet someone, or welcome a user.
license: MIT
metadata:
  author: coqui
  version: "1.0"
---

# Say Hello

Greet users in a warm, friendly way. Adapt your greeting based on the user's preferences.

## Default Behavior

When no specific style is requested, use a friendly, casual greeting appropriate to the context.

## Language Support

When asked to greet in a specific language, use a natural greeting in that language followed by a brief English translation in parentheses:

- **English:** "Hello! How can I help you today?"
- **Spanish:** "¡Hola! ¿Cómo puedo ayudarte hoy?"
- **French:** "Bonjour ! Comment puis-je vous aider aujourd'hui ?"

## Formality Levels

- **Casual:** "Hey!", "What's up!"
- **Formal:** "Good day. How may I assist you?"
- **Playful:** "Hey there! 🎉 Ready to have some fun?"
```

### Code Review (With References)

A more complex skill that includes a reference directory.

```
code-review/
├── SKILL.md
└── references/
    ├── php-checklist.md
    └── security-checklist.md
```

```markdown
---
name: code-review
description: Review code for quality, security, and maintainability. Use when asked to review, audit, or critique any code.
license: MIT
metadata:
  author: dev-team
  version: "2.0"
---

# Code Review

## Process

1. Read the code thoroughly before commenting
2. Check against the relevant language checklist (see references/)
3. Run through the security checklist for any code handling external input
4. Present findings grouped by severity

## Severity Levels

- **Critical** — Bugs, security vulnerabilities, data loss risks
- **Warning** — Code smells, performance issues, poor patterns
- **Suggestion** — Style, readability, naming improvements

## Output Format

| # | Severity | Location | Finding | Suggestion |
|---|----------|----------|---------|------------|
| 1 | Critical | file.php:42 | SQL injection | Use parameterized query |

## Language-Specific Checklists

For detailed checklists, see:
- [PHP checklist](references/php-checklist.md)
- [Security checklist](references/security-checklist.md)
```

### Data Pipeline (With Scripts)

A skill that includes executable scripts.

```
data-pipeline/
├── SKILL.md
├── scripts/
│   ├── extract.py
│   └── validate.sh
└── assets/
    └── schema.json
```

```markdown
---
name: data-pipeline
description: Transform, validate, and load data between formats (CSV, JSON, XML). Use when asked to process data files or build ETL workflows.
compatibility: Requires python3, jq
metadata:
  author: data-team
  version: "1.0"
---

# Data Pipeline

## Extraction

To extract data from a source file, run:
scripts/extract.py --input <source> --format <csv|json|xml>

## Validation

Validate against the schema:
scripts/validate.sh <data-file> assets/schema.json

## Transformation Rules

1. Normalize dates to ISO 8601
2. Trim whitespace from all string fields
3. Replace null values with empty strings
4. Validate required fields against assets/schema.json
```

## Troubleshooting

### Skill not appearing in skill_list

- Verify the directory is inside `workspace/skills/`
- Check that `SKILL.md` exists (case-sensitive: `SKILL.md` preferred, `skill.md` accepted)
- Ensure the frontmatter has both `name` and `description` fields
- Confirm the `name` value matches the directory name exactly
- Restart Coqui to trigger fresh discovery

### Skill not being activated

- Check the `description` field — does it include keywords the user is likely to say?
- The agent matches requests to descriptions. A vague description means fewer matches.
- Use `skill_list` to verify the skill is discovered
- Explicitly ask the agent to use the skill: "Use the code-review skill to review this file"

### Validation errors on skill_create

- **"Name must be lowercase"** — Use only `a-z`, `0-9`, and `-`
- **"Name must not start/end with a hyphen"** — Remove leading or trailing `-`
- **"Name must not contain consecutive hyphens"** — Replace `--` with `-`
- **"Description must be at most 1024 characters"** — Shorten the description; move detail to the body
- **"Skill directory already exists"** — Choose a different name or delete the existing skill first

### SKILL.md parse errors

- Frontmatter must start with `---` on the first line (no leading whitespace)
- Frontmatter must end with `---` on its own line
- YAML values with special characters should be quoted: `description: "Uses: colons and stuff"`
- The `metadata` field must be a one-level-deep map of string keys to string values

### Key Source Files

| File | Purpose |
|---|---|
| `src/Config/SkillParser.php` | Stateless parser — frontmatter extraction, YAML parsing, validation |
| `src/Config/SkillDiscovery.php` | Boot-time scanning, caching, prompt summary generation |
| `src/Toolkit/SkillToolkit.php` | Three tools: skill_list, skill_read, skill_create |
| `src/Contract/SkillProperties.php` | Value object for parsed frontmatter fields |
| `src/Exception/SkillParseException.php` | Parse failures (missing file, malformed frontmatter) |
| `src/Exception/SkillNotFoundException.php` | Name resolution failures |
| `src/Exception/SkillValidationException.php` | Validation failures with error array |
| `prompts/tools/skills.md` | Agent prompt template with `{{available_skills}}` placeholder |
| `examples/say-hello/SKILL.md` | Example prompt-only skill |
