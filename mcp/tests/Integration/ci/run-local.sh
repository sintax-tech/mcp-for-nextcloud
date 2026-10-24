#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Runs ONE cell of the integration matrix on a Mac, in disposable containers on Colima, through the same harness.sh the
# GitHub workflow runs. The container engine is started only for the run and is always stopped again, on success, on
# error and on INT/TERM: the test containers, their network and volumes are removed first, then `colima stop` runs, but
# only when this script is the one that started it (an engine that was already running is left running).
#
# Several runs (other agents, other terminals) share the one engine without touching each other: every run names its
# containers, network and log directory with its own id and removes only those. Up to MCP_IT_SLOTS runs (default 2) use
# the engine at once, each in a slot (~/.colima/mcp-it.lock, mcp-it.lock.2, ...: a directory holding the owner's pid;
# a dead owner's slot is claimed over); the others wait in a queue of tickets (~/.colima/mcp-it.queue) and get in in
# the order they arrived (see engine-queue.sh). Colima is stopped by the last run to leave, when a run of this script
# started it (~/.colima/mcp-it.engine); otherwise the engine stays up for the runs still there.
#
# Usage: run-local.sh <nc 32|33|34|35> <db mariadb|pgsql|sqlite> <mode fresh|upgrade|baseline> [--prune-images]
#        run-local.sh --shutdown   (the only safe way to stop the engine by hand; see above)
#   baseline        diagnostics: the released baseline stays installed, never upgraded (use with MCP_IT_PHPUNIT_ARGS)
#   --prune-images  also removes the images this run pulled (default: kept, they are only files on disk)
#   MCP_IT_WITH_MAIL=1 (environment) also runs the Mail part of the cell (mail-cell.sh): a throwaway IMAP server and
#                   the Mail app; without it the IMAP image is never pulled
#
# Logs and artifacts (run.log, resources.log, mcp-it/artifacts/: junit.xml, nextcloud.log, observations.txt,
# provision.log) go to mcp/tests/Integration/.local-runs/<cell>-<timestamp>-<pid>/ (git-ignored). Image digests, the
# baseline tag and the cell come from .github/workflows/integration.yml, so there is one list of pins.
set -eu

# Runs take the engine's slots in the order they arrived (see engine-queue.sh).
. "$(cd "$(dirname "$0")" && pwd)/engine-queue.sh"

# run-local.sh --shutdown: the only safe way to stop the engine by hand. It takes its turn in the queue like a run (a
# slot), so it never cuts a run in progress, removes the containers and networks dead runs left behind, and stops Colima
# only when no other run uses or waits for it (otherwise the last of them stops it).
if [ "${1:-}" = "--shutdown" ]; then
    command -v colima >/dev/null 2>&1 || { echo "colima is not installed" >&2; exit 2; }
    trap release_lock EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    acquire_lock
    engine_lock || exit 3
    if colima status >/dev/null 2>&1; then
        # Other slots may be in use: only what runs that are gone left behind is removed.
        remove_leftovers
        # The engine is to be stopped: by this run if nobody else is there, otherwise by the last of them to leave.
        echo "$$" > "$ENGINE_FLAG"
        engine_release
    else
        log "Colima is not running"
        rm -f "$ENGINE_FLAG"
    fi
    exit 0
fi

usage() {
    echo "Usage: $0 <nc 32|33|34|35> <db mariadb|pgsql|sqlite> <mode fresh|upgrade|baseline> [--prune-images] | $0 --shutdown" >&2
    exit 2
}

[ $# -ge 3 ] || usage
CELL_NC=$1
CELL_DB=$2
CELL_MODE=$3
PRUNE_IMAGES=0
[ "${4:-}" = "--prune-images" ] && PRUNE_IMAGES=1
case "$CELL_MODE" in fresh|upgrade|baseline) ;; *) usage ;; esac

