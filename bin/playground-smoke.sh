#!/usr/bin/env bash
# Installs a built plugin zip into WordPress Playground (PHP 8.3, SQLite),
# activates it and fails unless Kaláka booted with no load failures.
# Usage: bin/playground-smoke.sh dist/kalaka-<VERSION>.zip
set -euo pipefail

zip="${1:?Usage: bin/playground-smoke.sh <plugin zip>}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
zip="$(cd "$(dirname "$zip")" && pwd)/$(basename "$zip")"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
cp "$zip" "$work/plugin.zip"

cat > "$work/blueprint.json" <<'JSON'
{
    "preferredVersions": { "php": "8.3", "wp": "latest" },
    "steps": [
        {
            "step": "installPlugin",
            "pluginData": { "resource": "vfs", "path": "/smoke/plugin.zip" },
            "options": { "activate": true }
        },
        {
            "step": "runPHP",
            "code": "<?php require '/wordpress/wp-load.php'; if (!class_exists('Kalaka\\\\Foundation\\\\Api\\\\LoadState') || !Kalaka\\Foundation\\Api\\LoadState::current()->isOpen()) { throw new Exception('Kalaka did not boot with an open load state.'); } echo 'Kalaka booted: load state is open.' . PHP_EOL;"
        }
    ]
}
JSON

cd "$root"
npx @wp-playground/cli run-blueprint --blueprint="$work/blueprint.json" --mount-before-install="$work:/smoke"
echo "Playground smoke test passed: Kaláka booted with an open load state."
