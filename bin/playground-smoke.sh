#!/usr/bin/env bash
# Installs built plugin zips into WordPress Playground (PHP 8.3, SQLite),
# activates them, core first, runs `wp kalaka seed` and fails unless Kaláka booted
# with no load failures, every extension's plugin is active, every module an
# extension registers is at the schema version it declares (none for a module
# without a schema) and the seed left its sample data.
# Usage: bin/playground-smoke.sh dist/kalaka-<VERSION>.zip [dist/kalaka-<name>-<VERSION>.zip ...]
set -euo pipefail

[ "$#" -ge 1 ] || { echo "Usage: bin/playground-smoke.sh <core zip> [<extension zip> ...]" >&2; exit 1; }
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="$(tr -d '[:space:]' < "$root/VERSION")"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Core's zip first, the extensions after it in the given order. Each extension's
# plugin file, <slug>/<slug>.php, must be active afterwards.
installed=""
plugins=""
copy() {
    local zip slug name
    zip="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
    slug="$(basename "$zip" .zip)"
    slug="${slug%-"$version"}"
    name="$slug.zip"
    cp "$zip" "$work/$name"
    installed="$installed $name"
    if [ "$slug" != "kalaka" ]; then
        plugins="$plugins $slug/$slug.php"
    fi
}
for zip in "$@"; do
    case "$(basename "$zip")" in
        "kalaka-$version.zip") copy "$zip" ;;
    esac
done
[ -n "$installed" ] || { echo "No core zip kalaka-$version.zip among the arguments." >&2; exit 1; }
for zip in "$@"; do
    case "$(basename "$zip")" in
        "kalaka-$version.zip") ;;
        *) copy "$zip" ;;
    esac
done

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
foreach (/*plugins*/ [] as $plugin) {
    if (!in_array($plugin, (array) get_option('active_plugins', []), true)) {
        throw new Exception('The extension plugin ' . $plugin . ' is not active.');
    }
}
// The modules the extensions register, collected again; registering only constructs them.
$extensions = new class implements Kalaka\Foundation\Api\Module\Modules {
    /** @var list<Kalaka\Foundation\Api\Module\Module> */
    public array $all = [];
    public function add(Kalaka\Foundation\Api\Module\Module $module): void
    {
        $this->all[] = $module;
    }
};
do_action('kalaka_register_modules', $extensions);
if (count($extensions->all) < count(/*plugins*/ [])) {
    throw new Exception('An extension registered no module.');
}
foreach ($extensions->all as $module) {
    $version = $module->schema()->version;
    if ($version > 0 && (int) get_option('kalaka_schema_' . $module->id()) !== $version) {
        throw new Exception('The schema of the module ' . $module->id() . ' is not at version ' . $version . '.');
    }
}
if (get_user_by('login', 'editor') === false) {
    throw new Exception('The seed did not create the editor user.');
}
echo 'Kalaka booted: load state is open, the seed left event 1 and the editor user.' . PHP_EOL;
PHP

# Every installed extension's plugin file, as a PHP array literal.
php_plugins=""
for plugin in $plugins; do
    php_plugins="$php_plugins'$plugin', "
done
check="${check//\/\*plugins\*\/ \[\]/[${php_plugins%, }]}"

# Playground defaults to the production environment type, where the seed refuses.
CHECK="$check" INSTALLED="$installed" node -e '
const installs = process.env.INSTALLED.trim().split(" ").map((name) => ({
    step: "installPlugin",
    pluginData: { resource: "vfs", path: "/smoke/" + name },
    options: { activate: true },
}));
const blueprint = {
    preferredVersions: { php: "8.3", wp: "latest" },
    steps: [
        { step: "defineWpConfigConsts", consts: { WP_ENVIRONMENT_TYPE: "development" } },
        ...installs,
        { step: "wp-cli", command: "wp kalaka seed" },
        { step: "runPHP", code: process.env.CHECK },
    ],
};
process.stdout.write(JSON.stringify(blueprint, null, 4) + "\n");
' > "$work/blueprint.json"

cd "$root"
npx @wp-playground/cli run-blueprint --blueprint="$work/blueprint.json" --mount-before-install="$work:/smoke"
echo "Playground smoke test passed: Kaláka booted with an open load state and the seed ran."
