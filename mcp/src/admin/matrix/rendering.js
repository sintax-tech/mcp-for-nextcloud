/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix rendering unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach table rendering and sticky header measurement to the matrix context.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.rendering = function (context) {
		let observedHeaderRow = null
		let headerObserver = null

		/** Makes the second header row stick right below the first one, whose height depends on the density.
		 * @return {void}
		 */
		function stickSecondHeaderRow() {
			const [top, sub] = context.table.tHead.rows
			if (!top || !sub) {
				return
			}
			const offset = top.getBoundingClientRect().height
			for (const cell of sub.cells) {
				cell.style.top = offset + 'px'
			}
		}

		/**
		 * Re-measures the first header row whenever it resizes. The Access panel starts hidden, so the
		 * render there measures zero; showing the tab (or changing density or font size) resizes the row
		 * and the observer re-sticks the second row below it. A browser without ResizeObserver keeps the
		 * render-time measure, which is correct whenever the panel is visible.
		 * @return {void}
		 */
		function observeHeaderRow() {
			if (!window.ResizeObserver) {
				return
			}
			const top = context.table.tHead?.rows?.[0]
			if (!top || top === observedHeaderRow) {
				return
			}
			if (!headerObserver) {
				headerObserver = new window.ResizeObserver(context.stickSecondHeaderRow)
			}
			headerObserver.disconnect()
			observedHeaderRow = top
			headerObserver.observe(top)
		}

		/** Renders header and rows from state.data.
		 * @return {void}
		 */
		function render() {
			const data = context.state.data
			// After a conflict the saved switch may differ from what this page last showed.
			if (!context.serviceBox.disabled) {
				context.serviceBox.checked = data.serviceEnabled
			}
			context.renderGroups()
			context.renderApps()
			const modules = context.moduleLabels()
			const operations = context.operationLabels()
			const levelsView = context.isLevelsView()
			for (const [view, button] of Object.entries(context.viewButtons)) {
				button?.setAttribute('aria-pressed', String(view === (levelsView ? 'levels' : 'operations')))
			}
			for (const [view, legend] of Object.entries(context.legends)) {
				if (legend) legend.hidden = view !== (levelsView ? 'levels' : 'operations')
			}
			if (context.appFilter) {
				context.appFilter.hidden = levelsView
			}
			context.table.classList.toggle('mcp-levels', levelsView)

			const top = context.el('tr')
			const sub = context.el('tr')
			const rowSpan = levelsView ? 1 : 2
			const selectHeader = context.el('th', { rowSpan, className: 'mcp-select-col', scope: 'col' }, [context.el('span', { className: 'mcp-sr-only', textContent: t('mcp', 'Select') })])
			// The "select every user on this page" box of src/admin/overrides/start.js heads the selection column.
			window.OCA.Mcp.Admin.overrides?.decorateHeader?.(selectHeader)
			top.append(selectHeader)
			top.append(context.el('th', { rowSpan, className: 'mcp-user-col', scope: 'col', textContent: t('mcp', 'User') }))
			const canConnect = t('mcp', 'Can connect')
			top.append(context.el('th', { rowSpan, scope: 'col', title: t('mcp', 'The administrator allows this user to connect an MCP client'), textContent: canConnect }))
			Object.entries(data.catalog).forEach(([module, ops], index) => {
				const band = 'mcp-band-' + (index % 2)
				const name = modules[module] || module
				const header = context.el('th', { colSpan: levelsView ? 1 : ops.length, scope: levelsView ? 'col' : 'colgroup', className: 'mcp-module ' + band, textContent: name })
				header.dataset.module = module
				top.append(header)
				if (levelsView) {
					return
				}
				ops.forEach((operation, i) => {
					const cell = context.el('th', {
						scope: 'col',
						className: band + (i === 0 ? ' mcp-module' : ''),
						title: context.operationHint(module, operation),
						textContent: operations[operation] || operation,
					})
					cell.dataset.module = module
					sub.append(cell)
				})
			})
			top.append(context.el('th', { rowSpan, scope: 'col', className: 'mcp-actions-col' }, [context.el('span', { className: 'mcp-sr-only', textContent: t('mcp', 'Actions') })]))
			context.table.tHead.replaceChildren(...(levelsView ? [top] : [top, sub]))
			if (context.bulkBusy) {
				context.setBulkBusy(true)
			}
			context.stickSecondHeaderRow()
			context.observeHeaderRow()

			const rows = data.users.map(context.renderRow)
			if (rows.length === 0) {
				const columns = context.FIXED_COLUMNS + (levelsView ? Object.keys(data.catalog).length : Object.values(data.catalog).reduce((sum, ops) => sum + ops.length, 0))
				rows.push(context.el('tr', {}, [context.el('td', { colSpan: columns, className: 'mcp-empty', textContent: t('mcp', 'No users found.') })]))
			}
			context.table.tBodies[0].replaceChildren(...rows)
			context.applyAppFilter()
			context.applyExceptionsFilter()

			const pageText = data.total === null
				? t('mcp', 'Page {page}', { page: data.page })
				: t('mcp', 'Page {page} of {pages}', { page: data.page, pages: Math.max(1, Math.ceil(data.total / data.pageSize)) })
			for (const button of context.prevButtons) {
				button.disabled = data.page <= 1
			}
			for (const button of context.nextButtons) {
				button.disabled = !data.hasMore
			}
			for (const info of context.pageInfos) {
				info.textContent = pageText
			}
			const from = (data.page - 1) * data.pageSize + 1
			const to = from + data.users.length - 1
			context.countInfo.textContent = data.users.length === 0
				? ''
				: (data.total === null
					? t('mcp', 'Users {from}–{to}', { from, to })
					: t('mcp', 'Users {from}–{to} of {total}', { from, to, total: data.total }))
			window.dispatchEvent(new Event('mcp-matrix-rendered'))
		}

		Object.defineProperties(context, {
			observedHeaderRow: { configurable: true, get: () => observedHeaderRow, set: (value) => { observedHeaderRow = value } },
			headerObserver: { configurable: true, get: () => headerObserver, set: (value) => { headerObserver = value } },
		})

		Object.assign(context, { observeHeaderRow, render, stickSecondHeaderRow })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
