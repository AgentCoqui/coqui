# MCP Client as Core Behavior — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the MCP client engine + runtime service into Coqui core so MCP works out of the box, and reduce `coquibot/coqui-toolkit-mcp-client` to an optional, thin management-UX package (the `mcp` agent tool + `/mcp` REPL).

**Architecture:** Relocate the engine, config, orchestration, and runtime/management service from the toolkit into `CoquiBot\Coqui\Mcp\*` (`src/Mcp/`). Core builds a per-context `McpRuntime` facade shared by three consumers: agent exposure (per-server tools, deferred), the HTTP API (full CRUD), and the optional toolkit (via `$context['mcp_runtime']`). The toolkit keeps only the `mcp` tool, `/mcp` REPL, formatter, tokenizer, and OAuth (the latter plugged into core via a small interface).

**Tech Stack:** PHP 8.4 (strict types, `final` by default), Pest (tests), PHPStan (static analysis), Composer, `carmelosantana/php-agents` (agent loop + `ToolkitInterface`, `ToolInterface`, `Parameter`).

## Global Constraints

- PHP 8.4; `declare(strict_types=1);` in every file; `final` by default; one class per file; 4-space indent; constructor injection.
- Core namespace: `CoquiBot\Coqui\` → `src/`. Tests: `CoquiBot\Coqui\Tests\` → `tests/`.
- Toolkit namespace stays `CoquiBot\Toolkits\Mcp\` → `src/`. Toolkit keeps **no** composer dependency on `coquibot/coqui` (uses stubs for core types).
- Preserve on-disk formats/paths byte-for-byte: `.workspace/mcp.json`, `.workspace/toolkit-loading.json`, `.workspace/.mcp-tokens/{server}.json`.
- Preserve config keys `agents.defaults.mcp.allowedStdioCommands` / `deniedStdioCommands`, `ConfigValidator`, and `ConfigGuard` (agent cannot edit stdio policy).
- Preserve `McpServerPolicy` enforcement, audit logging, stdio sandboxing.
- Commit messages end with: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- Definition of done: `composer test` and `composer analyse` clean in **both** repos.
- Git identity + remotes come from the **powerbank** skill — invoke it in Task 1 and Task 14; do not hardcode identity or remotes.

## Naming reference (used across tasks)

Core relocations — source (toolkit) → destination (core), namespace `CoquiBot\Toolkits\Mcp\X` → `CoquiBot\Coqui\Mcp\X`:

| Toolkit source | Core destination |
|---|---|
| `src/McpClient.php` | `src/Mcp/McpClient.php` |
| `src/Transport/TransportInterface.php` | `src/Mcp/Transport/TransportInterface.php` |
| `src/Transport/StdioTransport.php` | `src/Mcp/Transport/StdioTransport.php` |
| `src/JsonRpc/Message.php` | `src/Mcp/JsonRpc/Message.php` |
| `src/JsonRpc/JsonRpcError.php` | `src/Mcp/JsonRpc/JsonRpcError.php` |
| `src/JsonRpc/IdGenerator.php` | `src/Mcp/JsonRpc/IdGenerator.php` |
| `src/Schema/SchemaConverter.php` | `src/Mcp/Schema/SchemaConverter.php` |
| `src/Exception/McpConnectionException.php` | `src/Mcp/Exception/McpConnectionException.php` |
| `src/Exception/McpProtocolException.php` | `src/Mcp/Exception/McpProtocolException.php` |
| `src/Exception/McpToolCallException.php` | `src/Mcp/Exception/McpToolCallException.php` |
| `src/Config/McpConfig.php` | `src/Mcp/Config/McpConfig.php` |
| `src/Config/EnvResolver.php` | `src/Mcp/Config/EnvResolver.php` |
| `src/Support/McpServerPolicy.php` | `src/Mcp/Support/McpServerPolicy.php` |
| `src/Support/ServerLoadingModeStore.php` | `src/Mcp/Support/ServerLoadingModeStore.php` |
| `src/McpServerManager.php` | `src/Mcp/McpServerManager.php` |
| `src/McpManagementService.php` | `src/Mcp/McpManagementService.php` |
| `src/McpServerToolkit.php` | `src/Mcp/McpServerToolkit.php` |

Stays in toolkit (namespace unchanged): `McpToolkit`, `Command/McpCommandHandler`, `Support/McpManagementFormatter`, `Support/ArgumentTokenizer`, `Auth/OAuthHandler`, `Auth/OAuthException`.

New core files: `src/Mcp/McpRuntime.php`, `src/Contract/McpOAuthInterface.php`.

**Design decision flagged for review (Task 5/11): OAuth placement.** The approved spec preview kept OAuth in the toolkit, but `McpManagementService` (core) constructs an `OAuthHandler` and exposes `authorizeServer()`. To honor "OAuth in the toolkit" without a core→toolkit dependency, this plan introduces `McpOAuthInterface` in core; the core service takes a **nullable** `?McpOAuthInterface` and `authorizeServer()` throws a clear "OAuth requires the management toolkit" error when it is `null`. The toolkit's `OAuthHandler` implements the interface and registers itself into the runtime via context. If the reviewer prefers simplicity, the fallback is moving `OAuthHandler` into core (then `/auth` works without the toolkit) — call this out before starting Task 5.

---

### Task 1: Git baseline, identity, and green starting point

**Files:**
- Create: `/home/carmelo/Projects/CoquiBot/Core/coqui/.gitignore` (only if absent)
- Create: `/home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client/.gitignore` (only if absent)

Both working copies are currently **not** git repositories. This task establishes version control (goal 3, part 1) and a green baseline before any code moves.

- [ ] **Step 1: Get git identity + intended remotes from powerbank**

Invoke the **powerbank** skill and ask for: the correct git author name/email for the `coquibot` repos, and the GitHub remote URLs for `coquibot/coqui` and `coquibot/coqui-toolkit-mcp-client`. Record them for Steps 2 and Task 14. Do not guess identity/remotes.

- [ ] **Step 2: Confirm current test + analyse state in the toolkit (pre-move baseline)**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client && composer install && composer test && vendor/bin/phpstan analyse`
Expected: PASS (record any pre-existing failures; they are not introduced by this work).

- [ ] **Step 3: Confirm current test + analyse state in core**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer install && composer test && composer analyse`
Expected: PASS.

- [ ] **Step 4: Initialize git in both repos**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui && git init && git symbolic-ref HEAD refs/heads/main
cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client && git init && git symbolic-ref HEAD refs/heads/main
```
Set identity locally in each repo using the values from Step 1:
```bash
git -C /home/carmelo/Projects/CoquiBot/Core/coqui config user.name "<from powerbank>"
git -C /home/carmelo/Projects/CoquiBot/Core/coqui config user.email "<from powerbank>"
git -C /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client config user.name "<from powerbank>"
git -C /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client config user.email "<from powerbank>"
```

- [ ] **Step 5: Ensure vendor/ and workspace artifacts are ignored**

If `.gitignore` is missing in either repo, create it with at least:
```
/vendor/
/.workspace/
composer.lock
```
(If a `.gitignore` already exists, leave it; do not remove existing entries.)

