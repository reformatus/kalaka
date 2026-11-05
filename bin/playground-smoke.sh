#!/usr/bin/env bash
# Installs a built plugin zip into WordPress Playground (PHP 8.3, SQLite),
# activates it, runs `wp kalaka seed` and fails unless Kaláka booted with no load
# failures and the seed left its sample data.
# Usage: bin/playground-smoke.sh dist/kalaka-<VERSION>.zip
set -euo pipefail

zip="${1:?Usage: bin/playground-smoke.sh <plugin zip>}"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
zip="$(cd "$(dirname "$zip")" && pwd)/$(basename "$zip")"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
cp "$zip" "$work/plugin.zip"

# The check that runs after the seed. It is PHP inside JSON, so node writes the JSON.
read -r -d '' check <<'PHP' || true
<?php
require '/wordpress/wp-load.php';
if (!class_exists('Kalaka\Foundation\Api\LoadState') || !Kalaka\Foundation\Api\LoadState::current()->isOpen()) {
    throw new Exception('Kalaka did not boot with an open load state.');
}
$event = (new Kalaka\Events\Api\Events())->find(1);
if ($event === null || $event->name !== 'Nyári tábor' || $event->state() !== Kalaka\Events\Api\EventState::Published) {
    throw new Exception('The seed did not leave event 1 as the published "Nyári tábor".');
}
if (get_user_by('login', 'editor') === false) {
    throw new Exception('The seed did not create the editor user.');
}
echo 'Kalaka booted: load state is open, the seed left event 1 and the editor user.' . PHP_EOL;
PHP

# Playground defaults to the production environment type, where the seed refuses.
CHECK="$check" node -e '
const blueprint = {
    preferredVersions: { php: "8.3", wp: "latest" },
    steps: [
        { step: "defineWpConfigConsts", consts: { WP_ENVIRONMENT_TYPE: "development" } },
        {
            step: "installPlugin",
            pluginData: { resource: "vfs", path: "/smoke/plugin.zip" },
            options: { activate: true },
        },
        { step: "wp-cli", command: "wp kalaka seed" },
        { step: "runPHP", code: process.env.CHECK },
    ],
};
process.stdout.write(JSON.stringify(blueprint, null, 4) + "\n");
' > "$work/blueprint.json"

cd "$root"
npx @wp-playground/cli run-blueprint --blueprint="$work/blueprint.json" --mount-before-install="$work:/smoke"
echo "Playground smoke test passed: Kaláka booted with an open load state and the seed ran."
