#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# A disposable Nextcloud with one app package installed, for the browser walk-through of the admin page (RE-11):
# the page loads js/mcp-admin.js from the package, and a person or an agent goes through the checklist of the
# RE-11 report in a browser. Nothing here runs the integration suite; tests/Integration/ci/run-local.sh does that.
#
# Usage: admin-page.sh up <archive.tar.gz> [nc 34|35]   start, install, print the URL, the accounts and the run id
#        admin-page.sh down <run id>                            remove exactly what that run created
#
# Every run has its own id (admin-page-<time>-<pid>) and records the names of what it creates, before creating it, in
# .mcp-it-runs/<run id>/owned-resources at the repository root (git-ignored): one container and one named volume for
# /var/www/html. `down` and the cleanup after a failed or interrupted `up` remove only the names of that record, so
# runs of other agents on the same engine are never touched. The engine (the shared Colima, or a GitHub runner's
# Docker) must already run; this script never starts or stops it. The local port is chosen by Docker unless
# MCP_IT_ADMIN_PORT sets it. admin-page-test.sh proves the isolation with a fake docker.
set -eu

ADMIN_PASSWORD=admin-page-throwaway
USER_PASSWORD=user-page-throwaway
REPO="$(cd "$(dirname "$0")/../../../.." && pwd)"
WORKFLOW="$REPO/.github/workflows/integration.yml"
RUNS="${MCP_IT_RUNS_DIR:-$REPO/.mcp-it-runs}"
TRIES="${MCP_IT_ADMIN_TRIES:-300}"

usage() {
	echo "Usage: $0 up <archive.tar.gz> [nc 34|35] | $0 down <run id>" >&2
	exit 2
}

# occ <container> <args...>: the server's console, as the web server user.
occ() {
	container="$1"
	shift
	docker exec -u www-data "$container" php occ "$@"
}

# image <major>: the pinned Nextcloud image of a major, from the integration workflow, so there is one list of pins.
image() {
	grep -o "nextcloud:$1\.[0-9.]*-apache@sha256:[0-9a-f]*" "$WORKFLOW" | head -n 1
}

# remove_run <run id>: removes the container and the volume named in the run's record, and then the record.
remove_run() {
	record="$RUNS/$1/owned-resources"
	[ -f "$record" ] || { echo "no record of run $1 in $RUNS" >&2; return 1; }
	# The container goes first: its volume cannot be removed while a container uses it.
	for kind in container volume; do
		grep "^$kind " "$record" | while read -r _ name; do
			case "$name" in mcp-it-admin-page-*) ;; *) echo "refusing to remove $kind '$name' of $record" >&2; exit 1 ;; esac
			if [ "$kind" = container ]; then
				docker rm -f -v "$name" >/dev/null 2>&1 || true
			else
				docker volume rm "$name" >/dev/null 2>&1 || true
			fi
			echo "removed $kind $name"
		done || return 1
	done
	rm -rf "${RUNS:?}/$1"
}

# up <archive> [major]: a fresh SQLite instance with the package installed and a few accounts for the matrix.
up() {
	case "$2" in 34|35) ;; *) usage ;; esac
	archive="$1"
	major="${2:-35}"
	[ -f "$archive" ] || { echo "no archive at $archive" >&2; exit 2; }
	pinned=$(image "$major")
	[ -n "$pinned" ] || { echo "no pinned image for Nextcloud $major in $WORKFLOW" >&2; exit 2; }
	run="admin-page-$(date +%Y%m%d-%H%M%S)-$$"
	nc="mcp-it-$run"
	volume="mcp-it-$run-html"
	mkdir -p "$RUNS/$run"
	printf 'container %s\nvolume %s\n' "$nc" "$volume" > "$RUNS/$run/owned-resources"
	# A failed or interrupted start leaves nothing behind: only this run's record is cleaned.
	trap 'status=$?; trap - EXIT; [ "$status" -eq 0 ] || remove_run "$run" >&2; exit "$status"' EXIT
	trap 'exit 130' INT
	trap 'exit 143' TERM
	docker volume create "$volume" >/dev/null
	docker run -d --name "$nc" -p "127.0.0.1:${MCP_IT_ADMIN_PORT:-}:80" -v "$volume:/var/www/html" \
		-e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD="$ADMIN_PASSWORD" -e SQLITE_DATABASE=nextcloud \
		-e NEXTCLOUD_TRUSTED_DOMAINS="localhost 127.0.0.1" "$pinned" >/dev/null
	tries=0
	until occ "$nc" status --output=json 2>/dev/null | grep -q '"installed":true'; do
		tries=$((tries + 1))
		[ "$tries" -lt "$TRIES" ] || { docker logs "$nc" >&2 || true; exit 1; }
		sleep 2
	done
	docker cp "$archive" "$nc:/tmp/mcp.tar.gz"
	docker exec -u www-data "$nc" tar -xzf /tmp/mcp.tar.gz -C /var/www/html/custom_apps
	occ "$nc" app:enable mcp
	# The welcome dialog of a fresh instance would cover the page.
	occ "$nc" app:disable firstrunwizard >/dev/null 2>&1 || true
	occ "$nc" group:add staff >/dev/null
	for user in alice bob carol; do
		docker exec -u www-data -e OC_PASS="$USER_PASSWORD" "$nc" php occ user:add --password-from-env "$user" >/dev/null
	done
	occ "$nc" group:adduser staff alice
	occ "$nc" group:adduser staff bob
	port=$(docker port "$nc" 80/tcp | head -n 1 | sed 's/.*://')
	trap - EXIT INT TERM
	echo "Admin page: http://localhost:$port/settings/admin/mcp"
	echo "Accounts: admin / $ADMIN_PASSWORD; alice, bob, carol / $USER_PASSWORD (alice and bob in group staff)"
	echo "Run id: $run"
	echo "Remove with: $0 down $run"
}

case "${1:-}" in
	up)
		[ $# -ge 2 ] || usage
		up "$2" "${3:-35}"
		;;
	down)
		[ $# -eq 2 ] || usage
		remove_run "$2"
		;;
	*)
		usage
		;;
esac
