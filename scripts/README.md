# Coqui Scripts

Utility scripts for development and maintenance of the Coqui project.

## ci-test.sh

Mirrors the GitHub Actions CI pipeline locally. Runs Pest tests and PHPStan static analysis in sequence, exiting on the first failure.

### Usage

```bash
# Run tests + PHPStan (vendor/ must already exist)
./scripts/ci-test.sh

# Install dependencies first, then run checks
./scripts/ci-test.sh --install
```

### What it does

1. Checks PHP version (8.4+ required) and verifies required extensions (`pdo_sqlite`, `mbstring`, `curl`, `xml`)
2. Warns about optional extensions (`pcntl`, `zip`) if missing
3. Optionally runs `composer install`
4. Runs `composer test` (Pest)
5. Runs `composer analyse` (PHPStan level 8, bleeding edge)

See [docs/GITHUB-ACTIONS.md](../docs/GITHUB-ACTIONS.md) for full CI documentation including macOS/Ubuntu setup instructions.

## sync-workspace-toolkits.sh

Scans for `coqui-toolkit-*` packages alongside the project root, rebuilds `.workspace/composer.json` with path-repo symlinks for each discovered toolkit, and runs `composer update` to apply changes.

This script **only** modifies `.workspace/composer.json` — it never touches the root `composer.json`.

### What it does

1. Scans the parent directory (`Projects/CoquiBot/`) for `coqui-toolkit-*` directories that contain a valid `composer.json`
2. Extracts the Composer package name from each toolkit
3. Rebuilds `.workspace/composer.json`:
   - Removes stale toolkit entries and bot-generated packages
   - Preserves non-toolkit dependencies (e.g. `carmelosantana/php-agents`)
   - Adds `@dev` requires and path repository entries for every discovered toolkit
4. Runs `composer update` to install/symlink everything

### Usage

```bash
# From the project root
./scripts/sync-workspace-toolkits.sh

# Or from anywhere
/path/to/coqui/scripts/sync-workspace-toolkits.sh
```

### Requirements

- `php` (CLI) on PATH
- `composer` on PATH
- `realpath` (coreutils)

### Adding a new toolkit

Just create a `coqui-toolkit-<name>/` directory alongside the project root with a valid `composer.json` (must declare a `name` field). Run the script and it will be picked up automatically.

### Example output

```
Scanning for coqui-toolkit-* packages in CoquiBot/
  FOUND coquibot/coqui-toolkit-browser -> ../../coqui-toolkit-browser
  FOUND coquibot/coqui-toolkit-calculator -> ../../coqui-toolkit-calculator
  SKIP coqui-toolkit-cloudflare (no composer.json)
  ...

Found 8 toolkit(s)

Rebuilding .workspace/composer.json ...
  Wrote 8 toolkit(s) + 1 preserved require(s)
  Repositories: 8 total (8 toolkit path repos)

Running composer update in .workspace/ ...
```
