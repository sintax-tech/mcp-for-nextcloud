#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Proves ownership cleanup and shared-engine startup/release without touching real services.
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
runs=$(sh "$HERE/local-artifact-root.sh")
mkdir -p "$runs"
MCP_SHARED_TEST_ROOT=$(mktemp -d "$runs/shared-engine-test.XXXXXX")
export MCP_SHARED_TEST_ROOT
trap 'rm -rf "$MCP_SHARED_TEST_ROOT"' EXIT
mkdir -p "$MCP_SHARED_TEST_ROOT/bin" "$MCP_SHARED_TEST_ROOT/runs/dead" "$MCP_SHARED_TEST_ROOT/runs/live"
cat > "$MCP_SHARED_TEST_ROOT/bin/docker" <<'EOF'
#!/bin/sh
set -eu
r=$MCP_SHARED_TEST_ROOT
printf '%s\n' "$*" >> "$r/docker.log"
case "$1 ${2:-}" in
	'container inspect'|'network inspect'|'volume inspect') test -f "$r/$3" ;;
	'inspect --format') echo mcp-it-owned-volume ;;
	'rm -f') rm -f "$r/$4" "$r/mcp-it-owned-volume" ;;
	'network rm') rm -f "$r/$3" ;;
	'info ') test ! -f "$r/daemon-down" ;;
	*) exit 1 ;;
esac
EOF
cat > "$MCP_SHARED_TEST_ROOT/bin/colima" <<'EOF'
#!/bin/sh
set -eu
r=$MCP_SHARED_TEST_ROOT
printf '%s\n' "$*" >> "$r/colima.log"
case "$1" in
	status) test -f "$r/up" ;;
	start) touch "$r/up"; [ ! -f "$r/fail-start" ] || exit 17 ;;
	*) echo 'Shared engine must never be stopped' >&2; exit 1 ;;
esac
EOF
chmod +x "$MCP_SHARED_TEST_ROOT/bin/"*
PATH="$MCP_SHARED_TEST_ROOT/bin:$PATH"
export PATH
# This PID is outside the operating system's PID range on supported local hosts.
printf '%s\n' 999999999 mcp-it-owned-nc mcp-it-owned-db mcp-it-owned-net mcp-it-owned-imap \
	> "$MCP_SHARED_TEST_ROOT/runs/dead/owned-resources"
printf '%s\n' "$$" mcp-it-live-nc mcp-it-live-db mcp-it-live-net mcp-it-live-imap \
	> "$MCP_SHARED_TEST_ROOT/runs/live/owned-resources"
for name in mcp-it-owned-nc mcp-it-owned-db mcp-it-owned-net mcp-it-owned-imap mcp-it-owned-volume mcp-it-live-nc mcp-it-unrelated-nc; do
	touch "$MCP_SHARED_TEST_ROOT/$name"
done
sh "$HERE/cleanup-local-runs.sh" "$MCP_SHARED_TEST_ROOT/runs"
for name in mcp-it-owned-nc mcp-it-owned-db mcp-it-owned-net mcp-it-owned-imap mcp-it-owned-volume; do
	test ! -f "$MCP_SHARED_TEST_ROOT/$name"
done
test ! -f "$MCP_SHARED_TEST_ROOT/runs/dead/owned-resources"
test -f "$MCP_SHARED_TEST_ROOT/runs/live/owned-resources"
test -f "$MCP_SHARED_TEST_ROOT/mcp-it-live-nc"
test -f "$MCP_SHARED_TEST_ROOT/mcp-it-unrelated-nc"
! grep -q 'mcp-it-live\|mcp-it-unrelated' "$MCP_SHARED_TEST_ROOT/docker.log"
# Empty --shutdown must not call Colima or scan the daemon's global inventory.
sh "$HERE/run-local.sh" --shutdown
# Invalid ownership data fails before resource mutation.
mkdir -p "$MCP_SHARED_TEST_ROOT/invalid/run"
printf '%s\n' 999999999 foreign-nc mcp-it-db mcp-it-net mcp-it-imap > "$MCP_SHARED_TEST_ROOT/invalid/run/owned-resources"
if sh "$HERE/cleanup-local-runs.sh" "$MCP_SHARED_TEST_ROOT/invalid" 2>/dev/null; then exit 1; fi
# Shared startup, release, then reuse: one start, no stop, and no legacy stop flag written.
. "$HERE/shared-engine.sh"
engine_lock() { :; }
engine_unlock() { :; }
leave_slot() { touch "$MCP_SHARED_TEST_ROOT/released"; }
log() { echo "$*"; }
shared_engine_take
shared_engine_release
shared_engine_take
test -f "$MCP_SHARED_TEST_ROOT/released"
test "$(grep -c '^start ' "$MCP_SHARED_TEST_ROOT/colima.log")" = 1
! grep -q '^stop' "$MCP_SHARED_TEST_ROOT/colima.log"
# A partially successful engine startup must also preserve the shared profile on failure.
rm "$MCP_SHARED_TEST_ROOT/up"
touch "$MCP_SHARED_TEST_ROOT/fail-start"
set +e
sh -ec '
	. "$1"
	engine_lock() { :; }
	engine_unlock() { :; }
	leave_slot() { :; }
	log() { :; }
	trap shared_engine_release EXIT
	shared_engine_take
' sh "$HERE/shared-engine.sh"
start_exit=$?
set -e
test "$start_exit" = 17
test -f "$MCP_SHARED_TEST_ROOT/up"
! grep -q '^stop' "$MCP_SHARED_TEST_ROOT/colima.log"
echo 'Shared engine: owned resources removed; live/foreign resources preserved; startup reused; failure retained; no stop'
