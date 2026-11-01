/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Individual override saving operations.
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
	admin.modules.overrides.saving = function (context) {
		/** Sends the command of the open preview. Nothing is repeated on its own: a retry is the administrator's click.
		 * @return {Promise<*>} operation result
		 */
		async function execute() {
			if (context.review === null || context.saving || context.matrix.isBusy()) {
				return
			}
			if (context.outdated(context.review)) {
				// A matrix save moved the table's revision after the preview was read: say so instead of acting on it.
				context.renderReview()
				return
			}
			const current = context.review
			const commands = context.commandsOf(current.plan)
			context.saving = true
			context.matrix.setBusy(true)
			for (const button of context.panel.querySelectorAll('button')) {
				button.disabled = true
			}
			context.updateToolbar()
			context.say('')
			let undo = null
			let failed = false
			try {
				try {
					const sent = current.revision ?? context.matrix.data().revision
					const saved = await context.matrix.api('POST', '/api/grants/commands', { expectedRevision: sent, commands })
					context.matrix.announce(sent, saved.revision)
					for (const target of current.plan.targets) {
						context.selected.delete(target.user.uid)
					}
					context.close(false)
					// The table still shows the old values until it reloads: the result is told only now, from the server's answer.
					undo = context.matrix.offerUndo(saved.changed === 0
						? t('mcp', 'Nothing changed: those overrides were already gone.')
						: n('mcp', '%n individual override removed.', '%n individual overrides removed.', saved.changed, undefined, { escape: false }), saved)
					await context.matrix.reload()
				} catch (e) {
					if (e.message === '503' && !(e.data && e.data.uncertain)) {
						// Nothing was written: the preview, the selection and the table stay, and the same command can be sent again.
						context.review.retry = true
						context.renderReview()
						context.say(t('mcp', 'The change could not be made because what these users inherit could not be read. Nothing was changed, and your selection is kept. Try again.'), true)
					} else if (!(await context.matrix.policyError(e))) {
						failed = true
						context.close(false)
						context.say(t('mcp', 'Could not return the users to group inheritance. The table was reloaded with the saved values.'), true)
						await context.matrix.reload()
					} else {
						failed = true
						context.close(false)
					}
				}
			} finally {
				context.saving = false
				context.matrix.setBusy(false)
				context.updateToolbar()
			}
			if (context.review !== null && context.review.retry) {
				context.panel.querySelector('button').focus()
			}
			if (failed) {
				// The preview is gone and the table was redrawn: the focus goes to a control of the toolbar, never to the page.
				context.focusSomething(current.trigger)
			}
			context.matrix.focusUndo(undo)
		}

		Object.assign(context, { execute })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
