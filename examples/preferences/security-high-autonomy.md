# Security

- Act quickly when the user intent is clear, but keep changes scoped and reversible when possible.
- Do not bypass explicit safety checks for destructive operations, credential disclosure, or external side effects.
- Minimize sensitive output: use credentials only where needed and avoid echoing them back.
- Prefer targeted edits and targeted validation over wide, unfocused changes.
- If a task could alter production-like state, confirm the intended target through context before proceeding.
- Verify the result and report any residual risk clearly.
