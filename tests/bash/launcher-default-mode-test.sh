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

tmpdir=$(mktemp -d)
trap 'rm -rf "$tmpdir"' EXIT

mkdir -p "$tmpdir/bin" "$tmpdir/.workspace"
cp bin/coqui "$tmpdir/bin/coqui"
cp bin/coqui-launcher "$tmpdir/bin/coqui-launcher"

cat > "$tmpdir/bin/coqui-console" <<'STUB'
#!/usr/bin/env php
<?php

declare(strict_types=1);

$logfile = getenv('COQUI_TEST_LOG');
if (!is_string($logfile) || $logfile === '') {
    fwrite(STDERR, "COQUI_TEST_LOG is required\n");
    exit(1);
}

$arguments = $argv;
array_shift($arguments);
$mode = $arguments[0] ?? 'run';

if ($mode === 'api') {
    array_shift($arguments);
    $host = '127.0.0.1';
    $port = '3300';

    while ($arguments !== []) {
        $flag = array_shift($arguments);
        if ($flag === '--host' && $arguments !== []) {
            $host = (string) array_shift($arguments);
            continue;
        }

        if ($flag === '--port' && $arguments !== []) {
            $port = (string) array_shift($arguments);
            continue;
        }
    }

    file_put_contents($logfile, sprintf("api-start %d %s %s\n", getmypid(), $host, $port), FILE_APPEND);
    register_shutdown_function(static function () use ($logfile): void {
        file_put_contents($logfile, sprintf("api-stop %d\n", getmypid()), FILE_APPEND);
    });

    $server = @stream_socket_server(sprintf('tcp://%s:%s', $host, $port), $errno, $error);
    if ($server === false) {
        fwrite(STDERR, sprintf("%s (%d)\n", $error, $errno));
        exit(1);
    }

    stream_set_blocking($server, false);
    while (true) {
        $read = [$server];
        $write = null;
        $except = null;
        $changed = @stream_select($read, $write, $except, 1);
        if ($changed === false || $changed === 0) {
            continue;
        }

        $connection = @stream_socket_accept($server, 0);
        if (is_resource($connection)) {
            fclose($connection);
        }
    }
}

if ($mode === 'doctor') {
    file_put_contents($logfile, sprintf("doctor-run %d\n", getmypid()), FILE_APPEND);
    exit(0);
}

if ($mode === '--wizard') {
    file_put_contents($logfile, sprintf("wizard-run %d\n", getmypid()), FILE_APPEND);
    exit(0);
}

file_put_contents($logfile, sprintf("repl-run %d\n", getmypid()), FILE_APPEND);
exit(0);
STUB

chmod +x "$tmpdir/bin/coqui" "$tmpdir/bin/coqui-launcher" "$tmpdir/bin/coqui-console"

if bash -euo pipefail -c '
    port=$((44000 + ($$ % 1000)))
    export COQUI_TEST_LOG="$1/launcher.log"

    "$1/bin/coqui" --port "$port" --verbose >/tmp/coqui-launcher-default-test.out 2>&1

    grep -q "repl-run" "$COQUI_TEST_LOG"
    grep -q "api-start" "$COQUI_TEST_LOG"

    if ps -axo command= | grep -F "$1/bin/coqui-console" | grep -v grep >/dev/null; then
        exit 1
    fi

    if lsof -nP -iTCP:"$port" -sTCP:LISTEN 2>/dev/null | grep -F "$port" >/dev/null; then
        exit 1
    fi
' _ "$tmpdir"; then
    pass "public coqui entrypoint starts launcher-managed REPL+API and leaves no console stub processes behind"
else
    fail "public coqui entrypoint failed to run launcher-managed default mode cleanly"
fi

if bash -euo pipefail -c '
    export COQUI_TEST_LOG="$1/doctor.log"
    : > "$COQUI_TEST_LOG"

    "$1/bin/coqui" doctor >/tmp/coqui-doctor-test.out 2>&1

    grep -q "doctor-run" "$COQUI_TEST_LOG"
    ! grep -q "api-start" "$COQUI_TEST_LOG"
    ! grep -q "repl-run" "$COQUI_TEST_LOG"
' _ "$tmpdir"; then
    pass "public coqui entrypoint preserves explicit advanced console commands"
else
    fail "public coqui entrypoint did not preserve explicit advanced console command routing"
fi

if bash -euo pipefail -c '
    export COQUI_TEST_LOG="$1/run-alias.log"
    : > "$COQUI_TEST_LOG"

    "$1/bin/coqui" run --new >/tmp/coqui-run-alias-test.out 2>&1

    grep -q "repl-run" "$COQUI_TEST_LOG"
    ! grep -q "api-start" "$COQUI_TEST_LOG"
' _ "$tmpdir"; then
    pass "coqui run stays on REPL-only launcher mode"
else
    fail "coqui run did not stay on REPL-only launcher mode"
fi

if bash -euo pipefail -c '
    export COQUI_TEST_LOG="$1/setup-alias.log"
    : > "$COQUI_TEST_LOG"

    "$1/bin/coqui" setup >/tmp/coqui-setup-alias-test.out 2>&1

    grep -q "wizard-run" "$COQUI_TEST_LOG"
    ! grep -q "api-start" "$COQUI_TEST_LOG"
    ! grep -q "repl-run" "$COQUI_TEST_LOG"
' _ "$tmpdir"; then
    pass "coqui setup routes through the launcher wizard mode"
else
    fail "coqui setup did not route through the launcher wizard mode"
fi

if bash -euo pipefail -c '
    port=$((45000 + ($$ % 1000)))
    export COQUI_TEST_LOG="$1/api-only.log"
    : > "$COQUI_TEST_LOG"

    "$1/bin/coqui" api --background --port "$port" >/tmp/coqui-api-only-test.out 2>&1

    for _ in 1 2 3 4 5 6 7 8 9 10; do
        if lsof -nP -iTCP:"$port" -sTCP:LISTEN 2>/dev/null | grep -F "$port" >/dev/null; then
            break
        fi
        sleep 0.2
    done

    grep -q "api-start" "$COQUI_TEST_LOG"
    ! grep -q "repl-run" "$COQUI_TEST_LOG"
    lsof -nP -iTCP:"$port" -sTCP:LISTEN 2>/dev/null | grep -F "$port" >/dev/null

    "$1/bin/coqui" stop-api --port "$port" >/tmp/coqui-stop-api-test.out 2>&1

    if lsof -nP -iTCP:"$port" -sTCP:LISTEN 2>/dev/null | grep -F "$port" >/dev/null; then
        exit 1
    fi
' _ "$tmpdir"; then
    pass "coqui api routes to launcher-managed API-only mode without starting the REPL"
else
    fail "coqui api did not behave as launcher-managed API-only mode"
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