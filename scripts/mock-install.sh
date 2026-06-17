#!/usr/bin/env bash

set -euo pipefail

COQUI_INSTALL_DIR="${COQUI_INSTALL_DIR:-$HOME/.coqui}"
COQUI_BIN_DIR="${COQUI_BIN_DIR:-}"
COQUI_SOURCE_DIR="${COQUI_SOURCE_DIR:-$PWD}"
FORCE=false
QUIET=false

usage() {
    cat <<'EOF'
Usage: scripts/mock-install.sh [options]

Create a dev-only Coqui install by symlinking a source checkout into the
expected install path and refreshing the public command symlinks.

Options:
  --source PATH         Source checkout to install (default: current directory)
  --install-dir PATH    Install path to replace with a symlink (default: ~/.coqui)
  --bin-dir PATH        Directory for the coqui command symlink
  --force               Replace an existing install path without prompting
  --quiet, -q           Minimal output
  --help, -h            Show this help

Environment overrides:
  COQUI_SOURCE_DIR      Same as --source
  COQUI_INSTALL_DIR     Same as --install-dir
  COQUI_BIN_DIR         Same as --bin-dir
  COQUI_VERSION         Optional version override for the mock-install marker
EOF
}

status() {
    if [ "$QUIET" = false ]; then
        printf '  -> %s\n' "$*"
    fi
}

success() {
    printf '  OK %s\n' "$*"
}

warn() {
    printf '  !! %s\n' "$*"
}

fatal() {
    printf '  XX %s\n' "$*" >&2
    exit 1
}

available() {
    command -v "$1" >/dev/null 2>&1
}

parse_args() {
    while [ $# -gt 0 ]; do
        case "$1" in
            --source)
                [ $# -ge 2 ] || fatal "Missing value for --source"
                COQUI_SOURCE_DIR="$2"
                shift 2
                ;;
            --install-dir)
                [ $# -ge 2 ] || fatal "Missing value for --install-dir"
                COQUI_INSTALL_DIR="$2"
                shift 2
                ;;
            --bin-dir)
                [ $# -ge 2 ] || fatal "Missing value for --bin-dir"
                COQUI_BIN_DIR="$2"
                shift 2
                ;;
            --force)
                FORCE=true
                shift
                ;;
            --quiet|-q)
                QUIET=true
                shift
                ;;
            --help|-h)
                usage
                exit 0
                ;;
            *)
                fatal "Unknown argument: $1"
                ;;
        esac
    done
}

resolve_existing_dir() {
    local path="$1"

    if [ ! -d "$path" ]; then
        return 1
    fi

    (
        cd "$path" >/dev/null 2>&1
        pwd -P
    )
}

resolve_path_or_fail() {
    local path="$1"
    local label="$2"

    [ -d "$path" ] || fatal "$label does not exist: $path"

    (
        cd "$path" >/dev/null 2>&1
        pwd -P
    )
}

validate_source_dir() {
    COQUI_SOURCE_DIR="$(resolve_path_or_fail "$COQUI_SOURCE_DIR" "Source directory")"

    [ -f "$COQUI_SOURCE_DIR/composer.json" ] || fatal "Source directory is missing composer.json: $COQUI_SOURCE_DIR"
    [ -x "$COQUI_SOURCE_DIR/bin/coqui" ] || fatal "Source directory is missing bin/coqui: $COQUI_SOURCE_DIR"
}

detect_bin_dir() {
    if [ -n "$COQUI_BIN_DIR" ]; then
        return
    fi

    if printf '%s' "$PATH" | tr ':' '\n' | grep -qx '/opt/homebrew/bin' && [ -w '/opt/homebrew/bin' ]; then
        COQUI_BIN_DIR='/opt/homebrew/bin'
        return
    fi

    if printf '%s' "$PATH" | tr ':' '\n' | grep -qx '/usr/local/bin' && [ -w '/usr/local/bin' ]; then
        COQUI_BIN_DIR='/usr/local/bin'
        return
    fi

    COQUI_BIN_DIR="$HOME/.local/bin"
}

