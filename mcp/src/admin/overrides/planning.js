/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Individual override planning operations.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.overrides = admin.modules.overrides || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.overrides.planning = function (context) {
		/**
		 * @param {object} user row from the API
		 * @return {Array<{module: string|null, operation: string, kind: string}>} the cells where the user has an individual override
		 */
		function overrideCells(user) {
			const cells = []
			const eligible = context.OVERRIDES[user.origins?.eligible?.source]
			if (eligible) {
				cells.push({ module: null, operation: 'eligible', kind: eligible })
			}
			for (const [module, operations] of Object.entries(user.origins?.grants || {})) {
				for (const [operation, origin] of Object.entries(operations)) {
					if (context.OVERRIDES[origin?.source]) {
						cells.push({ module, operation, kind: context.OVERRIDES[origin.source] })
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
			return user.eligible === false || !(cell.module in (context.matrix.data()?.catalog || {})) || user.appsEnabled?.[cell.module] === false
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
				const cells = context.overrideCells(user).filter((cell) => only === null
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
					if (context.unseenCell(user, cell)) {
						result.unseen++
					}
				}
			}
			result.tooMany = result.cells > context.MAX_CELLS || result.users > context.MAX_USERS
			return result
		}


		/**
		 * @param {object} result a plan
		 * @return {Array<{uid: string, module: string|null, operation: string, state: string}>} its inherit commands
		 */
		function commandsOf(result) {
			return result.targets.flatMap((target) => target.cells.map((cell) => ({
				uid: target.user.uid,
				module: cell.module,
				operation: cell.operation,
				state: 'inherit',
			})))
		}


		/**
		 * @param {object} current the open preview
		 * @return {boolean} whether the preview was read at another revision than the table on screen, so what the table lists
		 *     and what the preview shows may not be the same overrides
		 */
		function outdated(current) {
			return current.revision !== null && current.revision !== context.matrix.data()?.revision
		}


		/** @return {string} key of one cell of one user
		 * @param {*} uid operation input
		 * @param {*} module operation input
		 * @param {*} operation operation input
		 */
		function afterKey(uid, module, operation) {
			return uid + '\0' + (module ?? '') + '\0' + operation
		}

		Object.assign(context, { afterKey, commandsOf, outdated, overrideCells, plan, unseenCell })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
