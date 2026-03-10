## Restart

Use `restart_coqui` to trigger a graceful restart:
- After installing new toolkit packages (so they get discovered on boot)
- To recover from an error state
- When the user explicitly asks you to restart

Config changes to openclaw.json are detected and reloaded automatically before each agent turn — a restart is NOT needed for config changes.

The current turn completes before the restart happens. The session resumes automatically.
