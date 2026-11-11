#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Source-only release selection/staging for the Tables harness; never starts an engine or installs an app itself.

# pin_release: keep matrix defaults; an explicit local archive selects only the SHA-pinned 2.4.0 proof on NC35.
pin_release() {
	if [ -n "${MCP_IT_TABLES_ARCHIVE:-}" ]; then
		[ "$MCP_IT_NC" = 35 ] || { echo 'The Tables 2.4.0 archive opt-in requires NC35' >&2; return 1; }
		TABLES_TAG=v2.4.0
		TABLES_SHA=47847b64ee65a58afd2a8ac3f943c53128a927a1b321ed94c4a322daa6b5eb99
		return
	fi
	case "$MCP_IT_NC" in
		34) TABLES_TAG=v2.2.2; TABLES_SHA=c2806c1c40b58efdfd1b1f2e2d6f37a19fb0ee34bcf9ee7f26cecb170233ac4f ;;
		35) TABLES_TAG=v2.3.2; TABLES_SHA=29953270010587fc8c0a95cfaba7b167adafb93d2f1a0a5a89e42f2d2be1cf28 ;;
		*) echo "No Tables release pinned for Nextcloud $MCP_IT_NC" >&2; return 1 ;;
	esac
}

# stage_tables_archive <destination>: copy the explicit developer archive or download the unchanged matrix pin.
# The caller verifies TABLES_SHA before unpacking either source into the disposable server.
stage_tables_archive() {
	if [ -n "${MCP_IT_TABLES_ARCHIVE:-}" ]; then
		cp "$MCP_IT_TABLES_ARCHIVE" "$1"
	else
		curl -fsSL -o "$1" "https://github.com/nextcloud-releases/tables/releases/download/$TABLES_TAG/tables-$TABLES_TAG.tar.gz"
	fi
}
