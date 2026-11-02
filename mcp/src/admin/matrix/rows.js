/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Matrix rows operations.
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
	admin.modules.matrix.rows = function (context) {
		const OWN_SOURCES = ['individual_allow', 'individual_deny', 'invalid']

		/**
		 * @param {object} user row from the API
		 * @return {HTMLTableRowElement}
		 */
		function renderRow(user) {
			const row = context.buildRow(user)
			window.OCA.Mcp.Admin.overrides?.decorateRow(row, user)
			return row
		}

		/**
		 * @param {object} user row from the API
		 * @return {HTMLTableRowElement}
		 */
		function buildRow(user) {
			const row = context.el('tr', { className: user.enabled ? '' : 'mcp-disabled-user' })
			row.dataset.uid = user.uid
			// The selection box of src/admin/overrides/start.js goes here, in its own column instead of next to the name.
			row.append(context.el('td', { className: 'mcp-select-col' }))
			row.append(context.el('th', { scope: 'row', className: 'mcp-user-col' }, [context.el('span', { className: 'mcp-name', textContent: user.displayName }), context.userLine(user)]))
			row.append(context.isLevelsView() ? context.eligibleCell(user) : context.permissionCell(user, null, user.eligible, t('mcp', 'Can connect'), { eligible: null }))
			const rowReason = context.rowUnavailableReason(user)
			if (rowReason !== null) {
				// One notice instead of a mark in every column; the saved permissions come back with "Can connect".
				const notice = context.el('td', { className: 'mcp-cell mcp-row-unavailable', colSpan: context.visiblePermissionColumns(), textContent: rowReason })
				notice.title = user.enabled
					? t('mcp', 'The saved permissions are kept and shown again when “Can connect” is on.')
					: t('mcp', 'The saved permissions are kept and shown again when the account is enabled and “Can connect” is on.')
				row.append(notice)
				row.append(context.actionsCell(user))
				return row
			}
			const modules = context.moduleLabels()
			const operations = context.operationLabels()
			if (context.isLevelsView()) {
				Object.entries(context.state.data.catalog).forEach(([module, ops], index) => row.append(context.levelCell(user, module, ops, index)))
				row.append(context.actionsCell(user))
				return row
			}
			Object.entries(context.state.data.catalog).forEach(([module, ops], index) => {
				ops.forEach((operation, i) => {
					// The same name as the column header and the reset button, never the raw id ("files:read").
					const label = (modules[module] || module) + ': ' + (operations[operation] || operation)
					const cell = context.permissionCell(user, module, user.grants[module][operation], label, { module, operation, granted: null })
					cell.dataset.module = module
					cell.hidden = context.state.app !== '' && context.state.app !== module
					cell.classList.add('mcp-band-' + (index % 2))
					if (i === 0) {
						cell.classList.add('mcp-module')
						// "Restricted to N" or "Scope unknown" of a resource scope, drawn and opened by src/admin/scope/start.js.
						const mark = user.scope?.[module] && window.OCA.Mcp.Admin.scope ? window.OCA.Mcp.Admin.scope.indicator(user.uid, module, user.scope[module]) : null
						if (mark) cell.append(mark)
					}
					row.append(cell)
				})
			})
			row.append(context.actionsCell(user))
			return row
		}

		/**
		 * The second line under a name: "Account disabled", "Connected" (with its mark, in the success colour as text) or, for
		 * everyone else, the user ID. Being connected is a fact worth seeing; not being connected is the normal case and says
		 * nothing more than the ID.
		 *
		 * @param {object} user row from the API
		 * @return {HTMLElement}
		 */
		function userLine(user) {
			if (!user.enabled) {
				return context.el('span', { className: 'mcp-uid mcp-user-disabled', textContent: t('mcp', 'Account disabled') })
			}
			if (user.eligible && user.connected) {
				const mark = context.el('span', { className: 'mcp-connected-mark', textContent: '●' })
				mark.setAttribute('aria-hidden', 'true')
				const line = context.el('span', { className: 'mcp-uid mcp-user-connected' }, [mark, ' ', t('mcp', 'Connected')])
				line.title = user.uid
				return line
			}
			return context.el('span', { className: 'mcp-uid', textContent: user.uid })
		}

		/** @return {HTMLTableCellElement} the cell with the row's actions menu (src/admin/admin-row-actions.js)
		 * @param {*} user operation input
		 */
		function actionsCell(user) {
			const cell = context.el('td', { className: 'mcp-actions-col' })
			const button = window.OCA.Mcp.Admin.rowActions?.button(user)
			if (button) cell.append(button)
			return cell
		}

		/**
		 * Whether a user has a real exception on the page (src/admin/admin-exceptions.js): an individual value that differs from what
		 * they would inherit. null while that is not known.
		 *
		 * @param {object} user row from the API
		 * @return {boolean|null}
		 */
		function hasException(user) {
			const exceptions = window.OCA.Mcp.Admin.exceptions
			if (!exceptions) return null
			const cells = []
			if (OWN_SOURCES.includes(user.origins?.eligible?.source)) cells.push([null, 'eligible'])
			for (const [module, operations] of Object.entries(user.origins?.grants || {})) {
				for (const [operation, origin] of Object.entries(operations)) {
					if (OWN_SOURCES.includes(origin?.source)) cells.push([module, operation])
				}
			}
			const kinds = cells.map(([module, operation]) => exceptions.kindOf(user.uid, module, operation).kind)
			if (kinds.includes('exception')) return true
			return kinds.includes('pending') ? null : false
		}

		/**
		 * The "All | With exceptions (N)" filter: it counts the users of this page who have a real exception and, when chosen,
		 * shows only them. It works on the page on screen, so it says so; nothing is asked of the server.
		 * @return {*} operation result
		 */
		function applyExceptionsFilter() {
			if (!context.onlyButtons.exceptions || !context.state.data) return
			const known = context.state.data.users.map((user) => [user.uid, context.hasException(user)])
			const pending = known.some(([, value]) => value === null)
			const count = known.filter(([, value]) => value === true).length
			context.onlyButtons.exceptions.textContent = pending
				? t('mcp', 'With exceptions')
				: t('mcp', 'With exceptions ({count})', { count }, undefined, { escape: false })
			context.onlyButtons.exceptions.title = t('mcp', 'People on this page with an individual value that differs from what they inherit')
			for (const [only, button] of Object.entries(context.onlyButtons)) {
				button?.setAttribute('aria-pressed', String((only === 'exceptions') === context.state.exceptionsOnly))
			}
			const show = new Set(known.filter(([, value]) => !context.state.exceptionsOnly || value !== false).map(([uid]) => uid))
			let shown = 0
			for (const row of context.table.tBodies[0].rows) {
				if (row.dataset.uid === undefined) continue
				row.hidden = !show.has(row.dataset.uid)
				if (!row.hidden) shown++
			}
			context.table.tBodies[0].querySelector('tr.mcp-only-empty')?.remove()
			if (context.state.exceptionsOnly && shown === 0 && context.state.data.users.length > 0) {
				context.table.tBodies[0].append(context.el('tr', { className: 'mcp-only-empty' }, [context.el('td', {
					colSpan: context.FIXED_COLUMNS + context.visiblePermissionColumns(),
					className: 'mcp-empty',
					textContent: t('mcp', 'No one on this page has an exception.'),
				})]))
			}
		}

		/**
		 * Why none of a user's permissions can be changed: a disabled account or "Can connect" off. The
		 * row then shows this one notice across the permission columns instead of a mark in each of them.
		 *
		 * @param {object} user row from the API
		 * @return {string|null} short notice, or null when the permissions are shown cell by cell
		 */
		function rowUnavailableReason(user) {
			if (!user.enabled) {
				return t('mcp', 'Account disabled: no MCP access.')
			}
			if (user.eligible === false) {
				return t('mcp', 'No MCP access: turn on “Can connect”.')
			}
			return null
		}

		/** @return {number} permission columns shown with the current app filter */
		function visiblePermissionColumns() {
			if (context.isLevelsView()) {
				return Object.keys(context.state.data?.catalog || {}).length
			}
			return Object.entries(context.state.data?.catalog || {})
				.filter(([module]) => context.state.app === '' || module === context.state.app)
				.reduce((sum, [, operations]) => sum + operations.length, 0)
		}

		/** Bind the existing All/With exceptions controls at their original startup phase.
		 * @return {void}
		 */
		function attachExceptions() {
			for (const [only, button] of Object.entries(context.onlyButtons)) {
				button?.addEventListener('click', () => {
					context.state.exceptionsOnly = only === 'exceptions'
					context.applyExceptionsFilter()
				})
			}
		}

		Object.defineProperties(context, {
			OWN_SOURCES: { configurable: true, get: () => OWN_SOURCES },
		})

		Object.assign(context, { actionsCell, applyExceptionsFilter, attachExceptions, buildRow, hasException, renderRow, rowUnavailableReason, userLine, visiblePermissionColumns })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
