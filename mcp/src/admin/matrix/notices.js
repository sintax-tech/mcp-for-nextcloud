/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix notices unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach the live bulk lock, undo notice state and notice interaction handlers.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.notices = function (context) {
		/** Seconds the undo of a bulk change stays offered; it waits while the pointer or focus is on it. */
		const UNDO_SECONDS = 10
		const notice = context.notice
		const noticeText = context.noticeText
		const undoButton = context.undoButton
		let noticeTimer = null
		let pendingUndo = null
		/** True while a bulk change or its undo saves and the matrix reloads: only one runs at a time. */
		let bulkBusy = false

		/**
		 * Locks the "All" menus and the undo while a bulk change or an undo is saving, so an older
		 * operation can never finish on top of a newer one's notice, undo or focus.
		 * @param {boolean} on whether an operation is running
		 * @return {void}
		 */
		function setBulkBusy(on) {
			bulkBusy = on
			const reason = on ? t('mcp', 'Wait until the change in progress is saved.') : ''
			for (const button of context.root.querySelectorAll('.mcp-bulk, #mcp-bulk-undo')) {
				button.disabled = on
				button.setAttribute('aria-disabled', on ? 'true' : 'false')
				button.title = reason
			}
			// The undo window only runs while its button can be used: it stops during the lock and
			// starts again, in full, once the lock is released.
			if (on) {
				clearTimeout(noticeTimer)
			} else if (notice.classList.contains('mcp-notice-on')) {
				context.armNotice()
			}
		}

		/** Clears the notice and forgets the undo it offered.
		 * @return {void}
		 */
		function hideNotice() {
			clearTimeout(noticeTimer)
			pendingUndo = null
			notice.classList.remove('mcp-notice-on')
			noticeText.textContent = ''
			undoButton.hidden = true
		}

		/** Starts the countdown, unless the admin is on the notice or a bulk change is still running.
		 * @return {void}
		 */
		function armNotice() {
			clearTimeout(noticeTimer)
			if (!bulkBusy && !notice.matches(':hover') && !notice.contains(document.activeElement)) {
				noticeTimer = setTimeout(context.hideNotice, UNDO_SECONDS * 1000)
			}
		}

		/**
		 * Shows a message under the matrix, with an Undo button when an undo is given.
		 * @param {string} message text of the notice
		 * @param {(function(): Promise<void>)|null} undo restores the previous values
		 * @return {void}
		 */
		function showNotice(message, undo) {
			// A newer notice takes the single undo slot; say so instead of dropping the older undo silently.
			const replaced = pendingUndo !== null && pendingUndo !== undo
			pendingUndo = undo
			noticeText.textContent = replaced ? message + ' ' + t('mcp', 'The previous undo is no longer available.') : message
			undoButton.hidden = undo === null
			notice.classList.add('mcp-notice-on')
			context.armNotice()
		}

		/** Bind undo, notice dismissal and pointer and focus holds on the undo expiry timer.
		 * @return {void}
		 */
		function attachNotices() {
			notice.addEventListener('mouseenter', () => clearTimeout(noticeTimer))
			notice.addEventListener('focusin', () => clearTimeout(noticeTimer))
			notice.addEventListener('mouseleave', () => notice.classList.contains('mcp-notice-on') && context.armNotice())
			notice.addEventListener('focusout', (event) => {
				if (notice.classList.contains('mcp-notice-on') && !notice.contains(event.relatedTarget)) {
					context.armNotice()
				}
			})
			undoButton.addEventListener('click', () => {
				const undo = pendingUndo
				if (!undo || bulkBusy) {
					return
				}
				// The button disappears, so the focus moves to the notice text, which announces the outcome
				// and stays on screen while it has the focus.
				pendingUndo = null
				clearTimeout(noticeTimer)
				undoButton.hidden = true
				noticeText.focus({ preventScroll: true })
				undo()
			})

		}

		Object.defineProperties(context, {
			UNDO_SECONDS: { configurable: true, get: () => UNDO_SECONDS },
			notice: { configurable: true, get: () => notice },
			noticeText: { configurable: true, get: () => noticeText },
			undoButton: { configurable: true, get: () => undoButton },
			noticeTimer: { configurable: true, get: () => noticeTimer, set: (value) => { noticeTimer = value } },
			pendingUndo: { configurable: true, get: () => pendingUndo, set: (value) => { pendingUndo = value } },
			bulkBusy: { configurable: true, get: () => bulkBusy, set: (value) => { bulkBusy = value } },
		})

		Object.assign(context, { armNotice, attachNotices, hideNotice, setBulkBusy, showNotice })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
