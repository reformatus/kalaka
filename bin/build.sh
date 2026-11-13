#!/usr/bin/env bash
# Builds dist/<plugin>-<VERSION>.zip for every plugin under plugins/.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

"$root/bin/check-version.sh"
version="$(tr -d '[:space:]' < VERSION)"

run_composer() {
    local dir="$1"
    shift
    if command -v composer > /dev/null; then
        command composer --working-dir="$dir" "$@"
    else
        docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app:z" -w "/app/$dir" \
            -e COMPOSER_HOME=/tmp/c composer:2 composer "$@"
    fi
}

# Runs the root's Strauss with the given directory as working directory; it
# reads that directory's composer.json (extra.strauss).
run_strauss() {
    local dir="$1"
    if command -v composer > /dev/null; then
        (cd "$dir" && php "$root/vendor/bin/strauss")
    else
        docker run --rm -u "$(id -u):$(id -g)" -v "$PWD:/app:z" -w "/app/$dir" \
            composer:2 php /app/vendor/bin/strauss
    fi
}

# Strauss is a development tool of the root composer.json.
run_composer . install --no-interaction

# The JavaScript build (engineering.md, "Builds") writes plugins/kalaka/build/, which the zip carries.
rm -rf plugins/*/build
npm ci
npm run build

rm -rf dist
mkdir -p dist

for plugin in plugins/*/; do
    slug="$(basename "$plugin")"
    stage="dist/stage/$slug"
    mkdir -p "$stage"

    cp "$plugin$slug.php" "$plugin/uninstall.php" "$plugin/composer.json" "$plugin/composer.lock" "$stage/"
    cp -R "$plugin/src" "$stage/src"
    find "$stage/src" \( -name README.md -o -name AGENTS.md \) -delete
    if [ -d "$plugin/build" ]; then
        cp -R "$plugin/build" "$stage/build"
    fi

    sed -i -E "s/^( \* Version:).*/\1 $version/; s/^(const KALAKA_VERSION = ').*(';)\$/\1$version\2/" "$stage/$slug.php"

    # A plugin that bundles libraries (extra.strauss) ends up with vendor/ and
    # vendor-prefixed/ (the Strauss-prefixed libraries). Strauss loads the first
    # autoloader it finds, so the stage's own is removed before it runs and
    # written again by dump-autoload. A plugin without them keeps Composer's
    # autoloader as installed.
    run_composer "$stage" install --no-dev --no-scripts --optimize-autoloader --no-interaction
    if grep -q '"strauss"' "$stage/composer.json"; then
        rm -f "$stage/vendor/autoload.php"
        run_strauss "$stage"
        rm -f "$stage/vendor/composer/autoload_aliases.php"
    fi
    run_composer "$stage" dump-autoload --no-dev --optimize --no-scripts
    rm "$stage/composer.json" "$stage/composer.lock"

    (cd dist/stage && zip -q -r -X -D "../$slug-$version.zip" "$slug")
done

rm -rf dist/stage
