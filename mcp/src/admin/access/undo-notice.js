// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createScope } from '../../platform/lifecycle.js'
export function createUndoNotice({ root, t, isBusy }) {
	const scope = createScope()
	const UNDO_SECONDS = 10
	const notice = root.querySelector('#mcp-bulk-notice')
	const noticeText = root.querySelector('#mcp-bulk-notice-text')
	const undoButton = root.querySelector('#mcp-bulk-undo')
	let noticeTimer = null
	let pendingUndo = null
	function hideNotice() {
		scope.clearTimeout(noticeTimer)
		pendingUndo = null
		notice.classList.remove('mcp-notice-on')
		noticeText.textContent = ''
		undoButton.hidden = true
	}
	function armNotice() {
		scope.clearTimeout(noticeTimer)
		if (!isBusy() && !notice.matches(':hover') && !notice.contains(document.activeElement)) {
			noticeTimer = scope.timeout(hideNotice, UNDO_SECONDS * 1000)
		}
	}
	function showNotice(message, undo) {
		// A newer notice takes the single undo slot; say so instead of dropping the older undo silently.
		const replaced = pendingUndo !== null && pendingUndo !== undo
		pendingUndo = undo
		noticeText.textContent = replaced ? message + ' ' + t('mcp', 'The previous undo is no longer available.') : message
		undoButton.hidden = undo === null
		notice.classList.add('mcp-notice-on')
		armNotice()
	}
	scope.on(notice, 'mouseenter', () => scope.clearTimeout(noticeTimer))
	scope.on(notice, 'focusin', () => scope.clearTimeout(noticeTimer))
	scope.on(notice, 'mouseleave', () => notice.classList.contains('mcp-notice-on') && armNotice())
	scope.on(notice, 'focusout', (event) => {
		if (notice.classList.contains('mcp-notice-on') && !notice.contains(event.relatedTarget)) {
			armNotice()
		}
	})
	scope.on(undoButton, 'click', () => {
		const undo = pendingUndo
		if (!undo || isBusy()) {
			return
		}
		// The button disappears, so the focus moves to the notice text, which announces the outcome
		// and stays on screen while it has the focus.
		pendingUndo = null
		scope.clearTimeout(noticeTimer)
		undoButton.hidden = true
		noticeText.focus({ preventScroll: true })
		undo()
	})
    function setBusy(on) {
        undoButton.disabled = on
        undoButton.setAttribute('aria-disabled', on ? 'true' : 'false')
        undoButton.title = on ? t('mcp', 'Wait until the change in progress is saved.') : ''
        if (on) scope.clearTimeout(noticeTimer)
        else if (notice.classList.contains('mcp-notice-on')) armNotice()
    }
    scope.use(hideNotice)
    return { show: showNotice, setBusy, focusUndo(undo) { if (undo !== null && pendingUndo === undo) undoButton.focus() }, destroy: scope.destroy }
}
