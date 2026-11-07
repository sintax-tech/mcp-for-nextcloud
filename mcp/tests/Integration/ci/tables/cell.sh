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

# Release selection is shared by provisioning and test-env; defaults stay pinned per Nextcloud major.
. "$(dirname "$0")/release.sh"

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
	stage_tables_archive "$archive"
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
