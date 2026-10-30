#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Synthetic messages of alice for the message tools, stored on the throwaway IMAP server of a Mail cell through doveadm,
# the server's own tool. Called by mail-cell.sh; IMAP names the IMAP container.
#
# Usage: IMAP=<container> mail/seed-messages.sh <seed|regenerate>
#   seed        before the Mail accounts exist:
#               - Batch, Archive and Reading: messages saved within the same seconds (the Mail cache orders by the
#                 date the server received a message, IMAP INTERNALDATE), an HTML-only message, a message with an
#                 attachment, a thread, a message found by its body text only;
#               - three messages over the 1 MiB whole-message budget whose parts or headers would hide it: a base64 text
#                 part, an inline part declaring Content-Disposition size=1, and a large header over a small body;
#               - Regen and RegenNoId, one message each (the second without Message-ID)
#   regenerate  after the Mail app cached them: Regen and RegenNoId are recreated on the server with another
#               UIDVALIDITY and a different message at UID 1, with the same Message-ID (or none) as the cached one,
#               so the Mail cache holds an older UID generation than the server
set -eu

IMAP=${IMAP:?IMAP names the IMAP container}

# save_raw <imap user> <mailbox>: stores the RFC 5322 message read from stdin.
save_raw() {
	docker exec -i "$IMAP" doveadm save -u "$1" -m "$2"
}

# dated <date> <subject> <body>: one plain message from Carol with this Date header.
dated() {
	printf 'From: Carol <carol@example.com>\r\nTo: alice@example.com\r\nSubject: %s\r\nDate: %s\r\nMessage-ID: <%s@mcp-it.example>\r\n\r\n%s\r\n' \
		"$2" "$1" "$(printf '%s' "$2" | tr ' ' '-')" "$3"
}

# header <subject> <message-id or empty> [extra header lines]: the header block of a message, ending with a blank line.
header() {
	printf 'From: Carol <carol@example.com>\r\nTo: alice@example.com\r\nSubject: %s\r\nDate: Tue, 20 Oct 2026 12:00:00 +0000\r\n' "$1"
	[ -z "$2" ] || printf 'Message-ID: %s\r\n' "$2"
	[ -z "${3:-}" ] || printf '%b' "$3"
	printf '\r\n'
}

# lines <count> <text>: that many CRLF lines of the text.
lines() {
	awk -v n="$1" -v text="$2" 'BEGIN { for (i = 0; i < n; i++) printf "%s\r\n", text }'
}

# seed_reading: Batch, Archive and Reading.
seed_reading() {
	docker exec "$IMAP" doveadm mailbox create -u alice Batch Reading
	same='Fri, 23 Oct 2026 09:00:00 +0000'
	for n in 1 2 3 4 5 6; do dated "$same" "Batch $n" "Batch body $n." | save_raw alice Batch; done
	dated 'Thu, 22 Oct 2026 09:00:00 +0000' 'Batch older' 'The zebracorn is in the batch.' | save_raw alice Batch
	dated 'Wed, 21 Oct 2026 09:00:00 +0000' 'Batch oldest' 'Oldest of the batch.' | save_raw alice Batch
	for n in 1 2; do dated "$same" "Archive tie $n" "Archived on the batch date." | save_raw alice Archive; done
	{ header 'HTML only' '<html-only@mcp-it.example>' "MIME-Version: 1.0\r\nContent-Type: text/html; charset=utf-8\r\n"
		printf '<html><head><style>p{color:red}</style></head><body><p>Hello&nbsp;<b>reader</b></p><script>alert(1)</script><img src="https://tracker.example/p.gif"></body></html>\r\n'
	} | save_raw alice Reading
	{ header 'With attachment' '<with-attachment@mcp-it.example>' "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"b1\"\r\n"
		printf -- '--b1\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nSee the attached plan.\r\n--b1\r\nContent-Type: application/pdf; name="plan.pdf"\r\nContent-Disposition: attachment; filename="plan.pdf"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjQKJcOkw7zDtsOfCg==\r\n--b1--\r\n'
	} | save_raw alice Reading
	{ header 'Oversized' '<oversized@mcp-it.example>'; lines 16000 "$(printf '%075d' 0 | tr 0 x)"; } | save_raw alice Reading
	printf 'From: Carol <carol@example.com>\r\nTo: alice@example.com\r\nSubject: Thread start\r\nDate: Mon, 19 Oct 2026 09:00:00 +0000\r\nMessage-ID: <thread-start@mcp-it.example>\r\n\r\nShall we meet?\r\n' \
		| save_raw alice Reading
	printf 'From: Dan <dan@example.com>\r\nTo: team@example.org\r\nCc: eve@example.com\r\nSubject: Re: Thread start\r\nDate: Mon, 19 Oct 2026 10:00:00 +0000\r\nMessage-ID: <thread-reply@mcp-it.example>\r\nIn-Reply-To: <thread-start@mcp-it.example>\r\nReferences: <thread-start@mcp-it.example>\r\n\r\nYes, on Friday.\r\n' \
		| save_raw alice Reading
	printf 'From: Zed <zed@example.net>\r\nTo: other@example.net\r\nCc: frank@example.com\r\nSubject: Venue booking\r\nDate: Sun, 18 Oct 2026 09:00:00 +0000\r\nMessage-ID: <venue-booking@mcp-it.example>\r\n\r\nThe zebracorn venue is booked.\r\n' \
		| save_raw alice Reading
}