HERE=$(cd "$(dirname "$0")" && pwd)
REPO_ROOT=$(cd "$HERE/../../../.." && pwd)
WORKFLOW="$REPO_ROOT/.github/workflows/integration.yml"
STAMP=$(date +%Y%m%d-%H%M%S)
LOCAL_DIR="$REPO_ROOT/mcp/tests/Integration/.local-runs/nc$CELL_NC-$CELL_DB-$CELL_MODE-$STAMP-$$"
mkdir -p "$LOCAL_DIR"

# cell_field <field>: the value of the cell's field in the workflow matrix ('' for an empty value).
cell_field() {
    awk -v nc="$CELL_NC" -v db="$CELL_DB" -v field="$1" '
        $1 == "-" && $2 == "nc:" { cur = $3; gsub(/[^0-9]/, "", cur); curdb = "" }
        $1 == "db:" { curdb = $2 }
        cur == nc && curdb == db && $1 == field ":" { value = $2; gsub(/\047/, "", value); print value; exit }
    ' "$WORKFLOW"
}

NC_IMAGE=$(cell_field nc_image)
DB_IMAGE=$(cell_field db_image)
BASELINE=$(sed -n 's/^ *MCP_IT_BASELINE: *//p' "$WORKFLOW" | head -n 1)
if [ -z "$NC_IMAGE" ] || { [ "$CELL_DB" != sqlite ] && [ -z "$DB_IMAGE" ]; }; then
    echo "No cell NC $CELL_NC / $CELL_DB in $WORKFLOW" >&2
    exit 2
fi

# One name per run: two runs on the same engine (another agent, another terminal) never see each other's containers.
RUN_ID="$STAMP-$$"
NET_NAME="mcp-it-$RUN_ID"
NC_NAME="mcp-it-nextcloud-$RUN_ID"
DB_NAME="mcp-it-db-$RUN_ID"
export MCP_IT_NET="$NET_NAME" MCP_IT_NC_NAME="$NC_NAME" MCP_IT_DB_NAME="$DB_NAME"
# The IMAP server of a Mail cell (MCP_IT_WITH_MAIL=1, see mail-cell.sh); never started otherwise.
export MCP_IT_IMAP_NAME="mcp-it-imap-$RUN_ID"
export MCP_IT_LOCAL=1
export RUNNER_TEMP="$LOCAL_DIR"
export MCP_IT_NC="$CELL_NC" MCP_IT_NC_IMAGE="$NC_IMAGE" MCP_IT_DB="$CELL_DB" MCP_IT_DB_IMAGE="$DB_IMAGE"
export MCP_IT_MODE="$CELL_MODE" MCP_IT_BASELINE="$BASELINE"
HARNESS="$HERE/harness.sh"
WORK="$LOCAL_DIR/mcp-it"
SAMPLER_PID=""
START_TIME=$(date +%s)

# Samples the memory the engine and the containers use, every 10 s, for the summary at the end.
sample_resources() {
    while :; do
        {
            echo "t=$(date +%s)"
            ps -axo rss=,command= | awk 'tolower($0) ~ /limactl|colima|virtualization|qemu/ { sum += $1 } END { printf "vm_rss_kb=%d\n", sum }'
            docker stats --no-stream --format 'container={{.Name}} mem={{.MemUsage}} cpu={{.CPUPerc}}' 2>/dev/null || true
        } >> "$LOCAL_DIR/resources.log"
        sleep 10
    done
}

