# Coqui Scripts

Utility scripts for development and maintenance of the Coqui project.

## Report Extraction

The codebase report generators were extracted into the standalone `code-report` package under `/Users/carmelo/Projects/CoquiBot/Scripts/code-report`.

Use that package for file inventory, complexity, churn, dependency fan-in, and suite generation.

## ci-test.sh

Mirrors the GitHub Actions CI pipeline locally. Runs Pest tests and PHPStan static analysis in sequence, exiting on the first failure.

### CI Direct Usage

```bash
# Run tests + PHPStan (vendor/ must already exist)
./scripts/ci-test.sh

# Install dependencies first, then run checks
./scripts/ci-test.sh --install

# Include a coverage report in the Pest step
./scripts/ci-test.sh --coverage
```

### CI Behavior

1. Checks PHP version (8.4+ required) and verifies required extensions (`pdo_sqlite`, `mbstring`, `curl`, `xml`)
2. Warns about optional extensions (`pcntl`, `zip`) if missing
3. Optionally runs `composer install`
4. Runs `composer test` (Pest) or `composer test:coverage` when `--coverage` is set
5. Runs `composer analyse` (PHPStan level 8, bleeding edge)

## test-coverage.php

Cross-platform coverage wrapper for Pest.

### Profiling Usage

```bash
# Terminal coverage summary
php scripts/test-coverage.php

# Save Clover XML with the same wrapper command shape used in CI
php scripts/test-coverage.php --clover build/coverage/clover.xml
```

The wrapper auto-enables PCOV when installed, or Xdebug coverage mode when Xdebug is available.

It also supports optional environment overrides:

```bash
COQUI_TEST_COVERAGE_MEMORY_LIMIT=768M php scripts/test-coverage.php
COQUI_TEST_COVERAGE_DRIVER=pcov php scripts/test-coverage.php
COQUI_TEST_COVERAGE_DRIVER=xdebug php scripts/test-coverage.php
```

- `COQUI_TEST_COVERAGE_MEMORY_LIMIT` defaults to `512M`.
- `COQUI_TEST_COVERAGE_DRIVER` defaults to `auto`, preferring `pcov` before `xdebug`.

## test-profile.php

Cross-platform Xdebug profiling wrapper for Pest.

### Usage

```bash
# Write cachegrind files for a targeted test run
php scripts/test-profile.php -- tests/Unit/Config/ContextWindowResolutionTest.php

# Use a custom output directory for one investigation
COQUI_TEST_PROFILE_OUTPUT_DIR=build/profiles/tests/context-window php scripts/test-profile.php -- --filter=context
```

The wrapper requires the `xdebug` CLI extension and writes cachegrind files to `build/profiles/tests` by default.
It excludes the `performance` test group by default because wall-clock benchmarks are intentionally distorted by profiler overhead.

It also supports optional environment overrides:

```bash
COQUI_TEST_PROFILE_MEMORY_LIMIT=768M php scripts/test-profile.php
COQUI_TEST_PROFILE_OUTPUT_DIR=build/profiles/tests/slow-run php scripts/test-profile.php
COQUI_TEST_PROFILE_OUTPUT_NAME=cachegrind.out.%p.%t php scripts/test-profile.php
COQUI_TEST_PROFILE_INCLUDE_PERFORMANCE=1 php scripts/test-profile.php -- tests/Unit/PerformanceTest.php
```

- `COQUI_TEST_PROFILE_MEMORY_LIMIT` defaults to `512M`.
- `COQUI_TEST_PROFILE_OUTPUT_DIR` defaults to `build/profiles/tests`.
- `COQUI_TEST_PROFILE_OUTPUT_NAME` defaults to `cachegrind.out.%p`.
- `COQUI_TEST_PROFILE_INCLUDE_PERFORMANCE` defaults to `0`, which keeps wall-clock benchmark tests out of profiled runs unless you opt in.

See [docs/TESTING.md](../docs/TESTING.md) for local test and coverage setup, and [docs/GITHUB-ACTIONS.md](../docs/GITHUB-ACTIONS.md) for CI workflow details.

## generate-model-defaults.php

Refreshes provider-backed `curatedModels` snapshots in [config/defaults.json](../config/defaults.json) by calling live provider discovery through the shared php-agents provider layer.

### Generator Direct Usage

```bash
# Dry run with automatic workspace .env loading
php scripts/generate-model-defaults.php

# Refresh only OpenAI and write results back
php scripts/generate-model-defaults.php --provider=openai --write

# Merge model definitions from multiple Ollama servers during refresh
php scripts/generate-model-defaults.php --provider=ollama --ollama-url=http://ollama:11434/v1 --write

# Same as above using an environment variable
OLLAMA_DISCOVERY_URLS=http://localhost:11434/v1,http://ollama:11434/v1 php scripts/generate-model-defaults.php --provider=ollama --write

# Write a report artifact to a custom path
php scripts/generate-model-defaults.php --write --report BUILD/reports/model-defaults-report.json

# Use an explicit .env file
php scripts/generate-model-defaults.php --write --env-file ~/.coqui/.workspace/.env
```

### Generator Behavior

1. Loads provider credentials from the current process environment, then from a supplied `.env` file or the default workspace `.env`
2. Calls live provider discovery through php-agents instead of maintaining separate provider clients in Coqui
3. Rewrites discovered provider catalogs in `config/defaults.json`
4. Preserves manual-only fields like `recommended`, `cost`, and other non-generated metadata when model IDs still match
5. Removes stale curated entries for a provider when that provider no longer returns them
6. Writes a JSON report to `BUILD/reports/model-defaults-report.json` by default, including added, removed, and heuristic-only models per provider
7. Supports optional generator-only Ollama discovery across multiple endpoints without changing runtime provider selection or `openclaw.json`
8. Applies provider-specific recovery rules when direct discovery is empty: xAI keeps its official curated catalog because OpenRouter aliases like `x-ai/grok-4.1-fast` and `x-ai/grok-4-fast` do not map cleanly to direct xAI ids, while MiniMax can be reconstructed from OpenRouter mirror entries

### Build Integration

```bash
# Refresh model defaults before packaging a release
./scripts/build.sh --refresh-model-defaults
```

This step is opt-in so offline builds and release packaging without provider keys remain possible.

## mock-install.sh

Creates a dev-only mock install by symlinking a local checkout into the normal install path and refreshing the public `coqui` and `coqui-launcher` command symlinks.

### Mock Install Usage

```bash
# From the repo root, install this checkout into ~/.coqui
./scripts/mock-install.sh

# Replace an existing install without prompting
./scripts/mock-install.sh --force

# Use a custom install path and bin dir
COQUI_INSTALL_DIR=/tmp/coqui-dev COQUI_BIN_DIR=/tmp/bin ./scripts/mock-install.sh --force
```

### Mock Install Behavior

1. Validates that the source directory looks like a Coqui checkout
2. Replaces the install path with a symlink to that checkout
3. Refreshes `coqui` and `coqui-launcher` command symlinks in a writable bin directory
4. Writes a separate `.mock-install.env` marker next to the install path so the repo itself is not modified
