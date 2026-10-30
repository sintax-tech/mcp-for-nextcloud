#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Synthetic messages of alice for mail_move_message, in the folder Move of the throwaway IMAP server of a Mail cell,
# stored through doveadm, the server's own tool, with the folders they are moved to: "Move target", Trash and Junk (the
# Mail app takes Trash and Junk as the account's Trash and Junk folder by their role or name when it synchronizes the
# account). Called by mail-cell.sh before the Mail accounts exist; IMAP names the IMAP container. The other folders,
# which the read and flag tests compare, are never touched by the move tests.
# - "Move to folder", "Move to trash", "Move to junk": moved by the actor test;
# - "Move HTTP basic", "Move HTTP bearer": moved through the endpoint;
# - "Move stays": never moved, the control.
#
# Usage: IMAP=<container> mail/seed-move.sh
set -eu

IMAP=${IMAP:?IMAP names the IMAP container}

# save <subject>: one plain message from Carol in alice's Move folder.
save() {
	printf 'From: Carol <carol@example.com>\r\nTo: alice@example.com\r\nSubject: %s\r\nDate: Sun, 25 Oct 2026 10:00:00 +0000\r\nMessage-ID: <%s@mcp-it.example>\r\n\r\nMove fixture.\r\n' \
		"$1" "$(printf '%s' "$1" | tr ' ' '-')" | docker exec -i "$IMAP" doveadm save -u alice -m Move
}

docker exec "$IMAP" doveadm mailbox create -u alice Move 'Move target' Trash Junk
save 'Move to folder'
save 'Move to trash'
save 'Move to junk'
save 'Move HTTP basic'
save 'Move HTTP bearer'
save 'Move stays'
