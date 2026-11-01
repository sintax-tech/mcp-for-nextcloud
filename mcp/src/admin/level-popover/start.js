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

	admin.modules.level_popover = admin.modules.level_popover || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.level_popover.entry = function (context) {
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
;
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the level popover once.
 * @param {object} admin app registry
 * @return {void} starts the original popover
 */
(function (admin) {
	'use strict'
	const matrix = window.OCA.Mcp.Admin.matrix
	const levels = window.OCA.Mcp.Admin.levels
	const popover = document.getElementById('mcp-level-popover')
	if (!matrix || !levels || !popover) {
		return
	}


	const context = { matrix, levels, popover }
	admin.sessions.levelPopover = context
	admin.modules.level_popover.session(context)
	admin.modules.level_popover.planning(context)
	admin.modules.level_popover.wording(context)
	admin.modules.level_popover.rendering(context)
	admin.modules.level_popover.saving(context)
	admin.modules.level_popover.entry(context)

	// Opening "Operations" makes it taller: it comes back into view whole (toggle does not bubble, so it is caught here).
	context.popover.addEventListener('toggle', () => context.popover.scrollIntoView?.({ block: 'nearest', inline: 'nearest' }), true)
	context.popover.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.preventDefault()
			context.close(true)
		}
	})
	document.addEventListener('pointerdown', (event) => {
		if (context.current !== null && !context.current.saving && !context.popover.contains(event.target) && event.target !== context.current.trigger && !context.current.trigger.contains?.(event.target)) {
			context.close(false)
		}
	})
	// A table drawn again (a reload, another block's write) replaces the cell: the popover would point at a stale one.
	window.addEventListener('mcp-matrix-rendered', () => {
		if (context.current !== null && !context.current.saving) {
			context.close(false)
		}
	})

	window.OCA.Mcp.Admin.levelPopover = { open: context.open, close: context.close, planLevel: context.planLevel, planInherit: context.planInherit, planOperation: context.planOperation, planTarget: context.planTarget, inheritedValues: context.inheritedValues, losesEligibility: context.losesEligibility }

})((globalThis.window || globalThis).OCA.Mcp.Admin)
