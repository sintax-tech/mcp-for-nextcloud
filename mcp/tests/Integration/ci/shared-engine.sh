# SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
# SPDX-License-Identifier: AGPL-3.0-or-later
# Local-run ownership and lifecycle for the shared Colima profile. Source after engine-queue.sh.

# shared_engine_take: serialize startup through the existing queue without scheduling an engine stop.
shared_engine_take() {
	engine_lock || exit 3
	if ! colima status >/dev/null 2>&1; then
		log "starting shared Colima (4 CPUs, 8 GiB)"
		colima start --vm-type vz --mount-type virtiofs --cpu 4 --memory 8 --disk 40
	fi
	engine_unlock
}

# shared_engine_release: release only this run's slot, even after an error or interrupt.
shared_engine_release() {
	leave_slot
	log "Shared Colima profile left running"
}

# record_owned_resources <manifest>: exact names used by this run, never a global prefix scan.
record_owned_resources() {
	printf '%s\n' "$$" "$NC_NAME" "$DB_NAME" "$NET_NAME" "$MCP_IT_IMAP_NAME" > "$1"
}
