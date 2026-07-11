# Channels Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete the entire Channels feature from Coqui core — drivers, managers, API/REPL handlers, storage, config, and the session-channel schema coupling.

**Architecture:** Channels is a woven feature: consumers reference channel classes, and channel classes reference `CoquiDefaults` constants, forming a dependency cycle that cannot be half-removed while keeping both the runtime autoloader and PHPStan green. Therefore the core removal is **one atomic task** (delete files + excise references together, iterate to green), followed by a docs/source-map task. This is a pure removal — no new behavior — so the "test" at each boundary is: full Pest suite green + PHPStan clean + orphan-grep empty. One genuine behavior change: API session objects lose `channel`/`channel_bound` and `session_origin` can no longer be `'channel'` — that gets explicit test updates.

**Tech Stack:** PHP 8.4, Pest (`composer test`), PHPStan level 8 (`composer analyse`), SQLite (`SessionStorage`).

**Spec:** `docs/superpowers/specs/2026-07-10-phase1-cuts-design.md` (Item 1).

## Global Constraints

- PHP 8.4, `declare(strict_types=1);`, `final` by default, one class per file, 4-space indent.
- Branch off `origin/feat/phase1-cuts` (= `main` + the batch spec/plans): `git fetch origin && git checkout -b feat/channels-removal origin/feat/phase1-cuts`. (Base carries no code changes over main — only the planning docs — so your PR diff is your removal plus these docs.)
- **Never `git add -A` or `git add .`** — the working tree has two intentional unstaged edits (`.gitignore` modified, `.vscode/settings.json` deleted) that MUST stay unstaged. Stage only exact paths.
- Every commit message ends with: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- Validation commands: `composer test` (Pest), `composer analyse` (PHPStan L8). Both must be green before every commit.
- **Merge order:** this is PR 1 of 3 (Channels → Background → Composer/Packagist). It touches `CoquiDefaults.php` (the `CHANNEL_*` constants — a different region from the other PRs). Land first if possible.
- Update `config/source.json` whenever source structure changes (this plan removes many entries).

---

### Task 1: Remove all Channels code and references (atomic)

**Files — delete (source):**
- `src/Api/ChannelExecutionManager.php`
- `src/Api/ChannelManager.php`
- `src/Api/Handler/ChannelHandler.php`
- `src/Channel/` — entire directory (`ChannelConfig.php`, `ChannelConfigurationEditor.php`, `ChannelDiscovery.php`, `Builtin/DiscordChannelDriver.php`, `Builtin/TelegramChannelDriver.php`, `Builtin/SignalChannelDriver.php`, `Builtin/SignalCliChannelRuntime.php`, `Builtin/PlaceholderChannelRuntime.php`)
- `src/Contract/ChannelDriverInterface.php`
- `src/Contract/ChannelRuntimeInterface.php`
- `src/Repl/Handler/ChannelHandler.php`
- `src/Storage/ChannelStore.php` (creator of the `session_channel` table)

**Files — delete (tests):**
- `tests/Support/Channel/TestExternalChannelDriver.php`
- `tests/Unit/Api/ChannelExecutionManagerTest.php`
- `tests/Unit/Api/ChannelManagerTest.php`
- `tests/Unit/Api/Handler/ChannelHandlerTest.php`
- `tests/Unit/Channel/ChannelDiscoveryTest.php`
- `tests/Unit/Channel/SignalChannelDriverTest.php`
- `tests/Unit/Repl/Handler/ChannelHandlerTest.php`
- `tests/Unit/Storage/ChannelStoreTest.php`

