#!/usr/bin/env bash
#
# scripts/build.sh — Build a production-ready Coqui release package
#
# Usage:
#   scripts/build.sh                    # auto-detect version from latest git tag
#   scripts/build.sh --version 0.0.1    # explicit version
#
# Produces:
#   BUILD/dist/coqui-v{VERSION}.zip
#   BUILD/dist/coqui-v{VERSION}.tar.gz
#   BUILD/dist/coqui-v{VERSION}.zip.sha256
#   BUILD/dist/coqui-v{VERSION}.tar.gz.sha256
#
set -euo pipefail

# ─── Colors ───────────────────────────────────────────────────────────────────

BOLD="\033[1m"
GREEN="\033[32m"
YELLOW="\033[33m"
RED="\033[31m"
RESET="\033[0m"

step()  { echo -e "\n${BOLD}${GREEN}▸ $1${RESET}"; }
info()  { echo -e "  ${GREEN}✓${RESET} $1"; }
warn()  { echo -e "  ${YELLOW}⚠${RESET} $1"; }
error() { echo -e "  ${RED}✗${RESET} $1" >&2; }
die()   { error "$1"; exit 1; }

# ─── Parse arguments ─────────────────────────────────────────────────────────

VERSION=""
REFRESH_MODEL_DEFAULTS=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --version|-v)
            VERSION="$2"
            shift 2
            ;;
        --refresh-model-defaults)
            REFRESH_MODEL_DEFAULTS=1
            shift
            ;;
        --help|-h)
            echo "Usage: $0 [--version VERSION] [--refresh-model-defaults]"
            echo ""
            echo "Build a production-ready Coqui release package."
            echo ""
            echo "Options:"
            echo "  --version, -v    Version string (e.g. 0.0.1). Default: auto-detect from git tag."
            echo "  --refresh-model-defaults  Refresh provider-backed curated model catalogs before packaging."
            echo "  --help, -h       Show this help message."
            exit 0
            ;;
        *)
            die "Unknown argument: $1"
            ;;
    esac
done

# ─── Resolve project root ────────────────────────────────────────────────────

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
cd "${PROJECT_ROOT}"

# ─── Resolve version ─────────────────────────────────────────────────────────

if [[ -z "${VERSION}" ]]; then
    if git describe --tags --exact-match HEAD >/dev/null 2>&1; then
        VERSION="$(git describe --tags --exact-match HEAD | sed 's/^v//')"
        info "Detected version from git tag: ${VERSION}"
    elif git describe --tags --abbrev=0 >/dev/null 2>&1; then
        VERSION="$(git describe --tags --abbrev=0 | sed 's/^v//')"
        warn "HEAD is not tagged — using nearest tag: ${VERSION}"
    else
        die "No git tag found and no --version specified. Use: $0 --version 0.0.1"
    fi
fi

echo -e "\n${BOLD}Building Coqui v${VERSION}${RESET}"

# ─── Preflight checks ────────────────────────────────────────────────────────

step "Preflight checks"

command -v git      >/dev/null || die "git is required"
command -v php      >/dev/null || die "php is required"
command -v composer >/dev/null || die "composer is required"
command -v zip      >/dev/null || die "zip is required"
command -v tar      >/dev/null || die "tar is required"

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
if [[ "$(printf '%s\n' "8.4" "${PHP_VERSION}" | sort -V | head -n1)" != "8.4" ]]; then
    die "PHP 8.4+ required (found ${PHP_VERSION})"
fi

info "git, php ${PHP_VERSION}, composer, zip, tar — all present"

if [[ "${REFRESH_MODEL_DEFAULTS}" == "1" ]]; then
    step "Refreshing provider-backed model defaults"
    php scripts/generate-model-defaults.php --write
    info "Model defaults refreshed"
fi

# ─── Setup directories ───────────────────────────────────────────────────────

step "Setting up build directories"

BUILD_DIR="${PROJECT_ROOT}/BUILD"
STAGE_DIR="${BUILD_DIR}/coqui"
DIST_DIR="${BUILD_DIR}/dist"
ARTIFACT_BASE="coqui-v${VERSION}"

rm -rf "${STAGE_DIR}"
mkdir -p "${STAGE_DIR}" "${DIST_DIR}"

info "Stage: ${STAGE_DIR}"
info "Dist:  ${DIST_DIR}"

# ─── Export clean source via git archive ──────────────────────────────────────

step "Exporting clean source (respects .gitattributes export-ignore)"

git archive HEAD | tar -x -C "${STAGE_DIR}"

info "Source exported"

# ─── Remove dev files (belt-and-suspenders with .gitattributes) ───────────────

step "Removing development files"

(
    cd "${STAGE_DIR}"

    # CI / dev tooling
    rm -rf .github/ 2>/dev/null || true
    rm -f .dockerignore Dockerfile 2>/dev/null || true
    rm -f phpstan.neon phpunit.xml 2>/dev/null || true
    rm -f AGENTS.md .claude 2>/dev/null || true
    rm -f .editorconfig .php-cs-fixer.php 2>/dev/null || true

    # Tests, examples, build scripts
    rm -rf tests/ scripts/ examples/ 2>/dev/null || true

    # Sample OpenClaw configs
    rm -f openclaw-claude.json openclaw-ollama.json openclaw-openai.json openclaw-xai.json 2>/dev/null || true
)

