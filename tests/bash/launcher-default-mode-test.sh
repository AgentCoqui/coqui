#!/usr/bin/env bash

set -euo pipefail

PASS=0
FAIL=0
ERRORS=()

pass() { PASS=$((PASS + 1)); echo "  ✓  $1"; }
fail() { FAIL=$((FAIL + 1)); ERRORS+=("$1"); echo "  ✗  $1"; }

echo ""
echo "  coqui-launcher — default mode end-to-end test"
echo ""

if ! command -v perl >/dev/null 2>&1; then
    pass "default mode test skipped (perl not available for stub listener)"
else
    tmpdir=$(mktemp -d)
    trap 'rm -rf "$tmpdir"' EXIT

    mkdir -p "$tmpdir/bin" "$tmpdir/.workspace"
    cp bin/coqui-launcher "$tmpdir/bin/coqui-launcher"

    cat > "$tmpdir/bin/coqui" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail

logfile="${COQUI_TEST_LOG:?}"
mode="${1:-run}"

if [[ "$mode" == "api" ]]; then
    shift
    host="127.0.0.1"
    port="3300"

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --host)
                host="$2"
                shift 2
                ;;
            --port)
                port="$2"
                shift 2
                ;;
            *)
                shift
                ;;
        esac
    done

    echo "api-start $$ $host $port" >> "$logfile"

    cleanup() {
        echo "api-stop $$" >> "$logfile"
        if [[ -n "${listener_pid:-}" ]]; then
            kill "$listener_pid" 2>/dev/null || true
            wait "$listener_pid" 2>/dev/null || true
        fi
        exit 0
    }

    trap cleanup TERM INT HUP EXIT

    perl -MIO::Socket::INET -e '
        $SIG{TERM} = sub { exit 0 };
        $SIG{INT} = sub { exit 0 };
        $SIG{HUP} = sub { exit 0 };
        my ($host, $port) = @ARGV;
        my $server = IO::Socket::INET->new(
            LocalAddr => $host,
            LocalPort => $port,
            Listen => 5,
            ReuseAddr => 1,
            Proto => q(tcp),
        ) or die $!;
        while (1) { sleep 1; }
    ' "$host" "$port" &
    listener_pid=$!
    wait "$listener_pid"
    exit 0
fi

echo "repl-run $$" >> "$logfile"
exit 0
STUB

    chmod +x "$tmpdir/bin/coqui" "$tmpdir/bin/coqui-launcher"

    if bash -euo pipefail -c '
        port=$((44000 + ($$ % 1000)))
        export COQUI_TEST_LOG="$1/launcher.log"

        "$1/bin/coqui-launcher" --port "$port" --verbose >/tmp/coqui-launcher-default-test.out 2>&1

        grep -q "repl-run" "$COQUI_TEST_LOG"
        grep -q "api-start" "$COQUI_TEST_LOG"

        if ps -axo command= | grep -F "$1/bin/coqui" | grep -v grep >/dev/null; then
            exit 1
        fi

        if lsof -nP -iTCP:"$port" -sTCP:LISTEN 2>/dev/null | grep -F "$port" >/dev/null; then
            exit 1
        fi
    ' _ "$tmpdir"; then
        pass "default launcher mode starts REPL+API and leaves no stub processes behind"
    else
        fail "default launcher mode left stub processes or failed to execute the expected flow"
    fi
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