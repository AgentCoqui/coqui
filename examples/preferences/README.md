# Preferences Examples

This directory contains copy-ready `preferences.json` examples plus a couple of optional `security.md` overrides.

If you want a complete persona you can copy as a starting point, see `../personas/deliberate-operator/`.

How to use them:

1. Copy one JSON example into your persona as `personas/<name>/preferences.json`.
2. Optionally copy one security example into the same persona as `personas/<name>/security.md`.
3. Adjust role names, prompt directives, and feature flags to match how restrictive or capable you want the persona to be.

Notes:

- `security.md` cannot be disabled through `preferences.json`.
- If you create a persona-level `security.md`, it must contain non-empty content.
- `prompts.roles.allow` must include `orchestrator`.
- `prompts.roles.deny` cannot include `orchestrator`.

Suggested pairings:

- `deliberate-operator.json`: A measured, planning-heavy persona that still allows coding and review. Pair well with `security-cautious.md`.
- `review-heavy.json`: A lean review and exploration persona that disables several runtime features and restricts coding.
- `commercial-operator.json`: A high-autonomy, outcome-first persona with broad feature access. Pair well with `security-high-autonomy.md`.

Security override examples:

- `security-cautious.md`: Strong guardrails for reversible work, explicit approval boundaries, and careful handling of secrets.
- `security-high-autonomy.md`: Still safe, but tuned for faster execution when the user clearly wants action.
