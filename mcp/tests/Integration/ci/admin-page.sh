#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# A disposable Nextcloud with one app package installed, for the browser walk-through of the admin page (RE-11):
# the page loads js/mcp-admin.js from the package, and a person or an agent goes through the checklist of the
# RE-11 report in a browser. Nothing here runs the integration suite; tests/Integration/ci/run-local.sh does that.
#
# Usage: admin-page.sh up <archive.tar.gz> [nc 32|33|34|35]   start, install, print the URL and the accounts
#        admin-page.sh down                                     remove this script's containers
#
# The engine (Colima or a GitHub runner's Docker) must already run; this script never starts or stops it, and touches
# only the containers named mcp-it-admin-page-*. MCP_IT_ADMIN_PORT picks the local port (default 8381).
set -eu

PREFIX=mcp-it-admin-page
NC="$PREFIX-nextcloud"
PORT="${MCP_IT_ADMIN_PORT:-8381}"
ADMIN_PASSWORD=admin-page-throwaway
USER_PASSWORD=user-page-throwaway
WORKFLOW="$(cd "$(dirname "$0")/../../../.." && pwd)/.github/workflows/integration.yml"

usage() {
	echo "Usage: $0 up <archive.tar.gz> [nc 32|33|34|35] | $0 down" >&2
	exit 2
}

# occ <args...>: the server's console, as the web server user.
occ() {
	docker exec -u www-data "$NC" php occ "$@"
}

# image <major>: the pinned Nextcloud image of a major, from the integration workflow, so there is one list of pins.
image() {
	grep -o "nextcloud:$1\.[0-9.]*-apache@sha256:[0-9a-f]*" "$WORKFLOW" | head -n 1
}

# Removes every container this script started; nothing else.
down() {
	docker ps -a --format '{{.Names}}' | grep "^$PREFIX-" | while read -r name; do
		docker rm -f "$name" >/dev/null
		echo "removed $name"
	done
}

# up <archive> [major]: a fresh SQLite instance with the package installed and a few accounts for the matrix.
up() {
	archive="$1"
	major="${2:-35}"
	[ -f "$archive" ] || { echo "no archive at $archive" >&2; exit 2; }
	pinned=$(image "$major")
	[ -n "$pinned" ] || { echo "no pinned image for Nextcloud $major in $WORKFLOW" >&2; exit 2; }
	down
	docker run -d --name "$NC" -p "127.0.0.1:$PORT:80" -e NEXTCLOUD_ADMIN_USER=admin \
		-e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASSWORD" -e SQLITE_DATABASE=nextcloud \
		-e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1" "$pinned" >/dev/null
	tries=0
	until occ status --output=json 2>/dev/null | grep -q '"installed":true'; do
		tries=$((tries + 1))
		[ "$tries" -lt 300 ] || { docker logs "$NC" >&2; exit 1; }
		sleep 2
	done
	docker cp "$archive" "$NC:/tmp/mcp.tar.gz"
	docker exec -u www-data "$NC" tar -xzf /tmp/mcp.tar.gz -C /var/www/html/custom_apps
	occ app:enable mcp
	# The welcome dialog of a fresh instance would cover the page.
	occ app:disable firstrunwizard >/dev/null 2>&1 || true
	occ group:add staff >/dev/null
	for user in alice bob carol; do
		docker exec -u www-data -e OC_PASS="$USER_PASSWORD" "$NC" php occ user:add --password-from-env "$user" >/dev/null
	done
	occ group:adduser staff alice
	occ group:adduser staff bob
	echo "Admin page: http://localhost:$PORT/settings/admin/mcp"
	echo "Accounts: admin / $ADMIN_PASSWORD; alice, bob, carol / $USER_PASSWORD (alice and bob in group staff)"
	echo "Remove with: $0 down"
}

case "${1:-}" in
	up)
		[ $# -ge 2 ] || usage
		up "$2" "${3:-35}"
		;;
	down)
		down
		;;
	*)
		usage
		;;
esac
