# Composer + Packagist Toolkit Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete the `composer` and `packagist` agent toolkits from Coqui core, with no replacement mod. The agent loses the ability to install/search packages via tools; humans/apps drive package management (API-first).

**Architecture:** Both toolkits are always-loaded system toolkits wired in `OrchestratorAgent`, listed in `CoquiDefaults`, and (until the Background de-tool PR lands) referenced by `BackgroundToolExecutor`. Removal is reference-first (unwire so the toolkits are unreferenced, suite stays green), then delete the four source files + two tests, then docs/source-map. `PackageInfoTool` and `symfony/http-client` are unaffected and stay.

**Tech Stack:** PHP 8.4, Pest (`composer test`), PHPStan level 8 (`composer analyse`).

**Spec:** `docs/superpowers/specs/2026-07-10-phase1-cuts-design.md` (Item 2).

## Global Constraints

- PHP 8.4, `declare(strict_types=1);`, `final` by default, one class per file, 4-space indent.
- Branch off `origin/feat/phase1-cuts` (= `main` + the batch spec/plans): `git fetch origin && git checkout -b feat/composer-packagist-removal origin/feat/phase1-cuts`. (Base carries no code changes over main — only the planning docs.)
- **Never `git add -A` or `git add .`** — two intentional unstaged edits (`.gitignore` modified, `.vscode/settings.json` deleted) MUST stay unstaged. Stage only exact paths.
- Every commit message ends with: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- Both `composer test` and `composer analyse` must be green before every commit.
- **KEEP:** `src/Tool/PackageInfoTool.php` (independent of Packagist — reads local vendor info), `symfony/http-client` in `composer.json` (used by providers, `WebToolkit`, `VisionAnalyzer`). Do NOT touch `composer.json` requires. Leave the stale `/home/carmelo/Projects/CoquiBot/Toolkits/coqui-toolkit-{composer,packagist}` snapshots on disk untouched.
- **Merge order:** this is PR 3 of 3 (Channels → Background → Composer/Packagist). **Land after the Background de-tool PR.** See the `BackgroundToolExecutor` note in Task 1 Step 3 — its handling depends on whether the Background PR has merged.

---

### Task 1: Unwire and delete the Composer + Packagist toolkits

**Files — modify (excise references):**
- `src/Agent/OrchestratorAgent.php` — remove the `ComposerToolkit`/`PackagistToolkit` imports (lines ~67, 69), the `addSystemToolkit('ComposerToolkit', …)` and `addSystemToolkit('PackagistToolkit', …)` calls (lines ~487–491), and the gate-map entries `'ComposerToolkit' => 'packages'` / `'PackagistToolkit' => 'packages'` (lines ~169–170).
- `src/Contract/CoquiDefaults.php` — remove `'ComposerToolkit'` and `'PackagistToolkit'` from the default system-toolkit list (lines ~219–220).
- `src/Agent/BackgroundToolExecutor.php` — **conditional:** if this file still exists (Background de-tool PR not yet merged into your base), remove the `ComposerToolkit`/`PackagistToolkit` imports and the `registerToolkit(new ComposerToolkit(...))` / `registerToolkit(new PackagistToolkit())` calls (lines ~128, 132). If the file is already gone, skip. **At merge time:** if the Background PR merged first and deleted this file, resolve the delete/modify conflict by accepting the deletion (drop your edit).

**Files — delete:**
- `src/Tool/ComposerTool.php`
- `src/Tool/PackagistTool.php`
- `src/Toolkit/ComposerToolkit.php`
- `src/Toolkit/PackagistToolkit.php`
- `tests/Unit/Toolkit/ComposerToolkitTest.php`
- `tests/Unit/Toolkit/PackagistToolkitTest.php`

**Interfaces produced:** the `composer` and `packagist` agent tools no longer exist. No new symbols.

- [ ] **Step 1: Create the branch and confirm clean tree**

```bash
git fetch origin
git checkout -b feat/composer-packagist-removal origin/feat/phase1-cuts
git status --short   # expect only: M .gitignore, D .vscode/settings.json
```

- [ ] **Step 2: Enumerate the reference set (work-list)**

```bash
grep -rn "ComposerToolkit\|PackagistToolkit\|ComposerTool\b\|PackagistTool\b" src/ tests/ config/source.json --include="*.php" --include="*.json"
```
Note: `PackageInfoTool` must NOT appear as a removal target — it stays.

