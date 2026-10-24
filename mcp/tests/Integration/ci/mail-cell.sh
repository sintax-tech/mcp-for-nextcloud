#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The Mail part of an integration cell, only when MCP_IT_WITH_MAIL=1: a throwaway IMAP server (the image pinned below,
# on the run's own network, with an anonymous volume removed by `cleanup`), the Nextcloud Mail release pinned per
# Nextcloud major, and one Mail account for each of two fixture users, configured through the Mail app's own occ
# commands and synchronized once. A cell without MCP_IT_WITH_MAIL never pulls the image nor installs Mail.
#
# Usage: mail-cell.sh <provision|test-env|collect|cleanup>
#   provision  starts and seeds the IMAP server, installs and enables Mail, creates and synchronizes the accounts
#   test-env   prints the `-e NAME=value` arguments the integration tests need (one per line)
#   collect    copies the IMAP server log into the artifacts
#   cleanup    removes the IMAP container and its volume, proves the removal, and with PRUNE_IMAGES=1 the image too
# Environment: the harness variables (MCP_IT_NC, MCP_IT_NET, MCP_IT_NC_NAME, RUNNER_TEMP) and MCP_IT_IMAP_NAME, the name
# run-local.sh gives this run's IMAP container.
set -eu

# Smallest multi-architecture IMAP server image with a pinned index digest: dovecot/dovecot 2.4.5, about 70 MB compressed.
IMAP_IMAGE=dovecot/dovecot:2.4.5@sha256:43c14def08a57f25107593cbcf64a0a6fbae84a7b9de7aa038513c9b21e91914
# Plain IMAP listener of the rootless image.
IMAP_PORT=31143
# Throwaway password of a throwaway server, accepted for every IMAP user by the image's static passdb; not a secret.
IMAP_PASSWORD=mcp-it-imap-password
# Nextcloud Mail 5.12.5 declares min-version 32 and max-version 35 (appinfo/info.xml of the release): one build for
# every major this matrix covers. tests/fixtures/nextcloud-api/mail-sources.json covers the oldest release per major.
MAIL_TAG=v5.12.5
MAIL_SHA=c52dab18a4de1710854038d95810f9ae4eb76a8656d89800e7dceb5aff0034e0

HERE=$(cd "$(dirname "$0")" && pwd)
NET=${MCP_IT_NET:-mcp-it}
NC=${MCP_IT_NC_NAME:-mcp-it-nextcloud}
IMAP=${MCP_IT_IMAP_NAME:-mcp-it-imap}
WORK="${RUNNER_TEMP:?RUNNER_TEMP is set by the harness}/mcp-it"
TOOLS_DIR="$WORK/tools"
ARTIFACTS="$WORK/artifacts"

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

# imap_ready: succeeds once the IMAP listener accepts connections from the Nextcloud container.
imap_ready() {
	docker exec "$NC" php -r '$s = @fsockopen($argv[1], (int)$argv[2], $n, $m, 2); exit($s ? 0 : 1);' "$IMAP" "$IMAP_PORT"
}

# message <from> <subject>: one small RFC 5322 message on stdout.
message() {
	printf 'From: %s\r\nTo: mcp-it@example.com\r\nSubject: %s\r\nDate: Sat, 24 Oct 2026 10:00:00 +0000\r\nMessage-ID: <%s@mcp-it.example>\r\n\r\nBody of %s.\r\n' \
		"$1" "$2" "$(printf '%s' "$2" | tr ' ' '-')" "$2"
}

# save <imap user> <mailbox> <subject>: stores one message through doveadm, the server's own tool.
save() {
	message "sender@example.com" "$3" | docker exec -i "$IMAP" doveadm save -u "$1" -m "$2"
}

# start_imap: the IMAP server of this run, seeded with the folders and messages the tests expect.
# alice: INBOX (3 messages, the first seen, the second flagged), Sent (1 seen), Archive (1), Projects, Projects/2026.
# bob: INBOX (1 message); bob's account is the one alice must never reach.
start_imap() {
	docker pull --quiet "$IMAP_IMAGE" >/dev/null
	docker run -d --name "$IMAP" --network "$NET" -e USER_PASSWORD="$IMAP_PASSWORD" \
		-v "$HERE/mail/mcp-it.conf:/etc/dovecot/conf.d/mcp-it.conf:ro" "$IMAP_IMAGE" >/dev/null
	deadline=$(($(date +%s) + 120))
	until imap_ready >/dev/null 2>&1; do
		if [ "$(date +%s)" -ge "$deadline" ]; then
			docker logs "$IMAP" >&2 || true
			echo "Timed out waiting for the IMAP server" >&2
			return 1
		fi
		sleep 2
	done
	docker exec "$IMAP" doveadm mailbox create -u alice Sent Archive Projects Projects/2026
	save alice INBOX "Seen message"
	save alice INBOX "Flagged message"
	save alice INBOX "Unread message"
	save alice Sent "Sent message"
	save alice Archive "Archived message"
	save bob INBOX "Message of bob"
	docker exec "$IMAP" doveadm flags add -u alice '\Seen' mailbox INBOX uid 1
	docker exec "$IMAP" doveadm flags add -u alice '\Flagged' mailbox INBOX uid 2
	docker exec "$IMAP" doveadm flags add -u alice '\Seen' mailbox Sent uid 1
	echo "IMAP server $IMAP_IMAGE -> $(docker image inspect --format '{{.Os}}/{{.Architecture}} {{.Size}} bytes' "$IMAP_IMAGE")"
}

