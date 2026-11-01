#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Upgrades the installed local package without replacing it from the App Store.
# Usage: upgrade-local-package.sh <disposable Nextcloud container>
set -eu
NC=$1

# occ <arguments...>: use the disposable instance's official configuration interface.
occ() {
	docker exec -u www-data "$NC" php /var/www/html/occ "$@"
}

# restore_store: retain both the original boolean and the absence of an explicit override.
restore_store() {
	if [ "$store" = __absent__ ]; then
		occ config:system:delete appstoreenabled
	else
		occ config:system:set appstoreenabled --type=boolean --value="$store"
	fi
}

store=$(occ config:system:get appstoreenabled --default-value=__absent__)
trap restore_store EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
occ config:system:set appstoreenabled --type=boolean --value=false
occ upgrade