# seed_hidden_size: three messages over the whole-message budget that their parts or headers would understate.
seed_hidden_size() {
	docker exec "$IMAP" doveadm mailbox create -u alice Sizes
	{ header 'Base64 text' '<base64-text@mcp-it.example>' "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n"
		lines 14500 'YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh'
	} | save_raw alice Sizes
	{ header 'Inline size one' '<inline-size-one@mcp-it.example>' "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Disposition: inline; size=1\r\n"
		lines 15000 "$(printf '%075d' 0 | tr 0 y)"
	} | save_raw alice Sizes
	{ header 'Large header' '<large-header@mcp-it.example>' "$(awk 'BEGIN { pad = sprintf("%0900d", 0); gsub(/0/, "h", pad); for (i = 0; i < 1300; i++) printf "X-Filler-%d: %s\r\n", i, pad }')"
		printf 'Small body.\r\n'
	} | save_raw alice Sizes
}

# seed_regen: the two folders regenerate replaces.
seed_regen() {
	docker exec "$IMAP" doveadm mailbox create -u alice Regen RegenNoId
	{ header 'Regen original' '<regen-same@mcp-it.example>'; printf 'The cached message.\r\n'; } | save_raw alice Regen
	{ header 'Regen no id original' ''; printf 'The cached message without id.\r\n'; } | save_raw alice RegenNoId
}

# regenerate_folder <folder> <message-id or empty>: the folder recreated with a new UIDVALIDITY and another message at UID 1.
regenerate_folder() {
	old=$(docker exec "$IMAP" doveadm -f flow mailbox status -u alice uidvalidity "$1" | sed -n 's/.*uidvalidity=\([0-9][0-9]*\).*/\1/p')
	case "$old" in ''|*[!0-9]*) echo "No UIDVALIDITY for $1" >&2; return 1 ;; esac
	docker exec "$IMAP" doveadm mailbox delete -u alice "$1"
	docker exec "$IMAP" doveadm mailbox create -u alice "$1"
	{ header "$1 replacement" "$2"; printf 'Another message at the same UID.\r\n'; } | save_raw alice "$1"
	docker exec "$IMAP" doveadm mailbox update -u alice --uid-validity "$((old + 1000))" "$1"
	echo "$1: uidvalidity $old -> $(docker exec "$IMAP" doveadm -f flow mailbox status -u alice 'uidvalidity uidnext' "$1")"
}

case "${1:-}" in
	seed)
		seed_reading
		seed_hidden_size
		seed_regen
		;;
	regenerate)
		regenerate_folder Regen '<regen-same@mcp-it.example>'
		regenerate_folder RegenNoId ''
		;;
	*)
		echo "Usage: IMAP=<container> $0 <seed|regenerate>" >&2
		exit 1
		;;
esac
