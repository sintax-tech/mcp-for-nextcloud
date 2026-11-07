/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the groups editor and start its original effects once.
 * @param {object} admin shared OCA.Mcp.Admin registry and public surfaces
 * @return {void}
 */
(function (admin) {
	'use strict'
	const root = document.getElementById('mcp-group-editor')
	if (!root) return

	const context = { root }
	admin.sessions.groups = context
	admin.modules.groups.unknownGrants(context)
	admin.modules.groups.session(context)
	admin.modules.groups.directory(context)
	admin.modules.groups.drafts(context)
	admin.modules.groups.rendering(context)
	admin.modules.groups.saving(context)
	context.search.addEventListener('input', () => {
		clearTimeout(context.timer)
		context.timer = setTimeout(() => { context.page = 1; context.loadList() }, 300)
	})
	context.prev.addEventListener('click', () => { context.page = Math.max(1, context.page - 1); context.loadList() })
	context.next.addEventListener('click', () => { context.page++; context.loadList() })
	context.loadList()
})((globalThis.window || globalThis).OCA.Mcp.Admin)
