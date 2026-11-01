/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the inspector editor and start its original effects once.
 * @param {object} admin operation input
 * @return {void} operation result
 */
(function (admin) {
	'use strict'
	const root = document.getElementById('mcp-inspector')
	if (!root) return

	const context = { root }
	admin.sessions.inspector = context
	admin.modules.inspector.session(context)
	admin.modules.inspector.wording(context)
	admin.modules.inspector.verdict(context)
	admin.modules.inspector.rendering(context)
	admin.modules.inspector.inspection(context)
	context.form.addEventListener('submit', (event) => { event.preventDefault(); context.inspect() })
	admin.modules.inspector.drawer(context)
	context.entryButton?.addEventListener('click', () => {
		if (context.openDrawer(context.entryButton)) context.user.focus?.()
	})
	context.closeButton?.addEventListener('click', () => context.closeDrawer())
	context.root.addEventListener?.('keydown', (event) => {
		if (event.key === 'Escape' && context.root.classList?.contains('mcp-drawer-open')) {
			event.preventDefault?.()
			context.closeDrawer()
		}
	})

	// The actions menu of a matrix row inspects that user (src/admin/admin-row-actions.js), in the drawer.
	window.OCA.Mcp.Admin.inspector = {
		open(target, trigger) {
			if (!context.openDrawer(trigger)) return false
			context.user.value = target
			context.drawerTitle?.focus?.()
			context.inspect()
			return true
		},
		close: context.closeDrawer,
	}
	context.pathForm.addEventListener('submit', (event) => {
		event.preventDefault()
		const path = context.pathInput.value.trim()
		if (context.inspected === null || path === '') return
		context.explain('path', context.pathResult, '/api/inspector/path', { path: path.startsWith('/') ? path : '/' + path }, 'files')
	})
	context.calendarModule.addEventListener('change', () => { if (context.inspected !== null) context.loadCalendars() })
	context.calendarForm.addEventListener('submit', (event) => {
		event.preventDefault()
		if (context.inspected === null || context.calendarSelect.value === '') return
		context.explain('calendar', context.calendarResult, '/api/inspector/calendar', { module: context.calendarModule.value, calendarId: context.calendarSelect.value, operation: context.calendarOperation.value }, context.calendarModule.value)
	})
})((globalThis.window || globalThis).OCA.Mcp.Admin)
