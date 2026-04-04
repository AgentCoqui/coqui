#!/usr/bin/env bash
# ci-test.sh
#
# Mirrors the GitHub Actions CI pipeline locally.
# Runs Pest tests and PHPStan static analysis in sequence.
#
# Usage:
#   ./scripts/ci-test.sh              # Run tests + PHPStan
#   ./scripts/ci-test.sh --install    # Install deps first, then run checks

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

cd "$PROJECT_ROOT"

# Colors (disabled if not a terminal)
if [[ -t 1 ]]; then
    GREEN='\033[0;32m'
    RED='\033[0;31m'
    YELLOW='\033[1;33m'
    BLUE='\033[0;34m'
    NC='\033[0m'
else
    GREEN='' RED='' YELLOW='' BLUE='' NC=''
fi

info()  { echo -e "${BLUE}[CI]${NC} $*"; }
ok()    { echo -e "${GREEN}[OK]${NC} $*"; }
fail()  { echo -e "${RED}[FAIL]${NC} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }

# Required extensions for Coqui
REQUIRED_EXTENSIONS=(pdo_sqlite mbstring curl xml)
OPTIONAL_EXTENSIONS=(pcntl zip)

# ---------------------------------------------------------------------------
# Preflight checks
# ---------------------------------------------------------------------------
check_requirements() {
    local missing=0

    if ! command -v php &>/dev/null; then
        fail "PHP is not installed or not in PATH"
        missing=1
    else
        local php_version
        php_version=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
        if [[ "$(echo "$php_version 8.4" | awk '{print ($1 >= $2)}')" != "1" ]]; then
            fail "PHP $php_version found — 8.4+ is required"
            missing=1
        else
            info "PHP $php_version"
        fi

        # Check required extensions
        for ext in "${REQUIRED_EXTENSIONS[@]}"; do
            if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
                fail "Missing required PHP extension: $ext"
                missing=1
            fi
        done

        # Check optional extensions
        for ext in "${OPTIONAL_EXTENSIONS[@]}"; do
            if ! php -m 2>/dev/null | grep -qi "^${ext}$"; then
                warn "Optional PHP extension not loaded: $ext (tests may still pass)"
            fi
        done
    fi

    if ! command -v composer &>/dev/null; then
        fail "Composer is not installed or not in PATH"
        missing=1
    fi

    if [[ $missing -ne 0 ]]; then
        echo ""
        fail "Missing requirements. See docs/TESTING.md for local setup instructions."
        exit 1
    fi
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
main() {
    local do_install=0
    local do_coverage=0

    for arg in "$@"; do
        case "$arg" in
            --install) do_install=1 ;;
            --coverage) do_coverage=1 ;;
            --help|-h)
                echo "Usage: $0 [--install] [--coverage]"
                echo ""
                echo "Options:"
                echo "  --install    Run composer install before tests"
                echo "  --coverage   Run the coverage-enabled Pest command"
                echo ""
                echo "Mirrors the GitHub Actions CI pipeline locally."
                echo "Runs: composer test or composer test:coverage, composer analyse"
                exit 0
                ;;
            *)
                warn "Unknown argument: $arg"
                ;;
        esac
    done

    echo ""
    info "Coqui CI — local pipeline"
    echo ""

    check_requirements

    # Install dependencies
    if [[ $do_install -eq 1 ]]; then
        echo ""
        info "Installing dependencies..."
        composer install --prefer-dist --no-progress --no-interaction
        ok "Dependencies installed"
    elif [[ ! -d "$PROJECT_ROOT/vendor" ]]; then
        warn "vendor/ not found — run with --install or run 'composer install' first"
        exit 1
    fi

    # Run tests
    echo ""
    if [[ $do_coverage -eq 1 ]]; then
        info "Running tests with coverage (Pest)..."
        if composer test:coverage; then
            ok "Coverage run passed"
        else
            fail "Coverage run failed"
            exit 1
        fi
    else
        info "Running tests (Pest)..."
        if composer test; then
            ok "Tests passed"
        else
            fail "Tests failed"
            exit 1
        fi
    fi

    # Run static analysis
    echo ""
    info "Running static analysis (PHPStan level 8, bleeding edge)..."
    if composer analyse; then
        ok "Static analysis passed"
    else
        fail "Static analysis failed"
        exit 1
    fi

    echo ""
    ok "All CI checks passed"
}

main "$@"
