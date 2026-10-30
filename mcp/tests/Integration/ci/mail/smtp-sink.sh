#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The throwaway SMTP sink of a Mail cell (smtp-sink.php), run by PHP inside the Nextcloud container of the run, on its
# loopback only: no new image, no network of its own, no relay. `start <account id>` starts it and points the SMTP of that
# Mail account (alice's) to it through the Mail app's own occ command; `collect <dir>` copies its log into the artifacts.
# The container is removed with the run, and the sink with it. Called by mail-cell.sh; NC names the Nextcloud container.
#
# Usage: NC=<container> mail/smtp-sink.sh start <account id> | collect <dir>
set -eu

NC=${NC:?NC names the Nextcloud container}
PORT=2525
LOG=/tmp/mcp-it-smtp-sink.jsonl

case "${1:-}" in
	start)
		account=${2:?account id}
		docker exec -u www-data "$NC" sh -c ": > $LOG"
		docker exec -d -u www-data "$NC" php /mcp-it-src/mcp/tests/Integration/ci/mail/smtp-sink.php "$PORT" "$LOG"
		docker exec -u www-data "$NC" php /var/www/html/occ mail:account:update-imap "$account" \
			--smtp-host=127.0.0.1 --smtp-port="$PORT" --smtp-ssl-mode=none
		echo "SMTP sink on 127.0.0.1:$PORT of $NC for Mail account $account"
		;;
	collect)
		docker cp "$NC:$LOG" "${2:?artifacts dir}/smtp-sink.jsonl" 2>/dev/null || true
		;;
	*)
		echo "usage: smtp-sink.sh start <account id> | collect <dir>" >&2
		exit 2
		;;
esac
