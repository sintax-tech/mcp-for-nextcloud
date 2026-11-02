/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** level_popover entry operations.
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
	admin.modules.levelPopover.entry = function (context) {
		/** Places the popover under the cell it belongs to, inside the matrix block, and brings all of it into view.
		 * @param {*} trigger operation input
		 * @return {*} operation result
		 */
		function place(trigger) {
			const block = context.popover.offsetParent || context.popover.parentElement
			const box = block.getBoundingClientRect()
			const rect = trigger.getBoundingClientRect()
			const width = context.popover.offsetWidth || 360
			context.popover.style.top = (rect.bottom - box.top + 4) + 'px'
			context.popover.style.left = Math.max(0, Math.min(rect.left - box.left, box.width - width)) + 'px'
			// Its height is capped (js/admin.css) and it scrolls inside: the page only moves as far as showing all of it takes.
			context.popover.scrollIntoView?.({ block: 'nearest', inline: 'nearest' })
		}

		/**
		 * Opens the popover of one cell.
		 *
		 * @param {HTMLElement} trigger the level button of the cell
		 * @param {string} uid user of the row
		 * @param {string|null} module module id, null for "Can connect"
		 * @return {*} operation result
		 */
		function open(trigger, uid, module) {
			if (context.current !== null) {
				const same = context.current.uid === uid && context.current.module === module
				context.close(false)
				if (same) {
					trigger.focus()
					return
				}
			}
			context.current = { uid, module, trigger, inherited: null, loading: true, unavailable: false, saving: false, operationsOpen: false }
			trigger.setAttribute('aria-expanded', 'true')
			context.render()
			context.popover.hidden = false
			context.place(trigger)
			;(context.popover.querySelector('input:checked') || context.popover.querySelector('input'))?.focus()
			context.loadInherited(context.current)
		}

		/** @param {boolean} restoreFocus give the focus back to the cell that opened the popover
		 * @return {*} operation result
		 */
		function close(restoreFocus) {
			if (context.current === null) {
				return
			}
			const trigger = context.current.trigger
			context.current = null
			context.popover.hidden = true
			context.popover.replaceChildren()
			trigger?.setAttribute('aria-expanded', 'false')
			if (restoreFocus && trigger?.isConnected) {
				trigger.focus()
			}
			window.dispatchEvent?.(new Event('mcp-level-popover-closed'))
		}

		Object.assign(context, { place, open, close })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
