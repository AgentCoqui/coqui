# Commands Reference

Coqui provides two interfaces: an interactive **REPL** with slash commands, and a **CLI** with subcommands. This document covers both.

## REPL Commands

All REPL commands start with `/`. Type `/help` during a session to see a quick reference.

### Session Management

| Command | Description |
| --- | --- |
| `/new` | Start a new session. When a profile is active, Coqui warns that it will summarize the current chat, store memories, archive and close the current conversation, then start a fresh session for that same profile (resets role to `orchestrator`) |
| `/sessions` | List active surfaced interactive sessions. Internal hidden background sessions are excluded |
| `/resume <id>` | Resume a specific active surfaced session by ID. Hidden internal sessions cannot be resumed from the REPL |
| `/history` | Show conversation history for the current session |

### Agent & Roles

| Command | Description |
| --- | --- |
| `/role` | Show the current active role |
| `/role <name>` | Switch to a different role (e.g. `/role coder`) |
| `/role edit <name>` | Open a role file in `$EDITOR` for editing |
| `/roles` | List all roles with template, update, and ignore status |
| `/roles update [name]` | Apply pending built-in role updates |
| `/roles ignore <name>` | Ignore future built-in updates for a role |
| `/roles unignore <name>` | Resume receiving built-in updates for a role |
| `/profile` | Show the current active personality profile |
| `/profile <name>` | Switch to a personality profile. Coqui reuses the most recent active session for that profile, archives older duplicate active sessions if any exist, and creates a new one only when none remain active |
| `/profile default` | Show the configured default startup profile |
| `/profile default <name|none>` | Set or clear the default startup profile in `openclaw.json` |
| `/profile reset` | Clear profile, revert to default identity (resumes the last unprofiled session or creates one) |
| `/profiles` | List all available personality profiles |
| `/group` | Show help, or show the current group session when the active session is group-enabled |
| `/group status` | Show the active group session's members, round cap, and orchestrator role |
| `/group start <member1,member2,...> [--rounds=N]` | Start or resume an orchestrator-managed group session for the requested member set |
| `/group add <profile>` | Add one member to the current group session |
| `/group remove <profile>` | Remove one member from the current group session |
| `/group replace <member1,member2,...> [--rounds=N]` | Replace the current group membership, optionally updating the round cap |
| `/group rounds <n>` | Update the maximum per-turn coordination rounds for the current group session |
| `/model [role]` | Show model configuration (optionally for a specific role) |
| `/thinking` | Show thinking capability and current reasoning effort for the active model |
| `/thinking off\|low\|medium\|high` | Set reasoning effort for the active model (`off` disables thinking on thinking-capable Ollama models); persists to `openclaw.json` and applies on the next message |
| `/thinking clear` | Remove the reasoning-effort override and restore the model default |

Group sessions stay orchestrator-managed and clear any single active profile scope. Starting or reshaping a group session reuses the same membership validation and duplicate-composition rules as the HTTP API.

When the active session is group-enabled, the REPL prompt shows the current member list, for example `You (group session with @alex-hormozi, @trinity)`. Prompts without explicit member mentions fan out to all members in stored order. Use `@name` to narrow the responder set or `@everyone` / `@group` to force a full-team reply. During a turn, streamed start, iteration, and reasoning output is labeled with the active member so you can see who is speaking and why that responder set was chosen.

### Configuration & System

| Command | Description |
| --- | --- |
| `/config` | Show current configuration |
| `/config show` | Show the raw `openclaw.json` file |
| `/config edit` | Reconfigure via setup wizard, then restart |
| `/config history [status\|on\|off]` | Show or toggle whether prior turns are also appended into the final `Conversation History` system-prompt block while normal replay stays enabled |
| `/restart` | Restart the current Coqui REPL process (re-reads config, re-discovers toolkits) |
| `/update` | Check for and apply dependency updates, then restart |
| `/help` | Show the command reference table |
| `/quit` | Exit Coqui |
| `/exit` | Exit Coqui (alias) |
| `/q` | Exit Coqui (alias) |

### Tool & Toolkit Management