cleanup() {
    status=$?
    trap - EXIT INT TERM
    set +e
    [ -n "$SAMPLER_PID" ] && kill "$SAMPLER_PID" 2>/dev/null
    if docker info >/dev/null 2>&1; then
        log "removing the test containers, network and volumes"
        # The IMAP server goes first: it sits on the run's network, which cannot be removed while it is attached.
        if [ "${MCP_IT_WITH_MAIL:-0}" = 1 ] && ! PRUNE_IMAGES=$PRUNE_IMAGES sh "$HERE/mail-cell.sh" cleanup > "$LOCAL_DIR/cleanup-mail.txt"; then status=1; fi
        # Inspect only this run's anonymous volumes, before rm -v loses their names.
        volumes=$(docker inspect --format '{{range .Mounts}}{{if eq .Type "volume"}}{{.Name}}{{"\n"}}{{end}}{{end}}' \
            "$NC_NAME" "$DB_NAME" 2>/dev/null || true)
        docker rm -f -v "$NC_NAME" "$DB_NAME" >/dev/null 2>&1
        docker network rm "$NET_NAME" >/dev/null 2>&1
        # Prove removal while the daemon is still up; a stopped daemon cannot prove an empty inventory.
        remaining=$(docker ps -aq --filter "name=$NC_NAME" --filter "name=$DB_NAME") || remaining=unavailable
        networks=$(docker network ls --filter "name=^$NET_NAME$" --format '{{.Name}}') || networks=unavailable
        leftover_volumes=""
        for volume in $volumes; do
            if docker volume inspect "$volume" >/dev/null 2>&1; then leftover_volumes="$leftover_volumes $volume"; fi
        done
        printf 'containers=%s\nnetwork=%s\nvolumes=%s\n' "${remaining:-none}" "${networks:-none}" "${leftover_volumes:-none}" \
            > "$LOCAL_DIR/cleanup.txt"
        if [ -n "$remaining$networks$leftover_volumes" ]; then
            log "cleanup left resources or could not verify removal (see cleanup.txt)"
            status=1
        fi
        if [ "$PRUNE_IMAGES" = 1 ]; then
            for image in "$NC_IMAGE" "$DB_IMAGE"; do
                [ -n "$image" ] && docker rmi "$image" >/dev/null 2>&1
            done
        fi
    fi
    if [ -d "$WORK/baseline" ]; then
        git -C "$REPO_ROOT" worktree remove --force "$WORK/baseline" >/dev/null 2>&1
        git -C "$REPO_ROOT" worktree prune
    fi
    # The downloads and packages of this run are rebuilt every time; only the logs and artifacts stay.
    rm -rf "$WORK/tools" "$WORK/branch-pkg" "$WORK/baseline-pkg" "$WORK/package-src" "$WORK/pglog" "$WORK"/*.tar.gz
    [ "$HAVE_LOCK" = 1 ] && engine_release
    summary
    release_lock
    exit "$status"
}

summary() {
    elapsed=$(($(date +%s) - START_TIME))
    peak_vm=$(awk -F= '/^vm_rss_kb=/ { if ($2 > max) max = $2 } END { printf "%d", max / 1024 }' "$LOCAL_DIR/resources.log" 2>/dev/null)
    log "elapsed ${elapsed}s; peak resident memory of the engine processes on the host ${peak_vm:-0} MB; logs in $LOCAL_DIR"
}

trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for tool in colima docker git php composer curl; do
    command -v "$tool" >/dev/null 2>&1 || { echo "$tool is required (brew install colima docker)" >&2; exit 2; }
done

acquire_lock
engine_take
docker info >/dev/null

log "cell: NC $CELL_NC, $CELL_DB, $CELL_MODE (baseline $BASELINE)"

# The pins are digests of the image index when they are multi-architecture; record what this machine actually got.
for image in "$NC_IMAGE" "$DB_IMAGE"; do
    [ -n "$image" ] || continue
    docker pull --quiet "$image" >/dev/null
    log "image $image -> $(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$image")"
done

sample_resources &
SAMPLER_PID=$!

failed=0
for step in packages tools start-db start-nextcloud install-app fixtures provision report; do
    # The provisioning of groupfolders, external storage, Talk, Notes and Calendar exists only on the lines that prove
    # the exclusion (issue #4); the harness of this line has no such step.
    if [ "$step" = provision ] && ! grep -q '^    provision)' "$HARNESS"; then
        continue
    fi
    log "step: $step"
    if ! sh "$HARNESS" "$step"; then
        log "step $step FAILED"
        failed=1
        break
    fi
done

test_status=0
if [ "$failed" = 0 ]; then
    log "step: test"
    sh "$HARNESS" test || test_status=$?
fi
log "step: collect"
sh "$HARNESS" collect || true

if [ "$failed" != 0 ]; then
    exit 1
fi
exit "$test_status"
