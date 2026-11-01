/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Matrix commands operations.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.matrix.commands = function (context) {
		/**
		 * What a bulk change would do: per operation, the users whose saved value differs from the target and that value; users
		 * with an unavailable or unknown cell are left out, users already set are counted.
		 *
		 * @param {string|null} module module id, null for eligibility
		 * @param {string[]} operationList operation ids, or ["eligible"]
		 * @param {boolean|Object<string, boolean>} granted the new value of every operation, or one per operation
		 * @param {string[]|null} [uids] the users to change, the whole page when null
		 * @return {{changes: Array<{operation: string, granted: boolean, previous: Map<string, boolean>}>, users: number, unavailable: number, unchanged: number}}
		 */
		function bulkPlan(module, operationList, granted, uids = null) {
			const target = (operation) => typeof granted === 'boolean' ? granted : granted[operation] === true
			const changes = operationList.map((operation) => ({ operation, granted: target(operation), previous: new Map() }))
			let users = 0
			let unavailable = 0
			let unchanged = 0
			for (const user of context.state.data.users) {
				if (uids !== null && !uids.includes(user.uid)) {
					continue
				}
				if (context.unavailableReason(user, module) !== null || operationList.some((operation) =>
					(module === null ? user.eligible : user.grants[module][operation]) === null)) {
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


		/**
		 * Saves a bulk change as one command: the server writes every cell or none, and returns the
		 * receipt that undoes exactly these cells.
		 *
		 * @param {string|null} module module id, null for eligibility
		 * @param {Array<{operation: string, uids: string[], granted: boolean}>} requests cells to save
		 * @return {Promise<object>} the saved command: {revision, changed, receiptId}
		 */
		async function sendCommand(module, requests) {
			const commands = requests.flatMap((request) => request.uids.map((uid) => ({
				uid,
				module,
				operation: request.operation,
				state: request.granted ? 'allow' : 'deny',
			})))
			const sent = context.state.data.revision
			const saved = await context.api('POST', '/api/grants/commands', { expectedRevision: sent, commands })
			context.announce(sent, saved.revision)
			return saved
		}


		/**
		 * The selection bar's "Set" (src/admin/overrides/start.js): gives the selected users one level of a module, or turns "Can
		 * connect" on or off. It confirms exactly what changes, how many are left out and why, and how many already have it,
		 * sends nothing when nobody changes, saves one command and offers the undo. Every cell is an explicit allow or deny,
		 * never an inheritance, as the level popover does for a removal (F1).
		 *
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {string} level a level of src/admin/admin-levels.js; for "Can connect", "read" is on and "none" is off
		 * @param {string[]} uids the selected users of the page
		 * @return {Promise<*>} operation result
		 */
		async function bulk(module, level, uids) {
			// The plan is computed from the table on screen, so never from one that is being replaced.
			if (context.bulkBusy || context.matrixLocked) {
				return
			}
			const operationList = module === null ? ['eligible'] : context.state.data.catalog[module]
			const allowed = module === null ? (level === 'read' ? ['eligible'] : []) : window.OCA.Mcp.Admin.levels.operationsFor(module, operationList, level)
			const plan = context.bulkPlan(module, operationList, Object.fromEntries(operationList.map((operation) => [operation, allowed.includes(operation)])), uids)
			const levelName = module === null ? (level === 'read' ? t('mcp', 'Yes') : t('mcp', 'No')) : context.levelLabels()[level] || level
			const vars = { permission: module === null ? t('mcp', 'Can connect') : context.moduleLabels()[module] || module, level: levelName }
			const lines = []
			if (plan.unavailable > 0) {
				lines.push(n('mcp', '%n selected person is left out: this permission is unavailable or unknown for them.', '%n selected people are left out: this permission is unavailable or unknown for them.', plan.unavailable, vars, { escape: false }))
			}
			if (plan.unchanged > 0) {
				lines.push(n('mcp', '%n selected person already has it.', '%n selected people already have it.', plan.unchanged, vars, { escape: false }))
			}
			if (plan.users === 0) {
				context.showNotice([t('mcp', 'Nothing to change for the selected people: “{permission}” is already {level}.', vars, undefined, { escape: false }), ...lines].join(' '), null)
				return
			}
			const question = n('mcp', 'Set “{permission}” to {level} for %n selected person?', 'Set “{permission}” to {level} for %n selected people?', plan.users, vars, { escape: false })
			if (module === null && level !== 'read') {
				lines.push(t('mcp', 'They are signed out of their MCP clients, and undoing this does not sign them back in.'))
			}
			const mailWarning = module !== null && window.OCA.Mcp.Admin.mailGrants ? window.OCA.Mcp.Admin.mailGrants.writeWarning(plan.changes.filter((change) => change.granted).map((change) => ({ module, operation: change.operation }))) : null
			if (mailWarning) lines.push(mailWarning)
			if (!window.confirm([question, ...lines].join('\n'))) {
				return
			}
			const requests = plan.changes.map((change) => ({ operation: change.operation, uids: [...change.previous.keys()], granted: change.granted }))
			let undo = null
			let reloaded = false
			context.setBulkBusy(true)
			try {
				try {
					const saved = await context.sendCommand(module, requests)
					undo = saved.receiptId === null ? null : () => context.undoBulk(saved.receiptId, saved.revision)
					context.showNotice(n('mcp', '“{permission}” set to {level} for %n person.', '“{permission}” set to {level} for %n people.', plan.users, vars, { escape: false }), undo)
				} catch (e) {
					// The command is all or nothing, but an unreadable response cannot confirm its outcome.
					reloaded = await context.policyError(e)
					if (!reloaded) {
						context.notifyError(t('mcp', 'Could not update the selected people.'))
					}
				}
				if (!reloaded) {
					await context.reloadLocked()
				}
			} finally {
				context.setBulkBusy(false)
			}
			if (undo !== null && context.pendingUndo === undo) {
				// The focus goes to the undo, scrolling the notice under the table into view when it is below the fold.
				context.undoButton.focus()
			}
		}


		/**
		 * Undoes a bulk change through its receipt: the server puts back each cell's exact saved value,
		 * absence included, and refuses when anything changed since that change.
		 *
		 * @param {string} receiptId receipt of the saved command
		 * @param {string} savedRevision revision that command committed
		 * @return {Promise<*>} operation result
		 */
		async function undoBulk(receiptId, savedRevision) {
			context.setBulkBusy(true)
			try {
				try {
					const undone = await context.api('POST', '/api/grants/undo', { receiptId, expectedRevision: savedRevision })
					context.announce(savedRevision, undone.revision)
					context.showNotice(t('mcp', 'Change undone.'), null)
				} catch (e) {
					if (e.message === '503' && !(e.data && e.data.uncertain)) {
						// Nothing was written (what the users inherit could not be read): the table stays as it is, and the same
						// undo is offered again instead of a reload that would hide that it is still possible.
						const retry = t('mcp', 'The change could not be undone because what these users inherit could not be read. Nothing was changed. Try again.')
						context.notifyError(retry)
						context.showNotice(retry, () => context.undoBulk(receiptId, savedRevision))
						return
					}
					const failure = e.message === '503' && e.data && e.data.uncertain
						? t('mcp', 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.')
						: e.message === '409'
							? t('mcp', 'The permissions changed after this change, so it can no longer be undone. The table was reloaded with the saved values.')
							: e.message === '410'
								? t('mcp', 'This change can no longer be undone: the time to undo it has passed.')
								: t('mcp', 'Could not undo the change. The table was reloaded with the saved values.')
					context.notifyError(failure)
					context.showNotice(failure, null)
				}
				await context.reloadLocked()
			} finally {
				context.setBulkBusy(false)
			}
		}

		Object.assign(context, { bulk, bulkPlan, sendCommand, undoBulk })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