- [ ] **Step 6: Commit the baseline (includes the design spec + this plan)**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
git add -A
git commit -m "chore: initialize git repo and record MCP core-migration spec + plan

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client
git add -A
git commit -m "chore: initialize git repo (pre-migration baseline)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```
(Remotes are added in Task 14, after the code changes are verified.)

---

### Task 2: Relocate the protocol engine into core

**Files:**
- Create: `src/Mcp/McpClient.php`, `src/Mcp/Transport/TransportInterface.php`, `src/Mcp/Transport/StdioTransport.php`, `src/Mcp/JsonRpc/Message.php`, `src/Mcp/JsonRpc/JsonRpcError.php`, `src/Mcp/JsonRpc/IdGenerator.php`, `src/Mcp/Schema/SchemaConverter.php`, `src/Mcp/Exception/McpConnectionException.php`, `src/Mcp/Exception/McpProtocolException.php`, `src/Mcp/Exception/McpToolCallException.php`
- Test: `tests/Unit/Mcp/JsonRpcMessageTest.php`, `tests/Unit/Mcp/SchemaConverterTest.php`, `tests/Unit/Mcp/ExceptionTest.php`

**Interfaces:**
- Produces: `CoquiBot\Coqui\Mcp\McpClient` with `connect(string,array,array):void`, `listTools():array`, `callTool(string,array):array`, `disconnect():void`, `isConnected():bool`, `invalidateToolCache():void`, `serverName():?string`, `serverVersion():?string`, `serverInstructions():?string`, `serverCapabilities():array`. `CoquiBot\Coqui\Mcp\Transport\TransportInterface` + `StdioTransport(int $timeout = 30)`. `CoquiBot\Coqui\Mcp\JsonRpc\Message` (readonly, static `request`/`notification`/`fromJson`, `toJson`). `CoquiBot\Coqui\Mcp\Schema\SchemaConverter::convert(array):array` (returns `CarmeloSantana\PHPAgents\Tool\Parameter\Parameter[]`).

- [ ] **Step 1: Copy the engine files verbatim to their core destinations**

Use the Naming reference table. For each source file, create the destination file with identical body. Example:
```bash
cd /home/carmelo/Projects/CoquiBot/Core
mkdir -p coqui/src/Mcp/Transport coqui/src/Mcp/JsonRpc coqui/src/Mcp/Schema coqui/src/Mcp/Exception
cp coqui-toolkit-mcp-client/src/McpClient.php coqui/src/Mcp/McpClient.php
cp coqui-toolkit-mcp-client/src/Transport/TransportInterface.php coqui/src/Mcp/Transport/TransportInterface.php
cp coqui-toolkit-mcp-client/src/Transport/StdioTransport.php coqui/src/Mcp/Transport/StdioTransport.php
cp coqui-toolkit-mcp-client/src/JsonRpc/Message.php coqui/src/Mcp/JsonRpc/Message.php
cp coqui-toolkit-mcp-client/src/JsonRpc/JsonRpcError.php coqui/src/Mcp/JsonRpc/JsonRpcError.php
cp coqui-toolkit-mcp-client/src/JsonRpc/IdGenerator.php coqui/src/Mcp/JsonRpc/IdGenerator.php
cp coqui-toolkit-mcp-client/src/Schema/SchemaConverter.php coqui/src/Mcp/Schema/SchemaConverter.php
cp coqui-toolkit-mcp-client/src/Exception/McpConnectionException.php coqui/src/Mcp/Exception/McpConnectionException.php
cp coqui-toolkit-mcp-client/src/Exception/McpProtocolException.php coqui/src/Mcp/Exception/McpProtocolException.php
cp coqui-toolkit-mcp-client/src/Exception/McpToolCallException.php coqui/src/Mcp/Exception/McpToolCallException.php
```
(Do NOT delete the toolkit originals yet — deletion happens in Task 11 so the toolkit stays green until then.)

- [ ] **Step 2: Rewrite the namespaces in the copied core files**

In each copied file under `coqui/src/Mcp/`, replace the namespace prefix `CoquiBot\Toolkits\Mcp` with `CoquiBot\Coqui\Mcp` — in the `namespace` declaration AND every `use` statement referencing a sibling MCP class.
```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
grep -rl 'CoquiBot\\Toolkits\\Mcp' src/Mcp | xargs sed -i 's/CoquiBot\\Toolkits\\Mcp/CoquiBot\\Coqui\\Mcp/g'
```
Verify no stragglers: `grep -rn 'Toolkits\\Mcp' src/Mcp` → expect no output.

- [ ] **Step 3: Port the pure-unit tests for these classes**

Copy the toolkit's engine tests to `tests/Unit/Mcp/` and re-namespace their `use` imports to `CoquiBot\Coqui\Mcp\*`:
```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
mkdir -p tests/Unit/Mcp
cp ../coqui-toolkit-mcp-client/tests/Unit/JsonRpcMessageTest.php tests/Unit/Mcp/JsonRpcMessageTest.php
cp ../coqui-toolkit-mcp-client/tests/Unit/SchemaConverterTest.php tests/Unit/Mcp/SchemaConverterTest.php
cp ../coqui-toolkit-mcp-client/tests/Unit/ExceptionTest.php tests/Unit/Mcp/ExceptionTest.php
grep -rl 'CoquiBot\\Toolkits\\Mcp' tests/Unit/Mcp | xargs sed -i 's/CoquiBot\\Toolkits\\Mcp/CoquiBot\\Coqui\\Mcp/g'
```
If the toolkit test namespace differs (e.g. `CoquiBot\Toolkits\Mcp\Tests`), also rewrite the test file's own `namespace` line to `CoquiBot\Coqui\Tests\Unit\Mcp` (or remove it if the core suite uses namespaceless Pest tests — match the convention in `tests/Unit/` of core).

- [ ] **Step 4: Regenerate the autoloader and run the ported tests**

Run:
```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
composer dump-autoload
vendor/bin/pest tests/Unit/Mcp/JsonRpcMessageTest.php tests/Unit/Mcp/SchemaConverterTest.php tests/Unit/Mcp/ExceptionTest.php
```
Expected: PASS. If a test references a toolkit-only helper, re-point it at the core class.

- [ ] **Step 5: Commit**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
git add src/Mcp tests/Unit/Mcp
git commit -m "feat(mcp): relocate MCP protocol engine into core (src/Mcp)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Relocate config, policy, and loading-mode store into core

**Files:**
- Create: `src/Mcp/Config/McpConfig.php`, `src/Mcp/Config/EnvResolver.php`, `src/Mcp/Support/McpServerPolicy.php`, `src/Mcp/Support/ServerLoadingModeStore.php`
- Test: `tests/Unit/Mcp/McpConfigTest.php`, `tests/Unit/Mcp/EnvResolverTest.php`, `tests/Unit/Mcp/McpServerPolicyTest.php`, `tests/Unit/Mcp/ServerLoadingModeStoreTest.php`

