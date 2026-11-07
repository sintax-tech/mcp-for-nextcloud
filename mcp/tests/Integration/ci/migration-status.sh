#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Reports app migration status on disposable NC32–35 instances only.
# All four releases register migrations:status only when debug is enabled.
# Usage: migration-status.sh <mcp-it-* container>
# Preserves debug's original boolean or absence, including on command failure.
# Missing commands and configuration failures are fatal; preview is not an app status command.
set -eu
NC=${1:-}
case "$NC" in
	mcp-it-*) ;;
	*) echo 'Migration status requires a disposable mcp-it-* container' >&2; exit 1 ;;
esac

# occ <arguments...>: call the disposable instance's official console as its web user.
occ() {
	docker exec -u www-data "$NC" php /var/www/html/occ "$@"
}

# restore_debug: restore the original boolean, or remove the temporary override if none existed.
restore_debug() {
	if [ "$debug" = __absent__ ]; then
		occ config:system:delete debug
	else
		occ config:system:set debug --type=boolean --value="$debug"
	fi
}

debug=$(occ config:system:get debug --default-value=__absent__)
case "$debug" in
	__absent__|true|false) ;;
	*) echo "Unexpected debug configuration: '$debug'" >&2; exit 1 ;;
esac
trap restore_debug EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
occ config:system:set debug --type=boolean --value=true
occ migrations:status mcp
