/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the picker saving unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.picker = admin.modules.picker || {}
	/** Attach confirmed selection persistence, rollback and option toggling.
	 * @param {object} context picker specification, mounted controls and live selection bindings
	 * @return {void}
	 */
	admin.modules.picker.saving = function (context) {
		/**
		 * Persists a new selection: confirms first when the caller asks, locks the control, keeps the
		 * previous state on failure and reports through the status element and the onError hook.
		 * @param {Set<string>} next the intended selection
		 * @param {boolean} force permits an explicit save while the options show a fallback
		 * @return {Promise<void>}
		 */
		async function commit(next, force = false) {
			if (context.busy || (context.fallbackText !== null && !force)) {
				return
			}
			if (context.spec.confirmSave && !(await context.spec.confirmSave(Array.from(next)))) {
				return
			}
			// Rebuilding chips and disabling the input can send keyboard focus to the body.
			const restoreFocus = document.activeElement === context.input || context.chips.contains(document.activeElement)
			const before = context.selected
			context.selected = new Set(next)
			context.paint()
			context.spec.onChange?.(Array.from(context.selected))
			context.setBusy(true)
			context.setStatus(t('mcp', 'Saving…'))
			try {
				const result = await context.spec.save(Array.from(context.selected), Array.from(before))
				context.setStatus(t('mcp', 'Saved'))
				if (context.spec.clearSavedAfterMs) {
					const savedText = t('mcp', 'Saved')
					setTimeout(() => {
						if (context.spec.status && context.spec.status.textContent === savedText) {
							context.spec.status.textContent = ''
						}
					}, context.spec.clearSavedAfterMs)
				}
				await context.spec.onSaved?.(result)
			} catch (error) {
				// Rollback first, so an onError hook that resynchronizes wins over the old state.
				context.selected = before
				context.paint()
				context.spec.onChange?.(Array.from(context.selected))
				context.setStatus(context.spec.errorStatus ?? t('mcp', 'Not saved'))
				await context.spec.onError?.(error)
			} finally {
				context.setBusy(false)
				if (restoreFocus && !context.box.hidden
					&& (document.activeElement === document.body || document.activeElement === context.input)) {
					context.input.focus()
				}
			}
		}

		/** Add or remove an option from the picker selection and start its save.
		 * @param {string} id option to add to or drop from the selection
		 * @return {void}
		 */
		function toggle(id) {
			const next = new Set(context.selected)
			if (next.has(id)) {
				next.delete(id)
			} else {
				next.add(id)
			}
			context.commit(next)
		}

		Object.assign(context, { commit, toggle })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
