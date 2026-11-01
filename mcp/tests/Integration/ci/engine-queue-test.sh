#!/bin/sh
# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Proves the queue in front of the engine (engine-queue.sh) without Colima: the runs are small sh processes driving the
# same functions as run-local.sh, `run-local.sh --shutdown` runs for real, and colima/docker are fakes on a PATH that
# does not reach the real ones. The lock, the queue and the engine flag live in a temporary directory
# (MCP_IT_LOCK_DIR); the real ~/.colima/mcp-it.* are never touched.
#
# Usage: engine-queue-test.sh [repetitions]   (default 1; repeat to catch races)
set -eu
# Background runs keep SIGINT only with job control: without it they start with SIGINT ignored, which no trap can undo
# (a shell without a terminal, such as dash here, may refuse it). The INT case is then skipped; Ctrl+C on a real run
# reaches it in the foreground, where SIGINT is not ignored.
set -m 2>/dev/null || true
SIGNALS="TERM:C:143"
case $- in *m*) SIGNALS="INT:B:130 $SIGNALS" ;; *) echo "no job control in this shell: the SIGINT case is skipped" >&2 ;; esac

HERE=$(cd "$(dirname "$0")" && pwd)
REPEAT=${1:-1}
ROOT=$(mktemp -d "${TMPDIR:-/tmp}/mcp-it-queue-test.XXXXXX")
FAKE="$ROOT/bin"
EVENTS="$ROOT/events.log"
STATE="$ROOT/state"
export MCP_IT_LOCK_DIR="$STATE/mcp-it.lock" MCP_IT_LOCK_POLL=0.2 MCP_IT_LOCK_TIMEOUT=60
# One slot unless a scenario of slots sets more: the scenarios of one slot prove the behavior of the queue before slots.
export MCP_IT_SLOTS=1
export EVENTS ROOT FAKE_UP="$ROOT/colima-up" LIB="$HERE/engine-queue.sh"
QUEUE="$STATE/mcp-it.queue"
FLAG="$STATE/mcp-it.engine"
PIDS=""

trap 'for p in $PIDS; do kill -9 "$p" 2>/dev/null || true; done; rm -rf "$ROOT"' EXIT

mkdir -p "$FAKE" "$STATE"
cat > "$FAKE/colima" <<'EOF'
#!/bin/sh
case "$1" in
    status) echo "colima status $PPID" >> "$EVENTS"; [ -f "$FAKE_UP" ] ;;
    # Slow enough for a second run to reach the engine while the first starts it.
    start) sleep 0.5; touch "$FAKE_UP"; echo "colima start $PPID" >> "$EVENTS"; [ ! -f "$ROOT/start-fails" ] ;;
    stop) rm -f "$FAKE_UP"; echo "colima stop $PPID" >> "$EVENTS" ;;
esac
EOF
# The containers and networks the fake engine holds are the lines of $ROOT/containers and $ROOT/networks.
cat > "$FAKE/docker" <<'EOF'
#!/bin/sh
case "$1 $2" in
    "ps -a") cat "$ROOT/containers" 2>/dev/null ;;
    "network ls") cat "$ROOT/networks" 2>/dev/null ;;
    "rm -f") grep -vx "$4" "$ROOT/containers" > "$ROOT/containers.next"; mv "$ROOT/containers.next" "$ROOT/containers"
        echo "docker rm $4" >> "$EVENTS" ;;
    "network rm") grep -vx "$3" "$ROOT/networks" > "$ROOT/networks.next"; mv "$ROOT/networks.next" "$ROOT/networks"
        echo "docker network rm $3" >> "$EVENTS" ;;
esac
exit 0
EOF
# A run as run-local.sh drives the queue: trap, lock, engine, work, engine release, unlock. go.<name> ends the work of
# a run started with "wait"; "fail" makes it fail while it holds the engine. RUN_MODE=direct takes a ticket but skips
# the queue order and the engine, and only takes slot 1 (lock_take), to race two runs on a dead owner's lock. The runs
# inside the engine are the files of $ROOT/in; one more than MCP_IT_SLOTS is an OVERLAP.
#
# Test-only pauses, defined here and not in engine-queue.sh: PAUSE lists points (before-ticket, after-ticket,
# before-claim, after-claim: around the ln that publishes a ticket or a claim) where the run announces
# at.<name>.<point> and stops until resume.<name>.<point> exists.
cat > "$ROOT/runner.sh" <<'EOF'
#!/bin/sh
set -eu
NAME=$1
. "$LIB"
pause_at() {
    case " ${PAUSE:-} " in
        *" $1 "*) ;;
        *) [ -f "$ROOT/arm.$NAME.$1" ] || return 0 ;;
    esac
    touch "$ROOT/at.$NAME.$1"
    until [ -f "$ROOT/resume.$NAME.$1" ]; do sleep 0.02; done
    return 0
}
# after-switch (armed by the test with arm.<name>.after-switch): right after the run lets the engine switch go.
rm() {
    command rm "$@" || return $?
    for target; do :; done
    case "$target" in *.engine.lock) pause_at after-switch ;; esac
    return 0
}
ln() {
    for target; do :; done
    case "$target" in */t.*) point=ticket ;; */claim.*) point=claim ;; *) point="" ;; esac
    [ -z "$point" ] || pause_at "before-$point"
    command ln "$@" || return $?
    [ -z "$point" ] || pause_at "after-$point"
    return 0
}
finish() {
    status=$?
    trap - EXIT INT TERM
    set +e
    [ "$HAVE_LOCK" = 1 ] && engine_release
    release_lock
    exit "$status"
}
trap finish EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
if [ "${RUN_MODE:-}" = direct ]; then
    take_ticket
    until lock_take "$LOCK_DIR" "$TICKET" slot; do sleep 0.05; done
    drop_ticket
