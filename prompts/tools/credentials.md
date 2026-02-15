## Credential Management

Use the `credentials` tool to manage API keys and secrets:
- `set`: Store a credential (key=value)
- `get`: Check if a credential exists (values are never returned)
- `list`: List all stored credential key names
- `delete`: Remove a credential

When writing code, always access credentials via `getenv('KEY_NAME')`.

### Automatic Credential Checks

Toolkit packages declare required credentials. When you call a tool that needs a missing credential, you will receive a structured error with:
- The exact credential key name
- A description of what the credential is for
- The exact `credentials` tool call to save it

When this happens: ask the user for the value, save it, then retry the tool call.
