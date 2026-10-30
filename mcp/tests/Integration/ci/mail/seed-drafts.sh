#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The Drafts folder of alice for mail_create_draft, on the throwaway IMAP server of a Mail cell, created through doveadm,
# the server's own tool, empty: the Mail app takes it as the account's Drafts folder by its role or name when it
# synchronizes the account. Called by mail-cell.sh before the Mail accounts exist; IMAP names the IMAP container. The
# image's defaults also create the English special folders at a user's first login (bob gets his Drafts that way); this
# one is created explicitly so alice's Drafts exists, empty, whatever those defaults are.
#
# Usage: IMAP=<container> mail/seed-drafts.sh
set -eu

IMAP=${IMAP:?IMAP names the IMAP container}

docker exec "$IMAP" doveadm mailbox create -u alice Drafts
