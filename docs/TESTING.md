# Testing

Coqui uses a small number of test layers with different goals:

- Pest unit tests under `tests/Unit/` cover PHP classes directly.
- Bash tests under `tests/bash/` cover launcher, installer helper, and other shell-specific behavior.
- PHPStan runs separately as static analysis and should be treated as part of the test gate.

The current suite is mostly SQLite-backed and filesystem-backed unit testing. Most tests create temporary workspaces, temporary SQLite databases, and real store/tool instances instead of relying heavily on mocks.

## Test Layout

- `tests/Unit/Agent/` — agent orchestration, loop execution, prompt budgeting, evaluators
- `tests/Unit/Api/` — API managers, handlers, middleware
- `tests/Unit/Config/` — config parsing, guards, discovery, role/toolkit resolution
- `tests/Unit/Storage/` — SQLite persistence and query behavior
- `tests/Unit/Toolkit/` and `tests/Unit/Tool/` — tool and toolkit behavior
- `tests/bash/` — launcher, mock-installer, and signal-handling tests

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

Compact test commands reducing output noise:

```bash
composer test -- --compact
make test-compact
```

```bash
composer test -- --compact 2>&1 | rg -i 'warning|deprecated|risky|error|fail(ed)?'
make test-problems
```

## Developer Policy

During active development, the default local gate is:

```bash
composer test
composer analyse
```

Local coverage is optional and should be run on demand when you need coverage data or when you are working on the coverage workflow itself. CI coverage is still required: the GitHub Actions coverage lane must continue to pass and produce `build/coverage/clover.xml`.

This keeps local iteration fast during large refactors while preserving a real end-to-end coverage check in CI.

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

The wrapper also supports two environment overrides:

```bash
COQUI_TEST_COVERAGE_MEMORY_LIMIT=768M composer test:coverage
COQUI_TEST_COVERAGE_DRIVER=pcov composer test:coverage
COQUI_TEST_COVERAGE_DRIVER=xdebug composer test:coverage
```

- `COQUI_TEST_COVERAGE_MEMORY_LIMIT` defaults to `512M` for coverage runs.
- `COQUI_TEST_COVERAGE_DRIVER` defaults to `auto`, which prefers `pcov` and falls back to `xdebug`.

The coverage lane uses the same wrapper command shape with a Clover output path:

```bash
composer test:coverage -- --clover build/coverage/clover.xml
```

That command writes Clover XML to `build/coverage/clover.xml`.

## Profiling Commands

Xdebug profiling is local-only and intended for short-lived bottleneck investigations. It is slower than normal test runs and should stay out of the default edit loop.

Use the profiling wrapper when you want cachegrind output from a test run:

```bash
composer test:profile
composer test:profile -- tests/Unit/Config/ContextWindowResolutionTest.php --filter=context
COQUI_TEST_PROFILE_OUTPUT_DIR=build/profiles/tests/context-window composer test:profile
```

The wrapper requires the `xdebug` CLI extension and writes cachegrind files to `build/profiles/tests` by default.
It excludes the `performance` test group by default because profiler overhead makes wall-clock benchmark thresholds meaningless.

Optional overrides:

```bash
COQUI_TEST_PROFILE_MEMORY_LIMIT=768M composer test:profile
COQUI_TEST_PROFILE_OUTPUT_DIR=build/profiles/tests/slow-run composer test:profile
COQUI_TEST_PROFILE_OUTPUT_NAME=cachegrind.out.%p.%t composer test:profile
COQUI_TEST_PROFILE_INCLUDE_PERFORMANCE=1 composer test:profile -- tests/Unit/PerformanceTest.php
```

- `COQUI_TEST_PROFILE_MEMORY_LIMIT` defaults to `512M`.
- `COQUI_TEST_PROFILE_OUTPUT_DIR` defaults to `build/profiles/tests`.
- `COQUI_TEST_PROFILE_OUTPUT_NAME` defaults to `cachegrind.out.%p`.
- `COQUI_TEST_PROFILE_INCLUDE_PERFORMANCE` defaults to `0`, which keeps benchmark-only tests out of profiled runs unless you explicitly opt in.

For application profiling, keep the runtime unchanged and launch the existing entry point under Xdebug only for the run you want to inspect:

```bash
mkdir -p build/profiles/app
XDEBUG_MODE=profile \
XDEBUG_CONFIG="output_dir=$(pwd)/build/profiles/app profiler_output_name=cachegrind.out.%p" \
./bin/coqui --repl-only
```

