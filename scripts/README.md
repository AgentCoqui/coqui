# Coqui Scripts

Utility scripts for development and maintenance of the Coqui project.

## ci-test.sh

Mirrors the GitHub Actions CI pipeline locally. Runs Pest tests and PHPStan static analysis in sequence, exiting on the first failure.

### Direct Usage

```bash
# Run tests + PHPStan (vendor/ must already exist)
./scripts/ci-test.sh

# Install dependencies first, then run checks
./scripts/ci-test.sh --install

# Include a coverage report in the Pest step
./scripts/ci-test.sh --coverage
```

### What it does

1. Checks PHP version (8.4+ required) and verifies required extensions (`pdo_sqlite`, `mbstring`, `curl`, `xml`)
2. Warns about optional extensions (`pcntl`, `zip`) if missing
3. Optionally runs `composer install`
4. Runs `composer test` (Pest) or `composer test:coverage` when `--coverage` is set
5. Runs `composer analyse` (PHPStan level 8, bleeding edge)

## test-coverage.php

Cross-platform coverage wrapper for Pest.

### Usage

```bash
# Terminal coverage summary
php scripts/test-coverage.php

# Save Clover XML for CI tooling
php scripts/test-coverage.php --clover build/coverage/clover.xml
```

The wrapper auto-enables PCOV when installed, or Xdebug coverage mode when Xdebug is available.

See [docs/TESTING.md](../docs/TESTING.md) for local test and coverage setup, and [docs/GITHUB-ACTIONS.md](../docs/GITHUB-ACTIONS.md) for CI workflow details.
