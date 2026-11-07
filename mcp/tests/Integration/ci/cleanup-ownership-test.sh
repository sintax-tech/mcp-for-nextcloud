#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Proves interrupted ownership survives daemon, inspect, removal and verification failures.
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
MCP_CLEANUP_TEST_ROOT=$(mktemp -d "${TMPDIR:-/tmp}/mcp-it-cleanup-test.XXXXXX")
export MCP_CLEANUP_TEST_ROOT
trap 'rm -rf "$MCP_CLEANUP_TEST_ROOT"' EXIT
mkdir -p "$MCP_CLEANUP_TEST_ROOT/bin" "$MCP_CLEANUP_TEST_ROOT/checkout/.mcp-it-runs/dead"
cat > "$MCP_CLEANUP_TEST_ROOT/bin/docker" <<'DOCKER'
#!/bin/sh
set -eu
r=$MCP_CLEANUP_TEST_ROOT
[ ! -f "$r/daemon-down" ] || exit 1
case "$1 ${2:-}" in
	'info ') exit 0 ;;
	'container inspect'|'network inspect')
		[ ! -f "$r/inspect-fails" ] && test -f "$r/$3" ;;
	'inspect --format') echo mcp-it-owned-volume ;;
	'container ls'|'network ls'|'volume ls')
		[ ! -f "$r/list-fails" ] || exit 1
		for arg; do
			case "$arg" in name=*) name=${arg#name=^}; name=${name%\$}; test ! -f "$r/$name" || echo "$name" ;; esac
		done ;;
	'rm -f')
		[ ! -f "$r/remove-fails" ] || exit 1
		[ -f "$r/retains-container" ] || rm -f "$r/$4"
		[ -f "$r/retains-volume" ] || rm -f "$r/mcp-it-owned-volume" ;;
	'network rm') rm -f "$r/$3" ;;
	'volume rm') [ ! -f "$r/retains-volume" ] && rm -f "$r/$3" ;;
	*) exit 1 ;;
esac
DOCKER
cat > "$MCP_CLEANUP_TEST_ROOT/bin/sort" <<'SORT'
#!/bin/sh
set -eu
[ ! -f "$MCP_CLEANUP_TEST_ROOT/journal-read-fails" ] || exit 1
exec /usr/bin/sort "$@"
SORT
chmod +x "$MCP_CLEANUP_TEST_ROOT/bin/"*
PATH="$MCP_CLEANUP_TEST_ROOT/bin:$PATH"
export PATH
manifest="$MCP_CLEANUP_TEST_ROOT/checkout/.mcp-it-runs/dead/owned-resources"
# Each failed proof preserves both the interrupted run's ownership and the resource still needing cleanup.
for fault in daemon-down inspect-fails list-fails remove-fails retains-container retains-volume journal-read-fails; do
	printf '%s\n' 999999999 mcp-it-owned-nc mcp-it-owned-db mcp-it-owned-net mcp-it-owned-imap > "$manifest"
	for resource in nc db net imap volume; do touch "$MCP_CLEANUP_TEST_ROOT/mcp-it-owned-$resource"; done
	touch "$MCP_CLEANUP_TEST_ROOT/$fault"
	if sh "$HERE/shutdown-sandbox.sh" "$MCP_CLEANUP_TEST_ROOT/checkout"; then
		echo "Cleanup accepted failed proof: $fault" >&2
		exit 1
	fi
	test -f "$manifest"
	if [ "$fault" = retains-volume ]; then
		test ! -f "$MCP_CLEANUP_TEST_ROOT/mcp-it-owned-nc"
		grep -Fxq mcp-it-owned-volume "$(dirname "$manifest")/owned-volumes"
	fi
	rm "$MCP_CLEANUP_TEST_ROOT/$fault"
	# Retry the same manifest, including a volume whose original container has already gone.
	sh "$HERE/shutdown-sandbox.sh" "$MCP_CLEANUP_TEST_ROOT/checkout"
	test ! -f "$manifest"
	for resource in nc db net imap volume; do test ! -f "$MCP_CLEANUP_TEST_ROOT/mcp-it-owned-$resource"; done
done
# Resources never created by an interrupted startup are positively absent in a responsive inventory.
printf '%s\n' 999999999 mcp-it-owned-nc mcp-it-owned-db mcp-it-owned-net mcp-it-owned-imap > "$manifest"
sh "$HERE/shutdown-sandbox.sh" "$MCP_CLEANUP_TEST_ROOT/checkout"
test ! -f "$manifest"
echo 'Ownership cleanup: seven failures retain manifests; retries clean exact resources; absent resources confirmed'