else
    acquire_lock
fi
touch "$ROOT/in/$NAME"
inside=$(ls "$ROOT/in" | wc -l | tr -d ' ')
[ "$inside" -le "${MCP_IT_SLOTS:-1}" ] || echo "OVERLAP $NAME ($inside inside)" >> "$EVENTS"
echo "inside $inside" >> "$EVENTS"
echo "got $NAME $$" >> "$EVENTS"
[ "${RUN_MODE:-}" = direct ] || engine_take
echo "ready $NAME" >> "$EVENTS"
if [ "${2:-}" = wait ]; then
    until [ -f "$ROOT/go.$NAME" ]; do sleep 0.05; done
else
    sleep 0.1
fi
echo "done $NAME" >> "$EVENTS"
rm "$ROOT/in/$NAME"
[ "${2:-}" = fail ] && exit 1
exit 0
EOF
# A run of the copy of run-local.sh before the ticket queue (other worktrees may still run it): its lock, queue and
# engine code verbatim, only polling faster.
cat > "$ROOT/legacy.sh" <<'LEGACY'
#!/bin/sh
set -eu
NAME=$1
LOCK_DIR="${MCP_IT_LOCK_DIR:-$HOME/.colima/mcp-it.lock}"
QUEUE_DIR="${LOCK_DIR%.lock}.queue"
ENGINE_FLAG="${LOCK_DIR%.lock}.engine"
HAVE_LOCK=0
STARTED_COLIMA=0
log() {
    echo "[run-local $(date +%H:%M:%S)] $*"
}
acquire_lock() {
    mkdir -p "$(dirname "$LOCK_DIR")" "$QUEUE_DIR"
    echo "$$" > "$QUEUE_DIR/$$"
    waited=0
    until mkdir "$LOCK_DIR" 2>/dev/null; do
        owner=$(cat "$LOCK_DIR/pid" 2>/dev/null || true)
        if [ -n "$owner" ] && ! kill -0 "$owner" 2>/dev/null; then
            log "the run $owner that held the engine is gone; taking its lock"
            rm -rf "$LOCK_DIR"
            continue
        fi
        [ "$waited" -ge 3600 ] && { rm -f "$QUEUE_DIR/$$"; echo "Timed out waiting for the engine lock held by run ${owner:-?}" >&2; exit 3; }
        [ $((waited % 60)) -eq 0 ] && log "waiting for the run ${owner:-?} to finish with the engine"
        sleep 0.2
        waited=$((waited + 5))
    done
    echo "$$" > "$LOCK_DIR/pid"
    rm -f "$QUEUE_DIR/$$"
    HAVE_LOCK=1
}
others_waiting() {
    found=1
    for entry in "$QUEUE_DIR"/*; do
        [ -e "$entry" ] || continue
        pid=$(cat "$entry" 2>/dev/null || true)
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            found=0
        else
            rm -f "$entry"
        fi
    done
    return $found
}
cleanup() {
    status=$?
    trap - EXIT INT TERM
    set +e
    if [ "$STARTED_COLIMA" = 1 ]; then
        if others_waiting; then
            log "another run is waiting for the engine: Colima stays up for it, and it takes over stopping it"
        else
            log "stopping Colima (a run of this script started it and nobody is waiting)"
            colima stop
            rm -f "$ENGINE_FLAG"
        fi
    fi
    [ "$HAVE_LOCK" = 1 ] && rm -rf "$LOCK_DIR"
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
acquire_lock
touch "$ROOT/in/$NAME"
inside=$(ls "$ROOT/in" | wc -l | tr -d ' ')
[ "$inside" -le "${MCP_IT_SLOTS:-1}" ] || echo "OVERLAP $NAME ($inside inside)" >> "$EVENTS"
echo "inside $inside" >> "$EVENTS"
echo "got $NAME $$" >> "$EVENTS"
if colima status >/dev/null 2>&1; then
    [ -f "$ENGINE_FLAG" ] && STARTED_COLIMA=1
else
    STARTED_COLIMA=1
    echo "$$" > "$ENGINE_FLAG"
    colima start
fi
if [ "${2:-}" = wait ]; then
    until [ -f "$ROOT/go.$NAME" ]; do sleep 0.05; done
else
    sleep 0.1
fi
echo "done $NAME" >> "$EVENTS"
rm "$ROOT/in/$NAME"
LEGACY
chmod +x "$FAKE/colima" "$FAKE/docker" "$ROOT/runner.sh" "$ROOT/legacy.sh"
PATH="$FAKE:/usr/bin:/bin:/usr/sbin:/sbin"
export PATH
[ "$(command -v colima)" = "$FAKE/colima" ] && [ "$(command -v docker)" = "$FAKE/docker" ] \
    || { echo "the fake colima/docker are not first on PATH; refusing to run" >&2; exit 2; }

fail() {
    echo "FAIL ($SCENARIO): $*" >&2
    echo "--- events" >&2
    cat "$EVENTS" >&2 || true
    exit 1
}

# wait_for <description> <shell condition>: up to 30 s.
wait_for() {
    tries=0
    until eval "$2"; do
        tries=$((tries + 1))
        [ "$tries" -ge 600 ] && fail "timed out waiting for $1"
        sleep 0.05
    done
}

tickets() {
    n=0
    for t in "$QUEUE"/t.*; do [ -f "$t" ] && n=$((n + 1)); done
    echo "$n"
}

# start <name> [wait|fail]: launches a run in the background; RUN_<name> holds its pid. RUN_ENV (assignments such as
# PAUSE=before-ticket, RUN_MODE=direct) goes to that run only and is cleared.
start() {
    env ${RUN_ENV:-} "$ROOT/runner.sh" "$@" > "$ROOT/out.$1" 2>&1 &
    RUN_ENV=""
    eval "RUN_$1=$!"
    PIDS="$PIDS $!"
}

# queue_up <name> [wait]: launches a run and waits until its ticket is in the queue (a known arrival order).
queue_up() {
    before=$(tickets)
    start "$@"
    wait_for "the ticket of $1" "[ \$(tickets) -gt $before ]"
}

hold_first() {
    start "$1" wait
    wait_for "$1 to hold the lock" "grep -q '^got $1 ' '$EVENTS' 2>/dev/null"
}

wait_all() {
    for p in $PIDS; do wait "$p" 2>/dev/null || true; done
    PIDS=""
}

dead_pid() {
    sh -c 'exit 0' &
    p=$!
    wait "$p" || true
    echo "$p"
}

served() {
    sed -n 's/^got \([^ ]*\) .*/\1/p' "$EVENTS" | tr '\n' ' ' | sed 's/ $//'
}