info "Development files removed"

# ─── Inject version ──────────────────────────────────────────────────────────

step "Writing build version ${VERSION}"

printf '%s\n' "${VERSION}" > "${STAGE_DIR}/config/version.txt"

info "config/version.txt set to ${VERSION}"

# ─── Production Composer install ──────────────────────────────────────────────

step "Installing production dependencies"

(
    cd "${STAGE_DIR}"

    # Remove lock file so composer resolves fresh with --no-dev
    # (the lock may include dev dependencies)
    rm -f composer.lock

    composer install \
        --no-dev \
        --classmap-authoritative \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader

    info "Main dependencies installed"
)

# ─── Strip vendor bloat ──────────────────────────────────────────────────────

step "Stripping vendor bloat"

(
    cd "${STAGE_DIR}"

    # Remove git metadata from vendor
    find vendor/ -type d -name '.git' -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type f -name '.gitignore' -delete 2>/dev/null || true
    find vendor/ -type f -name '.gitattributes' -delete 2>/dev/null || true

    # Remove test directories from vendor
    find vendor/ -type d -name 'tests' -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type d -name 'Tests' -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type d -name 'test' -exec rm -rf {} + 2>/dev/null || true

    # Remove CI / dev config files from vendor
    find vendor/ -type f -name 'phpunit.xml*' -delete 2>/dev/null || true
    find vendor/ -type f -name 'phpstan.neon*' -delete 2>/dev/null || true
    find vendor/ -type f -name '.php-cs-fixer*' -delete 2>/dev/null || true
    find vendor/ -type f -name '.editorconfig' -delete 2>/dev/null || true
    find vendor/ -type f -name '.travis.yml' -delete 2>/dev/null || true
    find vendor/ -type f -name '.scrutinizer.yml' -delete 2>/dev/null || true
    find vendor/ -type f -name 'Makefile' -delete 2>/dev/null || true

    # Remove non-essential markdown from vendor (keep README and LICENSE)
    find vendor/ -type f -name '*.md' \
        ! -name 'README.md' \
        ! -name 'LICENSE*' \
        ! -name 'LICENCE*' \
        -delete 2>/dev/null || true

    # Remove doc directories from vendor
    find vendor/ -type d -name 'docs' -exec rm -rf {} + 2>/dev/null || true
    find vendor/ -type d -name 'doc' -exec rm -rf {} + 2>/dev/null || true

    # Remove leftover empty directories
    find vendor/ -type d -empty -delete 2>/dev/null || true
)

info "Vendor bloat stripped"

# ─── Remove build artifacts from stage ────────────────────────────────────────

step "Final cleanup"

(
    cd "${STAGE_DIR}"

    # Remove files that shouldn't be in a release but git archive may have missed
    rm -f .gitattributes .gitignore
    rm -rf .github/ 2>/dev/null || true

    # Remove workspace artifacts
    rm -rf .workspace/ 2>/dev/null || true
)

info "Stage directory clean"

# ─── Create archives ─────────────────────────────────────────────────────────

step "Creating archives"

# ZIP
(
    cd "${BUILD_DIR}"
    rm -f "${DIST_DIR}/${ARTIFACT_BASE}.zip"
    zip -qr "${DIST_DIR}/${ARTIFACT_BASE}.zip" coqui/
)
ZIP_SIZE=$(du -h "${DIST_DIR}/${ARTIFACT_BASE}.zip" | cut -f1)
info "ZIP: ${DIST_DIR}/${ARTIFACT_BASE}.zip (${ZIP_SIZE})"

# tar.gz
(
    cd "${BUILD_DIR}"
    tar -czf "${DIST_DIR}/${ARTIFACT_BASE}.tar.gz" coqui/
)
TAR_SIZE=$(du -h "${DIST_DIR}/${ARTIFACT_BASE}.tar.gz" | cut -f1)
info "tar.gz: ${DIST_DIR}/${ARTIFACT_BASE}.tar.gz (${TAR_SIZE})"

# ─── Generate checksums ──────────────────────────────────────────────────────

step "Generating SHA-256 checksums"

(
    cd "${DIST_DIR}"
    for file in "${ARTIFACT_BASE}.zip" "${ARTIFACT_BASE}.tar.gz"; do
        if command -v sha256sum >/dev/null 2>&1; then
            sha256sum "${file}" > "${file}.sha256"
        else
            shasum -a 256 "${file}" > "${file}.sha256"
        fi
    done
)

info "Checksums written"

# ─── Summary ─────────────────────────────────────────────────────────────────

step "Build complete"
echo ""
echo "  Artifacts:"
for f in "${DIST_DIR}/${ARTIFACT_BASE}"*; do
    SIZE=$(du -h "${f}" | cut -f1)
    echo "    $(basename "${f}") (${SIZE})"
done
echo ""
echo "  Verify:"
echo "    ${BOLD}cd BUILD && php coqui/bin/coqui --version${RESET}"
echo ""
echo "  Install:"
echo "    ${BOLD}tar xzf ${DIST_DIR}/${ARTIFACT_BASE}.tar.gz -C /opt${RESET}"
echo "    ${BOLD}ln -s /opt/coqui/bin/coqui /usr/local/bin/coqui${RESET}"
echo ""
