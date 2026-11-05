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

    run_composer "$stage" install --no-dev --optimize-autoloader --no-interaction
    rm "$stage/composer.json" "$stage/composer.lock"

    (cd dist/stage && zip -q -r -X -D "../$slug-$version.zip" "$slug")
done

rm -rf dist/stage
