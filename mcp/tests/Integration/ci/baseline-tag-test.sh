#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Covers the unchanged workflow default, an existing release, malformed input and a missing release.
set -eu
here=$(cd "$(dirname "$0")" && pwd)
test_repo=$(mktemp -d)
trap 'rm -rf "$test_repo"' EXIT INT TERM
git -C "$test_repo" init -q
git -C "$test_repo" -c user.name=Harness -c user.email=harness@example.test commit --allow-empty -qm fixture
git -C "$test_repo" tag v1.2.5
actual=$(unset MCP_IT_BASELINE_TAG; sh "$here/baseline-tag.sh" "$test_repo" v1.0.8)
[ "$actual" = v1.0.8 ] || { echo 'FAIL: workflow default changed' >&2; exit 1; }
actual=$(MCP_IT_BASELINE_TAG=v1.2.5 sh "$here/baseline-tag.sh" "$test_repo" v1.0.8)
[ "$actual" = v1.2.5 ] || { echo 'FAIL: local release was not selected' >&2; exit 1; }
for invalid in '../v1.2.5' 'v1.2.5-rc.1' '' 'v9.9.9'; do
	if MCP_IT_BASELINE_TAG="$invalid" sh "$here/baseline-tag.sh" "$test_repo" v1.0.8 >"$test_repo/out" 2>"$test_repo/err"; then
		echo 'FAIL: invalid or absent release was accepted' >&2
		exit 1
	fi
	[ ! -s "$test_repo/out" ] && [ -s "$test_repo/err" ] || { echo 'FAIL: no clear refusal' >&2; exit 1; }
done
echo 'OK: workflow default, existing release, malformed and missing tags'
