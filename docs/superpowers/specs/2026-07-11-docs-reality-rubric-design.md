# Docs-Reality Rubric & Website Alignment — Design

**Status:** approved 2026-07-11. Drives the core-docs slate and the two website tracks.

## Problem

The platform-thinning effort removed or extracted several capabilities from core
(channels, the agent-facing composer/packagist and background-task tools, the
fullscreen TUI; MCP-management and backstory Docx/Pdf/Html extracted to mods;
webhooks extracted to a mod). Documentation drifted: some core docs, some
machine-facing prompts, and the public docs site still describe removed things as
present. We need a repeatable standard — a rubric — for deciding whether any
doc / site / prompt claim reflects current core, plus a plan to bring everything
back in line.

## External marker: the last release

`v0.0.26` (2026-06-30) is the last published release, and **every** thinning
change merged after it. So the capability delta `v0.0.26..HEAD` is the
authoritative, evidence-based list of what changed — "where we were" vs "where we
are now." A claim is stale iff it matches the `v0.0.26` world but not `HEAD`.

Delta at time of writing (removed): `ComposerTool`, `PackagistTool`,
`BackgroundTaskToolkit` (+ `start_background_task`/`start_background_tool`),
`prompts/tools/background-tasks.md`, `docs/CHANNELS.md`; extracted to mods:
MCP-management (`coqui-toolkit-mcp-client`), backstory formats
(`coqui-toolkit-backstory-formats`), webhooks (`coqui-toolkit-webhooks`).

## The rubric — "does this claim reflect current core?"

A doc / site / prompt claim **passes** only if:

1. **Present at HEAD.** The tool / command / endpoint / feature is actually
   registered in core *now*, not just at `v0.0.26`. Proof = the capability delta.
2. **Right home (full-expunge).** If extracted to a mod, core carries **zero**
   specifics — docs *and* prompts — and the feature is documented 100% in its
   mod; core mentions it at most as a name in the marketplace catalog. If deleted
   outright, no trace. (See `platform-thinning-roadmap` memory: full-expunge is
   the standing policy for extracted optional features.)
3. **Prompts match registered tools.** No `prompts/**` file advertises a tool
   that is not registered in core. `PromptLoader` glob-loads every
   `prompts/tools/*.md` unconditionally, so a leftover injects guidance for a
   nonexistent tool into every system prompt. (Recurring leak: webhooks,
   composer/packagist.)
4. **Generated artifacts are regenerated, never hand-patched.**
   `config/documentation.json` (core) and the docs-site `content/**` come from
   cleaned sources via their generators; a generator's route/file list must not
   map to a deleted doc.
5. **Partial removals are edited, not dropped.** Where only part of a feature was
   removed — MCP (engine + HTTP API core / `mcp` tool + `/mcp` command in the
   mod) and background tasks (substrate + `/tasks` API core / agent tools gone) —
   the page is kept and edited to describe the surviving surface.
6. **Marketing copy maps to a real capability.** Every feature card / use-case /
   snippet / endpoint on the marketing site corresponds to a supported current
   capability.

## Architecture: where web content comes from

- **`docs.agentcoqui.com` (`com-agentcoqui-docs`, repo `docs-coquibot-org`) is
  GENERATED from core.** `scripts/sync-docs.mjs` copies `Core/coqui/docs/*.md` +
  `README.md` into `content/**` via an explicit route table. On Vercel the sync
  is skipped and the committed `content/**` ships. So core docs are the source of
  truth; you never hand-edit `content/**`. **Live breakage:** `CHANNELS.md` was
  deleted from core but is still mapped, so `validateSourceDocs()` throws and the
  live site still serves a stale `channels.mdx`.
- **`agentcoqui.com` (`com-agentcoqui`, repo `coqui-space`) is hand-authored**
  (Next.js marketplace + landing). It does not import core; feature copy is
  hardcoded TSX. Its only hard stale claim is a "Create Webhooks" use-case card.
- `com-agentcoqui-admin` — superseded legacy variant, out of scope.

## Execution — three tracks

**Track A — Core docs slate** (branch `feat/docs-drift-slate` off `main`).
Fix the ~30 drift lines the audit found: rewrite the composer/packagist prompt
sections (`prompts/base.md`, `prompts/tools/space.md`, `prompts/tools/packages.md`)
around `package_info` + Coqui Mods; drop the deleted background-task tools row
(`README.md`); drop the fullscreen-browser claim (`docs/COMMANDS.md`);
regenerate `config/documentation.json` (removes 21 stale channels headings). This
spec ships with it. Webhooks is handled on its own branch and folds in on merge
(both regenerate `config/documentation.json` → regenerate once after both land).

**Track B — Docs site** (`com-agentcoqui-docs`). Remove the `CHANNELS.md` route
from `sync-docs.mjs` (unblocks the throwing build), reconcile the
`BACKGROUND-TASKS.md` route, re-run `pnpm sync-docs`, and commit the regenerated
`content/**` (Vercel ships committed files). Depends on Track A (+ webhooks)
landing so the synced source is clean.

**Track C — Marketing site** (`com-agentcoqui`). Remove/replace the "Create
Webhooks" use-case card + screenshot in `components/use-case-showcase.tsx`;
review the "background processes" wording in `app/(site)/page.tsx`. Small,
independent copy edit.

## Out of scope

- Webhooks doc expunge (already done on `feat/webhooks-extraction-impl`).
- Broader question of whether every mod's commands (e.g. `/image` from
  `coqui-toolkit-images`) should leave core docs — that predates this removal set;
  Track A only fixes the stale *fullscreen* claim, not the images docs wholesale.
- Regenerating the `data/projects.json` PowerBank cache (unrelated).
