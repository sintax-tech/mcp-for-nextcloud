#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Proves App Store isolation and restoration on successful and failed local upgrades.
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
runs=$(sh "$HERE/local-artifact-root.sh")
mkdir -p "$runs"
MCP_UPGRADE_TEST_ROOT=$(mktemp -d "$runs/upgrade-test.XXXXXX")
export MCP_UPGRADE_TEST_ROOT
trap 'rm -rf "$MCP_UPGRADE_TEST_ROOT"' EXIT
mkdir -p "$MCP_UPGRADE_TEST_ROOT/bin"
cat > "$MCP_UPGRADE_TEST_ROOT/bin/docker" <<'EOF'
#!/bin/sh
set -eu
shift 6
r=$MCP_UPGRADE_TEST_ROOT
case "$1" in
	config:system:get) cat "$r/store" ;;
	config:system:set) printf '%s\n' "${4#--value=}" > "$r/store" ;;
	config:system:delete) echo __absent__ > "$r/store" ;;
	upgrade)
		test "$(cat "$r/store")" = false
		echo local-package-upgrade >> "$r/observations"
		if [ -f "$r/fail" ]; then exit 17; fi
		;;
	*) exit 1 ;;
esac
EOF
chmod +x "$MCP_UPGRADE_TEST_ROOT/bin/docker"
PATH="$MCP_UPGRADE_TEST_ROOT/bin:$PATH"
export PATH
for store in __absent__ true false; do
	for fail in no yes; do
		printf '%s\n' "$store" > "$MCP_UPGRADE_TEST_ROOT/store"
		rm -f "$MCP_UPGRADE_TEST_ROOT/fail"
		[ "$fail" = no ] || touch "$MCP_UPGRADE_TEST_ROOT/fail"
		status=0
		sh "$HERE/upgrade-local-package.sh" mcp-it-upgrade-proof || status=$?
		if [ "$fail" = yes ]; then test "$status" = 17; else test "$status" = 0; fi
		test "$(cat "$MCP_UPGRADE_TEST_ROOT/store")" = "$store"
	done
done
test "$(wc -l < "$MCP_UPGRADE_TEST_ROOT/observations" | tr -d ' ')" = 6
echo 'Local upgrade: App Store disabled; absent/true/false restored; failure exit retained (6 cases)'
