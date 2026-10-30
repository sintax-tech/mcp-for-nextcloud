#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Polls part of the official harness: SHA-pinned release, isolated users and native service seed. No engine lifecycle.
set -eu
NC=${MCP_IT_NC_NAME:?}
WORK="${RUNNER_TEMP:?}/mcp-it"
HERE=$(cd "$(dirname "$0")" && pwd)
case "${MCP_IT_NC:?}" in
	32) VERSION=8.6.3; SHA=aa49bbaf9c46f4e18bbe05389c027f3a9f925c7f3071d6aceb44ca569a17f74d ;;
	35) VERSION=9.3.0; SHA=e23d57fcb410f9d5253464cd788f8f8b4a7e223e8fea138b1e353cc3ddb53e01 ;;
	*) echo 'No Polls runtime pin for this cell' >&2; exit 2 ;;
esac
# Calls the native Nextcloud command as its web-server user.
occ() {
	docker exec -u www-data "$NC" php /var/www/html/occ "$@"
}
case "${1:-}" in
	provision)
		mkdir -p "$WORK/tools"
		sh "$HERE/archive.sh" "$VERSION" "$WORK/tools/polls.tar.gz"
		echo "$SHA  $WORK/tools/polls.tar.gz" | shasum -a 256 -c -
		docker exec -u www-data "$NC" tar -xzf /mcp-it-tools/polls.tar.gz -C /var/www/html/custom_apps
		occ app:enable polls
		[ "$(occ config:app:get polls installed_version)" = "$VERSION" ]
		password=$(openssl rand -hex 24)
		for uid in owner viewer delegate outsider admin; do
			docker exec -u www-data -e OC_PASS="$password" "$NC" php /var/www/html/occ user:add --password-from-env "mcp-it-polls-$uid"
		done
		occ group:adduser admin mcp-it-polls-admin
		docker exec -u www-data -e NEXTCLOUD_ROOT=/var/www/html "$NC" php /mcp-it-src/mcp/tests/Integration/ci/polls/seed.php /tmp/mcp-it-polls-scene.json
		docker exec -u www-data "$NC" test -s /tmp/mcp-it-polls-scene.json
		;;
	test-env)
		printf '%s\n' -e 'MCP_IT_WITH_POLLS=1' -e "MCP_IT_POLLS_VERSION=$VERSION" -e 'MCP_IT_POLLS_SCENE=/tmp/mcp-it-polls-scene.json'
		;;
	*) echo 'Usage: cell.sh <provision|test-env>' >&2; exit 2 ;;
esac
