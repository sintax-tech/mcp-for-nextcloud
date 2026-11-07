#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Collectives part of the official harness: the SHA-pinned official release, one collective created by the app's own
# command and its owner's Collectives folder set as the browser would set it. No engine lifecycle.
set -eu
NC=${MCP_IT_NC_NAME:-mcp-it-nextcloud}
WORK="${RUNNER_TEMP:?}/mcp-it"
HERE=$(cd "$(dirname "$0")" && pwd)
# 4.7.1 declares Nextcloud 32 to 35 (appinfo/info.xml), so one release serves every cell.
VERSION=4.7.1
SHA=6aef84674527393edbfc2915d58014e4ef72bfb27f3ca606b7c1faf985ae0879
OWNER=mcp-it-coll-owner
OUTSIDER=mcp-it-coll-outsider
# Calls the native Nextcloud command as its web-server user.
occ() {
	docker exec -u www-data "$NC" php /var/www/html/occ "$@"
}
case "${1:-}" in
	provision)
		mkdir -p "$WORK/tools"
		if [ -n "${MCP_IT_COLLECTIVES_ARCHIVE:-}" ]; then
			cp "$MCP_IT_COLLECTIVES_ARCHIVE" "$WORK/tools/collectives.tar.gz"
		else
			curl -fsSL "https://github.com/nextcloud/collectives/releases/download/v$VERSION/collectives-$VERSION.tar.gz" -o "$WORK/tools/collectives.tar.gz"
		fi
		echo "$SHA  $WORK/tools/collectives.tar.gz" | shasum -a 256 -c -
		docker exec -u www-data "$NC" tar -xzf /mcp-it-tools/collectives.tar.gz -C /var/www/html/custom_apps
		occ app:enable circles
		occ app:enable collectives
		[ "$(occ config:app:get collectives installed_version)" = "$VERSION" ]
		password=$(openssl rand -hex 24)
		for uid in "$OWNER" "$OUTSIDER"; do
			docker exec -u www-data -e OC_PASS="$password" "$NC" php /var/www/html/occ user:add --password-from-env "$uid"
		done
		occ collectives:create 'MCP Wiki' --owner="$OWNER"
		# What the browser does on the first visit; the MCP tools refuse until it is set (they never write it in a plan).
		# Both accounts have their Collectives folder, so the outsider is admitted by MCP and refused by Collectives itself.
		for uid in "$OWNER" "$OUTSIDER"; do
			occ user:setting "$uid" collectives user_folder /.Collectives
		done
		# The report names the exact tree this cell tested.
		echo "collectives cell tree: $(git -C "$HERE" rev-parse HEAD)$(git -C "$HERE" diff --quiet HEAD -- || echo ' (with uncommitted changes)')"
		;;
	test-env)
		printf '%s\n' -e 'MCP_IT_WITH_COLLECTIVES=1' -e "MCP_IT_COLLECTIVES_VERSION=$VERSION" \
			-e "MCP_IT_COLLECTIVES_OWNER=$OWNER" -e "MCP_IT_COLLECTIVES_OUTSIDER=$OUTSIDER"
		;;
	*) echo 'Usage: cell.sh <provision|test-env>' >&2; exit 2 ;;
esac
