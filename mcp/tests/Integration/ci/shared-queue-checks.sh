# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Shared-profile assertions for queue fixtures; source in engine-queue-test.sh and its legacy runner.

# expect_shared_engine_once: all queued callers share one startup and preserve the engine.
expect_shared_engine_once() {
	[ "$(grep -c '^colima start' "$EVENTS")" = 1 ] || fail "Colima did not start exactly once"
	! grep -q '^colima stop' "$EVENTS" || fail "Shared Colima was stopped"
	[ -f "$FAKE_UP" ] || fail "Shared Colima was not preserved"
}

# shared_legacy_engine_release: the old queue protocol may report waiters but cannot stop the shared profile.
shared_legacy_engine_release() {
	if others_waiting; then
		log "another run is waiting for the engine; shared profile left running"
	else
		log "shared profile left running"
	fi
}

# shared_queue_finish_race: concurrent exits release every slot without stopping the shared profile.
shared_queue_finish_race() {
	reset finish-race
	export MCP_IT_SLOTS=2
	hold_slots A B
	wait_for "A and B ready" "grep -q '^ready A' '$EVENTS' && grep -q '^ready B' '$EVENTS'"
	touch "$ROOT/go.A" "$ROOT/go.B"
	wait_all
	expect_shared_engine_once
	expect_no_residue
}
