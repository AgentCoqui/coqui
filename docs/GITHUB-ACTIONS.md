# GitHub Actions CI

Coqui uses GitHub Actions for continuous integration. The workflow runs on every pull request targeting `main` and on direct pushes to `main`, ensuring tests and static analysis pass before code is merged. Coverage is reported on a dedicated lane, but it is not enforced as a merge gate yet.

For local test and coverage setup, see [TESTING.md](TESTING.md).

## Workflow Overview

The CI workflow (`.github/workflows/ci.yml`) runs four main jobs:

- **Tests** — `composer test` across the full matrix
- **Coverage** — `composer test:coverage -- --clover build/coverage/clover.xml` on one stable lane, uploading Clover XML
- **PHPStan** — `composer analyse` at level 8 with bleeding edge rules
- **Bash Tests** — shell regressions for launcher behavior and the dev mock installer

### PHP Version Matrix

- **8.4** — Required minimum
- **8.5** — Future-proofing

The matrix jobs use `fail-fast: false` so all matrix combinations report independently — a failure on 8.5 won't cancel the 8.4 run.

### Extensions

The workflow installs: `pdo_sqlite`, `mbstring`, `curl`, `xml`, `zip`, `pcntl`. The coverage job additionally enables PCOV so Pest can generate code-coverage reports without slowing every matrix lane.

### Caching

Composer dependencies are cached by PHP version using the `actions/cache` action. The cache key hashes `composer.lock` (Coqui is a project and commits its lockfile for reproducible builds).

### Release Version Source

Release builds derive the application version from the git tag (`v1.2.3` becomes `1.2.3`) and inject it into `config/version.txt` inside the staged build. The root `composer.json` is not used as a manually maintained application version source.

## Relationship to php-agents

Coqui depends on `carmelosantana/php-agents` (installed from Packagist). Running Coqui's test suite indirectly exercises php-agents. However, php-agents has its own independent CI workflow — changes to that library are validated in isolation without running the full Coqui suite.

## Branch Protection

After the workflow is merged, configure branch protection on GitHub:

1. Go to **Settings → Branches → Add rule**
2. Branch name pattern: `main`
3. Enable **Require status checks to pass before merging**
4. Select these required checks:
   - `Tests (PHP 8.4)`
   - `Tests (PHP 8.5)`
   - `PHPStan (PHP 8.4)`
   - `PHPStan (PHP 8.5)`
5. Optionally enable **Require branches to be up to date before merging**

## Testing Locally

Before pushing, you can run the same checks the CI pipeline executes. This catches failures early and avoids waiting for GitHub runners.

### Prerequisites

Both macOS and Linux need:

- **PHP 8.4+** with extensions: `pdo_sqlite`, `mbstring`, `curl`, `xml`, `zip`, `pcntl`
- **Composer 2.x**

### macOS

Install PHP and Composer via Homebrew:

```bash
brew install php@8.4 composer
```

Verify the installation and check extensions:

```bash
php -v            # Should show 8.4.x
php -m | grep -E 'pdo_sqlite|mbstring|curl|xml|zip|pcntl'
composer --version
```

> **Note:** `pcntl` is not available on macOS via Homebrew by default. The test suite does not require it — it's only needed at runtime for background task cancellation. Tests will still pass without it.

Run the checks:

```bash
composer install
composer test
composer analyse
```

For a local coverage summary:

```bash
composer test:coverage
```

### Linux (Ubuntu)

Install PHP 8.4 from the `ondrej/php` PPA:

```bash
sudo add-apt-repository ppa:ondrej/php
sudo apt-get update
sudo apt-get install -y \
    php8.4-cli \
    php8.4-sqlite3 \
    php8.4-mbstring \
    php8.4-curl \
    php8.4-xml \
    php8.4-zip
```

> `pcntl` is built into the CLI SAPI on Ubuntu — no separate package needed.

Install Composer:

```bash
curl -fsSL https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
```

Run the checks:

```bash
composer install
composer test
composer analyse
```

For a local coverage summary:

```bash
composer test:coverage
```

### Using Docker

If you have Docker installed, you can run tests inside the Coqui container without installing PHP locally:

```bash
make test
```

Or with coverage:

```bash
make test-coverage
```

See the [Makefile](../Makefile) for all available targets.

### Using the Test Script

A convenience script is provided that mirrors the CI pipeline locally:

```bash
./scripts/ci-test.sh
```

The script runs both `composer test` and `composer analyse` in sequence, exiting on the first failure. Pass `--install` to also run `composer install` before testing:

```bash
./scripts/ci-test.sh --install
```

Add `--coverage` when you want the Pest step to emit a coverage report:

```bash
./scripts/ci-test.sh --coverage
```

See [scripts/ci-test.sh](../scripts/ci-test.sh) for details.

## Coverage Reporting

Coverage is currently reporting-only. The CI coverage lane runs on Ubuntu with PHP 8.4 and uses the same wrapper command as local coverage runs, adding `--clover build/coverage/clover.xml` to upload a Clover XML artifact.

For local detailed reporting, use one of these:

```bash
composer test -- --coverage
composer test:coverage
make test-coverage
```

`composer test -- --coverage` works when your PHP process is already configured for coverage. `composer test:coverage` uses the repository wrapper script to auto-enable PCOV or Xdebug when available.

The wrapper also accepts local environment overrides for active development:

- `COQUI_TEST_COVERAGE_DRIVER=pcov|xdebug|auto` to force a specific driver or keep auto-detection.
- `COQUI_TEST_COVERAGE_MEMORY_LIMIT=512M` or higher to raise the PHP CLI memory ceiling for coverage runs.

## Workflow File Reference

The full workflow lives at `.github/workflows/ci.yml`. Key design decisions:

- **Matrix test lanes + a single coverage lane** — fast feedback on correctness, focused reporting on coverage
- **`composer.lock` committed** — as a project, Coqui pins exact dependency versions for reproducible CI runs
- **`shivammathur/setup-php@v2`** — reliable PHP provisioning with extension management
- **`actions/cache@v4`** — caches Composer's download directory across runs
- **PHPStan bleeding edge** — Coqui uses `bleedingEdge.neon` for strictest analysis

## Platform Support

Linux and macOS are the primary supported platforms. WSL2 is the supported environment for Windows users.

The CI matrix runs `windows-latest` for basic cross-platform coverage, but `ShellToolkit` and `FileSystemToolkit` are designed for Unix environments — tests that rely on Unix commands (`ls`, `rm`, `grep`, etc.) or Unix path/permission semantics are skipped on Windows. Windows CI failures in those areas do not indicate product bugs.

If you are contributing primarily from Windows, use WSL2 (Windows Subsystem for Linux) to run the full test suite and validate shell-related functionality.

## Troubleshooting

### Tests pass locally but fail in CI

- Check the PHP version. CI runs 8.4 and 8.5 — you may be running a different patch version locally.
- Run `php -m` to verify extensions match: `pdo_sqlite`, `mbstring`, `curl`, `xml`, `zip`, `pcntl`.
- Ensure `composer.lock` is committed and up to date. Run `composer install` (not `update`) to match the lockfile.

### PHPStan fails in CI but not locally

- Ensure you're running the same PHPStan version. Delete `vendor/` and run `composer install` from the lockfile.
- Coqui uses `bleedingEdge.neon` which enables experimental strictness rules — these may flag things that standard PHPStan does not.
- PHPStan caches results — delete `.phpstan.cache` locally and rerun.

### Coverage command fails locally

- Install either PCOV or Xdebug.
- `composer test:coverage` auto-enables PCOV or Xdebug coverage mode when the extension is present.
- `composer test -- --coverage` requires your PHP process to already be configured for coverage, for example with `XDEBUG_MODE=coverage`.
- In active refactors, keep `composer test` and `composer analyse` as the default local gate and run coverage on demand rather than on every edit cycle.

### Missing ext-pdo_sqlite

On Ubuntu, install the SQLite extension:

```bash
sudo apt-get install php8.4-sqlite3
```

On macOS (Homebrew), `pdo_sqlite` is included in the default `php` formula.

### Cache issues

If dependencies seem stale, the CI cache can be cleared by pushing a change to `composer.lock` (which changes the cache key hash). Alternatively, delete caches manually from **Actions → Caches** in the GitHub UI.