- [ ] **Step 3: Excise references**

Edit `OrchestratorAgent.php` and `CoquiDefaults.php` per the list. For `BackgroundToolExecutor.php`, apply the conditional in the "Files — modify" note (edit if present, skip if already deleted).

- [ ] **Step 4: Delete the four source files + two tests**

```bash
git rm src/Tool/ComposerTool.php src/Tool/PackagistTool.php \
       src/Toolkit/ComposerToolkit.php src/Toolkit/PackagistToolkit.php \
       tests/Unit/Toolkit/ComposerToolkitTest.php tests/Unit/Toolkit/PackagistToolkitTest.php
```

- [ ] **Step 5: Run PHPStan and fix danglers**

Run: `composer analyse`
Expected: `[OK] No errors`. Fix any undefined `ComposerToolkit`/`PackagistToolkit`/`ComposerTool`/`PackagistTool` reference and repeat.

- [ ] **Step 6: Run the full suite**

Run: `composer test`
Expected: all green. `PackageInfoTool` tests (if any) must still pass — confirm it was not touched.

- [ ] **Step 7: Confirm PackageInfoTool and symfony/http-client are intact**

```bash
test -f src/Tool/PackageInfoTool.php && echo "PackageInfoTool present ✓"
grep -n "symfony/http-client" composer.json
```
Expected: file present; dependency still declared (unchanged).

- [ ] **Step 8: Final orphan grep (must be empty)**

```bash
grep -rn "ComposerToolkit\|PackagistToolkit\|new ComposerTool\|new PackagistTool\|'packagist'\|'composer'" src/ tests/ --include="*.php" | grep -viE "PackageInfoTool|composer\.json|composer install|composer require|/mods"
```
Expected: no toolkit references remain (mentions of the `composer` CLI in unrelated contexts are fine).

- [ ] **Step 9: Commit**

```bash
git add -u src/ tests/
git status --short   # verify .gitignore and .vscode/settings.json are NOT staged
git commit -m "$(cat <<'EOF'
refactor(toolkits): remove composer and packagist toolkits from core

Deletes the composer/packagist agent tools and their system-toolkit wiring.
Package management is now driven by humans/apps (API-first), not agent tools.
PackageInfoTool and symfony/http-client are unaffected. The /mods install path
does not use ComposerTool and is unchanged.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Docs and source map

**Files:**
- Modify: `docs/TOOLKITS.md`, `docs/FEATURES.md`, `README.md` (if referenced) — remove composer/packagist toolkit mentions.
- Modify: `config/source.json` — remove the `ComposerTool`, `PackagistTool`, `ComposerToolkit`, `PackagistToolkit` entries (~lines 1504–1515 for the toolkits, plus the tool entries).

- [ ] **Step 1: Remove composer/packagist toolkit references from docs**

```bash
grep -rnil "packagist\|composer toolkit\|ComposerToolkit\|PackagistToolkit" docs/ README.md
```
Edit each hit. Keep legitimate `composer test`/`composer install` command references.

- [ ] **Step 2: Prune `config/source.json`**

Remove the four entries (two tools + two toolkits).

- [ ] **Step 3: Verify**

```bash
composer test && composer analyse
grep -rn "ComposerToolkit\|PackagistToolkit" docs/ config/source.json
```
Expected: green; grep empty.

- [ ] **Step 4: Commit**

```bash
git add docs/ config/source.json README.md
git status --short
git commit -m "$(cat <<'EOF'
docs(toolkits): drop composer/packagist toolkit references

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

- **Spec coverage:** delete list ✓ (Task 1 Step 4), unwiring incl. the conditional `BackgroundToolExecutor` handling ✓ (Task 1 Step 3 + merge note), keep-list (`PackageInfoTool`, `symfony/http-client`, snapshots) ✓ (Global Constraints + Task 1 Step 7), docs + source.json ✓ (Task 2).
- **Placeholder scan:** none — exact files/commands throughout.
- **Cross-PR robustness:** the `BackgroundToolExecutor` edit is explicitly conditional with a merge-time resolution, so this plan works whether or not the Background PR has merged into the base.
- **Type consistency:** removals only; no new symbols.

**Handoff:** developed on `feat/composer-packagist-removal`; the user reviews and merges. Do not push or open the PR without confirmation.
