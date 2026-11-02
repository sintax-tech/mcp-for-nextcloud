/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Matrix view operations.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.matrix.view = function (context) {
		/** Filter only presentation; user queries and permission payloads stay unchanged.
		 * @return {*} operation result
		 */
		function applyAppFilter() {
			const app = context.shownApp()
			for (const cell of context.table.querySelectorAll('[data-module]')) {
				cell.hidden = app !== '' && cell.dataset.module !== app
				if (cell.hidden) {
					for (const menu of cell.querySelectorAll('details[open]')) {
						menu.open = false
					}
				}
			}
			const permissions = context.visiblePermissionColumns()
			for (const cell of context.table.querySelectorAll('td.mcp-empty')) {
				cell.colSpan = context.FIXED_COLUMNS + permissions
			}
			for (const cell of context.table.querySelectorAll('td.mcp-row-unavailable')) {
				cell.colSpan = permissions
			}
			context.stickSecondHeaderRow()
		}

		/** Read the Access tab's app parameter on reload and history navigation.
		 * @return {*} operation result
		 */
		function readAppFromHash() {
			const [tab, query = ''] = window.location.hash.slice(1).split('?')
			if (tab !== 'access') {
				return
			}
			const params = new URLSearchParams(query)
			const view = params.get('view') === 'operations' ? 'operations' : 'levels'
			context.state.app = params.get('app') || ''
			if (!context.state.data) {
				context.state.view = view
				return
			}
			if (view !== context.state.view) {
				context.state.view = view
				context.render()
				return
			}
			context.renderApps()
			context.applyAppFilter()
		}

		/** @return {boolean} whether the matrix shows one level per module */
		function isLevelsView() {
			return context.state.view === 'levels' && Boolean(window.OCA.Mcp.Admin.levels)
		}

		/** @return {string} the app whose columns are shown alone, '' for all; the levels view always shows every module */
		function shownApp() {
			return context.isLevelsView() ? '' : context.state.app
		}

		/**
		 * Switches between the levels and the operations view, keeping the page, the filters and the data on screen.
		 *
		 * @param {string} view "levels" or "operations"
		 * @param {string} [app] module to show alone in the operations view
		 * @return {*} operation result
		 */
		function setView(view, app) {
			context.state.view = view
			// The levels view shows every module, so the app filter of the operations view leaves the address with it.
			if (view === 'levels') {
				context.state.app = ''
			} else if (app !== undefined) {
				context.state.app = app
			}
			if (context.state.data) {
				context.render()
			}
			context.writeAppToHash(false)
		}

		/**
		 * Writes the chosen app into the address (#access?app=<module>, no parameter for all apps).
		 *
		 * @param {boolean} replace correct the current entry instead of adding a history entry
		 * @return {*} operation result
		 */
		function writeAppToHash(replace) {
			const [tab, query = ''] = window.location.hash.slice(1).split('?')
			if (tab !== 'access') {
				return
			}
			const params = new URLSearchParams(query)
			if (context.state.app === '') {
				params.delete('app')
			} else {
				params.set('app', context.state.app)
			}
			if (context.state.view === 'operations') {
				params.set('view', 'operations')
			} else {
				params.delete('view')
			}
			const next = '#access' + (params.toString() ? '?' + params.toString() : '')
			if (next === window.location.hash) {
				return
			}
			if (replace) {
				window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + next)
			} else {
				window.location.hash = next
			}
		}

		/** Install this unit’s original listeners once, at its historical startup phase.
		 * @return {*} operation result
		 */
		function attachView() {
			context.appSelect.addEventListener('change', () => {
				context.state.app = context.appSelect.value
				context.applyAppFilter()
				context.writeAppToHash(false)
			})
			for (const [view, button] of Object.entries(context.viewButtons)) {
				button?.addEventListener('click', () => {
					if (context.state.view !== view) {
						context.setView(view)
					}
				})
			}
			window.addEventListener('hashchange', context.readAppFromHash)
			context.readAppFromHash()

		}

		Object.assign(context, { applyAppFilter, attachView, isLevelsView, readAppFromHash, setView, shownApp, writeAppToHash })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
