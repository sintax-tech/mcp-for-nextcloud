#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Cleans interrupted runs owned by one checkout, never the shared Colima engine.
# Usage: cleanup-local-runs.sh <checkout-local runs directory>
set -eu
runs=$1
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
	# Inspect exact owned containers before rm -v loses their anonymous volume names.
	for container in "$nc" "$db" "$imap"; do
		if docker container inspect "$container" >/dev/null 2>&1; then
			volumes=$(docker inspect --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}' "$container")
			docker rm -f -v "$container" >/dev/null
			for volume in $volumes; do
				if docker volume inspect "$volume" >/dev/null 2>&1; then
					echo "Owned volume remains: $volume" >&2
					exit 1
				fi
			done
		fi
	done
	if docker network inspect "$net" >/dev/null 2>&1; then
		docker network rm "$net" >/dev/null
	fi
	docker info >/dev/null
	rm "$manifest"
	echo "Cleaned owned run $owner; shared Colima left running"
done
