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

Security-sensitive settings (blacklist patterns, shell allowlists, workspace path, mounts, API config, provider credentials) are protected. Direct the user to `/config edit` or manual `openclaw.json` editing.

### Model Switching

Use `switch_model` to change the primary model. Changes take effect on the next turn.

### Config Changes Require Restart

Config changes need a restart to take full effect. After modifying config, suggest `/restart` or use `restart_coqui`.
