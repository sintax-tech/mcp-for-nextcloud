#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Synthetic messages of alice for mail_set_flags, in the folder Flags of the throwaway IMAP server of a Mail cell,
# stored and flagged through doveadm, the server's own tool. Called by mail-cell.sh before the Mail accounts exist;
# IMAP names the IMAP container. The other folders, which the read tests compare, are never touched by the flag tests.
# - "Flag target" and "Flag target HTTP": no flag;
# - "Flag already read": \Seen;
# - "Flag both": \Seen, \Flagged and \Answered.
#
# Usage: IMAP=<container> mail/seed-flags.sh
set -eu

IMAP=${IMAP:?IMAP names the IMAP container}

# save <subject>: one plain message from Carol in alice's Flags folder.
save() {
	printf 'From: Carol <carol@example.com>\r\nTo: alice@example.com\r\nSubject: %s\r\nDate: Sun, 25 Oct 2026 09:00:00 +0000\r\nMessage-ID: <%s@mcp-it.example>\r\n\r\nFlags fixture.\r\n' \
		"$1" "$(printf '%s' "$1" | tr ' ' '-')" | docker exec -i "$IMAP" doveadm save -u alice -m Flags
}

docker exec "$IMAP" doveadm mailbox create -u alice Flags
save 'Flag target'
save 'Flag target HTTP'
save 'Flag already read'
save 'Flag both'
docker exec "$IMAP" doveadm flags add -u alice '\Seen' mailbox Flags uid 3
docker exec "$IMAP" doveadm flags add -u alice '\Seen \Flagged \Answered' mailbox Flags uid 4
