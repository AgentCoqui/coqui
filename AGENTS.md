# Coqui Contributor Guide

This file is the contributor-facing guide for working on Coqui with AI agents.

It is intentionally concise. Use it for the project model, coding boundaries, and contributor workflow. Follow the linked docs for feature-specific details instead of expanding this file into a second architecture encyclopedia.

## What Coqui Is

Coqui is a personal operating system — a lightweight, hackable agent runtime built on [`carmelosantana/php-agents`](https://github.com/carmelosantana/php-agents). It supports coding, research, automation, and any workflow that benefits from persistent AI agents.

At a high level:

- `php-agents` provides the generic agent loop, providers, tools, toolkits, messages, and context-window primitives.
- Coqui adds the product layer: REPL, API server, safety model, sessions, memory, artifacts, loops, schedules, project context, and toolkit discovery.
- The REPL is the primary interface. The HTTP API is narrower and is optimized for application integration plus read-heavy inspection.

## Read This First

Use these documents as the canonical references:

- [README.md](README.md): installation, quick start, user-facing overview
- [docs/COMMANDS.md](docs/COMMANDS.md): REPL, CLI, and launcher commands
- [docs/CONFIGURATION.md](docs/CONFIGURATION.md): `openclaw.json`, models, workspace, mounts, runtime settings
- [docs/API.md](docs/API.md): canonical HTTP API reference and client integration guidance
- [docs/TESTING.md](docs/TESTING.md): test strategy and test commands
- [docs/TOOLKITS.md](docs/TOOLKITS.md): toolkit authoring and discovery
- [docs/TOOLKIT-EXTENSIBILITY.md](docs/TOOLKIT-EXTENSIBILITY.md): self-registering REPL commands from toolkits
- [docs/ROLES.md](docs/ROLES.md): role system and role files
- [docs/PROFILES.md](docs/PROFILES.md): personality profiles
- [docs/BACKGROUND-TASKS.md](docs/BACKGROUND-TASKS.md): background task model
- [docs/LOOPS.md](docs/LOOPS.md): loop system
- [docs/PROJECTS.md](docs/PROJECTS.md): lean project working scopes
- [docs/ARTIFACTS.md](docs/ARTIFACTS.md): artifact lifecycle and usage
- [docs/FEATURES.md](docs/FEATURES.md): broad feature overview
- [docs/GITHUB-ACTIONS.md](docs/GITHUB-ACTIONS.md): CI and release workflows

## Architecture in One Page

The core runtime flow is:

1. `BootManager` resolves config, workspace, mounts, credentials, storage, roles, toolkits, and memory.
2. `OrchestratorAgent` assembles system prompt sections, tools, toolkits, execution policy, and context-window behavior.
3. `AgentRunner` executes turns for the REPL, API, background tasks, and loop stages.
4. `SessionStorage` persists sessions, messages, turns, tasks, and audit records in SQLite.
5. Feature stores and toolkits build on that shared runtime.

Important boundaries:

- `php-agents` owns the generic loop.
- Coqui owns product behavior and integration surfaces.
- The REPL is the broadest control surface.
- The API deliberately does not expose every REPL capability.

## Working with AI Agents on This Repo

When using an agent to modify Coqui:

1. Verify behavior against source before changing docs.
2. Prefer the existing architecture over inventing parallel systems.
3. Keep edits minimal and local to the feature being changed.
4. Update the relevant docs in the same change.
5. Preserve the REPL-first product model unless there is an explicit decision to widen the API.

Good workflow:

1. Read the relevant source files.
2. Read the specific feature doc.
3. Change code.
4. Update user-facing docs and contributor docs that drifted.
5. Run the narrowest useful validation.

## Source of Truth Rules

Use the code as the source of truth.

Examples:

- API routes: `src/Command/ApiCommand.php` plus self-registering handlers under `src/Api/Handler/`
- REPL commands: `src/Repl/SlashCommandRouter.php`
- CLI commands: `src/Command/`
- Role behavior: `config/roles/` plus `src/Config/RoleParser.php` and `src/Config/RoleDiscovery.php`
- Toolkit registration and guards: `src/Config/ToolkitDiscovery.php`, `src/Tool/`, `src/Toolkit/`
- Storage schemas: `src/Storage/`

If documentation and code diverge, fix the docs unless the product behavior itself is wrong.

## Platform Support

Contributor expectations:

- Linux: fully supported
- macOS: fully supported
- WSL2: supported path for Windows users doing development work
- Docker: supported for isolated execution and API usage

When writing docs, keep platform language concise and honest. Do not imply parity that the project does not actually maintain.

## Dependency Management

Composer is the only package manager for Coqui itself.

Rules:

- Prefer PHP built-ins, SPL, and existing project utilities before adding a dependency.
- Keep dependencies small and framework-free.
- Favor PSR interfaces and focused components over large framework pulls.
- Use caret version constraints unless there is a strong reason not to.
- Never add a dependency without updating the documentation when it changes installation, runtime, or extension behavior.

Relevant files:

- [composer.json](composer.json)
- [docs/CONFIGURATION.md](docs/CONFIGURATION.md)
- [docs/TOOLKITS.md](docs/TOOLKITS.md)

## Coding Standards

Coqui targets PHP 8.4 with strict types.

Default standards:

- `declare(strict_types=1);` in every PHP file
- `final` by default
- constructor injection over service locators or static state
- enums and value objects over stringly typed control flow when practical
- one class per file
- 4-space indentation
- concise comments that explain why, not what

Prefer:

- composition over inheritance
- early returns over deep nesting
- explicit exceptions over `null` as an error signal
- immutable or readonly structures where possible

## Safety Constraints

Coqui has an explicit safety model. Do not weaken it casually.

Always preserve these behaviors:

- catastrophic blacklist checks stay in place
- audit logging remains intact
- destructive operations stay gated unless there is an explicit product decision
- shell and filesystem sandboxing remain enforced
- REPL/API differences stay documented when they are intentional

If a change affects approvals, shell execution, restart behavior, credentials, or task orchestration, review the relevant implementation carefully before editing.

## Testing Expectations

Run the narrowest validation that gives confidence.

Common commands:

```bash
composer test
./vendor/bin/pest
./vendor/bin/phpstan analyse
coqui benchmark
```

Use feature-specific checks when appropriate. For example:

- API or routing changes: verify against [docs/API.md](docs/API.md) and relevant handlers
- REPL command changes: verify against [docs/COMMANDS.md](docs/COMMANDS.md)
- installer or shell workflow changes: review launcher and install scripts directly
- documentation-only changes: validate links, command names, and examples against the current codebase

See [docs/TESTING.md](docs/TESTING.md) for the broader test matrix.

## Documentation Policy

Keep documentation small, accurate, and layered.

Rules:

- `README.md` explains the product and points users to deeper docs.
- `docs/` holds canonical feature references.
- `AGENTS.md` stays focused on contributor workflow and engineering rules.
- Do not duplicate the same reference material across multiple files unless there is a clear audience difference.
- When a detailed reference exists already, link to it instead of restating it.

Update docs whenever you change:

- commands
- routes
- configuration keys
- platform support claims
- dependency requirements
- contributor workflow

## Source Map Maintenance

`config/source.json` is the structured codebase map used by Coqui to understand its own source.

Update it when you:

- add a source file,
- remove or rename a source file,
- significantly change a class responsibility,
- add a new important external dependency that agents interact with.

## Generated: `config/documentation.json`

`config/documentation.json` is a **generated** index (produced by `scripts/generate-documentation-index.php` from the doc headings). It is intentionally **not tracked in git** — never hand-edit or commit it. It is regenerated automatically in the release and Docker builds, so shipped artifacts always carry a current index. Run `composer regen-docs` to refresh it locally. Keeping it out of version control stops parallel doc branches from colliding on a machine-generated file.

## Practical Change Checklist

Before finishing a change:

1. Confirm the code path you changed is the real source of truth.
2. Update the nearest canonical doc.
3. Update `README.md` only if the change is user-facing.
4. Update this file only if contributor workflow or project rules changed.
5. Update `config/source.json` if source structure or responsibility changed.
6. Run targeted validation.

## Keep This File Lean

If you are tempted to add a long feature deep dive here, put it in the relevant file under `docs/` instead and link to it from this guide.
