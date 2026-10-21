// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
export const OVERRIDES = { individual_allow: 'allow', individual_deny: 'deny', invalid: 'invalid' }
const MAX_CELLS = 2500
const MAX_USERS = 50
export function createOverridePlan({ catalog }) {
	/**
	 * @param {object} user row from the API
	 * @return {Array<{module: string|null, operation: string, kind: string}>} the cells where the user has an individual override
	 */
	function overrideCells(user) {
		const cells = []
		const eligible = OVERRIDES[user.origins?.eligible?.source]
		if (eligible) {
			cells.push({ module: null, operation: 'eligible', kind: eligible })
		}
		for (const [module, operations] of Object.entries(user.origins?.grants || {})) {
			for (const [operation, origin] of Object.entries(operations)) {
				if (OVERRIDES[origin?.source]) {
					cells.push({ module, operation, kind: OVERRIDES[origin.source] })
				}
			}
		}
		return cells
	}
	/**
	 * Whether the table offers no checkbox for this cell of this user: a disabled account, a user who may not connect (the
	 * permission columns show one notice instead), a module whose column is hidden or an app that is unavailable to them.
	 * An override there is still removed by a reset of the row, so the preview says so.
	 *
	 * @param {object} user row from the API
	 * @param {{module: string|null}} cell an override of the user
	 * @return {boolean}
	 */
	function unseenCell(user, cell) {
		if (!user.enabled) {
			return true
		}
		if (cell.module === null) {
			return false
		}
		return user.eligible === false || !(cell.module in catalog()) || user.appsEnabled?.[cell.module] === false
	}
	/**
	 * What a reset removes, computed from the table on screen.
	 *
	 * @param {object[]} users users to reset
	 * @param {Array<{module: string|null, operation: string}>|Function|null} only restrict to these cells, or to the cells a
	 *     function (user, cell) keeps; null for every override
	 * @return {object} {targets, users, cells, allow, deny, invalid, eligibleAllow, eligibleDeny, unseen, without, tooMany}:
	 *     eligibleDeny counts a Deny and an invalid value of "Can connect", both of which deny now and may stop denying;
	 *     unseen counts the overrides in a cell the table does not offer to the user (see unseenCell())
	 */
	function plan(users, only) {
		const result = { targets: [], users: 0, cells: 0, allow: 0, deny: 0, invalid: 0, eligibleAllow: 0, eligibleDeny: 0, unseen: 0, without: 0, tooMany: false }
		for (const user of users) {
			const cells = overrideCells(user).filter((cell) => only === null
				|| (typeof only === 'function' ? only(user, cell) : only.some((wanted) => wanted.module === cell.module && wanted.operation === cell.operation)))
			if (cells.length === 0) {
				result.without++
				continue
			}
			result.targets.push({ user, cells })
			result.users++
			for (const cell of cells) {
				result.cells++
				result[cell.kind]++
				if (cell.module === null && cell.kind === 'allow') {
					result.eligibleAllow++
				} else if (cell.module === null) {
					// An invalid stored value denies like a Deny: removing it hands the decision back to the groups too.
					result.eligibleDeny++
				}
				if (unseenCell(user, cell)) {
					result.unseen++
				}
			}
		}
		result.tooMany = result.cells > MAX_CELLS || result.users > MAX_USERS
		return result
	}
    return { overrideCells, unseenCell, plan }
}
