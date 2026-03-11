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
