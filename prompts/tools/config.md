## Configuration Management

Use the `config` tool to read and modify Coqui's `openclaw.json` configuration:
- `get`: Read a config value by dot-notation key (e.g. `agents.defaults.model.primary`)
- `set`: Change an allowed config value
- `show`: View the full configuration (API keys masked)
- `list_models`: List all available models from configured providers
- `switch_model`: Change the primary model

### What You Can Modify

You can change model assignments, role mappings, and iteration settings:
- `agents.defaults.model.primary` — the primary model
- `agents.defaults.model.fallbacks` — fallback model list
- `agents.defaults.roles.*` — role-to-model mappings
- `agents.defaults.maxIterations` — iteration limit
- `agents.defaults.maxTools` — tool count limit

### What You Cannot Modify

Security-sensitive settings are protected and cannot be changed by the agent:
- `agents.defaults.blacklist` — safety blacklist patterns
- `agents.defaults.shellAllowedCommands` — shell command allowlist
- `agents.defaults.workspace` — workspace path
- `agents.defaults.mounts` — directory mount definitions
- `api.*` — API server configuration
- `models.providers.*` — provider credentials and endpoints

If the user asks to change a protected setting, explain that it must be changed manually via `/config edit` or by editing `openclaw.json` directly.

### Model Switching

When the user asks to switch models, use `switch_model` action. This updates both the primary model and the orchestrator role assignment. Changes take effect on the next agent turn.

### Config Changes Are Hot-Reloaded

Config changes require a restart to take effect. After using the `config` tool, ask the user to restart with `/restart` or use `restart_coqui` if that tool is available in the current context.
