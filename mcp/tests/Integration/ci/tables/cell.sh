#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The Tables part of an integration cell, only when MCP_IT_WITH_TABLES=1: the Nextcloud Tables release pinned per
# Nextcloud major, checked against its SHA256 and enabled, four more fixture users, and the scene the Tables tests read,
# created through the Tables app's own public API as its owner (seed.php). A cell without MCP_IT_WITH_TABLES never
# installs Tables.
#
# Usage: cell.sh <provision|test-env>
#   provision  installs and enables Tables, adds the users and seeds the discovery, the rows and the row write scenes
#   test-env   prints the `-e NAME=value` arguments the integration tests need (one per line)
# Environment: the harness variables (MCP_IT_NC, MCP_IT_NC_NAME, RUNNER_TEMP).
set -eu

NC=${MCP_IT_NC_NAME:-mcp-it-nextcloud}
WORK="${RUNNER_TEMP:?RUNNER_TEMP is set by the harness}/mcp-it"
TOOLS_DIR="$WORK/tools"
# The users the scene needs beside mcp-it-alice (owner) and mcp-it-bob (one shared view).
USERS="mcp-it-tables-carol mcp-it-tables-dave mcp-it-tables-erin mcp-it-tables-frank"
# The users of the rows scene (seed-rows.php), apart from the discovery scene.
ROWS_USERS="mcp-it-rows-owner mcp-it-rows-viewer mcp-it-rows-reader mcp-it-rows-creator mcp-it-rows-member"
# The users of the row write scene (seed-write.php), apart from the others.
WRITE_USERS="mcp-it-write-owner mcp-it-write-viewer mcp-it-write-editor mcp-it-write-reader mcp-it-write-shared"

# Release pinned per Nextcloud major (appinfo/info.xml of each release): 1.0.10 is the newest 1.x and declares 30-32,
# 2.2.2 declares 33-35 and 2.3.2 declares 33-36. tests/fixtures/nextcloud-api/tables covers the oldest release per major.
pin_release() {
	case "$MCP_IT_NC" in
		32) TABLES_TAG=v1.0.10; TABLES_SHA=6a8e8294b8467c9c36a724d5d6f6d62b9b97b285b8de291712812958dfa1b727 ;;
		33|34) TABLES_TAG=v2.2.2; TABLES_SHA=c2806c1c40b58efdfd1b1f2e2d6f37a19fb0ee34bcf9ee7f26cecb170233ac4f ;;
		35) TABLES_TAG=v2.3.2; TABLES_SHA=29953270010587fc8c0a95cfaba7b167adafb93d2f1a0a5a89e42f2d2be1cf28 ;;
		*) echo "No Tables release pinned for Nextcloud $MCP_IT_NC" >&2; return 1 ;;
	esac
}

# occ <args...>: the server's command line, as the web server user.
occ() {
	docker exec -u www-data "$NC" php /var/www/html/occ "$@"
}

# sha256_check: checks "<sha256>  <file>" on stdin with GNU coreutils or the BSD tools.
sha256_check() {
	if command -v sha256sum >/dev/null 2>&1; then
		sha256sum -c -
	else
		shasum -a 256 -c -
	fi
}

# install_tables: the pinned release, checked against its checksum, unpacked into custom_apps and enabled.
install_tables() {
	mkdir -p "$TOOLS_DIR"
	archive="$TOOLS_DIR/tables.tar.gz"
	curl -fsSL -o "$archive" "https://github.com/nextcloud-releases/tables/releases/download/$TABLES_TAG/tables-$TABLES_TAG.tar.gz"
	echo "$TABLES_SHA  $archive" | sha256_check
	docker exec -u www-data "$NC" tar -xzf /mcp-it-tools/tables.tar.gz -C /var/www/html/custom_apps
	occ app:enable tables
	actual=$(occ config:app:get tables installed_version)
	[ "$actual" = "${TABLES_TAG#v}" ] || { echo "Unexpected installed Tables $actual" >&2; return 1; }
	echo "Tables $actual enabled"
}

# cmd_provision: Tables, the users and the scene.
cmd_provision() {
	pin_release
	install_tables
	password=$(openssl rand -hex 24)
	for uid in $USERS $ROWS_USERS $WRITE_USERS; do
		docker exec -u www-data -e OC_PASS="$password" "$NC" php /var/www/html/occ user:add --password-from-env "$uid"
	done
	docker exec -u www-data -e NEXTCLOUD_ROOT=/var/www/html "$NC" php /mcp-it-src/mcp/tests/Integration/ci/tables/seed.php /tmp/mcp-it-tables-scene.json
	docker exec -u www-data -e NEXTCLOUD_ROOT=/var/www/html "$NC" php /mcp-it-src/mcp/tests/Integration/ci/tables/seed-rows.php /tmp/mcp-it-tables-rows-scene.json
	docker exec -u www-data -e NEXTCLOUD_ROOT=/var/www/html "$NC" php /mcp-it-src/mcp/tests/Integration/ci/tables/seed-write.php /tmp/mcp-it-tables-write-scene.json
}

# cmd_test_env: what the tests need, one docker exec argument per line.
cmd_test_env() {
	pin_release
	printf '%s\n' -e "MCP_IT_WITH_TABLES=1" -e "MCP_IT_TABLES_VERSION=${TABLES_TAG#v}" -e "MCP_IT_TABLES_SCENE=/tmp/mcp-it-tables-scene.json" \
		-e "MCP_IT_TABLES_ROWS_SCENE=/tmp/mcp-it-tables-rows-scene.json" -e "MCP_IT_TABLES_WRITE_SCENE=/tmp/mcp-it-tables-write-scene.json"
}

case "${1:-}" in
	provision) cmd_provision ;;
	test-env) cmd_test_env ;;
	*)
		echo "Usage: $0 <provision|test-env>" >&2
		exit 1
		;;
esac
