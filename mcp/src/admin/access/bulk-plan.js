// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
export function planBulkChange({ users: rows, module, operations, granted, unavailableReason, uids = null }) {
		const target = operation => typeof granted === 'boolean' ? granted : granted[operation] === true
        const changes = operations.map((operation) => ({ operation, granted: target(operation), previous: new Map() }))
		let users = 0
		let unavailable = 0
		let unchanged = 0
		for (const user of rows) {
            if (uids !== null && !uids.includes(user.uid)) continue
			if (unavailableReason(user, module) !== null || operations.some(operation => typeof (module === null ? user.eligible : user.grants[module][operation]) !== 'boolean')) {
				unavailable++
				continue
			}
			let changed = false
			for (const change of changes) {
				const current = module === null ? user.eligible : user.grants[module][change.operation]
				if (current !== change.granted) {
					change.previous.set(user.uid, current)
					changed = true
				}
			}
			changed ? users++ : unchanged++
		}
		return { changes: changes.filter((change) => change.previous.size > 0), users, unavailable, unchanged }
	}
