# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Filesystem lock primitives for engine slots and the startup switch. Source with engine-queue.sh.

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