**Files — modify (excise references):**
- `src/Repl/ReplCommandCatalog.php` — remove the `/channels` `ReplCommandSpec` (line ~47).
- `src/Repl/SlashCommandRouter.php` — remove the `/channels` route/case and `ChannelHandler` wiring.
- `src/Repl/TabCompletion.php` — remove `'/channels' => …` (line ~93), the `completeChannels()` method, and the `commandSpec('/channels')` use (line ~239).
- `src/Api/Handler/ServerHandler.php` — remove the `channelManager` constructor param and the `$data['channels'] = [...]` block (line ~78).
- `src/Api/Handler/HealthHandler.php` — remove the `channelManager` param and the `'channels' => …` summary + `$data['channels']` (lines ~54, 74).
- `src/Api/Handler/ConfigHandler.php` — remove `'channels' => $config->get('channels')` (line ~115).
- `src/Api/Handler/SessionHandler.php` — remove channel fields from the session response.
- `src/Api/Webhook/WebhookDispatchService.php` — remove `'channel'` from the placeholder-path array (line ~87).
- `src/Command/RunCommand.php` — remove channel references.
- `src/Command/ApiCommand.php` — remove channel manager wiring passed to handlers (`ChannelManager`, `ChannelExecutionManager`) and any channel route registration.
- `src/Config/BootManager.php` — remove the `ChannelDiscovery` import + field, the `channelDiscovery()` accessor, and `discoverChannels()` + its call (line ~115).
- `src/Config/OpenClawConfig.php` — remove `getChannelConfig()` (line ~331).
- `src/Config/ConfigValidator.php` — remove `validateChannels()` (line ~365) and its call site (line ~42).
- `src/Contract/CoquiDefaults.php` — remove the `CHANNEL_*` constants (`CHANNEL_UNKNOWN_USER_POLICY`, `CHANNEL_EXECUTION_POLICY`, `CHANNEL_INBOUND_RATE_LIMIT`, `CHANNEL_OUTBOUND_CONCURRENCY`, `CHANNEL_HEALTH_CHECK_INTERVAL_SECONDS`).
- `src/Storage/SessionStorage.php` — remove the `session_channel` join (`sessionChannelJoin`), the `sc.channel_*` columns from session SELECTs (lines ~530, 620), and the channel row-hydration (lines ~1608–1615: `channel_bound`, `channel`, `session_origin='channel'`).
- `src/Support/ProfileSessionLifecycleManager.php` — remove the `channel_bound` filter (line ~37).
- `src/Support/InteractiveSessionService.php` — remove channel references.

**Interfaces produced:** API session objects no longer contain `channel` or `channel_bound`; `session_origin` is never `'channel'`. No new symbols introduced.

- [ ] **Step 1: Create the branch and confirm clean tree**

```bash
git fetch origin
git checkout -b feat/channels-removal origin/feat/phase1-cuts
git status --short   # expect only: M .gitignore, D .vscode/settings.json
```

- [ ] **Step 2: Enumerate the full reference set (work-list)**

```bash
grep -rn "Channel" src/ --include="*.php" | grep -v "src/Channel/" | grep -viE "src/(Api/Channel|Api/Handler/Channel|Repl/Handler/Channel|Storage/ChannelStore|Contract/Channel)"
grep -rn "channel" src/ config/ --include="*.php" -i | grep -viE "test"
```
Keep this list open; every hit must end up removed or explained (e.g. an unrelated word).

- [ ] **Step 3: Delete the channel source and test files**

```bash
git rm src/Api/ChannelExecutionManager.php src/Api/ChannelManager.php src/Api/Handler/ChannelHandler.php \
       src/Contract/ChannelDriverInterface.php src/Contract/ChannelRuntimeInterface.php \
       src/Repl/Handler/ChannelHandler.php src/Storage/ChannelStore.php
git rm -r src/Channel
git rm tests/Support/Channel/TestExternalChannelDriver.php \
       tests/Unit/Api/ChannelExecutionManagerTest.php tests/Unit/Api/ChannelManagerTest.php \
       tests/Unit/Api/Handler/ChannelHandlerTest.php tests/Unit/Channel/ChannelDiscoveryTest.php \
       tests/Unit/Channel/SignalChannelDriverTest.php tests/Unit/Repl/Handler/ChannelHandlerTest.php \
       tests/Unit/Storage/ChannelStoreTest.php
```

