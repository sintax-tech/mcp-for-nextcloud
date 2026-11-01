/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Matrix loading operations.
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
	admin.modules.matrix.loading = function (context) {
		let loadSeq = 0


		/**
		 * Reads the current page from the server and re-renders the table; a failure is shown in the table with a retry.
		 * The page and its revision always come from the same response, and only the newest request is rendered.
		 * @return {Promise<*>} operation result
		 */
		async function load() {
			const seq = ++loadSeq
			context.table.setAttribute('aria-busy', 'true')
			context.table.classList.add('mcp-loading')
			const query = new URLSearchParams({ search: context.state.search, group: context.state.group, filter: context.state.filter, page: String(context.state.page) })
			try {
				const data = await context.api('GET', '/api/grants?' + query.toString())
				if (seq !== loadSeq) {
					return
				}
				context.state.data = data
				context.render()
			} catch (e) {
				if (seq !== loadSeq) {
					return
				}
				context.renderError()
				context.notifyError(t('mcp', 'Could not load the users.'))
			} finally {
				if (seq === loadSeq) {
					context.table.setAttribute('aria-busy', 'false')
					context.table.classList.remove('mcp-loading')
				}
			}
		}


		/** Replaces the body with an error row and a retry button, keeping the last header.
		 * @return {*} operation result
		 */
		function renderError() {
			const retry = context.el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: context.load })
			const cell = context.el('td', { colSpan: context.state.data ? context.FIXED_COLUMNS + context.visiblePermissionColumns() : 1, className: 'mcp-empty mcp-load-error' },
				[t('mcp', 'Could not load the users.'), ' ', retry])
			context.table.tBodies[0].replaceChildren(context.el('tr', {}, [cell]))
			context.applyAppFilter()
		}


		/** Fills the group filter once, keeping the current selection.
		 * @return {*} operation result
		 */
		function renderGroups() {
			if (context.state.groupsLoaded) {
				return
			}
			for (const group of context.state.data.groups) {
				context.groupSelect.append(context.el('option', { value: group.id, textContent: group.displayName }))
			}
			context.state.groupsLoaded = true
		}


		/** Populate app choices from the actual catalog, preserving a valid selection.
		 * @return {*} operation result
		 */
		function renderApps() {
			const labels = context.moduleLabels()
			const modules = Object.keys(context.state.data.catalog)
			if (!modules.includes(context.state.app)) {
				const stale = context.state.app !== ''
				context.state.app = ''
				if (stale) {
					// An address naming an unknown app is corrected in place, without a new history entry.
					context.writeAppToHash(true)
				}
			}
			context.appSelect.replaceChildren(context.el('option', { value: '', textContent: t('mcp', 'All apps') }),
				...modules.map((module) => context.el('option', { value: module, textContent: labels[module] || module })))
			context.appSelect.value = context.state.app
			context.appSelect.disabled = false
		}

		Object.defineProperties(context, {
			loadSeq: { configurable: true, get: () => loadSeq, set: (value) => { loadSeq = value } },
		})

		Object.assign(context, { load, renderApps, renderError, renderGroups })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
