#!/usr/bin/env bash
# Fails, naming each file, unless VERSION, the header Version: of every
# plugins/<slug>/<slug>.php, and KALAKA_VERSION in core's kalaka.php and in
# phpstan-bootstrap.php are all equal.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
version="$(tr -d '[:space:]' < "$root/VERSION")"
status=0

if ! [[ "$version" =~ ^[0-9A-Za-z.+-]+$ ]]; then
    echo "VERSION '$version' is not a valid version." >&2
    exit 1
fi

compare() {
    local label="$1" found="$2"
    if [ "$found" != "$version" ]; then
        echo "Version mismatch: $label is '${found:-<missing>}', VERSION is '$version'." >&2
        status=1
    fi
}

for main in "$root"/plugins/*/*.php; do
    slug="$(basename "$(dirname "$main")")"
    [ "$(basename "$main")" = "$slug.php" ] || continue
    plugin="plugins/$slug/$slug.php"
    header="$(sed -nE 's/^ \* Version:[[:space:]]*(.*[^[:space:]])[[:space:]]*$/\1/p' "$main" | head -n 1)"
    compare "$plugin header Version:" "$header"
done

# Only core defines the constant; extensions define none.
plugin="plugins/kalaka/kalaka.php"
constant="$(sed -nE "s/^const KALAKA_VERSION = '([^']*)';.*/\1/p" "$root/$plugin" | head -n 1)"
bootstrap="$(sed -nE "s/^const KALAKA_VERSION = '([^']*)';.*/\1/p" "$root/phpstan-bootstrap.php" | head -n 1)"

compare "$plugin KALAKA_VERSION" "$constant"
compare "phpstan-bootstrap.php KALAKA_VERSION" "$bootstrap"

exit "$status"