- [ ] **Step 4: Excise every reference listed in "Files — modify" above**

Work through each file, removing channel constructor params, wiring, config methods, constants, and the SessionStorage join + row-hydration. Use the Step 2 work-list to confirm none are missed.

- [ ] **Step 5: Run PHPStan and fix every dangling reference**

Run: `composer analyse`
Expected: `[OK] No errors`. If PHPStan reports an undefined class/constant/method, it is a missed reference — remove it. Repeat until clean.

- [ ] **Step 6: Update the session-shape tests to assert channels are gone**

In `tests/Unit/Storage/SessionStorageTest.php` and `tests/Unit/Api/Handler/SessionHandlerTest.php`, remove assertions that expect `channel`/`channel_bound`/`session_origin === 'channel'`, and add assertions that these keys are absent:

```php
expect($session)->not->toHaveKey('channel');
expect($session)->not->toHaveKey('channel_bound');
expect($session['session_origin'] ?? null)->not->toBe('channel');
```

- [ ] **Step 7: Run the full suite and fix failures**

Run: `composer test`
Expected: all green. Fix any remaining channel-coupled test setup (e.g. fixtures that inserted `session_channel` rows).

- [ ] **Step 8: Final orphan grep (must be empty)**

Run:
```bash
grep -rniE "channelmanager|channeldiscovery|channelstore|channeldriver|channelruntime|getchannelconfig|validatechannels|session_channel|channel_bound" src/ tests/
```
Expected: no output (an unrelated substring match is acceptable only if clearly not channels).

- [ ] **Step 9: Commit**

```bash
git add -u src/ tests/
git status --short   # verify .gitignore and .vscode/settings.json are NOT staged
git commit -m "$(cat <<'EOF'
refactor(channels): remove the Channels feature from core

Deletes all channel drivers, managers, API/REPL handlers, storage, config
surface, and the session_channel schema coupling. API session objects no
longer expose channel/channel_bound; session_origin is never 'channel'.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Docs, config map, and openclaw.json note

**Files:**
- Delete: `docs/CHANNELS.md`
- Modify: `docs/API.md` (remove channel routes; add a note that session objects dropped `channel`/`channel_bound`), `docs/COMMANDS.md` (remove `/channels`), `docs/FEATURES.md` (remove channels), `README.md` (if it mentions channels), `docs/CONFIGURATION.md` (note the `channels` block is now ignored)
- Modify: `config/source.json` (remove all channel entries)

- [ ] **Step 1: Remove the channels doc and references**

```bash
git rm docs/CHANNELS.md
grep -rnil "channel" docs/ README.md
```
Edit each remaining hit to remove channel content. In `docs/API.md`, add a short changelog line: "Session objects no longer include `channel`/`channel_bound`; `session_origin` is never `channel`."

- [ ] **Step 2: Prune `config/source.json`**

Remove every channel file entry (search the file for `Channel`).

- [ ] **Step 3: Verify docs/source map have no stale channel references**

Run: `grep -rniE "channel" docs/ config/source.json README.md`
Expected: no channel-feature references remain (unrelated matches acceptable).

- [ ] **Step 4: Commit**

```bash
git add docs/ config/source.json README.md
git status --short
git commit -m "$(cat <<'EOF'
docs(channels): remove channel docs and source-map entries

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Self-Review

- **Spec coverage:** Item 1 file-delete list ✓ (Task 1 Step 3), reference-excise list ✓ (Task 1 Step 4), session-shape change ✓ (Task 1 Step 6), docs + source.json ✓ (Task 2). openclaw.json note ✓ (Task 2 Step 1).
- **Placeholder scan:** none — every step names exact files/commands.
- **Behavior change isolated:** only the session-shape change is a real API change and it has explicit test updates + an API.md note.
- **Green boundaries:** Task 1 is atomic by necessity (dependency cycle); it ends green. Task 2 is docs-only.

**Handoff:** developed on `feat/channels-removal`; the user reviews and merges (self-approval blocked). Do not push or open the PR without confirmation.
