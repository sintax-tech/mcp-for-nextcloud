#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Builds build/mcp-<version>.tar.gz with a single mcp/ root holding only production files
# and a production-only vendor/ (composer --no-dev); tests and dev dependencies stay out.
# scripts/check_package.php rejects the archive (and deletes it) on macOS metadata, extra top-level entries,
# a dev dependency in vendor/ or a missing script/style referenced with Util::addScript/addStyle.
set -eu
cd "$(dirname "$0")/.."
version=$(sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)
mkdir -p build
archive="build/mcp-$version.tar.gz"
# Invalidate this version's published result before any fallible build/staging work.
rm -f "$archive"
node scripts/docs/cli.mjs check
node scripts/docs/cli.mjs translations
npm run build
stage=$(mktemp -d "$PWD/build/package-stage-XXXXXX")
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/mcp"
cp -R appinfo lib templates LICENSE NOTICE THIRD-PARTY-NOTICES.md LICENSES composer.json composer.lock "$stage/mcp/"
# Optional asset folders: copied when present, skipped otherwise.
for dir in js css img l10n; do
    if [ -d "$dir" ]; then
        cp -R "$dir" "$stage/mcp/"
    fi
done
node scripts/docs/cli.mjs stage "$stage"
composer install --quiet --no-dev --optimize-autoloader --no-interaction --working-dir="$stage/mcp"
rm "$stage/mcp/composer.lock"
candidate="$stage/package.tar.gz"
# --no-xattrs keeps macOS extended attributes (com.apple.*) out of pax headers.
COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' --exclude='.DS_Store' -C "$stage" -czf "$candidate" mcp

# tar -t on macOS hides AppleDouble entries, so the archive is inspected by its own tar reader in PHP.
if ! php scripts/check_package.php "$candidate" templates lib; then
    exit 1
fi
# Inspect paths/types before extracting, then verify the actual candidate bytes.
mkdir "$stage/extracted"
tar -xzf "$candidate" -C "$stage/extracted"
node scripts/docs/cli.mjs verify "$stage/extracted"
mv "$candidate" "$archive"
echo "$archive"
