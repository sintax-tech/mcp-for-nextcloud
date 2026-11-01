# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# The queue in front of the Colima engine, sourced by run-local.sh (and by engine-queue-test.sh). Up to MCP_IT_SLOTS
# runs (default 2) use the engine at once; the others wait and get in in the order they arrived:
#
#   - slot 1 is the directory $LOCK_DIR, slot k > 1 is $LOCK_DIR.k; each holds its owner's pid and is taken with mkdir
#     (slot 1 is the lock of the copies of this script from before the slots, so they share it);
#   - a run arriving goes through a doorway, as in Lamport's bakery: it creates $QUEUE_DIR/.new.<pid> (it is choosing),
#     reads the highest ticket, publishes its own ticket $QUEUE_DIR/t.<16-digit number>.<10-digit pid> (a file holding
#     its pid) one above it, and only then removes .new.<pid>. While any live run is choosing, nobody decides. A run that
#     enters the doorway after another has published reads that ticket and gets a higher number, so a ticket published
#     late can only be one that was being chosen while everybody waited for it;
#   - the live tickets in name order (number, then pid) are the queue; with F slots free (or held by a dead owner), the
#     first F of them may take one, so a waiting run is never overtaken by one that arrived after it;
#   - every name carries its run's pid, so a name is never handed to another run, and the tickets and .new.<pid> of
#     dead runs are removed by name without ever removing a live run's;
#   - the slot of a dead owner is never removed by a waiting run: one run claims it with <slot>/claim.<dead pid>, a hard
#     link to its own ticket (so it holds the claimer's pid) made with ln (link(2) fails if the name exists, so only one
#     run claims a given dead owner), and then writes its pid as the slot's owner. The owner is the pid file followed
#     through the claims (pid -> claim.<pid> -> ...), so a claimer that dies is claimed over in turn, and a claim that
#     lands in a newer lock (the old one was released meanwhile) does not lead from its owner and is withdrawn. The pid
#     of a new lock is published with ln (it fails if a pid is there), so a slot whose creator died between mkdir and
#     the pid (no pid file for more than a minute) is taken over by publishing a pid the same way: of the late creator
#     and the run taking over, exactly one owns it;
#   - a run holding a slot keeps $QUEUE_DIR/h.<pid> (its pid), so older copies of this script see it as waiting and
#     never stop the engine under it;
#   - engine startup is serialized through the engine switch; shared-engine.sh owns the lifecycle and
#     always leaves the shared Colima profile running. Resource cleanup requires a checkout-owned manifest.
#
# Older queue formats may use slot 1 and numeric entries; only dead entries are removed from the local queue.
# Knobs: MCP_IT_SLOTS (default 2), MCP_IT_LOCK_DIR (lock path), MCP_IT_LOCK_POLL (default 2 seconds),
# MCP_IT_LOCK_TIMEOUT (default 3600 seconds without queue movement).

LOCK_DIR="${MCP_IT_LOCK_DIR:-$HOME/.colima/mcp-it.lock}"
QUEUE_DIR="${LOCK_DIR%.lock}.queue"
ENGINE_LOCK="${LOCK_DIR%.lock}.engine.lock"
SLOTS="${MCP_IT_SLOTS:-2}"
case "$SLOTS" in '' | *[!0-9]* | 0) echo "MCP_IT_SLOTS must be a whole number of at least 1" >&2; exit 2 ;; esac
HAVE_LOCK=0
# The files and locks of this run, known before they exist so the traps remove them whatever the moment of the signal.
CHOOSING=""
TICKET=""
HOLD="$QUEUE_DIR/h.$(printf '%010d' "$$")"
SLOT_DIR=""
ENGINE_HELD=""

log() {
    echo "[run-local $(date +%H:%M:%S)] $*"
}

