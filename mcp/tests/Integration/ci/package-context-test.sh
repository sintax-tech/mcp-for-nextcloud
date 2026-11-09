#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# The harness preserves the app/root layout, runs each revision's own package script and never invents a changelog.
set -eu
here=$(cd "$(dirname "$0")" && pwd)
fixture=$(mktemp -d)
trap 'git -C "$fixture/repo" worktree remove --force "$fixture/run/mcp-it/baseline" 2>/dev/null || true; rm -rf "$fixture"' EXIT INT TERM
repo="$fixture/repo"
mkdir -p "$repo/mcp/tests/Integration/ci" "$repo/mcp/scripts"
cp "$here/harness.sh" "$repo/mcp/tests/Integration/ci/harness.sh"
git -C "$repo" init -q
# A historical revision has neither a root changelog nor a script that consumes it.
cat > "$repo/mcp/scripts/package.sh" <<'SCRIPT'
set -eu
mkdir -p build
echo historical > build/package.tar.gz
echo build/package.tar.gz
SCRIPT
git -C "$repo" add .
git -C "$repo" -c user.name=Harness -c user.email=harness@example.test commit -qm historical
git -C "$repo" tag historical
cat > "$repo/mcp/scripts/package.sh" <<'SCRIPT'
set -eu
mkdir -p build
cp ../CHANGELOG.md build/package.tar.gz
echo build/package.tar.gz
SCRIPT
echo current > "$repo/CHANGELOG.md"
git -C "$repo" add .
git -C "$repo" -c user.name=Harness -c user.email=harness@example.test commit -qm current
MCP_IT_LOCAL=1 RUNNER_TEMP="$fixture/run" MCP_IT_MODE=upgrade MCP_IT_BASELINE=historical \
	sh "$repo/mcp/tests/Integration/ci/harness.sh" packages
[ "$(cat "$fixture/run/mcp-it/mcp-branch.tar.gz")" = current ]
[ "$(cat "$fixture/run/mcp-it/mcp-baseline.tar.gz")" = historical ]
# A second build must not reuse the changelog of the first one if the release source is incomplete.
rm "$repo/CHANGELOG.md"
if MCP_IT_LOCAL=1 RUNNER_TEMP="$fixture/run" MCP_IT_MODE=fresh \
	sh "$repo/mcp/tests/Integration/ci/harness.sh" packages >"$fixture/out" 2>"$fixture/err"; then
	echo 'FAIL: a release without its root changelog was accepted' >&2
	exit 1
fi
grep -q CHANGELOG.md "$fixture/err"
echo 'OK: current root changelog, historical script without changelog, missing release changelog refused'
