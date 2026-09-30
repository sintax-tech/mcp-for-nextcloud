#!/bin/sh
# Builds build/mcp-<version>.tar.gz with a single mcp/ root holding only production files
# and a production-only vendor/ (composer --no-dev); tests and dev dependencies stay out.
# Fails when a script or style referenced with Util::addScript/addStyle is missing from the archive.
set -eu
cd "$(dirname "$0")/.."
version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/mcp" build
cp -R appinfo lib templates README.md composer.json composer.lock "$stage/mcp/"
# Optional asset folders: copied when present, skipped otherwise.
for dir in js css img l10n; do
    if [ -d "$dir" ]; then
        cp -R "$dir" "$stage/mcp/"
    fi
done
composer install --quiet --no-dev --optimize-autoloader --no-interaction --working-dir="$stage/mcp"
rm "$stage/mcp/composer.lock"
archive="build/mcp-$version.tar.gz"
COPYFILE_DISABLE=1 tar -C "$stage" -czf "$archive" mcp

# Every asset referenced from PHP must be inside the archive.
listing=$(tar -tzf "$archive")
missing=0
for pair in $(grep -rhoE "add(Script|Style)\([[:space:]]*['\"]mcp['\"][[:space:]]*,[[:space:]]*['\"][^'\"]+['\"]" templates lib \
        | sed -E "s/add(Script|Style)\([[:space:]]*['\"]mcp['\"][[:space:]]*,[[:space:]]*['\"]([^'\"]+)['\"]/\1:\2/"); do
    kind=${pair%%:*}
    name=${pair#*:}
    if [ "$kind" = Script ]; then file="mcp/js/$name.js"; else file="mcp/css/$name.css"; fi
    if ! printf '%s\n' "$listing" | grep -qxF "$file"; then
        echo "missing in package: $file (referenced by add$kind)" >&2
        missing=1
    fi
done
if [ "$missing" -ne 0 ]; then
    rm -f "$archive"
    exit 1
fi
echo "$archive"
