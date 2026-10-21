#!/bin/sh
# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Explicit disposable integration run; never starts or stops the shared Docker daemon.
set -eu
CI_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
plan_only=false
if [ "${1:-}" = --plan ]; then plan_only=true; shift; fi
plan=$(php "$CI_DIR/matrix.php" "$@")
if [ "$plan_only" = true ]; then printf '%s\n' "$plan"; exit 0; fi
ROOT=$(CDPATH='' cd -- "$CI_DIR/../../../.." && pwd)
MCP_IT_RUN_ID="mcp-it-$(date -u +%Y%m%d%H%M%S)-$(php -r 'echo bin2hex(random_bytes(6));')"
RUNNER_TEMP="$ROOT/mcp/build/integration/$MCP_IT_RUN_ID"
umask 077
mkdir -p "$RUNNER_TEMP"
printf '%s\n' "$plan" > "$RUNNER_TEMP/plan.json"
# shellcheck disable=SC2016
field() { php -r '$p=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); echo $p[$argv[2]] ?? "";' "$RUNNER_TEMP/plan.json" "$1"; }
MCP_IT_NC=$(field nc); MCP_IT_DB=$(field db); MCP_IT_MODE=$(field mode)
MCP_IT_NC_IMAGE=$(field nc_image); MCP_IT_DB_IMAGE=$(field db_image); MCP_IT_BASELINE=$(field baseline_commit)
MCP_IT_NET="$MCP_IT_RUN_ID-net"; MCP_IT_NC_NAME="$MCP_IT_RUN_ID-nc"; MCP_IT_DB_NAME="$MCP_IT_RUN_ID-db"
MCP_IT_LOCAL=1
export RUNNER_TEMP MCP_IT_RUN_ID MCP_IT_LOCAL MCP_IT_NC MCP_IT_DB MCP_IT_MODE MCP_IT_NC_IMAGE MCP_IT_DB_IMAGE MCP_IT_BASELINE MCP_IT_NET MCP_IT_NC_NAME MCP_IT_DB_NAME
# The label check also protects a pre-existing resource if creation fails on a name collision.
cleanup() {
    result=$?
    trap - EXIT HUP INT TERM
    sh "$CI_DIR/harness.sh" collect || result=1
    for container in "$MCP_IT_NC_NAME" "$MCP_IT_DB_NAME"; do
        owner=$(docker inspect --format '{{ index .Config.Labels "mcp.integration.run" }}' "$container" 2>/dev/null || true)
        if [ "$owner" = "$MCP_IT_RUN_ID" ]; then docker rm -fv "$container" >/dev/null || result=1; fi
    done
    owner=$(docker network inspect --format '{{ index .Labels "mcp.integration.run" }}' "$MCP_IT_NET" 2>/dev/null || true)
    if [ "$owner" = "$MCP_IT_RUN_ID" ]; then docker network rm "$MCP_IT_NET" >/dev/null || result=1; fi
    printf '{"exitCode":%s}\n' "$result" > "$RUNNER_TEMP/mcp-it/artifacts/result.json"
    printf 'Integration artifacts: %s/mcp-it/artifacts\n' "$RUNNER_TEMP"
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' HUP TERM
for step in packages tools start-db start-nextcloud install-app fixtures provision report test recovery; do
    status=0
    if [ "$step" = packages ]; then
        # npm ci and generated assets share this checkout; native cells serialize packaging only.
        flock "$ROOT/mcp/build/integration-packaging.lock" sh "$CI_DIR/harness.sh" "$step" > "$RUNNER_TEMP/$step.raw.log" 2>&1 || status=$?
    else
        sh "$CI_DIR/harness.sh" "$step" > "$RUNNER_TEMP/$step.raw.log" 2>&1 || status=$?
    fi
    php "$CI_DIR/redact.php" "$RUNNER_TEMP/mcp-it/credentials" "$RUNNER_TEMP/$step.raw.log" > "$RUNNER_TEMP/mcp-it/artifacts/$step.log"
    cat "$RUNNER_TEMP/mcp-it/artifacts/$step.log"
    [ "$status" -eq 0 ] || exit "$status"
done
