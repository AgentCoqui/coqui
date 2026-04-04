# Testing

Coqui uses a small number of test layers with different goals:

- Pest unit tests under `tests/Unit/` cover PHP classes directly.
- Bash tests under `tests/bash/` cover launcher and shell-specific behavior.
- PHPStan runs separately as static analysis and should be treated as part of the test gate.

The current suite is mostly SQLite-backed and filesystem-backed unit testing. Most tests create temporary workspaces, temporary SQLite databases, and real store/tool instances instead of relying heavily on mocks.

## Test Layout

- `tests/Unit/Agent/` — agent orchestration, loop execution, prompt budgeting, evaluators
- `tests/Unit/Api/` — API managers, handlers, middleware, webhook processing
- `tests/Unit/Config/` — config parsing, guards, discovery, role/toolkit resolution
- `tests/Unit/Storage/` — SQLite persistence and query behavior
- `tests/Unit/Toolkit/` and `tests/Unit/Tool/` — tool and toolkit behavior
- `tests/bash/` — launcher and signal-handling tests

## Default Commands

For normal development, keep the default path fast:

```bash
composer test
composer analyse
```

Convenience wrappers are also available:

```bash
make test
make analyse
./scripts/ci-test.sh
```

To include the bash launcher suite:

```bash
composer test-bash
make test-launcher
```

## Coverage Commands

Coverage is opt-in locally and reporting-only in CI right now.

Use one of these commands:

```bash
composer test:coverage
composer test -- --coverage
make test-coverage
./scripts/ci-test.sh --coverage
```

Use `composer test:coverage` when you want the repository wrapper to auto-enable a coverage driver when possible. Use `composer test -- --coverage` when your PHP process is already configured for coverage.

CI uses:

```bash
composer test:coverage:ci
```

That command writes Clover XML to `build/coverage/clover.xml`.

## How Coverage Drivers Work

Pest coverage requires one of these CLI extensions:

- `pcov` — preferred for routine coverage runs because it is lightweight
- `xdebug` — useful when you also need step debugging

Check what is currently installed:

```bash
php -m | grep -Ei '^(pcov|xdebug)$'
php --ini
```

If both are installed, prefer PCOV for routine coverage runs.

## Linux Setup

The simplest Linux path is Ubuntu or Debian with PHP packages installed from the `ondrej/php` PPA, which Coqui already documents elsewhere for PHP 8.4.

Install PCOV:

```bash
sudo apt-get update
sudo apt-get install -y php8.4-pcov
php -m | grep -i pcov
```

Install Xdebug instead:

```bash
sudo apt-get update
sudo apt-get install -y php8.4-xdebug
php -m | grep -i xdebug
```

If your distro package names differ, install the matching package for your active PHP CLI version.

When using raw PECL instead of distro packages:

```bash
sudo pecl install pcov
sudo pecl install xdebug
```

Then enable the extension in your CLI `.ini` scan directory and verify with `php --ini`.

For Xdebug coverage with plain Pest:

```bash
XDEBUG_MODE=coverage composer test -- --coverage
```

## macOS Setup

With Homebrew PHP, install PHP first if needed:

```bash
brew install php@8.4 composer
php -v
```

Then install a coverage driver with PECL.

Install PCOV:

```bash
pecl install pcov
```

Install Xdebug:

```bash
pecl install xdebug
```

Find your active CLI configuration paths:

```bash
php --ini
```

On Homebrew PHP, the additional `.ini` scan directory is typically one of these:

- `/opt/homebrew/etc/php/8.4/conf.d/` on Apple Silicon
- `/usr/local/etc/php/8.4/conf.d/` on Intel Macs

Enable PCOV with an `.ini` file containing:

```ini
extension=pcov.so
pcov.enabled=1
```

Enable Xdebug with an `.ini` file containing:

```ini
zend_extension=xdebug.so
```

Then verify the CLI sees the extension:

```bash
php -m | grep -Ei '^(pcov|xdebug)$'
```

For Xdebug coverage with plain Pest:

```bash
XDEBUG_MODE=coverage composer test -- --coverage
```

## CI Parity

To mirror the local CI pipeline:

```bash
./scripts/ci-test.sh
./scripts/ci-test.sh --install
./scripts/ci-test.sh --coverage
```

The GitHub Actions workflow keeps the full matrix fast by running coverage on one dedicated lane instead of on every job.

## Troubleshooting

If `composer test:coverage` fails with a coverage-driver error:

- Install PCOV or Xdebug for the active PHP CLI.
- Re-run `php -m | grep -Ei '^(pcov|xdebug)$'`.
- Re-run `php --ini` and confirm the extension is loaded from the CLI config, not just FPM or Apache.

If `composer test -- --coverage` fails while Xdebug is installed:

- Run it as `XDEBUG_MODE=coverage composer test -- --coverage`.

If coverage works in CI but not locally:

- Compare your local `php -v`, `php -m`, and `php --ini` output with the CI environment.
- Make sure you are using the same PHP major/minor version the repo targets.

If tests fail only on shell-heavy suites:

- Run them from macOS, Linux, or WSL2.
- Windows CI intentionally does not treat all Unix shell behavior as a product bug.