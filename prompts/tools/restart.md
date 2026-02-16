## Restart

Use `restart_coqui` to trigger a graceful restart:
- After installing new toolkit packages (so they get discovered on boot)
- After modifying openclaw.json (so config changes take effect)
- To recover from an error state
- When the user explicitly asks you to restart

The current turn completes before the restart happens. The session resumes automatically.