expect_served() {
    [ "$(served)" = "$1" ] || fail "served '$(served)', expected '$1'"
}

expect_no_residue() {
    grep -q OVERLAP "$EVENTS" && fail "two runs held the engine at once"
    [ -e "$MCP_IT_LOCK_DIR" ] && fail "lock left behind"
    [ -e "$FLAG" ] && fail "engine flag left behind"
    [ -n "$(ls -A "$QUEUE" 2>/dev/null)" ] && fail "queue entries left behind: $(ls -A "$QUEUE")"
    [ -n "$(ls -A "$ROOT/in")" ] && fail "a run did not finish its work: $(ls "$ROOT/in")"
    [ -n "$(ls -d "$MCP_IT_LOCK_DIR".* "$STATE/mcp-it.engine.lock" 2>/dev/null)" ] && fail "slot or engine switch left behind"
    [ -e "$FAKE_UP" ] && fail "the engine was left running"
    return 0
}

# expect_engine_once: started once, stopped once, by the last run served, after all work.
expect_engine_once() {
    [ "$(grep -c '^colima start' "$EVENTS")" = 1 ] || fail "colima started $(grep -c '^colima start' "$EVENTS") times"
    [ "$(grep -c '^colima stop' "$EVENTS")" = 1 ] || fail "colima stopped $(grep -c '^colima stop' "$EVENTS") times"
    [ "$(tail -n 1 "$EVENTS" | cut -d' ' -f1-2)" = "colima stop" ] || fail "colima stopped before the last run finished"
    stopper=$(tail -n 1 "$EVENTS" | cut -d' ' -f3)
    if [ "$MCP_IT_SLOTS" = 1 ]; then
        name=$(sed -n 's/^done //p' "$EVENTS" | tail -n 1)
        [ "$stopper" = "$(sed -n "s/^got $name //p" "$EVENTS")" ] || fail "colima was not stopped by the last run"
    else
        # With slots the last to leave is the last through the engine switch, not the last to write "done".
        grep -q "^got [^ ]* $stopper\$" "$EVENTS" || fail "colima was stopped by $stopper, which is not a run"
    fi
}

reset() {
    SCENARIO=$1
    rm -rf "$STATE" "$ROOT"/go.* "$ROOT"/out.* "$ROOT"/at.* "$ROOT"/resume.* "$ROOT"/arm.* "$ROOT/start-fails" "$ROOT/in" \
        "$FAKE_UP" "$ROOT/containers" \
        "$ROOT/networks"
    mkdir -p "$ROOT/in"
    export MCP_IT_SLOTS=1
    mkdir -p "$STATE"
    : > "$EVENTS"
}

# (a) Runs are served in the order they arrived; the engine started by the first is handed down and stopped by the last.
scenario_fifo() {
    reset fifo
    hold_first A
    for name in B C D E F; do queue_up "$name"; done
    touch "$ROOT/go.A"
    wait_all
    expect_served "A B C D E F"
    expect_engine_once
    grep -q "queue position 5: the engine's 1 slot(s) are held by run(s) [0-9]*; waiting before this run: [0-9]* [0-9]* [0-9]* [0-9]*$" \
        "$ROOT/out.F" || fail "F did not log its position and the runs ahead of it"
    expect_no_residue
}

# (a) Eight runs arriving at once are served once each, in ticket order, never two at a time.
scenario_burst() {
    reset burst
    hold_first H
    for name in 1 2 3 4 5 6 7 8; do start "R$name"; done
    wait_for "eight tickets" '[ $(tickets) -eq 8 ]'
    order=""
    for t in "$QUEUE"/t.*; do
        pid=$(cat "$t")
        for name in 1 2 3 4 5 6 7 8; do
            eval "[ \"\$RUN_R$name\" = $pid ]" && order="$order R$name"
        done
    done
    touch "$ROOT/go.H"
    wait_all
    expect_served "H$order"
    expect_engine_once
    expect_no_residue
}

# (b) A dead owner's lock, dead tickets (one at the head, one killed while waiting) and a dead entry of an older copy of
# the script never hold the queue.
scenario_dead() {
    reset dead
    mkdir -p "$MCP_IT_LOCK_DIR" "$QUEUE"
    dead_pid > "$MCP_IT_LOCK_DIR/pid"
    dead=$(dead_pid)
    echo "$dead" > "$QUEUE/t.0000000000000001.$(printf '%010d' "$dead")"
    dead=$(dead_pid)
    echo "$dead" > "$QUEUE/$dead"
    start A wait
    wait_for "A to take over the dead lock" "grep -q '^got A ' '$EVENTS' 2>/dev/null"
    queue_up B
    queue_up C
    queue_up D
    kill -9 "$RUN_C"
    wait "$RUN_C" 2>/dev/null || true
    touch "$ROOT/go.A"
    wait_all
    expect_served "A B D"
    grep -q 'that held the engine is gone; this run took its lock over' "$ROOT/out.A" || fail "A did not report the dead owner"
    grep -q 'dropping the ticket of the run' "$ROOT/out.A" || fail "A did not drop the dead ticket"
    expect_engine_once
    expect_no_residue
}

# Traps: INT and TERM while waiting remove the ticket; an error while holding the engine releases it to the next run.
scenario_traps() {
    reset traps
    start A wait
    wait_for "A to hold the lock" "grep -q '^got A ' '$EVENTS' 2>/dev/null"
    # Each signalled run waits alone, so no other waiter can drop its ticket for it: only its own trap can.
    for signal in $SIGNALS; do
        name=$(echo "$signal" | cut -d: -f2)
        queue_up "$name"
        eval "pid=\$RUN_$name"
        kill -"${signal%%:*}" "$pid"
        status=0
        wait "$pid" || status=$?
        [ "$status" = "${signal##*:}" ] || fail "$name exited $status after SIG${signal%%:*}"
        [ "$(tickets)" = 0 ] || fail "$name left its ticket after SIG${signal%%:*}"
    done
    queue_up D fail
    queue_up E
    touch "$ROOT/go.A"
    status=0
    wait "$RUN_D" || status=$?
    [ "$status" = 1 ] || fail "D exited $status, expected 1"
    wait_all
    expect_served "A D E"
    expect_engine_once
    expect_no_residue
}

# Legacy queue users retain their original lifecycle; the current local runner's shared-engine and
# ownership-only shutdown contract is covered separately by shared-engine-test.sh.
scenario_legacy() {
    reset legacy
    "$ROOT/legacy.sh" L1 wait > "$ROOT/out.L1" 2>&1 &
    PIDS="$PIDS $!"
    wait_for "L1 to hold the lock" "grep -q '^got L1 ' '$EVENTS' 2>/dev/null"
    queue_up N1
    "$ROOT/legacy.sh" L2 > "$ROOT/out.L2" 2>&1 &
    PIDS="$PIDS $!"
    wait_for "L2 to queue" "[ \$(ls '$QUEUE' | grep -vc '^t\.') -ge 1 ]"
    queue_up N2
    queue_up N3
    touch "$ROOT/go.L1"
    wait_all
    [ "$(served | tr ' ' '\n' | grep '^N' | tr '\n' ' ')" = "N1 N2 N3 " ] || fail "new runs served out of order: $(served)"
    [ "$(served | wc -w | tr -d ' ')" = 5 ] || fail "served '$(served)'"
    grep -q 'another run is waiting for the engine' "$ROOT/out.L1" || fail "the legacy run did not see the tickets"
    expect_engine_once
    expect_no_residue
}

scenario_shutdown() {
    sh "$HERE/shared-engine-test.sh"
}

# stays_false <description> <shell condition>: the condition stays false for 1 s (five polls of the runs).
stays_false() {
    tries=0
    while [ "$tries" -lt 20 ]; do
        eval "$2" && fail "$1"
        tries=$((tries + 1))
        sleep 0.05
    done
    return 0
}

dead_lock() {
    mkdir -p "$MCP_IT_LOCK_DIR"
    DEAD=$(dead_pid)
    echo "$DEAD" > "$MCP_IT_LOCK_DIR/pid"
}

# Review finding A, the queue part: A has read the queue and is slow to publish its ticket; C and D publish after it
# (C then dies, so D's number is above A's) while the lock's owner is dead. Nobody acts while A is in the doorway; A's
# ticket, published last and below D's, is first; the dead lock is taken over once.
scenario_doorway() {
    reset doorway
    dead_lock
    RUN_ENV="PAUSE=before-ticket"
    start A
    wait_for "A in the doorway" "[ -f '$ROOT/at.A.before-ticket' ]"
    # Started one after the other; each either queues (correct) or acts at once (the defect the review found).
    start C
    wait_for "C to queue" "[ \$(tickets) -ge 1 ] || grep -q '^got C ' '$EVENTS'"
    start D
    wait_for "D to queue" "[ \$(tickets) -ge 2 ] || grep -q '^got ' '$EVENTS'"
    kill -9 "$RUN_C"
    wait "$RUN_C" 2>/dev/null || true
    stays_false "a run acted while A was choosing its place" \
        "grep -q '^got' '$EVENTS' || ls '$MCP_IT_LOCK_DIR' | grep -q claim || [ \"\$(cat '$MCP_IT_LOCK_DIR/pid')\" != $DEAD ]"
    touch "$ROOT/resume.A.before-ticket"
    wait_all
    expect_served "A D"
    grep -q 'this run took its lock over' "$ROOT/out.A" || fail "A did not take the dead lock over"
    expect_engine_once
    expect_no_residue
}

# Review finding A, the lock part (no queue, two runs racing on a dead owner's lock). B has seen the owner dead and
# stops before claiming; A claims and works; B's claim fails and B waits for A. Then B stops before claiming while A
# releases and T takes the lock afresh: B's claim lands in T's lock, does not lead from T and is withdrawn.
scenario_claim() {
    reset claim
    dead_lock
    RUN_ENV="RUN_MODE=direct PAUSE=before-claim"
    start B
    wait_for "B before its claim" "[ -f '$ROOT/at.B.before-claim' ]"
    RUN_ENV="RUN_MODE=direct"
    start A wait
    wait_for "A to take the lock over" "grep -q '^got A ' '$EVENTS' 2>/dev/null"
    touch "$ROOT/resume.B.before-claim"
    stays_false "B entered while A held the lock" "grep -q '^got B ' '$EVENTS'"
    touch "$ROOT/go.A"
    wait_all
    expect_served "A B"
    expect_no_residue

    reset claim-late
    dead_lock
    RUN_ENV="RUN_MODE=direct PAUSE=before-claim"
    start B
    wait_for "B before its claim" "[ -f '$ROOT/at.B.before-claim' ]"
    RUN_ENV="RUN_MODE=direct"
    start A
    status=0
    wait "$RUN_A" || status=$?
    [ "$status" = 0 ] || fail "A exited $status"
    RUN_ENV="RUN_MODE=direct"
    start T wait
    wait_for "T to take the free lock" "grep -q '^got T ' '$EVENTS' 2>/dev/null"
    touch "$ROOT/resume.B.before-claim"
    stays_false "B entered T's lock" "grep -q '^got B ' '$EVENTS'"
    [ ! -e "$MCP_IT_LOCK_DIR/claim.$DEAD" ] || fail "B's claim in T's lock was not withdrawn"
    touch "$ROOT/go.T"
    wait_all
    expect_served "A T B"
    expect_no_residue
}

# Review finding B: TERM in the doorway, before and after the ln of the ticket, leaves nothing; a run killed (-9) in
# the doorway leaves a mark the next run sweeps; TERM right after claiming a dead lock leaves a claim the next run
# claims over in turn.
scenario_term() {
    for point in before-ticket after-ticket; do
        reset "term-$point"
        RUN_ENV="PAUSE=$point"
        start A
        wait_for "A at $point" "[ -f '$ROOT/at.A.$point' ]"
        kill -TERM "$RUN_A"
        status=0
        wait "$RUN_A" || status=$?
        [ "$status" = 143 ] || fail "A exited $status after SIGTERM at $point"
        [ -z "$(ls -A "$QUEUE")" ] || fail "SIGTERM at $point left $(ls -A "$QUEUE")"
        wait_all
        expect_no_residue
    done

    reset term-kill
    RUN_ENV="PAUSE=before-ticket"
    start A
    wait_for "A in the doorway" "[ -f '$ROOT/at.A.before-ticket' ]"
    kill -9 "$RUN_A"
    wait "$RUN_A" 2>/dev/null || true
    [ -n "$(ls -A "$QUEUE")" ] || fail "the doorway mark of A is missing"
    start B
    wait_all
    expect_served "B"
    expect_engine_once
    expect_no_residue

    reset term-claim
    dead_lock
    RUN_ENV="PAUSE=after-claim"
    start A
    wait_for "A after its claim" "[ -f '$ROOT/at.A.after-claim' ]"
    kill -TERM "$RUN_A"
    status=0
    wait "$RUN_A" || status=$?
    [ "$status" = 143 ] || fail "A exited $status after SIGTERM after its claim"
    [ "$(cat "$MCP_IT_LOCK_DIR/claim.$DEAD")" = "$RUN_A" ] || fail "the claim of A is not in the lock"
    start B
    wait_all
    expect_served "B"
    grep -q "the run $RUN_A that held the engine is gone; this run took its lock over" "$ROOT/out.B" \
        || fail "B did not claim over the dead claimer A"
    expect_engine_once
    expect_no_residue
}

max_inside() {
    sed -n 's/^inside //p' "$EVENTS" | sort -n | tail -n 1
}

# hold_slots <names...>: starts runs that hold their slot until go.<name>, each in after the other.
hold_slots() {
    for name in "$@"; do
        start "$name" wait
        wait_for "$name to hold a slot" "grep -q '^got $name ' '$EVENTS' 2>/dev/null"
    done
}

# Two slots: two runs use the engine at once, the four that arrive next wait in order and get the slots as they free
# up; the engine starts once and the last run to leave stops it.
scenario_slots2() {
    reset slots2
    export MCP_IT_SLOTS=2
    hold_slots H1 H2
    for name in A B C D; do queue_up "$name" wait; done
    stays_false "a third run entered two slots" "grep -q '^got [ABCD] ' '$EVENTS'"
    touch "$ROOT/go.H1"
    wait_for "A to take the freed slot" "grep -q '^got A ' '$EVENTS' 2>/dev/null"
    stays_false "B entered while only one slot was free" "grep -q '^got [BCD] ' '$EVENTS'"
    touch "$ROOT/go.H2"
    wait_for "B to take the freed slot" "grep -q '^got B ' '$EVENTS' 2>/dev/null"
    stays_false "C entered while A and B held the slots" "grep -q '^got [CD] ' '$EVENTS'"
    touch "$ROOT/go.A"
    wait_for "C to take the freed slot" "grep -q '^got C ' '$EVENTS' 2>/dev/null"
    stays_false "D entered while B and C held the slots" "grep -q '^got D ' '$EVENTS'"
    touch "$ROOT/go.B" "$ROOT/go.C" "$ROOT/go.D"
    wait_all
    expect_served "H1 H2 A B C D"
    [ "$(max_inside)" = 2 ] || fail "the engine held at most $(max_inside) runs, expected 2"
    grep -q 'using engine slot 2 of 2' "$ROOT/out.H2" || fail "H2 did not report slot 2"
    expect_engine_once
    expect_no_residue
}

# Three slots full, eight runs arriving at once: never more than three inside, all served, and a run is overtaken only
# by runs that could take a slot together with it (fewer than three places behind it).
scenario_slots3() {
    reset slots3
    export MCP_IT_SLOTS=3
    hold_slots H1 H2 H3
    for name in 1 2 3 4 5 6 7 8; do start "R$name"; done
    wait_for "eight tickets" '[ $(tickets) -eq 8 ]'
    order=""
    for t in "$QUEUE"/t.*; do
        pid=$(cat "$t")
        for name in 1 2 3 4 5 6 7 8; do
            eval "[ \"\$RUN_R$name\" = $pid ]" && order="$order R$name"
        done
    done
    touch "$ROOT/go.H1" "$ROOT/go.H2" "$ROOT/go.H3"
    wait_all
    [ "$(served | wc -w | tr -d ' ')" = 11 ] || fail "served '$(served)'"
    [ "$(max_inside)" = 3 ] || fail "the engine held at most $(max_inside) runs, expected 3"
    got=$(served | sed 's/^H1 H2 H3 //')
    i=0
    for early in $order; do
        i=$((i + 1))
        j=0
        for late in $order; do
            j=$((j + 1))
            [ "$j" -ge $((i + 3)) ] || continue
            before=$(echo " $got " | sed "s/ $late .*//")
            case " $before " in *" $early "*) ;; *) fail "$late (place $j) got in before $early (place $i): $got" ;; esac
        done
    done
    expect_engine_once
    expect_no_residue
}

