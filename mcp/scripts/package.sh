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
stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
mkdir -p "$stage/mcp" build
cp -R appinfo lib templates README.md LICENSE NOTICE THIRD-PARTY-NOTICES.md LICENSES composer.json composer.lock "$stage/mcp/"
# Optional asset folders: copied when present, skipped otherwise.
for dir in js css img l10n; do
    if [ -d "$dir" ]; then
        cp -R "$dir" "$stage/mcp/"
    fi
done
composer install --quiet --no-dev --optimize-autoloader --no-interaction --working-dir="$stage/mcp"
rm "$stage/mcp/composer.lock"
archive="build/mcp-$version.tar.gz"
# --no-xattrs keeps macOS extended attributes (com.apple.*) out of pax headers.
COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' --exclude='.DS_Store' -C "$stage" -czf "$archive" mcp

# tar -t on macOS hides AppleDouble entries, so the archive is inspected by its own tar reader in PHP.
if ! php scripts/check_package.php "$archive" templates lib; then
    rm -f "$archive"
    exit 1
fi
echo "$archive"
