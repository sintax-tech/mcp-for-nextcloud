/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the overrides editor and start its original effects once.
 * @param {object} admin operation input
 * @return {void} operation result
 */
(function (admin) {
	'use strict'
	const matrix = window.OCA.Mcp.Admin.matrix
	const table = document.getElementById('mcp-matrix')
	// The toolbar goes before the table's wrapper, or before the table itself when the wrapper is missing. With no
	// attached anchor it would be created outside the page and every message and the preview would be lost silently,
	// so nothing is installed at all: the matrix keeps working without the reset.
	const anchor = table?.closest('.mcp-matrix-wrap') || table
	if (!matrix || !table || typeof anchor.before !== 'function' || anchor.isConnected === false) {
		return
	}


	const context = { matrix, table, anchor }
	admin.sessions.overrides = context
	admin.modules.overrides.session(context)
	admin.modules.overrides.planning(context)
	admin.modules.overrides.decoration(context)
	admin.modules.overrides.preview(context)
	admin.modules.overrides.review(context)
	admin.modules.overrides.saving(context)
	admin.modules.overrides.selection(context)
	window.OCA.Mcp.Admin.overrides = { decorateRow: context.decorateRow, decorateHeader: context.decorateHeader, decorateCell: context.decorateCell, plan: context.plan, reviewCells: context.reviewCells, resetUser: context.resetUser, hasOverrides: context.hasOverrides }
	// The matrix may have been drawn before this file ran: draw it again so its rows carry the controls.
	context.matrix.refresh()
})((globalThis.window || globalThis).OCA.Mcp.Admin)