| Command | Description |
| --- | --- |
| `/toolkits` | List all packages and tools with current visibility |
| `/toolkits enable <pkg>` | Set a package to `enabled` (full schema in LLM context) |
| `/toolkits stub <pkg>` | Set a package to `stub` (minimal schema, discover via `tool_search`) |
| `/toolkits disable <pkg>` | Set a package to `disabled` (invisible to the LLM) |
| `/toolkits enable tool:<name>` | Set an individual tool to `enabled` |
| `/toolkits stub tool:<name>` | Set an individual tool to `stub` |
| `/toolkits disable tool:<name>` | Set an individual tool to `disabled` |
| `/toolkits promote <ToolkitClass>` | Force a toolkit to load eagerly regardless of budget |
| `/toolkits demote <ToolkitClass>` | Force a toolkit to stay deferred regardless of budget |
| `/toolkits auto <ToolkitClass>` | Return a toolkit to automatic budget-gated loading |
| `/budget [role]` | Show prompt-budget telemetry and toolkit loading decisions |
| `/prompt` | Print the fully rendered system prompt with tool/toolkit/token counts plus file and folder breakdowns |
| `/prompt export` | Export system prompt and tool schemas to a file in the workspace |

### Backstory Generator

| Command | Description |
| --- | --- |
| `/backstory` | Show backstory generation status, file and folder summaries, skipped unsupported files, and current issue summary |
| `/backstory generate` | Force regeneration of `backstory.md` from source files |
| `/backstory failed` | Show files that failed extraction or were skipped as unsupported |

### Images

| Command | Description |
| --- | --- |
| `/image` | Show the standardized image toolkit command reference |
| `/image help` | Show the same standardized image toolkit command reference |
| `/image generate <prompt>` | Generate an image through the installed image toolkit and save it into the workspace image library |
| `/image preview <path>` | Render an existing workspace image as a low-fidelity terminal preview |
| `/image list` | Browse saved image records; `--profile`, `--vendor`, `--category`, `--tag`, and `--sort` narrow the result set |
| `/image search <query>` | Search saved image records by prompt, tags, owner, profile, model, vendor, or path |
| `/image get <record-id>` | Show one saved image record |
| `/image tag <record-id> <tag1,tag2>` | Update tags and optional category for a saved image record |
| `/image delete <record-id>` | Delete a saved image record from the workspace image library |
| `/image config` | Show resolved image-generation defaults, workspace paths, and vendor settings |

`/image generate` accepts option flags such as `--model=vendor/model`, `--vendor=openai|ollama`, `--tags=a,b`, `--category=name`, `--file-hint=name`, `--save-dir=images/custom`, `--size=1024x1024`, and `--quality=standard|hd`.

Toolkit commands now use the same shared help formatting conventions as the built-in REPL commands. When a toolkit implements structured help metadata, `/command` and `/command help` render that metadata through Coqui's standard formatter; otherwise Coqui generates the page from the command registration data.

When the resolved model is an Ollama image model that is not installed locally, Coqui now asks for confirmation before running `ollama pull` and shows a controlled download status instead of dumping the raw pull stream into the REPL.

In the interactive REPL, Coqui now auto-renders one low-fidelity ANSI image preview per turn when a successful tool result returns a workspace-local image path, such as a `browser_capture` screenshot or an image-toolkit output. Streamed assistant markdown also previews one workspace-local markdown image per response. This v1 behavior is intentionally narrow: local workspace files only, first preview only, and graceful fallback when `ext-gd` is unavailable.

### MCP

