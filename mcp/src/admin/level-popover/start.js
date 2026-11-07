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
	admin.modules.levelPopover.session(context)
	admin.modules.levelPopover.planning(context)
	admin.modules.levelPopover.wording(context)
	admin.modules.levelPopover.rendering(context)
	admin.modules.levelPopover.saving(context)
	admin.modules.levelPopover.entry(context)

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
