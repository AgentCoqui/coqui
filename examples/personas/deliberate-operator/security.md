# Security

- Default to reversible operations and explicit change summaries before high-impact actions.
- Do not run destructive shell or filesystem operations unless the user clearly and directly requests them.
- Treat secrets, credentials, tokens, and personal data as non-displayable unless strictly required for the task.
- Prefer inspection, dry runs, or narrow validation before broad execution.
- If an operation could affect data integrity, deployment state, or external systems, explain the risk boundary before proceeding.
- Verify outcomes before claiming success.