# pid_of <path>: the pid at the end of a ticket or .new.<pid> name, without the zero padding.
pid_of() {
    pid=${1##*.}
    pid=$(echo "$pid" | sed 's/^0*//')
    echo "${pid:-0}"
}

slot_dir() {
    if [ "$1" = 1 ]; then echo "$LOCK_DIR"; else echo "$LOCK_DIR.$1"; fi
}

# take_ticket: the doorway; queues this run behind every ticket published before it entered.
take_ticket() {
    mkdir -p "$(dirname "$LOCK_DIR")" "$QUEUE_DIR"
    CHOOSING="$QUEUE_DIR/.new.$$"
    echo "$$" > "$CHOOSING"
    last=0
    for entry in "$QUEUE_DIR"/t.*.*; do
        [ -f "$entry" ] || continue
        last=${entry##*/t.}
        last=$(echo "${last%%.*}" | sed 's/^0*//')
    done
    TICKET="$QUEUE_DIR/t.$(printf '%016d' $((${last:-0} + 1))).$(printf '%010d' "$$")"
    ln -f "$CHOOSING" "$TICKET"
    rm -f "$CHOOSING"
    CHOOSING=""
}

drop_ticket() {
    rm -f ${CHOOSING:+"$CHOOSING"} ${TICKET:+"$TICKET"}
    CHOOSING=""
    TICKET=""
}

# queue_scan: drops the tickets and doorway marks of dead runs and sets QUEUE_CHOOSING (1 = another run is in the
# doorway), QUEUE_POS (1 = this run is first among the waiting) and QUEUE_AHEAD (the pids waiting in front of it).
queue_scan() {
    QUEUE_CHOOSING=0
    QUEUE_POS=0
    QUEUE_AHEAD=""
    # The doorway is read before the tickets: a run that enters it after this point gets a number above this run's.
    for entry in "$QUEUE_DIR"/.new.*; do
        [ -f "$entry" ] || continue
        pid=$(pid_of "$entry")
        [ "$pid" = "$$" ] && continue
        if kill -0 "$pid" 2>/dev/null; then
            QUEUE_CHOOSING=1
        else
            rm -f "$entry"
        fi
    done
    if [ ! -f "$TICKET" ]; then
        log "the ticket of this run disappeared; queuing again at the end"
        take_ticket
        # Others may have been in the doorway with this run: the next scan reads it again before deciding.
        return 0
    fi
    for entry in "$QUEUE_DIR"/t.*.*; do
        [ -f "$entry" ] || continue
        QUEUE_POS=$((QUEUE_POS + 1))
        [ "$entry" = "$TICKET" ] && return 0
        pid=$(pid_of "$entry")
        if ! kill -0 "$pid" 2>/dev/null; then
            log "dropping the ticket of the run $pid, which is gone"
            rm -f "$entry"
            QUEUE_POS=$((QUEUE_POS - 1))
            continue
        fi
        QUEUE_AHEAD="$QUEUE_AHEAD $pid"
    done
    # Removed while this scan ran: queue again on the next one.
    QUEUE_POS=0
}

# lock_owner <dir>: the pid owning a slot (or the engine switch), its pid file followed through the claims (a chain of
# any length; a loop, from a reused pid, ends it); "none" for a lock left without a pid for more than a minute; ''
# while a new owner publishes its pid.
lock_owner() {
    owner=$(cat "$1/pid" 2>/dev/null || true)
    if [ -z "$owner" ] && [ -n "$(find "$1" -prune -mmin +1 2>/dev/null)" ] && [ ! -e "$1/pid" ]; then
        owner=none
    fi
    seen=" "
    while [ -n "$owner" ]; do
        case "$seen" in *" $owner "*) break ;; esac
        seen="$seen$owner "
        next=$(cat "$1/claim.$owner" 2>/dev/null || true)
        [ -n "$next" ] || break
        owner=$next
    done
    echo "$owner"
}

# lock_held <dir> <slot|engine>: records a lock this run now owns (for the traps).
lock_held() {
    if [ "$2" = slot ]; then
        SLOT_DIR=$1
        HAVE_LOCK=1
    else
        ENGINE_HELD=$1
    fi
}

# lock_pid <dir>: publishes this run's pid as the lock's owner with ln, which fails if a pid is already there: the
# creator of a lock and a run taking over a lock left without a pid never both own it.
lock_pid() {
    { echo "$$" > "$1/.pid.$$"; } 2>/dev/null || return 1
    if ln "$1/.pid.$$" "$1/pid" 2>/dev/null; then
        rm -f "$1/.pid.$$"
        return 0
    fi
    rm -f "$1/.pid.$$"
    return 1
}

# lock_take <dir> <file holding this run's pid> <slot|engine>: takes the free lock, or the lock of a dead owner.
# Returns 1 when it is held by a live run (or by one still writing its pid).
lock_take() {
    if mkdir "$1" 2>/dev/null; then
        # A signal before the pid is published leaves a lock without a pid, taken over after a minute.
        lock_pid "$1" || return 1
        lock_held "$1" "$3"
        return 0
    fi
    dead=$(lock_owner "$1")
    if [ -z "$dead" ] || kill -0 "$dead" 2>/dev/null; then
        return 1
    fi
    if [ "$dead" = none ]; then
        lock_pid "$1" || return 1
        lock_held "$1" "$3"
        log "a lock of the engine was left without a pid; this run took it over"
        return 0
    fi
    ln "$2" "$1/claim.$dead" 2>/dev/null || return 1
    if [ "$(lock_owner "$1")" = "$$" ]; then
        lock_held "$1" "$3"
        if [ "$3" = slot ]; then
            log "the run $dead that held the engine is gone; this run took its lock over"
        else
            log "the run $dead that held the engine switch is gone; this run took it over"
        fi
        # Writing this run's pid makes the chain of claims start from it again.
        echo "$$" > "$1/.pid.$$"
        mv -f "$1/.pid.$$" "$1/pid"
        return 0
    fi
    # The lock was released and taken again meanwhile: this claim does not lead from its owner; withdraw it.
    [ "$(cat "$1/claim.$dead" 2>/dev/null)" = "$$" ] && rm -f "$1/claim.$dead"
    return 1
}

# slots_scan: sets SLOT_FREE (slots 1..SLOTS free or held by a dead owner) and SLOT_OWNERS (the live holders).
slots_scan() {
    SLOT_FREE=0
    SLOT_OWNERS=""
    k=1
    while [ "$k" -le "$SLOTS" ]; do
        dir=$(slot_dir "$k")
        owner=$(lock_owner "$dir")
        if [ ! -d "$dir" ] || { [ -n "$owner" ] && ! kill -0 "$owner" 2>/dev/null; }; then
            SLOT_FREE=$((SLOT_FREE + 1))
        else
            SLOT_OWNERS="$SLOT_OWNERS ${owner:-?}"
        fi
        k=$((k + 1))
    done
}

# slot_take: tries every slot; 0 when this run got one.
slot_take() {
    k=1
    while [ "$k" -le "$SLOTS" ]; do
        lock_take "$(slot_dir "$k")" "$TICKET" slot && return 0
        k=$((k + 1))
    done
    return 1
}

# acquire_lock: waits for this run's turn, then takes a slot.
acquire_lock() {
    take_ticket
    moved_at=$(date +%s)
    logged_at=0
    last_state=""
    while :; do
        queue_scan
        slots_scan
        if [ "$QUEUE_CHOOSING" = 0 ] && [ "$QUEUE_POS" -ge 1 ] && [ "$QUEUE_POS" -le "$SLOT_FREE" ] && slot_take; then
            break
        fi
        state="$QUEUE_POS:$SLOT_OWNERS"
        now=$(date +%s)
        [ "$state" != "$last_state" ] && moved_at=$now
        if [ $((now - moved_at)) -ge "${MCP_IT_LOCK_TIMEOUT:-3600}" ]; then
            drop_ticket
            echo "Timed out waiting for the engine (queue position $QUEUE_POS, slots held by$SLOT_OWNERS)" >&2
            exit 3
        fi
        if [ "$state" != "$last_state" ] || [ $((now - logged_at)) -ge 60 ]; then
            log "queue position $QUEUE_POS: the engine's $SLOTS slot(s) are held by run(s)${SLOT_OWNERS:- ?}; waiting before this run:${QUEUE_AHEAD:- nobody}"
            logged_at=$now
        fi
        last_state=$state
        sleep "${MCP_IT_LOCK_POLL:-2}"
    done
    # Visible to older copies of this script as a waiting run while this run holds the slot.
    ln -f "$TICKET" "$HOLD"
    drop_ticket
    if [ "$SLOT_DIR" = "$LOCK_DIR" ]; then slot=1; else slot=${SLOT_DIR##*.}; fi
    log "using engine slot $slot of $SLOTS"
}

# release_lock: removes this run's doorway mark, ticket and engine switch and, when it holds one, its slot (traps:
# error, INT/TERM and normal exit).
release_lock() {
    drop_ticket
    leave_slot
    engine_unlock
    return 0
}

# leave_slot: gives this run's slot and h.<pid> up.
leave_slot() {
    if [ "$HAVE_LOCK" = 1 ]; then
        rm -rf "$SLOT_DIR"
        HAVE_LOCK=0
        SLOT_DIR=""
    fi
    rm -f "$HOLD"
}

# engine_lock: takes the engine switch (serializing shared Colima startup); 1 after MCP_IT_LOCK_TIMEOUT seconds. A run that
# already holds it (cleanup after a failed colima start) keeps it.
engine_lock() {
    [ -z "$ENGINE_HELD" ] || return 0
    since=$(date +%s)
    until lock_take "$ENGINE_LOCK" "$HOLD" engine; do
        if [ $(($(date +%s) - since)) -ge "${MCP_IT_LOCK_TIMEOUT:-3600}" ]; then
            echo "Timed out waiting for the engine switch held by run $(lock_owner "$ENGINE_LOCK")" >&2
            return 1
        fi
        sleep "${MCP_IT_LOCK_POLL:-2}"
    done
}

engine_unlock() {
    [ -n "$ENGINE_HELD" ] && rm -rf "$ENGINE_HELD"
    ENGINE_HELD=""
    return 0
}

# Compatibility entry points delegate to the shared profile lifecycle; source shared-engine.sh too.
engine_take() {
    shared_engine_take
}

# Releasing a legacy caller's slot never transfers ownership of the shared engine.
engine_release() {
    shared_engine_release
}