**Interfaces:**
- Produces: `CoquiBot\Coqui\Mcp\Config\McpConfig(string $workspacePath)` with the full CRUD surface (`load`, `save`, `addServer`, `renameServer`, `removeServer`, `getServer`, `listServers`, `listEnabledServers`, `setServerEnv`, `enableServer`, `disableServer`, `getCommand`/`getArgs`/`getEnv`/`getDescription`, `isDisabled`, `configPath`). `EnvResolver::resolve(array):array{resolved,unresolved}`, `findMissing(array):array`. `Support\McpServerPolicy(array $allowed = [], array $denied = [])` + static `fromConfigValues(mixed,mixed):self`, `assertAllowedStdioCommand`, `validateStdioCommand`. `Support\ServerLoadingModeStore(string $workspacePath)` reading/writing `.workspace/toolkit-loading.json`, keys `McpServer:{name}`.

- [ ] **Step 1: Copy the files to core and re-namespace**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
mkdir -p src/Mcp/Config src/Mcp/Support
cp ../coqui-toolkit-mcp-client/src/Config/McpConfig.php src/Mcp/Config/McpConfig.php
cp ../coqui-toolkit-mcp-client/src/Config/EnvResolver.php src/Mcp/Config/EnvResolver.php
cp ../coqui-toolkit-mcp-client/src/Support/McpServerPolicy.php src/Mcp/Support/McpServerPolicy.php
cp ../coqui-toolkit-mcp-client/src/Support/ServerLoadingModeStore.php src/Mcp/Support/ServerLoadingModeStore.php
grep -rl 'CoquiBot\\Toolkits\\Mcp' src/Mcp/Config src/Mcp/Support | xargs sed -i 's/CoquiBot\\Toolkits\\Mcp/CoquiBot\\Coqui\\Mcp/g'
```

- [ ] **Step 2: Verify the loading-mode filename is preserved**

Open `src/Mcp/Support/ServerLoadingModeStore.php` and confirm the on-disk path resolves to `<workspace>/.workspace/toolkit-loading.json` (per the class inventory). Do not change it. This is the global-constraint "preserve paths" check.

- [ ] **Step 3: Port the tests and re-namespace**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
cp ../coqui-toolkit-mcp-client/tests/Unit/McpConfigTest.php tests/Unit/Mcp/McpConfigTest.php
cp ../coqui-toolkit-mcp-client/tests/Unit/EnvResolverTest.php tests/Unit/Mcp/EnvResolverTest.php
cp ../coqui-toolkit-mcp-client/tests/Unit/McpManagementServicePolicyTest.php tests/Unit/Mcp/McpServerPolicyTest.php
cp ../coqui-toolkit-mcp-client/tests/Unit/McpManagementServiceLoadingModeTest.php tests/Unit/Mcp/ServerLoadingModeStoreTest.php
grep -rl 'CoquiBot\\Toolkits\\Mcp' tests/Unit/Mcp | xargs sed -i 's/CoquiBot\\Toolkits\\Mcp/CoquiBot\\Coqui\\Mcp/g'
```
Note: the policy/loading-mode tests may exercise `McpManagementService` (relocated in Task 5). If a copied test references it, mark those cases with a `->skip('moves with McpManagementService in Task 5')` for now and restore in Task 5, OR defer copying those two files to Task 5. Prefer whichever keeps this task's suite green; keep the pure `McpConfig`/`EnvResolver` tests here.

- [ ] **Step 4: Run the config/policy tests**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer dump-autoload && vendor/bin/pest tests/Unit/Mcp/McpConfigTest.php tests/Unit/Mcp/EnvResolverTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Mcp/Config src/Mcp/Support tests/Unit/Mcp
git commit -m "feat(mcp): relocate config, policy, and loading-mode store into core

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: Relocate McpServerManager into core

**Files:**
- Create: `src/Mcp/McpServerManager.php`

**Interfaces:**
- Consumes: core `McpConfig`, `McpClient`, `StdioTransport`, `SchemaConverter`, exceptions (Tasks 2-3).
- Produces: `CoquiBot\Coqui\Mcp\McpServerManager(McpConfig $config, int $timeout = 30)` with `connectAll`, `connectServer`, `disconnectServer`, `disconnectAll`, `getTools():list<ToolInterface>`, `getToolsForServer`, `callTool(string,array):array`, `getServerStatus`, `getAllStatus`, `getServerInstructions`, `connectedServerNames`, `getServerToolDefs`, `errors`, `refreshServer`.

- [ ] **Step 1: Copy + re-namespace**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
cp ../coqui-toolkit-mcp-client/src/McpServerManager.php src/Mcp/McpServerManager.php
sed -i 's/CoquiBot\\Toolkits\\Mcp/CoquiBot\\Coqui\\Mcp/g' src/Mcp/McpServerManager.php
grep -n 'Toolkits\\Mcp' src/Mcp/McpServerManager.php   # expect no output
```

- [ ] **Step 2: Sanity-check it autoloads and analyses**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer dump-autoload && vendor/bin/phpstan analyse src/Mcp/McpServerManager.php --level=max --no-progress`
Expected: no errors about missing `CoquiBot\Coqui\Mcp\*` symbols. (Config-level from `phpstan.neon` will be applied by `composer analyse`; this targeted run just checks references resolve.)

- [ ] **Step 3: Commit**

```bash
git add src/Mcp/McpServerManager.php
git commit -m "feat(mcp): relocate McpServerManager into core

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: Add `McpOAuthInterface` and relocate `McpManagementService` (OAuth-decoupled)

**Files:**
- Create: `src/Contract/McpOAuthInterface.php`
- Create: `src/Mcp/McpManagementService.php`
- Test: `tests/Unit/Mcp/McpManagementServiceTest.php` (CRUD/status/loading-mode paths; not OAuth)

**Interfaces:**
- Produces: `CoquiBot\Coqui\Contract\McpOAuthInterface` with `authorize(string $serverName, array $authConfig): array`, `getAccessToken(string $serverName, array $authConfig): ?string`, `hasTokens(string $serverName): bool`, `clearTokens(string $serverName): void`.
- Produces: `CoquiBot\Coqui\Mcp\McpManagementService(McpConfig $config, McpServerManager $manager, ?McpOAuthInterface $oauth = null, ?ServerLoadingModeStore $loadingStore = null, ?McpServerPolicy $policy = null)` — every method from the class inventory. `authorizeServer(...)` throws `\RuntimeException('OAuth requires the management toolkit ...')` when `$oauth === null`.

- [ ] **Step 1: Write the OAuth interface**

Create `src/Contract/McpOAuthInterface.php`:
```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Pluggable OAuth provider for MCP servers.
 *
 * Core ships no OAuth implementation. The optional MCP management toolkit
 * provides one and registers it into the shared {@see \CoquiBot\Coqui\Mcp\McpRuntime}.
 */
interface McpOAuthInterface
{
    /**
     * @param array{authUrl?: string, tokenUrl?: string, clientId?: string, scopes?: list<string>} $authConfig
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    public function authorize(string $serverName, array $authConfig): array;

    /**
     * @param array{tokenUrl?: string, clientId?: string} $authConfig
     */
    public function getAccessToken(string $serverName, array $authConfig): ?string;

