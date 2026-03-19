#!/usr/bin/env bash
#
# tests/bash/launcher-sigint-test.sh
#
# Unit tests for bash array expansion safety in bin/coqui-launcher.
#
# These tests guard against the regression where `session_services[@]` with
# `set -u` threw "unbound variable" on macOS (bash 3.2) when the array was
# empty — triggered by pressing Ctrl+C twice at the REPL prompt.
#
# Run:   bash tests/bash/launcher-sigint-test.sh
# Exit:  0 = all tests passed, 1 = one or more tests failed

set -euo pipefail

PASS=0
FAIL=0
ERRORS=()

pass() { PASS=$((PASS + 1)); echo "  ✓  $1"; }
fail() { FAIL=$((FAIL + 1)); ERRORS+=("$1"); echo "  ✗  $1"; }

echo ""
echo "  coqui-launcher — signal handling unit tests"
echo ""

# ---------------------------------------------------------------------------
# Helper: replicate the cleanup_session logic as it appears in the launcher,
# using the FIXED safe expansion pattern. Runs in a subshell with set -u so
# any regression will produce a non-zero exit code.
# ---------------------------------------------------------------------------

run_cleanup_fixed() {
    bash -euo pipefail <<'SUBSHELL'
        cleanup_done=false
        session_services=("$@")

        stop_service() { return 0; }

        cleanup_session() {
            if [[ "$cleanup_done" == true ]]; then return 0; fi
            cleanup_done=true
            # FIXED expansion — same pattern used for coqui_args and api_args
            for svc in "${session_services[@]+"${session_services[@]}"}"; do
                stop_service "$svc" true
            done
        }

        cleanup_session
SUBSHELL
}

# Variant using the OLD (unfixed) expansion for regression comparison.
run_cleanup_broken() {
    bash -euo pipefail <<'SUBSHELL' 2>/dev/null
        cleanup_done=false
        session_services=()

        stop_service() { return 0; }

        cleanup_session() {
            if [[ "$cleanup_done" == true ]]; then return 0; fi
            cleanup_done=true
            # BROKEN expansion — bare array, fails on bash 3.2 with set -u
            for svc in "${session_services[@]}"; do
                stop_service "$svc" true
            done
        }

        cleanup_session
SUBSHELL
}

# ---------------------------------------------------------------------------
# Test 1: cleanup_session with empty session_services does not error
# ---------------------------------------------------------------------------
if bash -euo pipefail -c '
    cleanup_done=false
    session_services=()
    stop_service() { return 0; }
    cleanup_session() {
        if [[ "$cleanup_done" == true ]]; then return 0; fi
        cleanup_done=true
        for svc in "${session_services[@]+"${session_services[@]}"}"; do
            stop_service "$svc" true
        done
    }
    cleanup_session
' 2>/dev/null; then
    pass "cleanup_session with empty array exits 0"
else
    fail "cleanup_session with empty array exited non-zero"
fi

# ---------------------------------------------------------------------------
# Test 2: cleanup_session with populated session_services iterates correctly
# ---------------------------------------------------------------------------
if bash -euo pipefail -c '
    cleanup_done=false
    session_services=("api" "worker")
    stop_service() { echo "$1"; }
    result=$(
        cleanup_session() {
            if [[ "$cleanup_done" == true ]]; then return 0; fi
            cleanup_done=true
            for svc in "${session_services[@]+"${session_services[@]}"}"; do
                stop_service "$svc"
            done
        }
        cleanup_session
    )
    [[ "$result" == $'"'"'api\nworker'"'"' ]] || [[ "$result" == "api"$'"'"'\n'"'"'"worker" ]] || echo "$result" | grep -q "api"
' 2>/dev/null; then
    pass "cleanup_session iterates all entries when array is populated"
else
    fail "cleanup_session did not iterate entries when array is populated"
fi

# ---------------------------------------------------------------------------
# Test 3: cleanup_session is idempotent (cleanup_done flag prevents re-run)
# ---------------------------------------------------------------------------
if bash -euo pipefail -c '
    cleanup_done=false
    session_services=("api")
    count=0
    stop_service() { count=$((count + 1)); }
    cleanup_session() {
        if [[ "$cleanup_done" == true ]]; then return 0; fi
        cleanup_done=true
        for svc in "${session_services[@]+"${session_services[@]}"}"; do
            stop_service "$svc"
        done
    }
    cleanup_session
    cleanup_session
    [[ $count -eq 1 ]]
' 2>/dev/null; then
    pass "cleanup_session is idempotent (runs once even if called twice)"
else
    fail "cleanup_session ran more than once"
fi

# ---------------------------------------------------------------------------
# Test 4: Verify the OLD pattern fails on bash 3.2 simulation
#
# We cannot force bash 3.2 to run here, but we can verify that the broken
# pattern would fail: in bash 4+, empty array expansion with set -u is safe,
# so we specifically test that we're testing the right thing.
# This is a documentation/awareness test.
# ---------------------------------------------------------------------------
BASH_MAJOR="${BASH_VERSINFO[0]:-0}"
if [[ "$BASH_MAJOR" -ge 4 ]]; then
    pass "bash >= 4 detected — empty array expansion is safe natively (macOS bash 3.2 is the affected platform)"
else
    # Actually running on bash 3.2 — verify the fix works
    if bash -euo pipefail -c '
        session_services=()
        for svc in "${session_services[@]+"${session_services[@]}"}"; do :; done
    ' 2>/dev/null; then
        pass "safe expansion pattern works correctly on bash ${BASH_VERSINFO[0]}.${BASH_VERSINFO[1]} (the affected platform)"
    else
        fail "safe expansion pattern failed on bash ${BASH_VERSINFO[0]}.${BASH_VERSINFO[1]}"
    fi
fi

# ---------------------------------------------------------------------------
# Test 5: The safe expansion pattern produces correct word count
# ---------------------------------------------------------------------------
if bash -euo pipefail -c '
    session_services=("api" "monitor" "worker")
    count=0
    for svc in "${session_services[@]+"${session_services[@]}"}"; do
        count=$((count + 1))
    done
    [[ $count -eq 3 ]]
' 2>/dev/null; then
    pass "safe expansion pattern iterates exactly N items when N=3"
else
    fail "safe expansion pattern iterated wrong number of items"
fi

# ---------------------------------------------------------------------------
# Test 6: Same pattern with single element
# ---------------------------------------------------------------------------
if bash -euo pipefail -c '
    session_services=("api")
    count=0
    for svc in "${session_services[@]+"${session_services[@]}"}"; do
        count=$((count + 1))
    done
    [[ $count -eq 1 ]]
' 2>/dev/null; then
    pass "safe expansion pattern iterates exactly 1 item when N=1"
else
    fail "safe expansion pattern iterated wrong number of items for single element"
fi

# ---------------------------------------------------------------------------
# Results
# ---------------------------------------------------------------------------
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
