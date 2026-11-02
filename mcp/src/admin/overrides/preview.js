/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Individual override preview operations.
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
	admin.modules.overrides.preview = function (context) {
		// --- Preview and reset ------------------------------------------------------------------------------------

		/**
		 * Opens the preview of a reset, or says why there is nothing to reset.
		 *
		 * @param {object[]} users users to reset
		 * @param {Array<{module: string|null, operation: string}>|null} only restrict to these cells
		 * @param {HTMLElement} trigger control that asked, which gets the focus back when the preview is cancelled
		 * @return {*} operation result
		 */
		function start(users, only, trigger) {
			if (context.saving || context.matrix.isBusy()) {
				context.say(t('mcp', 'Wait until the change in progress is saved.'), true)
				return
			}
			const result = context.plan(users, only)
			if (result.cells === 0) {
				context.say(t('mcp', 'Nothing to reset: the selected users have no individual overrides.'))
				return
			}
			if (result.tooMany) {
				context.say(t('mcp', 'Too many overrides to reset at once. Select fewer users.'), true)
				return
			}
			context.say('')
			context.review = { plan: result, trigger, retry: false, after: null, loading: true, unavailable: false, revision: null }
			context.renderReview()
			context.panel.hidden = false
			context.panel.querySelector('h3').focus()
			context.loadAfter(context.review)
		}

		/**
		 * Asks the server what each cell has before and after the reset and redraws the preview with it. Read only. A preview
		 * that cannot be read leaves every "after" unknown and the warning conservative; it never blocks the reset itself,
		 * whose command refuses on its own what it cannot determine.
		 *
		 * @param {object} current the preview that asked; an answer for a preview that was closed or replaced is dropped
		 * @return {Promise<*>} operation result
		 */
		async function loadAfter(current) {
			try {
				const data = await context.matrix.api('POST', '/api/grants/commands/preview', { commands: context.commandsOf(current.plan) })
				if (context.review !== current) {
					return
				}
				current.after = new Map((Array.isArray(data.cells) ? data.cells : []).map((cell) => [context.afterKey(cell.uid, cell.module, cell.operation), cell]))
				// The command is tied to what the preview showed: it carries the revision the preview was read at, so a change
				// after it is a 409 and never something else applied.
				current.revision = typeof data.revision === 'string' ? data.revision : null
			} catch (e) {
				if (context.review !== current) {
					return
				}
				current.unavailable = true
			}
			current.loading = false
			context.renderReview()
			context.say(current.unavailable
				? t('mcp', 'The result after the reset could not be read, so it is shown as unknown.')
				: t('mcp', 'The result after the reset is shown.'), current.unavailable)
		}

		/** @param {boolean} restoreFocus give the focus back to the control that opened the preview
		 * @return {*} operation result
		 */
		function close(restoreFocus) {
			const trigger = context.review?.trigger
			context.review = null
			context.panel.hidden = true
			context.panel.replaceChildren()
			if (restoreFocus) {
				context.focusSomething(trigger)
			}
		}

		/**
		 * Puts the focus on the control that asked for the reset, or on the first control of the toolbar that can take it, and
		 * as the last resort on the toolbar itself, so the focus never falls back to the page when a preview goes away.
		 *
		 * @param {HTMLElement|undefined} trigger control that opened the preview, which a reload may have replaced
		 * @return {*} operation result
		 */
		function focusSomething(trigger) {
			const usable = (node) => node && node.isConnected !== false && !node.disabled
			const target = [trigger, context.resetSelected, context.selectAll].find(usable) || context.toolbar
			target.focus()
		}

		/**
		 * Opens the reset preview for exactly these cells of these users, as src/admin/admin-exceptions.js does for the values that only
		 * repeat what the people inherit. The reset itself is the same command and the same preview as any other.
		 *
		 * @param {Array<{uid: string, module: string|null, operation: string}>} cells cells of the users on the page
		 * @param {HTMLElement} trigger control that asked
		 * @return {*} operation result
		 */
		function reviewCells(cells, trigger) {
			const keys = new Set(cells.map((cell) => context.afterKey(cell.uid, cell.module, cell.operation)))
			const uids = new Set(cells.map((cell) => cell.uid))
			context.start(context.pageUsers().filter((user) => uids.has(user.uid)), (user, cell) => keys.has(context.afterKey(user.uid, cell.module, cell.operation)), trigger)
		}

		Object.assign(context, { close, focusSomething, loadAfter, reviewCells, start })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