    public function hasTokens(string $serverName): bool;

    public function clearTokens(string $serverName): void;
}
```

- [ ] **Step 2: Copy `McpManagementService` and re-namespace**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
cp ../coqui-toolkit-mcp-client/src/McpManagementService.php src/Mcp/McpManagementService.php
sed -i 's/CoquiBot\\Toolkits\\Mcp/CoquiBot\\Coqui\\Mcp/g' src/Mcp/McpManagementService.php
```

- [ ] **Step 3: Swap the OAuth dependency to the interface**

Edit `src/Mcp/McpManagementService.php`:
- Add `use CoquiBot\Coqui\Contract\McpOAuthInterface;`.
- Change the constructor parameter from the concrete `OAuthHandler $oauthHandler` to `?McpOAuthInterface $oauth = null` (reorder so all remaining params keep working; match the Produces signature above).
- In `authorizeServer(...)`, before using the handler:
```php
if ($this->oauth === null) {
    throw new \RuntimeException(
        'OAuth requires the management toolkit (coquibot/coqui-toolkit-mcp-client). Install it to authorize MCP servers.'
    );
}
```
- Replace remaining `$this->oauthHandler->` calls with `$this->oauth->`.
- Remove the now-unused `use CoquiBot\...\Auth\OAuthHandler;` import.

- [ ] **Step 4: Write the failing test for the OAuth-absent guard**

Create `tests/Unit/Mcp/McpManagementServiceTest.php` (match core Pest conventions in `tests/Unit/`):
```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\Config\McpConfig;
use CoquiBot\Coqui\Mcp\McpManagementService;
use CoquiBot\Coqui\Mcp\McpServerManager;

it('throws a clear error when authorizing without an OAuth provider', function (): void {
    $workspace = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    $config = new McpConfig($workspace);
    $manager = new McpServerManager($config);
    $service = new McpManagementService($config, $manager, oauth: null);

    $service->authorizeServer('github', 'https://auth', 'https://token');
})->throws(RuntimeException::class, 'OAuth requires the management toolkit');
```

- [ ] **Step 5: Run it and confirm it passes**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer dump-autoload && vendor/bin/pest tests/Unit/Mcp/McpManagementServiceTest.php`
Expected: PASS.

- [ ] **Step 6: Restore the relocated policy/loading-mode service tests (from Task 3 Step 3)**

If you deferred `McpManagementServicePolicyTest`/`McpManagementServiceLoadingModeTest`, port them now to `tests/Unit/Mcp/`, re-namespace to `CoquiBot\Coqui\Mcp\*`, update any `new McpManagementService(...)` calls to the new constructor signature (`oauth: null`), and run them green.

Run: `vendor/bin/pest tests/Unit/Mcp/`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Contract/McpOAuthInterface.php src/Mcp/McpManagementService.php tests/Unit/Mcp
git commit -m "feat(mcp): relocate McpManagementService to core, decouple OAuth via interface

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: Relocate `McpServerToolkit` into core

**Files:**
- Create: `src/Mcp/McpServerToolkit.php`
- Test: `tests/Unit/Mcp/McpServerToolkitTest.php`

**Interfaces:**
- Consumes: core `McpManagementService` (Task 5), `CoquiBot\Coqui\Contract\ToolkitLoadingKeyProvider` (already core).
- Produces: `CoquiBot\Coqui\Mcp\McpServerToolkit(string $serverName, McpManagementService $service)` implementing `ToolkitInterface` + `ToolkitLoadingKeyProvider`; `tools():list<ToolInterface>`, `guidelines():string`, `toolkitLoadingKey():string`, static `loadingKeyForServer(string):string`.

- [ ] **Step 1: Copy + re-namespace**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
cp ../coqui-toolkit-mcp-client/src/McpServerToolkit.php src/Mcp/McpServerToolkit.php
sed -i 's/CoquiBot\\Toolkits\\Mcp/CoquiBot\\Coqui\\Mcp/g' src/Mcp/McpServerToolkit.php
```
Confirm its `use CoquiBot\Coqui\Contract\ToolkitLoadingKeyProvider;` line is unchanged (it already points at a core contract).

- [ ] **Step 2: Write a test for the loading key + tool listing**

Create `tests/Unit/Mcp/McpServerToolkitTest.php`:
```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\Config\McpConfig;
use CoquiBot\Coqui\Mcp\McpManagementService;
use CoquiBot\Coqui\Mcp\McpServerManager;
use CoquiBot\Coqui\Mcp\McpServerToolkit;

it('derives a stable loading key from the server name', function (): void {
    expect(McpServerToolkit::loadingKeyForServer('GitHub'))->toBe('McpServer:github');
});

it('exposes the server toolkit loading key via the instance', function (): void {
    $workspace = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    $config = new McpConfig($workspace);
    $service = new McpManagementService($config, new McpServerManager($config));
    $toolkit = new McpServerToolkit('github', $service);

    expect($toolkit->toolkitLoadingKey())->toBe('McpServer:github');
});
```

- [ ] **Step 3: Run it**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer dump-autoload && vendor/bin/pest tests/Unit/Mcp/McpServerToolkitTest.php`
Expected: PASS. (If the sanitizer differs, adjust the expected key to match `McpServerToolkit::loadingKeyForServer` behavior in the source.)

- [ ] **Step 4: Commit**

```bash
git add src/Mcp/McpServerToolkit.php tests/Unit/Mcp/McpServerToolkitTest.php
git commit -m "feat(mcp): relocate McpServerToolkit (per-server tool exposer) into core

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 7: Create the `McpRuntime` facade

**Files:**
- Create: `src/Mcp/McpRuntime.php`
- Test: `tests/Unit/Mcp/McpRuntimeTest.php`

