#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Proves the FIFO log guard accepts the native transient holder and still requires position and waiters.
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
. "$HERE/shared-queue-checks.sh"
root=$(mktemp -d "${TMPDIR:-/tmp}/mcp-it-position-test.XXXXXX")
trap 'rm -rf "$root"' EXIT
for holder in 123 '?'; do
	printf "queue position 5: the engine's 1 slot(s) are held by run(s) %s; waiting before this run: 234 345 456 567\n" "$holder" > "$root/log"
	fifo_position_logged "$root/log"
done
for holder in '' invalid '123 234'; do
	printf "queue position 5: the engine's 1 slot(s) are held by run(s) %s; waiting before this run: 234 345 456 567\n" "$holder" > "$root/log"
	if fifo_position_logged "$root/log"; then echo 'Invalid holder accepted' >&2; exit 1; fi
done
printf "queue position 4: the engine's 1 slot(s) are held by run(s) ?; waiting before this run: 234 345 456 567\n" > "$root/log"
if fifo_position_logged "$root/log"; then echo 'Wrong position accepted' >&2; exit 1; fi
printf "queue position 5: the engine's 1 slot(s) are held by run(s) ?; waiting before this run: 234 345 456\n" > "$root/log"
if fifo_position_logged "$root/log"; then echo 'Missing waiter accepted' >&2; exit 1; fi
echo 'FIFO log: numeric and transient holders accepted; invalid holder, position and waiters rejected (7 cases)'