# install_mail: the pinned Mail release, checked against its checksum, unpacked into custom_apps and enabled.
install_mail() {
	mkdir -p "$TOOLS_DIR"
	archive="$TOOLS_DIR/mail.tar.gz"
	curl -fsSL -o "$archive" "https://github.com/nextcloud-releases/mail/releases/download/$MAIL_TAG/mail-$MAIL_TAG.tar.gz"
	echo "$MAIL_SHA  $archive" | sha256_check
	docker exec -u www-data "$NC" tar -xzf /mcp-it-tools/mail.tar.gz -C /var/www/html/custom_apps
	occ app:enable mail
	actual=$(occ config:app:get mail installed_version)
	[ "$actual" = "${MAIL_TAG#v}" ] || { echo "Unexpected installed Mail $actual" >&2; return 1; }
	echo "Mail $actual enabled"
}

# create_account <uid> <imap user>: one account through the Mail app's occ command, synchronized once; prints its id.
create_account() {
	created=$(occ mail:account:create-imap "$1" "Mail of $2" "$2@example.com" "$IMAP" "$IMAP_PORT" none "$2" "$IMAP_PASSWORD" \
		"$IMAP" 31587 none "$2" "$IMAP_PASSWORD")
	id=$(printf '%s\n' "$created" | sed -n 's/^Account \([0-9][0-9]*\) for .*/\1/p')
	case "$id" in
		''|*[!0-9]*) echo "mail:account:create-imap gave no account id: '$created'" >&2; return 1 ;;
	esac
	occ mail:account:sync "$id" >&2
	echo "$id"
}

# cmd_provision: IMAP server, Mail app and the two accounts.
cmd_provision() {
	start_imap
	install_mail
	# The IMAP server has a private address on the run's network; Nextcloud refuses local remote hosts by default.
	occ config:system:set allow_local_remote_servers --value=true --type=boolean
	alice=$(create_account mcp-it-alice alice)
	bob=$(create_account mcp-it-bob bob)
	echo "Mail accounts: mcp-it-alice=$alice mcp-it-bob=$bob"
}

# cmd_test_env: what the tests need to reach the IMAP server, one docker exec argument per line.
cmd_test_env() {
	printf '%s\n' -e "MCP_IT_WITH_MAIL=1" -e "MCP_IT_IMAP_HOST=$IMAP" -e "MCP_IT_IMAP_PORT=$IMAP_PORT" \
		-e "MCP_IT_IMAP_PASSWORD=$IMAP_PASSWORD" -e "MCP_IT_MAIL_VERSION=${MAIL_TAG#v}"
}

# cmd_collect: the IMAP server log, which lists every login and every flag change (mail_log), into the artifacts.
cmd_collect() {
	mkdir -p "$ARTIFACTS"
	docker logs "$IMAP" > "$ARTIFACTS/imap-container.log" 2>&1 || true
}

# cmd_cleanup: removes the container with its anonymous volume and proves that nothing of it is left.
cmd_cleanup() {
	volumes=$(docker inspect --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}' "$IMAP" 2>/dev/null || true)
	docker rm -f -v "$IMAP" >/dev/null 2>&1 || true
	remaining=$(docker ps -aq --filter "name=^$IMAP$") || remaining=unavailable
	leftover=""
	for volume in $volumes; do
		if docker volume inspect "$volume" >/dev/null 2>&1; then leftover="$leftover $volume"; fi
	done
	if [ "${PRUNE_IMAGES:-0}" = 1 ]; then
		docker rmi "$IMAP_IMAGE" >/dev/null 2>&1 || true
	fi
	printf 'imap_container=%s\nimap_volumes=%s\nimap_volumes_removed=%s\n' "${remaining:-none}" "${leftover:-none}" "$(echo $volumes)"
	[ -z "$remaining$leftover" ]
}

case "${1:-}" in
	provision) cmd_provision ;;
	test-env) cmd_test_env ;;
	collect) cmd_collect ;;
	cleanup) cmd_cleanup ;;
	*)
		echo "Usage: $0 <provision|test-env|collect|cleanup>" >&2
		exit 1
		;;
esac