**Interfaces:**
- Consumes: core `McpConfig`, `McpServerManager`, `McpManagementService`, `ServerLoadingModeStore`, `McpServerPolicy`, `McpServerToolkit`, `McpOAuthInterface`, and `CarmeloSantana\PHPAgents\Contract\ConfigInterface` (or Coqui's `ConfigInterface` — match what `McpServerPolicy::fromConfigValues` expects; it takes raw values via `$config->get(...)`).
- Produces: `CoquiBot\Coqui\Mcp\McpRuntime`:
  - `static fromWorkspace(string $workspacePath, ?callable $configGet = null): self` — `$configGet` returns a config value by dotted key (used for policy); pass `fn($k) => $config->get($k)` from callers, or `null` for no policy.
  - `managementService(): McpManagementService`
  - `manager(): McpServerManager`
  - `config(): McpConfig`
  - `connectEnabled(): void` — loads config and connects enabled servers (mirrors ApiCommand boot behavior)
  - `serverToolkits(): list<McpServerToolkit>` — one per enabled server (uses `McpConfig::listEnabledServers()`), for orchestrator exposure
  - `registerOAuth(McpOAuthInterface $oauth): void` — sets the OAuth provider on the management service (rebuild/settable; see Step 1)

- [ ] **Step 1: Write the runtime facade**

Create `src/Mcp/McpRuntime.php`:
```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp;

use CoquiBot\Coqui\Contract\McpOAuthInterface;
use CoquiBot\Coqui\Mcp\Config\McpConfig;
use CoquiBot\Coqui\Mcp\Support\McpServerPolicy;
use CoquiBot\Coqui\Mcp\Support\ServerLoadingModeStore;

/**
 * Per-context MCP runtime: builds the engine + management service once and
 * shares it across the agent (per-server tool exposure), the HTTP API, and the
 * optional management toolkit.
 */
final class McpRuntime
{
    private ?McpOAuthInterface $oauth = null;

    public function __construct(
        private readonly string $workspacePath,
        private readonly McpConfig $config,
        private readonly McpServerManager $manager,
        private readonly ServerLoadingModeStore $loadingStore,
        private readonly ?McpServerPolicy $policy,
    ) {}

    /**
     * @param (callable(string): mixed)|null $configGet Resolves dotted config keys for stdio policy.
     */
    public static function fromWorkspace(string $workspacePath, ?callable $configGet = null): self
    {
        $config = new McpConfig($workspacePath);
        $manager = new McpServerManager($config);
        $loadingStore = new ServerLoadingModeStore($workspacePath);
        $policy = $configGet === null
            ? null
            : McpServerPolicy::fromConfigValues(
                $configGet('agents.defaults.mcp.allowedStdioCommands'),
                $configGet('agents.defaults.mcp.deniedStdioCommands'),
            );

        return new self($workspacePath, $config, $manager, $loadingStore, $policy);
    }

    public function config(): McpConfig
    {
        return $this->config;
    }

    public function manager(): McpServerManager
    {
        return $this->manager;
    }

    public function registerOAuth(McpOAuthInterface $oauth): void
    {
        $this->oauth = $oauth;
    }

    public function managementService(): McpManagementService
    {
        return new McpManagementService(
            $this->config,
            $this->manager,
            $this->oauth,
            $this->loadingStore,
            $this->policy,
        );
    }

    public function connectEnabled(): void
    {
        $this->config->load();
        if ($this->config->listEnabledServers() !== []) {
            $this->manager->connectAll();
        }
    }

    /**
     * @return list<McpServerToolkit>
     */
    public function serverToolkits(): array
    {
        $this->config->load();
        $service = $this->managementService();
        $toolkits = [];
        foreach (array_keys($this->config->listEnabledServers()) as $serverName) {
            $toolkits[] = new McpServerToolkit((string) $serverName, $service);
        }

        return $toolkits;
    }
}
```
Note: if `McpManagementService` reads a *shared* connection state, constructing a fresh service per call is fine because `McpServerManager` (the stateful piece) is shared. Confirm `McpManagementService` holds no mutable state of its own beyond its injected collaborators; if it does, cache the instance in a private field instead of rebuilding.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Mcp/McpRuntimeTest.php`:
```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\McpRuntime;
use CoquiBot\Coqui\Mcp\McpServerToolkit;

it('builds a runtime with no policy and an empty server list', function (): void {
    $workspace = sys_get_temp_dir() . '/mcp-runtime-' . uniqid();
    $runtime = McpRuntime::fromWorkspace($workspace);

    expect($runtime->serverToolkits())->toBe([]);
    expect($runtime->managementService())->not->toBeNull();
});

it('exposes one server toolkit per enabled server', function (): void {
    $workspace = sys_get_temp_dir() . '/mcp-runtime-' . uniqid();
    $runtime = McpRuntime::fromWorkspace($workspace);
    $runtime->config()->addServer('github', ['command' => 'npx', 'args' => ['-y', 'x']]);
    $runtime->config()->save();

    $toolkits = $runtime->serverToolkits();
    expect($toolkits)->toHaveCount(1);
    expect($toolkits[0])->toBeInstanceOf(McpServerToolkit::class);
});
```

- [ ] **Step 3: Run it**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer dump-autoload && vendor/bin/pest tests/Unit/Mcp/McpRuntimeTest.php`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Mcp/McpRuntime.php tests/Unit/Mcp/McpRuntimeTest.php
git commit -m "feat(mcp): add McpRuntime facade shared by agent, API, and toolkit

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 8: Wire core agent exposure (per-server tools, deferred)

**Files:**
- Modify: `src/Agent/OrchestratorDependencies.php` (add `?McpRuntime $mcpRuntime = null`)
- Modify: `src/Agent/OrchestratorAgent.php` (register server toolkits as candidates; add `mcp_runtime` to discovery context)
- Modify: `src/Agent/AgentRunner.php:967` and `:1405` (pass `mcpRuntime:` into both `OrchestratorDependencies` constructions)

**Interfaces:**
- Consumes: `McpRuntime` (Task 7).
- Produces: per-server `McpServerToolkit`s registered into `$candidateToolkits` so they are budget-gated/deferred exactly as before; `$context['mcp_runtime']` available to discovered toolkits.

- [ ] **Step 1: Add the field to OrchestratorDependencies**

In `src/Agent/OrchestratorDependencies.php`, add the import `use CoquiBot\Coqui\Mcp\McpRuntime;` and add a new constructor-promoted property near the other stored collaborators (e.g. after `public ?ModManagerToolkit $modsToolkit = null,`):
```php
        public ?McpRuntime $mcpRuntime = null,
```

- [ ] **Step 2: Expose the dependency on the agent**

In `src/Agent/OrchestratorAgent.php`, store the new dep the same way sibling deps are stored (find where `$deps->modsToolkit` is assigned to `$this->modsToolkit` and add an analogous `private readonly ?McpRuntime $mcpRuntime;` field + assignment `$this->mcpRuntime = $deps->mcpRuntime;`). Add `use CoquiBot\Coqui\Mcp\McpRuntime;`.

- [ ] **Step 3: Register per-server toolkits as candidates**

In `src/Agent/OrchestratorAgent.php`, immediately after the Mods-toolkit candidate block (around line 527, before the discovery loop), add:
```php
        // Built-in MCP exposure: per-server tools from user config, budget-gated
        // (deferred by default) exactly like other candidate toolkits. The `mcp`
        // management tool + /mcp REPL come from the optional toolkit, not here.
        if ($this->mcpRuntime !== null) {
            foreach ($this->mcpRuntime->serverToolkits() as $serverToolkit) {
                $candidateToolkits[] = [
                    'toolkit' => $serverToolkit,
                    'package' => '',
                    'description' => $this->extractToolkitDescription($serverToolkit),
                ];
            }
        }
```

- [ ] **Step 4: Pass the runtime into the discovery context**

In `src/Agent/OrchestratorAgent.php`, in the `instantiateRegisteredGrouped(context: [...])` array (around line 531), add:
```php
                'mcp_runtime' => $this->mcpRuntime,
```
This is how the optional toolkit (when installed) receives the shared runtime.

- [ ] **Step 5: Build + pass the runtime in AgentRunner**

In `src/Agent/AgentRunner.php`, add a private lazily-built helper:
```php
    private ?McpRuntime $mcpRuntimeCache = null;

    private function mcpRuntime(): McpRuntime
    {
        return $this->mcpRuntimeCache ??= McpRuntime::fromWorkspace(
            $this->workspacePath,
            fn (string $key): mixed => $this->config->get($key),
        );
    }
```
Add `use CoquiBot\Coqui\Mcp\McpRuntime;`. Then in **both** `new OrchestratorDependencies(` calls (lines ~967 and ~1405), add `mcpRuntime: $this->mcpRuntime(),`. Confirm `$this->config` exposes `get(string): mixed`; if the property/method name differs, adapt the closure.

- [ ] **Step 6: Verify the full core suite still passes**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer dump-autoload && composer test`
Expected: PASS. If a toolkit-discovery test asserts on the old McpToolkit child expansion, note it — it is addressed in Tasks 10-12; if it fails now, add a targeted `->skip('MCP moves to core in this migration; see plan Task 10-12')` with a TODO to restore.

- [ ] **Step 7: Commit**

```bash
git add src/Agent/OrchestratorDependencies.php src/Agent/OrchestratorAgent.php src/Agent/AgentRunner.php
git commit -m "feat(mcp): expose per-server MCP tools from core (deferred) via McpRuntime

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 9: Rewire the HTTP API to core's runtime

**Files:**
- Modify: `src/Api/Handler/McpServerHandler.php:9` (import)
- Modify: `src/Command/ApiCommand.php:72-77` (imports), `:377-392` (construction)

**Interfaces:**
- Consumes: `McpRuntime` (Task 7), core `McpManagementService` (Task 5).
- Produces: `McpServerHandler` backed by core's service; full CRUD works with the toolkit absent; `/auth` returns a clear error when no OAuth provider is registered.

- [ ] **Step 1: Flip the handler import**

In `src/Api/Handler/McpServerHandler.php`, change:
```php
use CoquiBot\Toolkits\Mcp\McpManagementService;
```
to:
```php
use CoquiBot\Coqui\Mcp\McpManagementService;
```
No other changes to the handler — the `authorizeServer` call now throws the clear RuntimeException when OAuth is unregistered; confirm `handleAuth` returns that as a clean error response (wrap in the existing try/catch used by other write handlers; if `handleAuth` lacks one, add the same `try/catch` pattern used by `handleCreate`).

- [ ] **Step 2: Replace the ad-hoc construction in ApiCommand**

In `src/Command/ApiCommand.php`, remove the toolkit imports at lines 72-77 (`OAuthHandler as McpOAuthHandler`, `McpConfig`, `McpManagementService`, `McpServerManager`, `McpServerPolicy`, `ServerLoadingModeStore as McpServerLoadingModeStore`) and add:
```php
use CoquiBot\Coqui\Mcp\McpRuntime;
```
Replace lines 377-392 with:
```php
        $mcpRuntime = McpRuntime::fromWorkspace(
            $boot->workspacePath(),
            fn (string $key): mixed => $boot->config()->get($key),
        );
        $mcpRuntime->connectEnabled();
        $mcpServerHandler = new McpServerHandler($mcpRuntime->managementService());
```
Keep `use CoquiBot\Coqui\Api\Handler\McpServerHandler;` (line 28) as-is.

- [ ] **Step 3: Static analysis + API-shape check**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer dump-autoload && composer analyse`
Expected: no MCP-related errors. Then run any API handler tests: `vendor/bin/pest --filter=Mcp` (or the file under `tests/` that covers `McpServerHandler`). Expected: PASS. Endpoint paths/shapes are unchanged (no route edits).

- [ ] **Step 4: Commit**

```bash
git add src/Api/Handler/McpServerHandler.php src/Command/ApiCommand.php
git commit -m "feat(mcp): back the HTTP API with core McpRuntime; CRUD works without the toolkit

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 10: Update system-toolkit + prompt-slug references in core

**Files:**
- Modify: `src/Contract/CoquiDefaults.php:203-224` (`SYSTEM_TOOLKITS`)
- Modify: `src/Agent/OrchestratorAgent.php` (`TOOLKIT_PROMPT_SLUG_MAP`, if it maps `McpToolkit`/`McpServerToolkit`)

**Interfaces:**
- Produces: correct loading treatment for MCP now that the root `mcp` tool is no longer a discovered system toolkit and per-server toolkits are core candidates.

- [ ] **Step 1: Decide McpToolkit's system status**

The optional toolkit's `McpToolkit` (the `mcp` tool + `/mcp` REPL) should remain **always-loaded when installed** (it is a control surface, not a per-server tool). Keep `'McpToolkit'` in `CoquiDefaults::SYSTEM_TOOLKITS` so, when the optional package is present, its single `mcp` tool loads eagerly. The per-server `McpServerToolkit`s are NOT system (they must stay deferrable) — confirm `'McpServerToolkit'` is **not** in `SYSTEM_TOOLKITS` (it currently is not).

- [ ] **Step 2: Check the prompt-slug map**

In `src/Agent/OrchestratorAgent.php`, inspect `TOOLKIT_PROMPT_SLUG_MAP` (referenced near line 626). If it contains an entry keyed by `McpToolkit` or `McpServerToolkit`, leave `McpServerToolkit`'s behavior intact (deferred → excluded slug). No change needed unless a stale slug references removed classes; if so, update it to match current basenames.

- [ ] **Step 3: Run the suite**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer test`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Contract/CoquiDefaults.php src/Agent/OrchestratorAgent.php
git commit -m "chore(mcp): align system-toolkit + prompt-slug handling with core exposure

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 11: Slim the toolkit to management UX only

**Files (in `coqui-toolkit-mcp-client`):**
- Delete: `src/McpClient.php`, `src/Transport/`, `src/JsonRpc/`, `src/Schema/`, `src/Exception/`, `src/Config/`, `src/McpServerManager.php`, `src/McpManagementService.php`, `src/McpServerToolkit.php`, `src/Support/McpServerPolicy.php`, `src/Support/ServerLoadingModeStore.php`
- Modify: `src/McpToolkit.php`, `src/Command/McpCommandHandler.php`, `src/Auth/OAuthHandler.php`
- Keep: `src/Support/McpManagementFormatter.php`, `src/Support/ArgumentTokenizer.php`, `src/Auth/OAuthException.php`
- Create/modify: `stubs/coqui_mcp_contracts.php` (PHPStan stubs for the core runtime + service + OAuth interface)
- Modify: `composer.json` (version bump; keep no coqui dependency)
- Modify/prune: `tests/` (drop relocated engine tests; keep tool/REPL/formatter/OAuth tests)

**Interfaces:**
- Consumes (at runtime, via `$context['mcp_runtime']`): the core `McpRuntime` object exposing `managementService()` and `registerOAuth()`.
- Produces: `McpToolkit::fromCoquiContext(array $context): self` returning a toolkit whose `tools()` is `[mcp management tool]` and `commandHandlers()` is `[McpCommandHandler]`, with **no** `CompositeToolkitProvider`/`childToolkits()`.

- [ ] **Step 1: Delete the relocated files**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client
git rm -r src/McpClient.php src/Transport src/JsonRpc src/Schema src/Exception src/Config \
  src/McpServerManager.php src/McpManagementService.php src/McpServerToolkit.php \
  src/Support/McpServerPolicy.php src/Support/ServerLoadingModeStore.php
```

- [ ] **Step 2: Add PHPStan stubs for the core types the toolkit references**

Create `stubs/coqui_mcp_contracts.php` (parsed by PHPStan only; not autoloaded at runtime — mirror how `stubs/coqui_repl_contracts.php` is wired in `phpstan.neon`):
```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

interface McpOAuthInterface
{
    /** @param array<string, mixed> $authConfig @return array<string, mixed> */
    public function authorize(string $serverName, array $authConfig): array;
    /** @param array<string, mixed> $authConfig */
    public function getAccessToken(string $serverName, array $authConfig): ?string;
    public function hasTokens(string $serverName): bool;
    public function clearTokens(string $serverName): void;
}

namespace CoquiBot\Coqui\Mcp;

final class McpRuntime
{
    public function managementService(): \CoquiBot\Coqui\Mcp\McpManagementService {}
    public function registerOAuth(\CoquiBot\Coqui\Contract\McpOAuthInterface $oauth): void {}
}

final class McpManagementService
{
    /** @return list<array<string, mixed>> */
    public function listServers(): array {}
    // Declare the subset of methods the tool + REPL call. Add each method the
    // toolkit invokes with matching signatures from the core class (Task 5),
    // e.g. addServer, updateServer, removeServer, setServerSecret, enableServer,
    // disableServer, promoteServer, demoteServer, autoServer, connectServer,
    // disconnectServer, refreshServer, testServer, getServerSnapshot,
    // getServerTools, searchTools, authorizeServer, parseArgs, tools.
}
```
Add the file to `phpstan.neon` under the same `parameters.scanFiles`/`stubFiles` key already used for `stubs/coqui_repl_contracts.php`.

- [ ] **Step 3: Rework `McpToolkit`**

Edit `src/McpToolkit.php`:
- Remove `implements ... CompositeToolkitProvider` and delete the `childToolkits()` method (core owns per-server exposure now).
- Change `fromCoquiContext(array $context)` to read the runtime:
```php
    public static function fromCoquiContext(array $context): self
    {
        $runtime = $context['mcp_runtime'] ?? null;
        $workspacePath = (string) ($context['workspacePath'] ?? getcwd() . '/.workspace');

        $toolkit = new self($workspacePath);
        if ($runtime !== null) {
            // Provide the OAuth implementation core lacks.
            $runtime->registerOAuth(new \CoquiBot\Toolkits\Mcp\Auth\OAuthHandler($workspacePath));
            $toolkit->service = $runtime->managementService();
        }

        return $toolkit;
    }
```
- Store `?object $service` (the core management service) on the instance; `tools()` returns `[$this->mcpManagementTool()]` (the existing `mcp` control tool), delegating each action to `$this->service->...`. If `$this->service` is null (runtime absent → old/misconfigured core), `tools()` returns `[]` and `commandHandlers()` returns `[]` so the toolkit no-ops cleanly.
- `commandHandlers()` returns `[new McpCommandHandler($this->service, new McpManagementFormatter())]` only when `$this->service !== null`.
- Delete `fromEnv()` if it depended on building the engine locally; if kept, make it construct with `$service = null` (no-op) since the engine no longer lives here.

- [ ] **Step 4: Make `OAuthHandler` implement the core interface**

Edit `src/Auth/OAuthHandler.php` to add `implements \CoquiBot\Coqui\Contract\McpOAuthInterface` (satisfied by the stub for PHPStan; satisfied by the real interface at runtime because core is loaded in the host process). Its existing method signatures already match the interface (`authorize`, `getAccessToken`, `hasTokens`, `clearTokens`).

- [ ] **Step 5: Update `McpCommandHandler`**

The handler already takes `(McpManagementService $service, McpManagementFormatter $formatter)`. Change the `McpManagementService` type reference to the stubbed `\CoquiBot\Coqui\Mcp\McpManagementService` (the core class at runtime, the stub for analysis). Remove any `use CoquiBot\Toolkits\Mcp\McpManagementService;` import and point at the core namespace.

- [ ] **Step 6: Prune + fix tests**

Remove the relocated engine tests from the toolkit:
```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client
git rm tests/Unit/JsonRpcMessageTest.php tests/Unit/SchemaConverterTest.php \
  tests/Unit/ExceptionTest.php tests/Unit/McpConfigTest.php tests/Unit/EnvResolverTest.php \
  tests/Unit/McpManagementServiceLoadingModeTest.php tests/Unit/McpManagementServicePolicyTest.php
```
Keep and fix: `McpToolkitTest.php` (assert `tools()` returns the single `mcp` tool and that it no-ops when `mcp_runtime` is absent), `OAuthHandlerTest.php`. Update `McpToolkitTest` to pass a fake runtime object exposing `managementService()`/`registerOAuth()`.

- [ ] **Step 7: Bump the toolkit version + docs pointer in composer.json**

In `composer.json`, bump the version to a new minor (e.g. `0.2.0`) if a `version` field exists; keep `extra.php-agents.toolkits` = `["CoquiBot\\Toolkits\\Mcp\\McpToolkit"]`; keep no `coquibot/coqui` dependency. Add a `README`/description note that this package now requires a Coqui core that ships the MCP engine.

- [ ] **Step 8: Toolkit suite green**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client && composer dump-autoload && composer test && vendor/bin/phpstan analyse`
Expected: PASS.

- [ ] **Step 9: Commit (toolkit repo)**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client
git add -A
git commit -m "feat!: slim toolkit to MCP management UX; engine now ships in Coqui core

BREAKING CHANGE: requires a Coqui core that provides the MCP engine + McpRuntime.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 12: Drop the dependency from core + full green

**Files:**
- Modify: `/home/carmelo/Projects/CoquiBot/Core/coqui/composer.json:20`

- [ ] **Step 1: Remove the require line**

In `composer.json`, delete:
```json
    "coquibot/coqui-toolkit-mcp-client": "^0.1",
```

- [ ] **Step 2: Update dependencies**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer update coquibot/coqui-toolkit-mcp-client 2>/dev/null; composer install`
Expected: the package is no longer installed (or is only present if the user separately installs it). Confirm: `composer show coquibot/coqui-toolkit-mcp-client` reports not installed.

- [ ] **Step 3: Full suite + analyse, toolkit absent**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer test && composer analyse`
Expected: PASS with the toolkit NOT installed. This proves MCP engine + API work core-only. If any test expected the `mcp` tool to exist by default, update it to expect the deferred per-server exposure only (the `mcp` management tool is now opt-in via the toolkit).

- [ ] **Step 4: Smoke-test with the toolkit installed (optional but recommended)**

Temporarily require the local slimmed toolkit via a path repository to confirm end-to-end coupling:
```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
composer config repositories.mcpclient path ../coqui-toolkit-mcp-client
composer require coquibot/coqui-toolkit-mcp-client:@dev --no-update && composer update coquibot/coqui-toolkit-mcp-client
composer test
```
Expected: PASS, with the `mcp` tool + `/mcp` command available and operating on the shared runtime. Then remove the temporary requirement/repo so core ships without it:
```bash
composer remove coquibot/coqui-toolkit-mcp-client --no-update
composer config --unset repositories.mcpclient
composer install
```

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock
git commit -m "feat(mcp)!: drop coqui-toolkit-mcp-client from core; MCP engine is now core

BREAKING CHANGE: the mcp management tool + /mcp REPL now require installing
coquibot/coqui-toolkit-mcp-client separately. The MCP engine, per-server tool
exposure, and HTTP API ship in core.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 13: Documentation + source map

**Files:**
- Modify: `docs/TOOLKITS.md`, `docs/CONFIGURATION.md`, `docs/API.md`, `docs/FEATURES.md`
- Modify: `config/source.json`
- Modify: `coqui-toolkit-mcp-client/README.md`

- [ ] **Step 1: Update core docs**

- `docs/TOOLKITS.md`: document that the MCP engine is core; the `coqui-toolkit-mcp-client` package is optional and adds only the `mcp` tool + `/mcp` REPL.
- `docs/CONFIGURATION.md`: `.workspace/mcp.json` and `agents.defaults.mcp.*` are core; note engine ships by default.
- `docs/API.md`: MCP endpoints are core and work without the toolkit, except `POST /api/v1/mcp/servers/{name}/auth`, which requires the toolkit (OAuth). Keep endpoint list unchanged otherwise.
- `docs/FEATURES.md`: reflect the split (core runtime + optional management UX).

- [ ] **Step 2: Update `config/source.json`**

Add the new `src/Mcp/*` files and `src/Contract/McpOAuthInterface.php` to the map with their responsibilities; move MCP from `externalDependencies` into the appropriate core layer; remove the `coqui-toolkit-mcp-client` core-dependency entry (leave a note it is optional).

- [ ] **Step 3: Update the toolkit README**

State that the package is now optional, requires a Coqui core that ships the MCP engine, and provides only the management tool + REPL + OAuth.

- [ ] **Step 4: Validate doc references**

Grep for stale references to the toolkit as a required dependency or to `CoquiBot\Toolkits\Mcp\` engine classes in core docs:
```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
grep -rn 'Toolkits\\\\Mcp\\\\Mcp\(Client\|ServerManager\|Config\|ManagementService\)' docs config/source.json || echo "clean"
```
Expected: `clean` (engine classes are referenced only under their new core namespace).

- [ ] **Step 5: Commit**

```bash
git add docs config/source.json
git commit -m "docs(mcp): document core MCP engine + optional management toolkit split

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client
git add README.md
git commit -m "docs: mark toolkit optional; engine now ships in Coqui core

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 14: Reconnect repos to GitHub + final verification (goal 3, part 2 + goal 4 DoD)

**Files:** none (git + CI operations)

- [ ] **Step 1: Add remotes from powerbank**

Using the remote URLs recorded in Task 1 Step 1, add and verify remotes:
```bash
git -C /home/carmelo/Projects/CoquiBot/Core/coqui remote add origin <coqui remote from powerbank>
git -C /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client remote add origin <toolkit remote from powerbank>
git -C /home/carmelo/Projects/CoquiBot/Core/coqui remote -v
git -C /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client remote -v
```
If a remote already exists after `git init` (it should not), reconcile rather than duplicate. Confirm the identity from Task 1 matches the powerbank git-identity rule for these repos before pushing.

- [ ] **Step 2: Final full verification in both repos**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui && composer test && composer analyse
cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client && composer test && vendor/bin/phpstan analyse
```
Expected: PASS in both. This is the goal-4 definition of done.

- [ ] **Step 3: Push (only after explicit user go-ahead)**

Pushing publishes these repos. Confirm with the user first (per outward-facing-action policy), then:
```bash
git -C /home/carmelo/Projects/CoquiBot/Core/coqui push -u origin main
git -C /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-mcp-client push -u origin main
```
If the GitHub repos have pre-existing history, do NOT force-push; fetch, reconcile, and re-run verification before pushing.

- [ ] **Step 4: Report**

Summarize: engine relocated, dependency dropped, API core-backed, toolkit slimmed + version-bumped, both suites green, remotes reconnected. Note the deferred feature-trim lever (goal 1) from the spec's "Out of scope" section.

---

## Self-Review

**Spec coverage:**
- Engine → core: Tasks 2-4, 6. ✅
- Runtime service (API-backed CRUD) → core: Task 5. ✅
- `McpRuntime`, three consumers: Tasks 7 (facade), 8 (agent), 9 (API), 11 (toolkit via context). ✅
- Per-server tools deferred: Task 8 Step 3 (candidate registration → budget gate). ✅
- Toolkit slimmed, loose coupling, no coqui dep, no-op fallback: Task 11. ✅
- API full CRUD without toolkit; `/auth` gated: Tasks 5, 9, 12 Step 3. ✅
- OAuth decoupled via interface (flagged deviation): Task 5 + Task 11 Step 4. ✅
- Compat/paths/config keys/guard preserved: Task 3 Step 2, Global Constraints, Task 10. ✅
- Dependency removed from core: Task 12. ✅
- Tests split/ported, both suites green: Tasks 2-7, 11, 12, 14. ✅
- Docs + source.json: Task 13. ✅
- Goal 3 git reconnect (repos are non-git): Tasks 1, 14. ✅
- Goal 1 honestly deferred to feature-trim: noted in Task 14 Step 4 + spec. ✅

**Placeholder scan:** Relocation steps use exact source→dest paths and exact `sed` namespace rewrites rather than reproducing unavailable file bodies (appropriate for a move). New code (McpOAuthInterface, McpRuntime, wiring, tests) is shown in full. The one intentional "fill from source" is the toolkit stub's method list (Task 11 Step 2), which enumerates exactly which core `McpManagementService` methods to mirror.

**Type consistency:** `McpRuntime::fromWorkspace(string, ?callable)`, `managementService(): McpManagementService`, `serverToolkits(): list<McpServerToolkit>`, `registerOAuth(McpOAuthInterface)` are used consistently across Tasks 7-9, 11. `McpManagementService` constructor `(McpConfig, McpServerManager, ?McpOAuthInterface, ?ServerLoadingModeStore, ?McpServerPolicy)` matches between Task 5 (definition) and Task 7 (call site). `McpServerToolkit(string, McpManagementService)` consistent between Task 6 and Task 7.

**Open verification points (folded into steps, with fallbacks):** loading-mode filename (Task 3 Step 2), `$this->config->get()` shape in AgentRunner (Task 8 Step 5), `handleAuth` try/catch presence (Task 9 Step 1), `TOOLKIT_PROMPT_SLUG_MAP` contents (Task 10 Step 2), exact toolkit test filenames (Tasks 2-3, 11 — verify with `ls tests/Unit` before copying).
