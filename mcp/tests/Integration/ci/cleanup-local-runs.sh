#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Cleans interrupted runs owned by one checkout, never the shared Colima engine.
# Usage: cleanup-local-runs.sh <checkout-local runs directory>
set -eu
runs=$1

# absent <container|network|volume> <name>: failed inspect alone never proves absence.
absent() {
	kind=$1
	name=$2
	if [ "$kind" = container ]; then
		names=$(docker container ls --all --filter "name=^$name$" --format '{{.Names}}') || return 1
	else
		names=$(docker "$kind" ls --filter "name=^$name$" --format '{{.Name}}') || return 1
	fi
	! printf '%s\n' "$names" | grep -Fxq "$name"
}

for manifest in "$runs"/*/owned-resources; do
	[ -f "$manifest" ] || continue
	{
		read -r owner
		read -r nc
		read -r db
		read -r net
		read -r imap
	} < "$manifest"
	case "$owner" in ''|*[!0-9]*) echo "Invalid owner: $manifest" >&2; exit 1 ;; esac
	if kill -0 "$owner" 2>/dev/null; then
		echo "Leaving live run $owner"
		continue
	fi
	for resource in "$nc" "$db" "$net" "$imap"; do
		case "$resource" in mcp-it-*) ;; *) echo "Invalid resource: $manifest" >&2; exit 1 ;; esac
	done
	docker info >/dev/null
	# Persist exact anonymous-volume ownership before removing a container, including across retries.
	volume_manifest="$(dirname "$manifest")/owned-volumes"
	touch "$volume_manifest"
	for container in "$nc" "$db" "$imap"; do
		if docker container inspect "$container" >/dev/null 2>&1; then
			volumes=$(docker inspect --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}' "$container")
			[ -z "$volumes" ] || printf '%s\n' "$volumes" >> "$volume_manifest"
			docker rm -f -v "$container" >/dev/null
		fi
		absent container "$container" || { echo "Cannot prove container absent: $container" >&2; exit 1; }
	done
	if docker network inspect "$net" >/dev/null 2>&1; then
		docker network rm "$net" >/dev/null
	fi
	absent network "$net" || { echo "Cannot prove network absent: $net" >&2; exit 1; }
	owned_volumes=$(sort -u "$volume_manifest")
	for volume in $owned_volumes; do
		if ! absent volume "$volume"; then
			docker volume rm "$volume" >/dev/null
		fi
		absent volume "$volume" || { echo "Cannot prove volume absent: $volume" >&2; exit 1; }
	done
	docker info >/dev/null
	rm "$manifest" "$volume_manifest"
	echo "Cleaned owned run $owner; shared Colima left running"
done