You can swap `./bin/coqui --repl-only` for `./bin/coqui --api-only`, `./bin/coqui`, or `./bin/coqui --prompt "..."` depending on the slow path you are investigating.

## Analyzing Profile Output

Xdebug profiling writes `cachegrind.out.*` files that can be opened directly by QCacheGrind, KCacheGrind, or Webgrind.

If you want a browser UI without installing a desktop profiler locally, start the bundled Webgrind overlay:

```bash
make docker-webgrind-up
open http://localhost:3390
```

That overlay serves one flat profile directory at a time. By default it points at `build/profiles/tests`, because Webgrind does not reliably browse nested profile trees.

To inspect application profiles instead, start it with a different source directory:

```bash
COQUI_WEBGRIND_SOURCE_DIR=./build/profiles/app make docker-webgrind-up
open http://localhost:3390
```

The overlay does not change the base Coqui image or install Xdebug in Docker; profiling still comes from `composer test:profile` or any direct `XDEBUG_MODE=profile` run that writes into `build/profiles`.

Typical workflow:

1. Run one narrow test or one narrow application flow so the output stays small enough to inspect.
2. Open the newest file from `build/profiles/tests` or `build/profiles/app` in QCacheGrind or point Webgrind at that directory.
3. Sort by self cost and inclusive cost to find hot functions, then drill into callees and callers.
4. Repeat the same run after a change and compare the hottest frames instead of comparing unrelated broad runs.

When both PCOV and Xdebug are installed, keep using PCOV for routine coverage and switch to Xdebug only for profiling or debugger-backed investigations.

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

If you keep `xdebug` or `pcov` loaded in your normal CLI all the time, also disable CLI JIT locally to avoid the `JIT is incompatible with third party extensions...` startup warning:

```ini
opcache.jit=0
opcache.jit_buffer_size=0
```

On Homebrew PHP, a late-loading file such as `/opt/homebrew/etc/php/8.4/conf.d/zz-local-no-jit.ini` is usually the cleanest place for that local override.

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

For Apple Silicon Homebrew PHP, the most reliable setup is:

```bash
mkdir -p /opt/homebrew/lib/php/pecl/20240924
printf '\n' | pecl install -f xdebug
CPPFLAGS='-I/opt/homebrew/opt/pcre2/include' \
CFLAGS='-I/opt/homebrew/opt/pcre2/include' \
CPATH='/opt/homebrew/opt/pcre2/include' \
printf '\n' | pecl install -f pcov
php -m | grep -Ei '^(pcov|xdebug)$'
```

If both are installed, leave `composer test` as your default day-to-day command and use `composer test:coverage` only when you actually need coverage data.

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

For local profiling convenience, many developers also add:

```ini
xdebug.mode=develop
```

Then override the mode per run with `XDEBUG_MODE=profile` or `XDEBUG_MODE=coverage` so normal CLI usage stays lighter than a permanently enabled profiler.

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
- If you need to force one driver while debugging the environment, run `COQUI_TEST_COVERAGE_DRIVER=pcov composer test:coverage` or `COQUI_TEST_COVERAGE_DRIVER=xdebug composer test:coverage`.
- If the coverage run needs more headroom, run `COQUI_TEST_COVERAGE_MEMORY_LIMIT=768M composer test:coverage`.

If `composer test -- --coverage` fails while Xdebug is installed:

- Run it as `XDEBUG_MODE=coverage composer test -- --coverage`.

If `composer test:profile` does not write any cachegrind files:

- Re-run `php -m | grep -Ei '^xdebug$'` and confirm the active CLI actually loads Xdebug.
- Re-run `php --ini` and confirm the extension is loaded from the CLI config, not just FPM or Apache.
- Set an explicit writable directory with `COQUI_TEST_PROFILE_OUTPUT_DIR=build/profiles/tests/debug composer test:profile`.
- If you are profiling the application directly, include both `XDEBUG_MODE=profile` and an `XDEBUG_CONFIG` value with `output_dir=...`.

If coverage works in CI but not locally:

- Compare your local `php -v`, `php -m`, and `php --ini` output with the CI environment.
- Make sure you are using the same PHP major/minor version the repo targets.

If tests fail only on shell-heavy suites:

- Run them from macOS, Linux, or WSL2.
- Windows CI intentionally does not treat all Unix shell behavior as a product bug.