# A dead owner in slot 2 while slot 1 is in use: the next run claims slot 2 over and works next to H.
scenario_slots_dead() {
    reset slots-dead
    export MCP_IT_SLOTS=2
    hold_slots H
    mkdir -p "$MCP_IT_LOCK_DIR.2"
    dead_pid > "$MCP_IT_LOCK_DIR.2/pid"
    start A wait
    wait_for "A to claim slot 2" "grep -q '^got A ' '$EVENTS' 2>/dev/null"
    grep -q 'this run took its lock over' "$ROOT/out.A" || fail "A did not claim the dead slot over"
    grep -q 'using engine slot 2 of 2' "$ROOT/out.A" || fail "A is not in slot 2"
    touch "$ROOT/go.H" "$ROOT/go.A"
    wait_all
    [ "$(max_inside)" = 2 ] || fail "A did not work next to H"
    expect_engine_once
    expect_no_residue
}

# The current runner cleans only recorded resources and skips live owners regardless of occupied slots.
scenario_slots_shutdown() {
    sh "$HERE/shared-engine-test.sh"
}

# Review round 2, B1: a slot left without a pid (its creator died between mkdir and writing it) is claimed over once it
# is older than a minute, and not before.
scenario_pidless() {
    reset pidless
    mkdir -p "$MCP_IT_LOCK_DIR"
    start A
    stays_false "A took a lock that may still be getting its pid" "grep -q '^got A ' '$EVENTS'"
    touch -t 202001010000 "$MCP_IT_LOCK_DIR"
    wait_all
    expect_served "A"
    grep -q 'a lock of the engine was left without a pid; this run took it over' "$ROOT/out.A" \
        || fail "A did not claim the slot without a pid"
    expect_engine_once
    expect_no_residue
}

