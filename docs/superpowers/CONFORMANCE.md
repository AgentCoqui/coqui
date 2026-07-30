# CAP 0.5.0 conformance gate

coqui's conformance to the Coqui Agent Protocol is gated by a PHP test suite under
`tests/conformance/`, run as part of `composer test` (or focused via `composer conformance`).

## What it does
- Validates coqui-produced objects against the vendored CAP `schema/*.json` (opis/json-schema, draft 2020-12).
- Replays the spec's golden vectors (`GoldenVectorsTest`): every `valid` vector must pass its schema, every `invalid` vector must be rejected — the PHP mirror of the spec's `validate:vectors`.
- Tracks the Core MUSTs as a scoreboard (`CoreChecklistTest`, CORE-1..CORE-59); rows flip from `todo` to real assertions as each phase lands.

## Vendored snapshot
The schemas + vectors + seed loops live under `tests/conformance/spec/`, pinned to a spec
commit recorded in `tests/conformance/spec/SNAPSHOT.txt`. They are copied from the
`coqui-agent-spec` repo — never hand-edit them.

Refresh (after the spec advances):
`COQUI_SPEC_REPO=/path/to/coqui-agent-spec composer sync-spec`
(defaults to the sibling `../coqui-agent-spec` checkout).

## Secondary check (optional, requires Node)
The spec repo's own static harness confirms the vendored vectors/schemas are internally
consistent. From the spec checkout: `npm ci && npm test`. This is a sanity check on a fresh
sync, not part of the PHP gate.
