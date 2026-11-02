/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the inspector drawer unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/** Attach inspector placement, opening, closing and focus restoration.
	 * @param {object} context inspector controls, live request sequences and attached presentation operations
	 * @return {void}
	 */
	admin.modules.inspector.drawer = function (context) {
		const drawerTitle = document.getElementById('mcp-inspector-title')
		const closeButton = document.getElementById('mcp-inspector-close')
		const entryButton = document.getElementById('mcp-inspector-entry')
		let returnFocus = null
		let returnRow = null

		/**
		 * Puts the open drawer right under the Nextcloud header. A drawer is position: fixed, but Nextcloud 35 blurs the content
		 * behind it (backdrop-filter on #content-vue), which makes that box, not the window, the one it is placed in: its top
		 * would then be counted from the box, one header too low. The offset is measured, not assumed, since a theme may turn
		 * the blur off.
		 * @return {void}
		 */
		function placeDrawer() {
			context.root.style?.removeProperty?.('top')
			const rect = context.root.getBoundingClientRect?.()
			if (!rect) return
			const header = document.getElementById('header')?.getBoundingClientRect?.().bottom ?? 0
			const shift = rect.top - header
			if (Math.abs(shift) >= 1) context.root.style.top = (parseFloat(getComputedStyle(context.root).top) - shift) + 'px'
		}

		/**
		 * Opens the drawer; the focus goes back to `trigger` when it closes. Only one drawer is open at a time: when the
		 * administrator keeps a draft of the resource scope, its drawer stays and this one does not open.
		 * @return {boolean} whether the drawer opened
		 * @param {HTMLElement|null|undefined} trigger control that opened the drawer or popover and may receive focus again
		 */
		function openDrawer(trigger) {
			if (window.OCA.Mcp.Admin.scope?.close?.() === false) {
				trigger?.focus?.()
				return false
			}
			returnFocus = trigger || document.activeElement || null
			// The row of a "…" button that opened the drawer: the table may be drawn again meanwhile, and its new button is
			// the one that gets the focus back.
			returnRow = returnFocus?.closest?.('tr')?.dataset?.uid ?? null
			context.root.classList?.add('mcp-drawer-open')
			context.root.setAttribute('role', 'dialog')
			context.placeDrawer()
			return true
		}

		/**
		 * Closes the drawer and gives the focus back. The inspector only reads, so nothing can be lost.
		 * @return {boolean} always true: the drawer is closed now
		 */
		function closeDrawer() {
			if (!context.root.classList?.contains('mcp-drawer-open')) return true
			context.root.classList.remove('mcp-drawer-open')
			context.root.removeAttribute?.('role')
			context.root.style?.removeProperty?.('top')
			const target = returnFocus
			returnFocus = null
			context.focusBack(target)
			window.dispatchEvent?.(new Event('mcp-drawer-closed'))
			return true
		}

		/**
		 * Gives the focus back to the control that opened the drawer, or, when the table was drawn again and that control is
		 * gone, to the same row's "…" button, then to the drawer's own button on the Access tab: never to the page.
		 * @param {HTMLElement|null|undefined} target control to receive focus
		 * @return {void}
		 */
		function focusBack(target) {
			if (target && target.isConnected !== false) {
				target.focus?.()
				return
			}
			const rows = [...(document.getElementById('mcp-matrix')?.tBodies?.[0]?.rows || [])]
			const row = returnRow === null ? null : rows.find((candidate) => candidate.dataset.uid === returnRow)
			;(row?.querySelector('button.mcp-row-actions-button') || entryButton)?.focus?.()
		}

		Object.defineProperties(context, {
			drawerTitle: { configurable: true, get: () => drawerTitle },
			closeButton: { configurable: true, get: () => closeButton },
			entryButton: { configurable: true, get: () => entryButton },
			returnFocus: { configurable: true, get: () => returnFocus, set: (value) => { returnFocus = value } },
			returnRow: { configurable: true, get: () => returnRow, set: (value) => { returnRow = value } },
		})

		Object.assign(context, { placeDrawer, openDrawer, closeDrawer, focusBack })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
