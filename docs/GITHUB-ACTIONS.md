# GitHub Actions CI

Coqui uses GitHub Actions for continuous integration. The workflow runs on every pull request targeting `main` and on direct pushes to `main`, ensuring tests and static analysis pass before code is merged.

## Workflow Overview

The CI workflow (`.github/workflows/ci.yml`) runs two parallel jobs across a PHP version matrix:

| Job | Command | Purpose |
|-----|---------|---------|
| **Tests** | `composer test` | Runs the Pest 3.x test suite |
| **PHPStan** | `composer analyse` | Static analysis at level 8 (bleeding edge) |

### PHP Version Matrix

| Version | Status |
|---------|--------|
| 8.4 | Required (minimum) |
| 8.5 | Future-proofing |

Both jobs use `fail-fast: false` so all matrix combinations report independently — a failure on 8.5 won't cancel the 8.4 run.

### Extensions

The workflow installs: `pdo_sqlite`, `mbstring`, `curl`, `xml`, `zip`, `pcntl`. These cover the required `ext-pdo_sqlite` and suggested `ext-pcntl` extensions along with transitive needs from Symfony Console and HTTP Client.

### Caching

Composer dependencies are cached by PHP version using the `actions/cache` action. The cache key hashes `composer.lock` (Coqui is a project and commits its lockfile for reproducible builds).

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

See [scripts/ci-test.sh](../scripts/ci-test.sh) for details.

## Workflow File Reference

The full workflow lives at `.github/workflows/ci.yml`. Key design decisions:

- **Two parallel jobs** (tests + static analysis) rather than sequential steps — faster feedback and independent failure reporting
- **`composer.lock` committed** — as a project, Coqui pins exact dependency versions for reproducible CI runs
- **`shivammathur/setup-php@v2`** — reliable PHP provisioning with extension management
- **`actions/cache@v4`** — caches Composer's download directory across runs
- **PHPStan bleeding edge** — Coqui uses `bleedingEdge.neon` for strictest analysis

## Troubleshooting

### Tests pass locally but fail in CI

- Check the PHP version. CI runs 8.4 and 8.5 — you may be running a different patch version locally.
- Run `php -m` to verify extensions match: `pdo_sqlite`, `mbstring`, `curl`, `xml`, `zip`, `pcntl`.
- Ensure `composer.lock` is committed and up to date. Run `composer install` (not `update`) to match the lockfile.

### PHPStan fails in CI but not locally

- Ensure you're running the same PHPStan version. Delete `vendor/` and run `composer install` from the lockfile.
- Coqui uses `bleedingEdge.neon` which enables experimental strictness rules — these may flag things that standard PHPStan does not.
- PHPStan caches results — delete `.phpstan.cache` locally and rerun.

### Missing ext-pdo_sqlite

On Ubuntu, install the SQLite extension:

```bash
sudo apt-get install php8.4-sqlite3
```

On macOS (Homebrew), `pdo_sqlite` is included in the default `php` formula.

### Cache issues

If dependencies seem stale, the CI cache can be cleared by pushing a change to `composer.lock` (which changes the cache key hash). Alternatively, delete caches manually from **Actions → Caches** in the GitHub UI.