The MCP client engine, per-server tool exposure, and HTTP API ship in Coqui core by default — MCP servers work out of the box. The `/mcp` REPL command below is provided by the optional [`coquibot/coqui-toolkit-mcp-client`](https://github.com/AgentCoqui/coqui-toolkit-mcp-client) package as a self-registering command (see [docs/TOOLKIT-EXTENSIBILITY.md](TOOLKIT-EXTENSIBILITY.md)); it is not present unless that package is installed. Without it, manage MCP servers over the HTTP API (see [docs/API.md](API.md)) or by editing `.workspace/mcp.json` directly.

| Command | Description |
| --- | --- |
| `/mcp` | Show the standardized MCP server management command reference |
| `/mcp help` | Show the same standardized MCP server management command reference |
| `/mcp list` | List configured MCP servers with live connection state and last connectivity test |
| `/mcp status <server>` | Show one server's command, env links, discovered tool count, and live audit fields |
| `/mcp tools <server>` | List discovered MCP tools for one server |
| `/mcp search <query> [server]` | Search discovered MCP tool names and descriptions |
| `/mcp test <server>` | Reconnect and verify tool discovery for one server |
| `/mcp connect <server>` | Connect a configured MCP server immediately |
| `/mcp disconnect <server>` | Disconnect a configured MCP server immediately |
| `/mcp refresh <server>` | Reconnect and refresh discovered tools for one server |
| `/mcp add <server> <command> [args...]` | Add a new MCP server configuration |
| `/mcp update <server> <command> [args...]` | Update an existing MCP server command or args |
| `/mcp remove <server>` | Remove a configured MCP server |
| `/mcp enable <server>` | Enable a disabled MCP server |
| `/mcp disable <server>` | Disable and disconnect a configured MCP server |
| `/mcp promote <server>` | Force one MCP server toolkit to load eagerly on future turns |
| `/mcp demote <server>` | Force one MCP server toolkit to stay deferred on future turns |
| `/mcp auto <server>` | Return one MCP server toolkit to automatic budget-gated loading |
| `/mcp set-env <server> <ENV_KEY>` | Prompt for a secret value and link it into one server's env config |
| `/mcp auth <server> <auth-url> <token-url> [client-id] [scopes...]` | Run browser-based OAuth and link the resulting access token for one server |

MCP config changes are re-read on future agent turns without a full Coqui restart. If a running REPL or API process needs the new server runtime immediately, use `/mcp connect <server>`, `/mcp refresh <server>`, or `/restart`. Server loading mode changes reuse Coqui's normal toolkit budget gate: `promote` maps to eager loading, `demote` maps to deferred loading, and `auto` returns the server to budget-managed loading. Proxied MCP tools stay namespaced as `mcp_{server}_{tool}`, so the existing `/toolkits enable|stub|disable tool:<name>` controls still work for individual MCP tools.

### Background Tasks

| Command | Description |
| --- | --- |
| `/tasks [status]` | List background tasks, optionally filtered by status |
| `/task <id>` | Show a background task's status and recent events |
| `/task-cancel <id>` | Cancel a pending or running background task |

### Projects

| Command | Description |
| --- | --- |
| `/projects` | List all projects and mark the active project |
| `/projects active` | List only active projects |
| `/projects completed` | List only completed projects |
| `/projects archived` | List only archived projects |
| `/projects <slug\|id>` | Switch the active project for the current session |
| `/projects clear` | Clear the active project for the current session |

### Scheduling & Automation

Inspection commands in this section are user-facing. Mutation and lifecycle controls are still available, but they are best treated as advanced automation or operator workflows rather than routine chat interaction.

| Command | Description |
| --- | --- |
| `/schedules` | List scheduled tasks with status, cron expression, next/last run time |
| `/schedules status <name\|id>` | Show detailed schedule configuration, runtime state, and prompt text |
| `/schedules enable <name\|id\|all>` | Advanced operator control: enable a schedule (or all disabled schedules) |
| `/schedules disable <name\|id\|all>` | Advanced operator control: disable a schedule (or all enabled schedules) |
| `/schedules delete <name\|id\|all>` | Advanced operator control: delete a schedule (or all schedules) |
| `/schedules trigger <name\|id\|all>` | Advanced operator control: force-trigger a schedule on the next API tick |
| `/loops` | List all loops with status and progress |
| `/loops running` | List only running loops |
| `/loops paused` | List only paused loops |
| `/loops completed` | List only completed loops |
| `/loops failed` | List only failed loops |
| `/loops cancelled` | List only cancelled loops |
| `/loops start <definition> <goal>` | Advanced automation control: start a loop from a discovered definition with a goal |
| `/loops definitions` | Show available loop definitions |
| `/loops defs` | Alias for `/loops definitions` |
| `/loops status <id>` | Detailed status of a specific loop |
| `/loops pause <id\|all>` | Advanced automation control: pause running loop(s) |
| `/loops resume <id\|all>` | Advanced automation control: resume paused loop(s) |
| `/loops stop <id\|all>` | Advanced automation control: stop/cancel loop(s) |
| `/webhooks` | List webhook subscriptions with status and trigger counts for monitoring |
| `/webhooks status <name\|id>` | Show webhook configuration, secret mask, and trigger state |
| `/webhooks deliveries <name\|id>` | Show recent delivery logs for one webhook |
| `/webhooks enable <name\|id>` | Advanced operator control: enable a webhook subscription |
| `/webhooks disable <name\|id>` | Advanced operator control: disable a webhook subscription |
| `/webhooks delete <name\|id>` | Advanced operator control: delete a webhook subscription |
| `/webhooks rotate <name\|id>` | Advanced operator control: rotate a webhook signing secret |
| `/hints` | Toggle command hints in the input area |
| `/multiline` | Toggle multiline compose mode (double-Enter submits, bracketed paste auto-detected) |
| `/multiline on` | Enable multiline compose mode |
| `/multiline off` | Disable multiline compose mode |

> The REPL `/loops` view is text-only: a status/progress table and per-loop `/loops status <id>` snapshots. Rich, live loop monitoring lives on the HTTP API (see [docs/API.md](API.md)); the REPL no longer opens a full-screen loop dashboard.

### Context Management

| Command | Description |
| --- | --- |
| `/summarize` | Summarize conversation history to reclaim token budget |
| `/summarize recent N` | Summarize but keep the N most recent turns |
| `/summarize focus "topic"` | Summarize with emphasis on a specific topic |

Options can be combined: `/summarize recent 5 focus "database schema"`.

### Marketplace

Marketplace management now lives in external toolkits.

Use the toolkit-owned `/mods` command for discovery and install flows, and the `mods_skills`, `mods_toolkits`, `mods`, `mods_publish`, and `mods_account` tool surfaces for agent-driven marketplace actions.

## CLI Commands

Coqui is invoked via `coqui`. By default, `coqui` starts the launcher-managed app: REPL in the foreground plus the API in the background. Advanced console commands such as `doctor`, `benchmark`, `task:run`, and `turn:run` remain available when explicitly requested.

### `coqui`

Start the full launcher-managed app or use launcher service controls.

```bash
coqui                      # Start REPL + API (default)
coqui --api-only           # Start only the launcher-managed API
coqui --repl-only          # Start only the REPL via the launcher
coqui --wizard             # Run the setup wizard
coqui status               # Show launcher-managed service status
coqui stop                 # Stop launcher-managed services
coqui cleanup              # Clean stale/conflicting Coqui processes
```

### `coqui run`

Compatibility alias for REPL-only mode via the launcher.

```bash
coqui run                      # Start the REPL only
coqui run --new                # Start a fresh session
coqui run --session abc123     # Resume a specific session
coqui run --profile caelum     # Resume or create the last active session for a profile
coqui run --auto-approve       # Skip tool confirmation prompts
coqui run --continue           # Resume last session and auto-send "Continue."
```

| Option | Short | Description |
| --- | --- | --- |
| `--config <path>` | `-c` | Path to `openclaw.json` |
| `--new` | | Start a new session |
| `--session <id>` | `-s` | Resume a specific session |
| `--continue` | | Resume the last session and automatically send "Continue." as the first prompt |
| `--workdir <path>` | | Working directory (project root). Default: current directory |
| `--workspace <path>` | | Workspace directory (overrides config and default) |
| `--wizard` | `-w` | Run the setup wizard (no REPL, no session) |
| `--unsafe` | | Disable PHP script sanitization. **Use with caution** |
| `--auto-approve` | | Auto-approve all tool executions. **Use with caution** |
| `--update` | | Check for and apply dependency updates, then restart |
| `--no-terminal` | | Headless mode: run a single prompt without the REPL |
| `--prompt <text>` | `-p` | Prompt to send in headless mode |
| `--format <fmt>` | `-f` | Output format for headless mode: `text` (default) or `json` |

**Environment variable equivalents:** `COQUI_UNSAFE=true` for `--unsafe`, `COQUI_AUTO_APPROVE=true` for `--auto-approve`.

#### Headless Mode

Run a single prompt without the interactive REPL:

```bash
coqui run --no-terminal --prompt "List all PHP files in src/"
coqui run --no-terminal --prompt "Summarize this project" --format json
printf '%s' 'Summarize this project with a very large prompt body' | coqui run --no-terminal --format json
```

For very large prompts, prefer piping via stdin instead of `--prompt`. Coqui does not truncate headless input, but shell and OS argv limits can prevent very large `--prompt` values from reaching the process intact.

### `coqui api`

Compatibility alias for launcher-managed API-only mode.

```bash
coqui api                             # Start on 127.0.0.1:3300
coqui api --host 0.0.0.0 --port 8080  # Listen on all interfaces, port 8080
```

| Option | Short | Description |
| --- | --- | --- |
| `--port <port>` | | Port to listen on. Default: `3300` |
| `--host <host>` | | Host to bind to. Default: `127.0.0.1` |
| `--config <path>` | `-c` | Path to `openclaw.json` |
| `--workdir <path>` | `-w` | Working directory (project root) |
| `--workspace <path>` | | Workspace directory (overrides config and default) |
| `--unsafe` | | Disable PHP script sanitization |
| `--cors-origin <origins>` | | Allowed CORS origins, comma-separated. Default: `*` |

**Environment variable:** `COQUI_API_HOST` overrides the `--host` default when the flag is not explicitly passed.

### `coqui setup`

Compatibility alias for the launcher setup wizard.

```bash
coqui setup                       # Configure in current directory
coqui setup --workdir /my/project  # Configure a specific project
```

| Option | Short | Description |
| --- | --- | --- |
| `--workdir <path>` | | Working directory (project root) |
| `--output <path>` | `-o` | Output path for `openclaw.json`. Default: `{workdir}/openclaw.json` |

### `coqui doctor`

Run health checks on your Coqui installation.

```bash
coqui doctor              # Run all checks
coqui doctor --repair     # Fix detected issues automatically
coqui doctor --json       # Output results as JSON
```

| Option | Short | Description |
| --- | --- | --- |
| `--config <path>` | `-c` | Path to `openclaw.json` |
| `--workdir <path>` | `-w` | Working directory (project root) |
| `--workspace <path>` | | Workspace directory (overrides config and default) |
| `--repair` | | Automatically fix detected issues |
| `--json` | | Output results as JSON |

### `coqui benchmark`

Run lightweight performance benchmarks against key Coqui subsystems such as boot, token estimation, SQLite setup, and autoloader hot paths.

```bash
coqui benchmark
coqui benchmark --iterations 250
coqui benchmark --json
```

| Option | Short | Description |
| --- | --- | --- |
| `--iterations <n>` | `-i` | Number of iterations per benchmark. Default: `100` |
| `--json` | | Output machine-readable JSON instead of console tables |
| `--config <path>` | `-c` | Path to `openclaw.json` |
| `--workdir <path>` | `-w` | Working directory used for boot benchmarking |

## Launcher

`coqui` is the unified entrypoint and launcher. All service lifecycle operations go through it.

### Modes

```bash
coqui                   # Start REPL (foreground) + API (background)
coqui --repl-only       # Start REPL only, no API
coqui --api-only        # Start API only (foreground)
coqui --wizard          # Run setup wizard directly
coqui stop              # Stop all background services
coqui stop-api          # Stop the background API only
coqui status            # Show which services are running
```

### Launcher Flags

| Flag | Description |
| --- | --- |
| `--host <host>` | API bind address (default `127.0.0.1`, use `0.0.0.0` for network access) |
| `--port <port>` | API port (default `3300`) |
| `--auto-approve` | Forwarded to `coqui run` |
| `--continue` | Forwarded to `coqui run` |
| `--unsafe` | Forwarded to the launcher-managed REPL and API processes |
| `--config <path>` | Forwarded to the launcher-managed REPL and API processes |
| `--workdir <path>` | Forwarded to the launcher-managed REPL and API processes |
| `--workspace <path>` | Forwarded to the launcher-managed REPL and API processes |
| `--cors-origin <origins>` | Forwarded to launcher-managed API-only mode |
| `--background` | Background all daemon services, return to shell |
| `--verbose` | Enable detailed logging (`COQUI_VERBOSE=1`) |

### Exit Code Behavior

| Exit Code | Meaning | Launcher Response |
| --- | --- | --- |
| `0` | Clean exit (`/quit` or `Ctrl+C`) | Launcher stops, cleans up services |
| `10` | Restart requested (`/restart`) | Relaunches the REPL, resets crash counter |
| Other | Crash | Relaunches up to 3 consecutive times |

## Signal Handling

### At the REPL prompt

| Action | Behavior |
| --- | --- |
| First `Ctrl+C` | Prints `(Press Ctrl+C again to quit.)` |
| Second `Ctrl+C` | Exits cleanly (exit code `0`) |

The counter resets after any successful input.

### During agent execution

| Action | Behavior |
| --- | --- |
| First `Ctrl+C` | Cooperative cancellation — agent finishes current iteration then stops |
| Second `Ctrl+C` | Immediate kill (exit code `130`) |
| `Esc` | Cooperative cancellation via `EscCancellationObserver` |

## See Also

- [ROLES.md](ROLES.md) — Built-in roles and how to create custom roles
- [FEATURES.md](FEATURES.md) — Complete feature reference
- [CONFIGURATION.md](CONFIGURATION.md) — Configuration file reference
- [API.md](API.md) — HTTP API endpoints
