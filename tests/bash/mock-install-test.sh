#!/usr/bin/env bash

set -euo pipefail

PASS=0
FAIL=0
ERRORS=()

pass() { PASS=$((PASS + 1)); echo "  ✓  $1"; }
fail() { FAIL=$((FAIL + 1)); ERRORS+=("$1"); echo "  ✗  $1"; }

echo ""
echo "  mock-install.sh — dev install regression test"
echo ""

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT

run_mock_install() {
    local install_dir="$1"
    local bin_dir="$2"
    shift 2

    (
        cd "$repo_root"
        PATH="$bin_dir:$PATH" \
        COQUI_INSTALL_DIR="$install_dir" \
        COQUI_BIN_DIR="$bin_dir" \
        COQUI_SOURCE_DIR="$repo_root" \
        ./scripts/mock-install.sh "$@"
    )
}

if bash -euo pipefail -c '
    install_dir="$1/install"
    bin_dir="$1/bin"

    run_mock_install() {
        (
            cd "$2"
            PATH="$bin_dir:$PATH" \
            COQUI_INSTALL_DIR="$install_dir" \
            COQUI_BIN_DIR="$bin_dir" \
            COQUI_SOURCE_DIR="$2" \
            ./scripts/mock-install.sh --force --quiet >/tmp/coqui-mock-install-create.out 2>&1
        )
    }

    run_mock_install "$1" "$2"

    [ -L "$install_dir" ]
    [ "$(cd "$install_dir" && pwd -P)" = "$2" ]
    [ -L "$bin_dir/coqui" ]
    [ "$(readlink "$bin_dir/coqui")" = "$install_dir/bin/coqui" ]
    [ -f "$install_dir.mock-install.env" ]
    grep -q "^type=mock-dev-install$" "$install_dir.mock-install.env"
    grep -q "^install_dir=$install_dir$" "$install_dir.mock-install.env"
    grep -q "^source_dir=$2$" "$install_dir.mock-install.env"
' _ "$tmpdir" "$repo_root"; then
    pass "mock install creates install symlink, command symlinks, and marker file"
else
    fail "mock install did not create the expected symlinks and marker file"
fi

if bash -euo pipefail -c '
    install_dir="$1/existing-install"
    bin_dir="$1/existing-bin"
    mkdir -p "$install_dir"
    echo legacy > "$install_dir/keep.txt"

    if (
        cd "$2"
        PATH="$bin_dir:$PATH" \
        COQUI_INSTALL_DIR="$install_dir" \
        COQUI_BIN_DIR="$bin_dir" \
        COQUI_SOURCE_DIR="$2" \
        ./scripts/mock-install.sh --quiet </dev/null >/tmp/coqui-mock-install-existing.out 2>&1
    ); then
        exit 1
    fi

    grep -q "Re-run with --force" /tmp/coqui-mock-install-existing.out
    [ -d "$install_dir" ]
    [ ! -L "$install_dir" ]
    [ -f "$install_dir/keep.txt" ]
' _ "$tmpdir" "$repo_root"; then
    pass "mock install refuses to replace an existing install non-interactively without force"
else
    fail "mock install did not protect an existing install without force"
fi

if bash -euo pipefail -c '
    install_dir="$1/replace-install"
    bin_dir="$1/replace-bin"
    mkdir -p "$install_dir"
    echo legacy > "$install_dir/old.txt"

    (
        cd "$2"
        PATH="$bin_dir:$PATH" \
        COQUI_INSTALL_DIR="$install_dir" \
        COQUI_BIN_DIR="$bin_dir" \
        COQUI_SOURCE_DIR="$2" \
        COQUI_VERSION="dev-test-version" \
        ./scripts/mock-install.sh --force --quiet >/tmp/coqui-mock-install-force.out 2>&1
    )

    [ -L "$install_dir" ]
    [ "$(cd "$install_dir" && pwd -P)" = "$2" ]
    [ ! -e "$1/replace-install/old.txt" ]
    grep -q "^version=dev-test-version$" "$install_dir.mock-install.env"
' _ "$tmpdir" "$repo_root"; then
    pass "mock install force-replaces an existing install and records the requested version"
else
    fail "mock install did not force-replace an existing install correctly"
fi

echo ""
echo "  Results: ${PASS} passed, ${FAIL} failed"

if [[ $FAIL -gt 0 ]]; then
    echo ""
    echo "  Failures:"
    for err in "${ERRORS[@]}"; do
        echo "    - $err"
    done
    echo ""
    exit 1
fi

echo ""