# Review round 2, B2: forty claimers that died one after the other leave a chain of forty claims; the next run follows
# it to the end and takes the slot over.
scenario_chain() {
    reset chain
    mkdir -p "$MCP_IT_LOCK_DIR"
    owner=$(dead_pid)
    echo "$owner" > "$MCP_IT_LOCK_DIR/pid"
    n=0
    while [ "$n" -lt 40 ]; do
        next=$(dead_pid)
        echo "$next" > "$MCP_IT_LOCK_DIR/claim.$owner"
        owner=$next
        n=$((n + 1))
    done
    start A
    wait_all
    expect_served "A"
    grep -q "the run $owner that held the engine is gone; this run took its lock over" "$ROOT/out.A" \
        || fail "A did not claim over the last of the forty dead claimers"
    expect_engine_once
    expect_no_residue
}

# Two slots next to a copy of the script from before the slots: the old run L holds slot 1 (its lock) and starts the
# engine, the new run A works in slot 2; L leaves first and, seeing A's h.<pid>, leaves the engine up; A stops it.
scenario_slots_legacy() {
    reset slots-legacy
    export MCP_IT_SLOTS=2
    "$ROOT/legacy.sh" L wait > "$ROOT/out.L" 2>&1 &
    PIDS="$PIDS $!"
    wait_for "L to hold slot 1" "grep -q '^got L ' '$EVENTS' 2>/dev/null && grep -q '^colima start' '$EVENTS'"
    start A wait
    wait_for "A to hold slot 2" "grep -q '^got A ' '$EVENTS' 2>/dev/null"
    grep -q 'using engine slot 2 of 2' "$ROOT/out.A" || fail "A is not in slot 2"
    touch "$ROOT/go.L"
    wait_for "L to leave" "grep -q '^done L' '$EVENTS'"
    stays_false "the old run stopped the engine under A" "grep -q '^colima stop' '$EVENTS'"
    grep -q 'another run is waiting for the engine' "$ROOT/out.L" || fail "the old run did not see A"
    touch "$ROOT/go.A"
    wait_all
    [ "$(max_inside)" = 2 ] || fail "A did not work next to L"
    expect_engine_once
    expect_no_residue

    # The other way round: A leaves first while L, which has no ticket or h.<pid>, still holds slot 1.
    reset slots-legacy-first
    export MCP_IT_SLOTS=2
    "$ROOT/legacy.sh" L wait > "$ROOT/out.L" 2>&1 &
    PIDS="$PIDS $!"
    wait_for "L to hold slot 1" "grep -q '^got L ' '$EVENTS' 2>/dev/null && grep -q '^colima start' '$EVENTS'"
    start A
    wait_for "A to leave" "grep -q '^done A' '$EVENTS'"
    wait "$RUN_A" || fail "A failed"
    stays_false "A stopped the engine under the old run" "grep -q '^colima stop' '$EVENTS'"
    touch "$ROOT/go.L"
    wait_all
    expect_engine_once
    expect_no_residue
}

