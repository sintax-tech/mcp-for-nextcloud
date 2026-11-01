/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Initial matrix controls and saved page state.
 * @param {object} context live bindings of this editor
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	admin.modules.matrix.session = function (context) {
		const root = context.root
		/** Columns the matrix always shows besides the permissions: selection, user, "Can connect" and the row's actions. */
		const FIXED_COLUMNS = 4
		/**
		 * view is "levels" (one access level per module, src/admin/admin-levels.js) or "operations" (a box per operation); app filters
		 * the operations view only. Both live in the address as #access?view=operations&app=<module>.
		 */
		const state = { search: '', group: '', filter: 'eligible', page: 1, app: '', view: 'levels', exceptionsOnly: false, data: null, groupsLoaded: false }
		const table = document.getElementById('mcp-matrix')
		const searchInput = document.getElementById('mcp-search')
		const groupSelect = document.getElementById('mcp-group')
		const filterSelect = document.getElementById('mcp-filter')
		const appSelect = document.getElementById('mcp-app')
		const appFilter = document.getElementById('mcp-app-filter')
		const viewButtons = { levels: document.getElementById('mcp-view-levels'), operations: document.getElementById('mcp-view-operations') }
		const legends = { levels: document.getElementById('mcp-legend-levels'), operations: document.getElementById('mcp-legend-operations') }
		const countInfo = document.getElementById('mcp-count')
		// The pager sits under the table only; the count of users stays above it.
		const prevButtons = [document.getElementById('mcp-prev-bottom')].filter(Boolean)
		const nextButtons = [document.getElementById('mcp-next-bottom')].filter(Boolean)
		const pageInfos = [document.getElementById('mcp-page-info-bottom')].filter(Boolean)
		const onlyButtons = { all: document.getElementById('mcp-only-all'), exceptions: document.getElementById('mcp-only-exceptions') }
		const serviceBox = document.getElementById('mcp-service')
		const serviceStatus = document.getElementById('mcp-service-status')

		const notice = document.getElementById('mcp-bulk-notice')
		const noticeText = document.getElementById('mcp-bulk-notice-text')
		const undoButton = document.getElementById('mcp-bulk-undo')

		Object.defineProperties(context, {
			root: { configurable: true, get: () => root },
			FIXED_COLUMNS: { configurable: true, get: () => FIXED_COLUMNS },
			state: { configurable: true, get: () => state },
			table: { configurable: true, get: () => table },
			searchInput: { configurable: true, get: () => searchInput },
			groupSelect: { configurable: true, get: () => groupSelect },
			filterSelect: { configurable: true, get: () => filterSelect },
			appSelect: { configurable: true, get: () => appSelect },
			appFilter: { configurable: true, get: () => appFilter },
			viewButtons: { configurable: true, get: () => viewButtons },
			legends: { configurable: true, get: () => legends },
			countInfo: { configurable: true, get: () => countInfo },
			prevButtons: { configurable: true, get: () => prevButtons },
			nextButtons: { configurable: true, get: () => nextButtons },
			pageInfos: { configurable: true, get: () => pageInfos },
			onlyButtons: { configurable: true, get: () => onlyButtons },
			serviceBox: { configurable: true, get: () => serviceBox },
			serviceStatus: { configurable: true, get: () => serviceStatus },
			notice: { configurable: true, get: () => notice },
			noticeText: { configurable: true, get: () => noticeText },
			undoButton: { configurable: true, get: () => undoButton },
		})
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
