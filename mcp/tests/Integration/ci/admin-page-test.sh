#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Proves that admin-page.sh touches only what its own run created, without Colima: docker and colima are fakes on a
# PATH that does not reach the real ones, holding their containers and volumes as lines of files in a temporary
# directory. A control container and another run of admin-page.sh (with its own record) sit on the same fake engine
# and must survive every scenario: a failed start, an interrupted start, a start and its `down`, a `down` of an unknown
# run and a record that names something else. colima must never be called.
#
# Usage: admin-page-test.sh
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
ROOT=$(mktemp -d "${TMPDIR:-/tmp}/mcp-it-admin-page-test.XXXXXX")
FAKE="$ROOT/bin"
export ROOT MCP_IT_RUNS_DIR="$ROOT/runs"
trap 'rm -rf "$ROOT"' EXIT
mkdir -p "$FAKE" "$MCP_IT_RUNS_DIR"
FAILED=0

cat > "$FAKE/colima" <<'EOF'
#!/bin/sh
echo "colima $*" >> "$ROOT/colima-calls"
EOF
# The fake engine: one line per container in $ROOT/containers and per volume in $ROOT/volumes. The server reports
# itself installed only when $ROOT/installed exists; $ROOT/status-sleep makes each status check slow.
cat > "$FAKE/docker" <<'EOF'
#!/bin/sh
remove() { grep -vx "$2" "$ROOT/$1" > "$ROOT/$1.next" || true; mv "$ROOT/$1.next" "$ROOT/$1"; }
case "$1" in
	volume)
		case "$2" in
			create) echo "$3" >> "$ROOT/volumes" ;;
			rm) remove volumes "$3" ;;
		esac ;;
	run)
		while [ "$1" != --name ]; do shift; done
		echo "$2" >> "$ROOT/containers" ;;
	rm) for last; do :; done; remove containers "$last" ;;
	exec)
		case "$*" in
			*" status "*) [ -f "$ROOT/status-sleep" ] && sleep 1; [ -f "$ROOT/installed" ] && echo '{"installed":true}' ;;
		esac ;;
	port) echo "127.0.0.1:49999" ;;
esac
exit 0
EOF
chmod +x "$FAKE/colima" "$FAKE/docker"
PATH="$FAKE:/usr/bin:/bin"
export PATH
ARCHIVE="$ROOT/mcp.tar.gz"
touch "$ARCHIVE"

# check <description> <command...>: one assertion; a failure is reported and counted, the next ones still run.
check() {
	description="$1"
	shift
	if "$@"; then
		echo "ok - $description"
	else
		echo "not ok - $description"
		FAILED=$((FAILED + 1))
	fi
}

# The others: a control container that no admin-page run made, and another run with its own record.
reset_engine() {
	rm -f "$ROOT/installed" "$ROOT/status-sleep"
	printf 'mcp-it-control\nmcp-it-admin-page-other\n' > "$ROOT/containers"
	printf 'mcp-it-control-data\nmcp-it-admin-page-other-html\n' > "$ROOT/volumes"
	mkdir -p "$MCP_IT_RUNS_DIR/admin-page-other"
	printf 'container mcp-it-admin-page-other\nvolume mcp-it-admin-page-other-html\n' > "$MCP_IT_RUNS_DIR/admin-page-other/owned-resources"
}

others_survive() {
	grep -qx mcp-it-control "$ROOT/containers" && grep -qx mcp-it-admin-page-other "$ROOT/containers" \
		&& grep -qx mcp-it-control-data "$ROOT/volumes" && grep -qx mcp-it-admin-page-other-html "$ROOT/volumes" \
		&& [ -f "$MCP_IT_RUNS_DIR/admin-page-other/owned-resources" ]
}

# Only the other run's record and the control are left: no container, volume or record of the run under test.
only_others_left() {
	[ "$(grep -c . "$ROOT/containers")" -eq 2 ] && [ "$(grep -c . "$ROOT/volumes")" -eq 2 ] \
		&& [ "$(find "$MCP_IT_RUNS_DIR" -name owned-resources | wc -l | tr -d ' ')" -eq 1 ] && others_survive
}

# A start that never sees the server installed fails and removes its own container, volume and record.
reset_engine
check "a failed start exits non-zero" sh -c '! MCP_IT_ADMIN_TRIES=1 sh "$1" up "$2" 35 >/dev/null 2>&1' _ "$HERE/admin-page.sh" "$ARCHIVE"
check "a failed start leaves only the others" only_others_left

# An interrupted start (TERM while waiting for the server) cleans up the same way.
reset_engine
touch "$ROOT/status-sleep"
sh "$HERE/admin-page.sh" up "$ARCHIVE" 35 >/dev/null 2>&1 &
pid=$!
waited=0
until grep -q '^mcp-it-admin-page-[0-9]' "$ROOT/containers" || [ "$waited" -ge 50 ]; do sleep 0.1; waited=$((waited + 1)); done
kill -TERM "$pid"
status=0
wait "$pid" || status=$?
check "an interrupted start exits with 143" [ "$status" -eq 143 ]
check "an interrupted start leaves only the others" only_others_left

# A start that succeeds keeps its container and volume until `down <its id>`, which removes exactly them.
reset_engine
touch "$ROOT/installed"
out=$(sh "$HERE/admin-page.sh" up "$ARCHIVE" 35)
run=$(echo "$out" | sed -n 's/^Run id: //p')
check "a start prints its run id" [ -n "$run" ]
check "a start keeps its container" grep -qx "mcp-it-$run" "$ROOT/containers"
check "a start keeps its volume" grep -qx "mcp-it-$run-html" "$ROOT/volumes"
check "a start prints the port Docker chose" sh -c 'echo "$1" | grep -q "localhost:49999/settings/admin/mcp"' _ "$out"
check "a start leaves the others" others_survive
sh "$HERE/admin-page.sh" down "$run" >/dev/null
check "down removes exactly its run" only_others_left

# down needs a run id, and an unknown one removes nothing.
reset_engine
check "down without a run id is refused" sh -c '! sh "$1" down >/dev/null 2>&1' _ "$HERE/admin-page.sh"
check "down of an unknown run is refused" sh -c '! sh "$1" down admin-page-nobody >/dev/null 2>&1' _ "$HERE/admin-page.sh"
check "a refused down leaves the others" only_others_left

# A record that names something an admin-page run never creates is refused, and that thing survives.
reset_engine
mkdir -p "$MCP_IT_RUNS_DIR/admin-page-tampered"
printf 'container mcp-it-control\n' > "$MCP_IT_RUNS_DIR/admin-page-tampered/owned-resources"
check "a record naming the control is refused" sh -c '! sh "$1" down admin-page-tampered >/dev/null 2>&1' _ "$HERE/admin-page.sh"
check "the control survives a tampered record" others_survive
rm -rf "$MCP_IT_RUNS_DIR/admin-page-tampered"

check "colima is never called" [ ! -f "$ROOT/colima-calls" ]

[ "$FAILED" -eq 0 ] || { echo "$FAILED check(s) failed" >&2; exit 1; }
echo "all checks passed"
