/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the scope drawer unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/** Attach drawer placement, module tabs, draft confirmation and focus restoration.
	 * @param {object} context scope drawer controls, live module drafts and attached resource operations
	 * @return {void}
	 */
	admin.modules.scope.drawer = function (context) {
		/** Describe a module's saved scope and indicate whether its draft is unsaved.
		 * @return {string} the tab of a module: its saved state, and whether a draft is not saved
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 */
		function tabLabel(state) {
			const name = { files: t('mcp', 'Files'), calendar: t('mcp', 'Calendar'), tasks: t('mcp', 'Tasks') }[state.module]
			const saved = state.savedMode === 'restricted'
				? t('mcp', '{module}: {count}', { module: name, count: state.savedItems.length }, undefined, { escape: false })
				: t('mcp', '{module}: all', { module: name }, undefined, { escape: false })
			return context.dirty(state) ? saved + ' (' + t('mcp', 'not saved') + ')' : saved
		}

		/** The module tabs of the drawer (WAI-ARIA tabs: the arrow keys, Home and End move between them).
		 * @return {void}
		 */
		function renderTabs() {
			if (!context.tabs) return
			const shown = context.MODULES.filter((module) => context.modules[module])
			context.tabs.replaceChildren(...shown.map((module, index) => {
				const selected = module === context.currentTab
				const tab = context.el('button', { type: 'button', id: 'mcp-scope-tab-' + module, textContent: context.tabLabel(context.modules[module]), tabIndex: selected ? 0 : -1 })
				tab.setAttribute('role', 'tab')
				tab.setAttribute('aria-selected', String(selected))
				tab.setAttribute('aria-controls', 'mcp-scope-module-' + module)
				tab.addEventListener('click', () => context.selectTab(module, true))
				tab.addEventListener('keydown', (event) => {
					const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: shown.length - 1 }[event.key]
					if (next === undefined) return
					event.preventDefault?.()
					context.selectTab(shown[(next + shown.length) % shown.length], true)
				})
				return tab
			}))
		}

		/** Shows one module's section and marks its tab.
		 * @param {string} module catalog module ID
		 * @param {boolean} focus whether to focus the selected module tab
		 * @return {void}
		 */
		function selectTab(module, focus) {
			context.currentTab = module
			for (const other of context.MODULES) {
				const section = document.getElementById('mcp-scope-module-' + other)
				if (section) section.hidden = Boolean(context.tabs) && other !== module
			}
			context.renderTabs()
			if (focus) document.getElementById('mcp-scope-tab-' + module)?.focus?.()
		}

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

		/** Opens the drawer; the focus goes back to `trigger` when it closes.
		 * @param {HTMLElement|null|undefined} trigger control that opened the drawer or popover and may receive focus again
		 * @return {void}
		 */
		function openDrawer(trigger) {
			// Only one drawer is open at a time: the inspector closes when the scope opens.
			window.OCA.Mcp.Admin.inspector?.close?.()
			context.returnFocus = trigger || document.activeElement || null
			// The row of a "…" button that opened the drawer: the table may be drawn again meanwhile, and its new button is
			// the one that gets the focus back.
			context.returnRow = context.returnFocus?.closest?.('tr')?.dataset?.uid ?? null
			context.root.classList?.add('mcp-drawer-open')
			context.root.setAttribute('role', 'dialog')
			context.placeDrawer()
		}

		/** Whether a module has a draft that is not saved.
		 * @return {boolean} whether a module has a draft that is not saved */
		function hasDraft() {
			return Object.values(context.modules).some(context.dirty)
		}

		/** Drops the drafts the administrator agreed to lose, so they never come back when the drawer opens again.
		 * @return {void}
		 */
		function forget() {
			context.seq++
			context.uid = null
			context.modules = {}
			context.tabs?.replaceChildren()
			context.container.replaceChildren()
		}

		/**
		 * Closes the drawer, asking first when a draft of a module would be lost.
		 * @return {boolean} whether the drawer is closed now: false when the administrator kept the draft
		 */
		function closeDrawer() {
			if (!context.root.classList?.contains('mcp-drawer-open')) return true
			if (context.hasDraft()) {
				if (!window.confirm(t('mcp', 'Close the resource scope? The changes that are not saved are lost.'))) return false
				context.forget()
			}
			context.root.classList.remove('mcp-drawer-open')
			context.root.removeAttribute?.('role')
			context.root.style?.removeProperty?.('top')
			const target = context.returnFocus
			context.returnFocus = null
			context.focusBack(target)
			window.dispatchEvent?.(new Event('mcp-drawer-closed'))
			return true
		}

		/**
		 * Whether the drafts of the open user may be lost to open another one (or the same one read again): asked, never
		 * assumed.
		 * @return {boolean}
		 */
		function mayReplace() {
			return !context.hasDraft() || window.confirm(t('mcp', 'Open another person? The changes that are not saved for {user} are lost.', { user: context.uid }, undefined, { escape: false }))
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
			const row = context.returnRow === null ? null : rows.find((candidate) => candidate.dataset.uid === context.returnRow)
			;(row?.querySelector('button.mcp-row-actions-button') || context.entryButton)?.focus?.()
		}

		Object.assign(context, { closeDrawer, focusBack, forget, hasDraft, mayReplace, openDrawer, placeDrawer, renderTabs, selectTab, tabLabel })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
