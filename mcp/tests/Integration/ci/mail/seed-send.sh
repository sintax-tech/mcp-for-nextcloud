#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# A folder of alice for mail_send_message, on the throwaway IMAP server of a Mail cell, created through doveadm, empty:
# "Sent gone", which the Mail app caches at its first synchronization and the send test then deletes on the server and
# names as the account's Sent folder, so the copy to Sent fails after the SMTP sink accepted a message. Called by
# mail-cell.sh before the Mail accounts exist; IMAP names the IMAP container.
#
# Usage: IMAP=<container> mail/seed-send.sh
set -eu

IMAP=${IMAP:?IMAP names the IMAP container}

docker exec "$IMAP" doveadm mailbox create -u alice 'Sent gone'
