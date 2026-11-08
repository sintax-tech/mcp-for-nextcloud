#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Evidence of a crashed Apache worker in the disposable Nextcloud container. A worker that dies in the middle of a
# request only shows up as "Empty reply from server" in a test and as AH00051 in the container log; this script keeps
# what explains it. The runner's kernel.core_pattern sends cores to /tmp/mcp-it-core.<exe>.<pid> inside the container
# (.github/workflows/integration.yml); a local run keeps the shared engine's pattern, so it reports the crash without
# a core unless the pattern was set by hand.
#
# Usage: crash-evidence.sh <run-args|collect>
#   run-args  prints the `docker run` arguments of the Nextcloud container (one per line): unlimited cores and the
#             Apache CoreDumpDirectory that makes the workers dumpable
#   collect   appends to runtime.md the AH00051 lines, the gdb backtrace of each core (full gdb output next to it as
#             crash-<core>.txt; the core itself is removed, not uploaded) and the OPcache/JIT state of the workers
# Environment: the harness variables (MCP_IT_NC_NAME, RUNNER_TEMP, GITHUB_STEP_SUMMARY on the runner).
set -eu

NC=${MCP_IT_NC_NAME:-mcp-it-nextcloud}
WORK="${RUNNER_TEMP:?RUNNER_TEMP is set by the harness}/mcp-it"
ARTIFACTS="$WORK/artifacts"
HERE=$(cd "$(dirname "$0")" && pwd)
# The same files as seen from inside the container, where the harness mounts the repository at /mcp-it-src.
IN_CONTAINER=/mcp-it-src/mcp/tests/Integration/ci/crash-evidence
REPORT="$ARTIFACTS/runtime.md"
# At most this many cores are read; one is enough to tell where the worker died.
MAX_CORES=3
FENCE='```'

# Installs gdb in the container once, from the image's own Debian repositories.
install_gdb() {
	docker exec -u root "$NC" sh -c 'command -v gdb >/dev/null 2>&1 || { apt-get update -qq \
		&& DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends gdb >/dev/null; }'
}

# gdb_output <core>: backtrace, faulting instruction, its address, the registers it read (the image's opcache.so has
# no symbols, so the registers tell what the instruction dereferenced) and the files mapped by the crashed worker.
gdb_output() {
	docker exec -u root "$NC" gdb -batch -nx -ex 'bt 40' -ex 'x/i $pc' -ex 'info symbol $pc' -ex 'p/x $pc' \
		-ex 'info registers' -ex 'info proc mappings' /usr/sbin/apache2 "$1" 2>&1
}

# crash_section: the AH00051 lines and, for each core, where the worker died and its first frames.
crash_section() {
	crashes=$(docker logs "$NC" 2>&1 | grep 'AH00051' || true)
	echo '#### Apache worker crashes (AH00051)'
	echo "$FENCE"
	if [ -z "$crashes" ]; then
		echo 'none'
		echo "$FENCE"
		return
	fi
	echo "$crashes"
	echo "kernel.core_pattern: $(docker exec "$NC" cat /proc/sys/kernel/core_pattern 2>&1 || echo unknown)"
	cores=$(docker exec "$NC" sh -c 'ls /tmp/mcp-it-core.* 2>/dev/null' | head -n "$MAX_CORES" || true)
	if [ -z "$cores" ]; then
		echo 'no core in /tmp/mcp-it-core.* (the kernel.core_pattern above does not point there)'
	elif ! install_gdb; then
		echo "cores kept in the container, gdb could not be installed: $cores"
	else
		for core in $cores; do
			name=$(basename "$core")
			gdb_output "$core" > "$ARTIFACTS/crash-$name.txt" || true
			echo "$name: $(docker exec -i "$NC" php "$IN_CONTAINER/locate-pc.php" < "$ARTIFACTS/crash-$name.txt")"
			# gdb prints frame #0 once when it loads the core and again in the backtrace.
			grep '^#' "$ARTIFACTS/crash-$name.txt" | awk '!seen[$0]++' | head -n 15 || true
			echo "(full gdb output: crash-$name.txt)"
			docker exec -u root "$NC" rm -f "$core"
		done
	fi
	echo "$FENCE"
}

# opcache_section: the OPcache/JIT state the Apache workers share, read through Apache itself, since the PHP CLI
# runs without OPcache. ocs-provider/ is one of the folders the server's rewrite rules leave to Apache.
opcache_section() {
	probe=/var/www/html/ocs-provider/mcp-it-opcache-status.php
	echo '#### OPcache and JIT of the Apache workers at collect time'
	echo "$FENCE"
	if docker exec -u root "$NC" cp "$IN_CONTAINER/opcache-status.php" "$probe" 2>/dev/null; then
		docker exec "$NC" curl -fsS -m 30 http://localhost/ocs-provider/mcp-it-opcache-status.php 2>&1 || echo 'unavailable'
		docker exec -u root "$NC" rm -f "$probe"
	else
		echo 'unavailable (the container is not running)'
	fi
	echo "$FENCE"
}

case "${1:-}" in
	run-args)
		printf '%s\n' --ulimit core=-1 -v "$HERE/crash-evidence/apache-coredump.conf:/etc/apache2/conf-enabled/mcp-it-coredump.conf:ro"
		;;
	collect)
		mkdir -p "$ARTIFACTS"
		crashes=$(crash_section)
		opcache=$(opcache_section)
		printf '%s\n%s\n' "$crashes" "$opcache" >> "$REPORT"
		printf '%s\n%s\n' "$crashes" "$opcache"
		if [ -n "${GITHUB_STEP_SUMMARY:-}" ] && ! printf '%s\n' "$crashes" | grep -qx 'none'; then
			printf '%s\n' "$crashes" >> "$GITHUB_STEP_SUMMARY"
		fi
		;;
	*) echo 'Usage: crash-evidence.sh <run-args|collect>' >&2; exit 2 ;;
esac