confirm_replace() {
    local path="$1"

    if [ "$FORCE" = true ]; then
        return 0
    fi

    if [ ! -t 0 ]; then
        fatal "Install path already exists: $path. Re-run with --force to replace it non-interactively."
    fi

    printf '  -> Replace existing install at %s? [y/N] ' "$path"

    local reply
    read -r reply
    case "$reply" in
        y|Y|yes|YES)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

ensure_install_link() {
    local existing_target=""

    if [ -L "$COQUI_INSTALL_DIR" ]; then
        existing_target="$(resolve_existing_dir "$COQUI_INSTALL_DIR" || true)"
        if [ -n "$existing_target" ] && [ "$existing_target" = "$COQUI_SOURCE_DIR" ]; then
            status "Install path already points to this checkout"
            return 0
        fi
    fi

    if [ -e "$COQUI_INSTALL_DIR" ] || [ -L "$COQUI_INSTALL_DIR" ]; then
        if [ -d "$COQUI_INSTALL_DIR/.workspace" ] || [ -L "$COQUI_INSTALL_DIR/.workspace" ]; then
            warn "Replacing $COQUI_INSTALL_DIR will also replace its current .workspace path"
        fi

        confirm_replace "$COQUI_INSTALL_DIR" || fatal "Mock install cancelled"
        status "Removing existing install path"
        rm -rf "$COQUI_INSTALL_DIR"
    fi

    mkdir -p "$(dirname "$COQUI_INSTALL_DIR")"
    ln -s "$COQUI_SOURCE_DIR" "$COQUI_INSTALL_DIR"
    status "Created install symlink"
}

resolve_version() {
    if [ -n "${COQUI_VERSION:-}" ]; then
        printf '%s' "$COQUI_VERSION"
        return
    fi

    if [ -f "$COQUI_SOURCE_DIR/config/version.txt" ]; then
        local version
        version="$(tr -d '\r' < "$COQUI_SOURCE_DIR/config/version.txt" | head -n 1 | tr -d '\n')"
        if [ -n "$version" ]; then
            printf '%s' "$version"
            return
        fi
    fi

    if available git && git -C "$COQUI_SOURCE_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
        git -C "$COQUI_SOURCE_DIR" describe --tags --always --dirty 2>/dev/null | sed 's/^v//'
        return
    fi

    printf 'dev'
}

write_mock_marker() {
    local marker_path="${COQUI_INSTALL_DIR}.mock-install.env"
    local version
    local git_head=""
    local installed_at

    version="$(resolve_version)"
    installed_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

    if available git && git -C "$COQUI_SOURCE_DIR" rev-parse --short HEAD >/dev/null 2>&1; then
        git_head="$(git -C "$COQUI_SOURCE_DIR" rev-parse --short HEAD 2>/dev/null || true)"
    fi

    cat > "$marker_path" <<EOF
type=mock-dev-install
install_dir=$COQUI_INSTALL_DIR
source_dir=$COQUI_SOURCE_DIR
version=$version
git_head=$git_head
installed_at=$installed_at
EOF

    status "Wrote mock-install marker to $marker_path"
}

create_command_symlinks() {
    detect_bin_dir

    mkdir -p "$COQUI_BIN_DIR"
    ln -sf "$COQUI_INSTALL_DIR/bin/coqui" "$COQUI_BIN_DIR/coqui"

    status "Updated command symlinks in $COQUI_BIN_DIR"

    if ! printf '%s' "$PATH" | tr ':' '\n' | grep -qx "$COQUI_BIN_DIR"; then
        warn "$COQUI_BIN_DIR is not currently in PATH"
    fi
}

print_summary() {
    local marker_path="${COQUI_INSTALL_DIR}.mock-install.env"

    printf '\n'
    success "Mock install ready"
    printf '     install: %s -> %s\n' "$COQUI_INSTALL_DIR" "$COQUI_SOURCE_DIR"
    printf '     commands: %s/coqui\n' "$COQUI_BIN_DIR"
    printf '     marker: %s\n' "$marker_path"
    printf '\n'
    printf '     This is a dev-only symlinked install. Because the install root is a symlink,\n'
    printf '     the default workspace path resolves through it as well.\n'
    printf '\n'
}

main() {
    parse_args "$@"
    validate_source_dir
    ensure_install_link
    create_command_symlinks
    write_mock_marker
    print_summary
}

main "$@"