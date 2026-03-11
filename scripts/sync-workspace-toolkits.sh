#!/usr/bin/env bash
# sync-workspace-toolkits.sh
#
# Scans for coqui-toolkit-* packages alongside the project root,
# rebuilds .workspace/composer.json with path-repo symlinks for each,
# and runs composer update to apply changes.
#
# Only modifies .workspace/composer.json — never touches the root composer.json.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
WORKSPACE="$PROJECT_ROOT/.workspace"
PARENT_DIR="$(cd "$PROJECT_ROOT/.." && pwd)"
COMPOSER_JSON="$WORKSPACE/composer.json"

# ---------- helpers ----------

die() { echo "ERROR: $*" >&2; exit 1; }

info() { echo "  $*"; }

# ---------- preflight ----------

[[ -d "$WORKSPACE" ]] || die "Workspace directory not found: $WORKSPACE"
command -v php >/dev/null 2>&1 || die "php is required but not found on PATH"
command -v composer >/dev/null 2>&1 || die "composer is required but not found on PATH"

# ---------- discover toolkits ----------

echo "Scanning for coqui-toolkit-* packages in $(basename "$PARENT_DIR")/"

declare -a TOOLKIT_NAMES=()
declare -a TOOLKIT_PATHS=()
declare -a TOOLKIT_PACKAGES=()

for dir in "$PARENT_DIR"/coqui-toolkit-*; do
    [[ -d "$dir" ]] || continue
    [[ -f "$dir/composer.json" ]] || {
        info "SKIP $(basename "$dir") (no composer.json)"
        continue
    }

    # Extract package name from the toolkit's composer.json
    pkg_name=$(php -r "
        \$json = json_decode(file_get_contents('$dir/composer.json'), true);
        echo \$json['name'] ?? '';
    ")

    if [[ -z "$pkg_name" ]]; then
        info "SKIP $(basename "$dir") (no package name in composer.json)"
        continue
    fi

    # Compute relative path from .workspace/ to the toolkit
    rel_path=$(realpath --relative-to="$WORKSPACE" "$dir")

    TOOLKIT_NAMES+=("$(basename "$dir")")
    TOOLKIT_PATHS+=("$rel_path")
    TOOLKIT_PACKAGES+=("$pkg_name")

    info "FOUND $pkg_name -> $rel_path"
done

if [[ ${#TOOLKIT_PACKAGES[@]} -eq 0 ]]; then
    die "No toolkit packages found in $PARENT_DIR/coqui-toolkit-*"
fi

echo ""
echo "Found ${#TOOLKIT_PACKAGES[@]} toolkit(s)"

# ---------- rebuild composer.json ----------

echo ""
echo "Rebuilding $COMPOSER_JSON ..."

# Export variables for the PHP subprocess
export WORKSPACE
export COMPOSER_JSON
export PACKAGES
PACKAGES=$(printf '%s\n' "${TOOLKIT_PACKAGES[@]}")
export PATHS
PATHS=$(printf '%s\n' "${TOOLKIT_PATHS[@]}")

# Use PHP to do the JSON manipulation — guaranteed available, handles
# edge cases (existing non-toolkit requires, preserving structure, etc.)
php <<'PHPCLI'
<?php
declare(strict_types=1);

$workspace   = getenv('WORKSPACE');
$composerFile = getenv('COMPOSER_JSON');
$packages    = explode("\n", trim(getenv('PACKAGES')));
$paths       = explode("\n", trim(getenv('PATHS')));

// ---- read current composer.json (or start fresh) ----

$composer = file_exists($composerFile)
    ? json_decode(file_get_contents($composerFile), true)
    : [];

// ---- preserve non-toolkit requires ----

$existingRequire = $composer['require'] ?? [];
$preservedRequire = [];

foreach ($existingRequire as $pkg => $constraint) {
    // Keep anything that is NOT a coquibot/coqui-toolkit-* package
    // and NOT a stale bot-generated package (hello-toolkit, time-toolkit, etc.)
    if (str_starts_with($pkg, 'coquibot/coqui-toolkit-')) {
        continue; // will be re-added from scan
    }
    if (preg_match('#^coquibot/(hello|time|weather|code-edit$)#', $pkg)) {
        continue; // stale bot-generated packages
    }
    if ($pkg === 'guzzlehttp/guzzle') {
        continue; // stale dependency
    }
    $preservedRequire[$pkg] = $constraint;
}

// Ensure php-agents is present
if (!isset($preservedRequire['carmelosantana/php-agents'])) {
    $preservedRequire['carmelosantana/php-agents'] = '^0.5';
}

// ---- add toolkit requires ----

foreach ($packages as $pkg) {
    $preservedRequire[$pkg] = '@dev';
}

ksort($preservedRequire);

// ---- build repositories array (only toolkit path repos) ----

// Preserve any non-toolkit repositories (e.g. custom VCS repos)
$existingRepos = $composer['repositories'] ?? [];
$preservedRepos = [];
foreach ($existingRepos as $repo) {
    if (($repo['type'] ?? '') === 'path') {
        $url = $repo['url'] ?? '';
        if (str_contains($url, 'coqui-toolkit-') || str_contains($url, 'packages/')) {
            continue; // will be rebuilt
        }
    }
    $preservedRepos[] = $repo;
}

// Add toolkit path repos
$toolkitRepos = [];
foreach ($paths as $relPath) {
    $toolkitRepos[] = [
        'type'    => 'path',
        'url'     => $relPath,
        'options' => ['symlink' => true],
    ];
}

$allRepos = array_merge($preservedRepos, $toolkitRepos);

// ---- assemble final composer.json ----

$composer['name']        = $composer['name'] ?? 'coqui/workspace';
$composer['description'] = $composer['description'] ?? 'Coqui workspace — bot-managed dependencies';
$composer['type']        = $composer['type'] ?? 'project';
$composer['license']     = $composer['license'] ?? 'MIT';
$composer['require']     = $preservedRequire;
$composer['repositories'] = $allRepos;

$composer['autoload'] = $composer['autoload'] ?? [
    'psr-4' => ['CoquiWorkspace\\' => 'src/'],
];

$composer['config'] = $composer['config'] ?? [
    'optimize-autoloader' => true,
    'sort-packages'       => true,
    'allow-plugins'       => ['pestphp/pest-plugin' => true],
];

$composer['minimum-stability'] = 'dev';
$composer['prefer-stable']     = true;

// Remove keys we don't want
unset($composer['require-dev']); // workspace doesn't need dev deps

// ---- write ----

$json = json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($composerFile, $json);

echo "  Wrote " . count($packages) . " toolkit(s) + "
    . (count($preservedRequire) - count($packages)) . " preserved require(s)\n";
echo "  Repositories: " . count($allRepos) . " total ("
    . count($toolkitRepos) . " toolkit path repos)\n";
PHPCLI

# ---------- composer update ----------

echo ""
echo "Running composer update in .workspace/ ..."
echo ""

cd "$WORKSPACE"
composer update --no-interaction

echo ""
echo "Done. Workspace toolkits synced."
echo ""

# ---------- summary ----------

echo "Symlinked toolkits:"
for i in "${!TOOLKIT_PACKAGES[@]}"; do
    echo "  ${TOOLKIT_PACKAGES[$i]} -> ${TOOLKIT_PATHS[$i]}"
done
