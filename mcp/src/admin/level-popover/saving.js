/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** level_popover saving operations.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.levelPopover = admin.modules.levelPopover || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.levelPopover.saving = function (context) {
		/**
		 * Sends the commands as one, confirming first when "Can connect" is taken away, and offers the receipt as the undo. The
		 * matrix is reloaded afterwards with every write blocked until it is on screen.
		 *
		 * @param {Array<object>} commands what planLevel(), planInherit() or planOperation() returned
		 * @param {string} message the notice that names what was saved
		 * @return {Promise<void>}
		 */
		async function save(commands, message) {
			if (context.current === null || context.current.saving || context.matrix.isBusy()) {
				return
			}
			const open = context.current
			const user = context.userOf(open.uid)
			if (commands.length === 0) {
				context.close(true)
				context.matrix.offerUndo(t('mcp', 'Nothing changed.'), { receiptId: null, revision: context.matrix.data().revision })
				return
			}
			if (context.losesEligibility(user, commands, open.module === null ? open.inherited : null)
				&& !window.confirm(t('mcp', 'Turn off “Can connect” for {user}?', { user: user.displayName }, undefined, { escape: false }) + '\n'
					+ t('mcp', 'This signs the person out of every MCP client, and undoing it does not sign them back in.'))) {
				context.render()
				return
			}
			if (window.OCA.Mcp.Admin.mailGrants && !window.OCA.Mcp.Admin.mailGrants.confirmWrites(commands.filter((command) => command.state === 'allow'))) {
				context.render()
				return
			}
			open.saving = true
			context.render()
			context.matrix.setBusy(true)
			let reloaded = false
			try {
				try {
					const sent = context.matrix.data().revision
					const saved = await context.matrix.api('POST', '/api/grants/commands', { expectedRevision: sent, commands })
					context.matrix.announce(sent, saved.revision)
					context.close(false)
					context.matrix.offerUndo(message, saved)
				} catch (e) {
					// Nothing was saved: the command is all or nothing.
					context.close(false)
					reloaded = await context.matrix.policyError(e)
					if (!reloaded) {
						context.matrix.notifyError(t('mcp', 'Could not save the permission for {user}.', { user: user.displayName }, undefined, { escape: false }))
					}
				}
				if (!reloaded) {
					await context.matrix.reload()
				}
			} finally {
				context.matrix.setBusy(false)
			}
			context.focusCell(open.uid, open.module)
		}

		/** Gives the focus back to the cell the popover was opened from, in the table drawn after a save.
		 * @param {*} uid operation input
		 * @param {*} module operation input
		 * @return {*} operation result
		 */
		function focusCell(uid, module) {
			const table = document.getElementById('mcp-matrix')
			const row = [...(table?.tBodies?.[0]?.rows || [])].find((candidate) => candidate.dataset.uid === uid)
			const key = module === null ? 'eligible' : module
			const cell = [...(row?.cells || [])].find((candidate) => candidate.dataset.levelKey === key)
			cell?.querySelector('button')?.focus()
		}

		/**
		 * Asks what the person inherits, read only. An answer read at another revision than the table on screen is not used,
		 * so a level is never sent as "inherit" on the strength of a policy that is not the one shown.
		 * @param {*} open operation input
		 * @return {Promise<*>} operation result
		 */
		async function loadInherited(open) {
			const user = context.userOf(open.uid)
			const individual = context.planInherit(user, open.module)
			try {
				let cells = []
				if (individual.length > 0) {
					const data = await context.matrix.api('POST', '/api/grants/commands/preview', { commands: individual })
					if (typeof data.revision !== 'string' || data.revision !== context.matrix.data()?.revision) {
						throw new Error('stale')
					}
					cells = Array.isArray(data.cells) ? data.cells : []
				}
				if (context.current !== open) return
				open.inherited = context.inheritedValues(user, open.module, cells)
			} catch (e) {
				if (context.current !== open) return
				open.inherited = null
				open.unavailable = true
			}
			open.loading = false
			if (!open.saving) context.render()
		}

		Object.assign(context, { save, focusCell, loadInherited })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
