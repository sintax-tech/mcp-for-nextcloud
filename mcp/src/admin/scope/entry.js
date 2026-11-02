/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Resource scope public entry and original listener registration.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/** Publish scope entry points and register revision and drawer interaction handlers.
	 * @param {object} context scope drawer controls, live module drafts and attached resource operations
	 * @return {void}
	 */
	admin.modules.scope.entry = function (context) {
		/**
		 * The matrix cell mark of a module that is restricted, or whose scope cannot be read: visible text, no colour or
		 * icon alone, and a way into the panel. An unrestricted module gets no mark.
		 * @param {string} target uid of the row
		 * @param {string} module scope module
		 * @param {{mode: string, count: ?number}} scope the cell's scope from the matrix
		 * @return {HTMLButtonElement|null}
		 */
		function indicator(target, module, scope) {
			const unknown = scope.mode === 'unavailable'
			if (!unknown && scope.mode !== 'restricted') return null
			const mark = context.el('span', { className: 'mcp-scope-mark', textContent: unknown ? '?' : '◩' })
			mark.setAttribute('aria-hidden', 'true')
			const button = context.el('button', { type: 'button', className: 'mcp-scope-indicator' }, [mark, ' ' + (unknown ? t('mcp', 'Scope unknown') : t('mcp', 'Restricted to {count}', { count: scope.count }, undefined, { escape: false }))])
			button.title = unknown
				? t('mcp', 'The scope cannot be read now, so the tools of this module refuse until it can. Open the resource scope.')
				: t('mcp', 'The MCP tools of this user reach only the selected resources of this module. Open the resource scope.')
			button.addEventListener('click', () => open(target, module, button))
			return button
		}

		/**
		 * Opens the drawer on one user, at one module when given.
		 * @param {string} target user ID
		 * @param {string} [module] module whose tab opens
		 * @param {HTMLElement} [trigger] control the focus goes back to when the drawer closes
		 * @return {boolean}
		 */
		function open(target, module, trigger) {
			if (!context.root) return false
			// The same person in the open drawer: only the tab changes, and the draft stays.
			if (context.root.classList?.contains('mcp-drawer-open') && target === context.uid && Object.keys(context.modules).length > 0) {
				if (module) context.selectTab(module, true)
				return true
			}
			// The drawer is not modal: another person can be opened from the matrix while a draft is open.
			if (!context.mayReplace()) {
				trigger?.focus?.()
				return false
			}
			context.openDrawer(trigger)
			context.userInput.value = target
			context.drawerTitle?.focus?.()
			context.openUser(target, module)
			return true
		}

		window.OCA.Mcp.Admin.scope = { indicator, open, close: context.closeDrawer }
		// A module exactly at the revision a write of this page committed from takes the one it made; its draft stays.
		window.addEventListener(context.REVISION_MOVED, (event) => {
			for (const state of Object.values(context.modules)) {
				if (state.revision === event.detail.from) state.revision = event.detail.to
			}
		})
		// A matrix drawn before this script ran is drawn again, now with the scope marks.
		window.OCA.Mcp.Admin.matrix?.refresh?.()
		if (!context.root) return
		context.entryButton?.addEventListener('click', () => {
			context.openDrawer(context.entryButton)
			context.userInput.focus?.()
		})
		context.closeButton?.addEventListener('click', () => context.closeDrawer())
		context.root.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && context.root.classList?.contains('mcp-drawer-open')) {
				event.preventDefault?.()
				context.closeDrawer()
			}
		})
		context.form.addEventListener('submit', (event) => {
			event.preventDefault()
			const target = context.userInput.value.trim()
			if (target === '') {
				context.say(t('mcp', 'Enter the user ID.'), true)
				return
			}
			if (!context.mayReplace()) return
			context.openUser(target, null)
		})

		Object.assign(context, { indicator, open })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
