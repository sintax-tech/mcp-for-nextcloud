#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Opt-in Forms stage of the official harness. Installs a SHA-checked pinned release, creates only synthetic actors,
# and seeds through the official OCS API. It never owns the engine lifecycle.
set -eu
NC=${MCP_IT_NC_NAME:-mcp-it-nextcloud}
WORK="${RUNNER_TEMP:?}/mcp-it"
case "$MCP_IT_NC" in
 35) VERSION=5.4.1; SHA=9cfc3776721fce1b284f57ec4a9e165c75927adbb5f06d5b5bfddd14a8f0b42e ;;
 34) VERSION=5.4.2; SHA=c12cc4e05dd9c41cfee8df75a8cc0f759e9a95169fefabd617713a934da7943a ;;
 *) echo 'No Forms cell pinned for this server'; exit 2 ;;
esac
# occ: execute PHP only in the disposable server as its web user.
occ() { docker exec -u www-data "$NC" php /var/www/html/occ "$@"; }
# provision: obtain the pinned official archive and verify its checksum before installation.
provision() {
 archive="$WORK/tools/forms-v$VERSION.tar.gz"
 sh "$(dirname "$0")/archive.sh" "$VERSION" "$SHA" "$archive"
 docker exec -u www-data "$NC" tar -xzf "/mcp-it-tools/forms-v$VERSION.tar.gz" -C /var/www/html/custom_apps
 occ app:enable forms
 [ "$(occ config:app:get forms installed_version)" = "$VERSION" ]
 password=$(openssl rand -hex 24)
 for uid in mcp-it-forms-owner mcp-it-forms-reader mcp-it-forms-editor mcp-it-forms-outside; do
  docker exec -u www-data -e OC_PASS="$password" "$NC" php /var/www/html/occ user:add --password-from-env "$uid"
 done
 occ group:add mcp-it-forms-group
 occ group:adduser mcp-it-forms-group mcp-it-forms-reader
 docker exec -u www-data -e NEXTCLOUD_ROOT=/var/www/html "$NC" php /mcp-it-src/mcp/tests/Integration/ci/forms/seed.php
 docker exec -u www-data -e NEXTCLOUD_ROOT=/var/www/html "$NC" php /mcp-it-src/mcp/tests/Integration/ci/forms/results-seed.php
}
case "${1:-}" in
 provision) provision ;;
 test-env) printf '%s\n' -e MCP_IT_WITH_FORMS=1 -e "MCP_IT_FORMS_VERSION=$VERSION" ;;
 *) echo 'Usage: forms/cell.sh provision|test-env'; exit 2 ;;
esac
