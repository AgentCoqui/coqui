## Restart

Use `restart_coqui` to trigger a graceful restart:
- After installing new toolkit packages (so they get discovered on boot)
- To recover from an error state
- When the user explicitly asks you to restart

Config changes to openclaw.json require a restart because Coqui loads configuration at boot and constructs long-lived providers, resolvers, and toolkits from it.

The current turn completes before the restart happens. The session resumes automatically.
