#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# PHP settings of the disposable Nextcloud container, passed to the official image through the variables it reads.
# OPcache memory: the image's 128 MB fill up during provisioning with Nextcloud and every app a cell enables, and the
# scripts loaded after that are compiled in every request without being cached. Nextcloud's own setup check asks for
# more in that state ("The OPcache buffer is nearly full ... it is recommended to apply opcache.memory_consumption
# ... with a value higher than 128", settings/lib/SetupChecks/PhpOpcacheSetup.php). With the image's tracing JIT,
# PHP 8.3 crashed an Apache worker in a JIT helper over those uncached scripts on every NC 32 fresh cell from run
# 42430994415 on (php-src PR #24333); 256 MB keeps every script of a cell cached, as on a server set up as advised.
#
# Usage: php-config.sh run-args
#   run-args  prints the `docker run` arguments of the Nextcloud container (one per line)
set -eu

# OPcache shared memory, in MB (the image's PHP_OPCACHE_MEMORY_CONSUMPTION, default 128).
OPCACHE_MB=256

case "${1:-}" in
	run-args)
		printf '%s\n' -e "PHP_OPCACHE_MEMORY_CONSUMPTION=$OPCACHE_MB"
		;;
	*) echo 'Usage: php-config.sh run-args' >&2; exit 2 ;;
esac
