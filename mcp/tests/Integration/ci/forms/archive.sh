#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Fetch the official pinned Forms release when absent; verify both downloaded and supplied archives before use.
# Usage: archive.sh <version> <sha256> <destination>; owns only its temporary download.
set -eu
version=$1
checksum=$2
archive=$3

# Verify the supplied SHA-256 with GNU coreutils or the macOS tools; a mismatch refuses installation.
sha256_check() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum -c -
	else
		shasum -a 256 -c -
	fi
}

mkdir -p "$(dirname "$archive")"
if [ ! -f "$archive" ]; then
	partial="$archive.part.$$"
	trap 'rm -f "$partial"' EXIT INT TERM
	curl -fsSL -o "$partial" "https://github.com/nextcloud-releases/forms/releases/download/v$version/forms-v$version.tar.gz"
	echo "$checksum  $partial" | sha256_check
	mv "$partial" "$archive"
fi
echo "$checksum  $archive" | sha256_check