# Review round 3, M1: A and B leave together, each stopping right after it lets the engine switch go. The first through
# the switch has already left its slot, so the second sees nobody and stops the engine: never left up with nobody.
scenario_finish_race() {
    reset finish-race
    export MCP_IT_SLOTS=2
    hold_slots A B
    # Armed once both are past engine_take, so the pause is the one after leaving, not after starting.
    wait_for "A and B ready" "grep -q '^ready A' '$EVENTS' && grep -q '^ready B' '$EVENTS'"
    touch "$ROOT/arm.A.after-switch" "$ROOT/arm.B.after-switch"
    touch "$ROOT/go.A"
    wait_for "A past the switch" "[ -f '$ROOT/at.A.after-switch' ]"
    touch "$ROOT/go.B"
    wait_for "B past the switch" "[ -f '$ROOT/at.B.after-switch' ]"
    touch "$ROOT/resume.A.after-switch" "$ROOT/resume.B.after-switch"
    wait_all
    expect_engine_once
    expect_no_residue
}

# Review round 3, M2: colima start fails. The cleanup keeps the switch it already holds (no wait for itself), stops
# what the failed start left and removes the flag; the run fails at once, nothing is left.
scenario_start_error() {
    reset start-error
    export MCP_IT_SLOTS=2
    touch "$ROOT/start-fails"
    started=$(date +%s)
    start A
    status=0
    wait "$RUN_A" || status=$?
    PIDS=""
    [ "$status" != 0 ] || fail "A did not fail when colima start failed"
    [ $(($(date +%s) - started)) -lt 10 ] || fail "A waited for its own engine switch"
    grep -q 'Timed out' "$ROOT/out.A" && fail "A timed out on its own engine switch"
    [ "$(grep -c '^colima stop' "$EVENTS")" = 1 ] || fail "the failed start was not stopped"
    # The runner marks itself inside before engine_take; it fails there on purpose and never leaves the mark.
    rm -f "$ROOT/in/A"
    expect_no_residue

    # TERM while colima start runs: the trap runs once start returns, under the switch this run holds.
    reset start-term
    export MCP_IT_SLOTS=2
    start A
    wait_for "A starting Colima" "grep -q 'starting Colima' '$ROOT/out.A' 2>/dev/null"
    kill -TERM "$RUN_A"
    status=0
    wait "$RUN_A" || status=$?
    PIDS=""
    [ "$status" = 143 ] || fail "A exited $status after SIGTERM during colima start"
    grep -q 'Timed out' "$ROOT/out.A" && fail "A timed out on its own engine switch"
    [ "$(grep -c '^colima stop' "$EVENTS")" = 1 ] || fail "the engine started under TERM was not stopped"
    rm -f "$ROOT/in/A"
    expect_no_residue
}

rep=1
while [ "$rep" -le "$REPEAT" ]; do
    scenario_fifo
    scenario_burst
    scenario_dead
    scenario_traps
    scenario_shutdown
    scenario_legacy
    scenario_doorway
    scenario_claim
    scenario_term
    scenario_pidless
    scenario_chain
    scenario_slots2
    scenario_slots3
    scenario_slots_dead
    scenario_slots_shutdown
    scenario_slots_legacy
    scenario_finish_race
    scenario_start_error
    echo "repetition $rep/$REPEAT: fifo, burst, dead, traps, shutdown, legacy, doorway, claim, term, pidless, chain, slots2, slots3, slots-dead, slots-shutdown, slots-legacy, finish-race, start-error passed"
    rep=$((rep + 1))
done
