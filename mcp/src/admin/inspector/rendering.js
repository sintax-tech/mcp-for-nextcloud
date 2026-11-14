/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the inspector rendering unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/** Attach effective access tables, module summaries and individual grant explanations.
	 * @param {object} context inspector controls, live request sequences and attached presentation operations
	 * @return {void}
	 */
	admin.modules.inspector.rendering = function (context) {
		/**
		 * One line per module: the level the grants give, where it comes from, the resource scope and what blocks it. While a
		 * general check blocks everything, the lines say what will apply once it is fixed, without repeating it on each.
		 * @param {object[]} operations effective access rows returned by the inspector
		 * @return {HTMLElement}
		 */
		function renderModules(operations) {
			const general = context.generalBlock(operations) !== null
			const levels = window.OCA.Mcp.Admin.levels
			const byModule = new Map()
			for (const row of operations) {
				if (!byModule.has(row.module)) byModule.set(row.module, [])
				byModule.get(row.module).push(row)
			}
			const levelName = window.OCA.Mcp.Admin.levelLabels()
			const items = [...byModule.entries()].map(([module, rows]) => {
				const operationList = rows.map((row) => row.operation)
				const values = Object.fromEntries(rows.map((row) => [row.operation, row.grant ? row.grant.allowed : row.allowed]))
				const level = levels ? levels.levelOf(module, operationList, values) : null
				const gates = new Set(rows.map((row) => row.blockingGate).filter((gate) => gate !== null && gate !== 'grant'))
				const blocked = !general && rows.every((row) => row.allowed === false) && gates.size === 1 ? [...gates][0] : null
				const origin = levels ? levels.originOf(operationList, Object.fromEntries(rows.map((row) => [row.operation, row.grant]))) : null
				const source = origin === null ? '' : origin.source === 'individual' ? context.individualText(rows) : {
					invalid: t('mcp', 'Invalid stored value (denied)'),
					group: t('mcp', 'Group: {groups}', { groups: origin.groupIds.join(', ') }),
					unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') }),
					default: t('mcp', 'Default'),
				}[origin.source]
				const scope = rows[0].scope && rows[0].scope.mode === 'restricted' ? t('mcp', 'Restricted to {count}', { count: rows[0].scope.count }) : ''
				const state = blocked !== null
					? context.el('span', { className: 'mcp-inspector-blocked', textContent: t('mcp', 'Blocked') })
					: context.el('span', { className: level === 'unknown' ? 'mcp-inspector-unknown' : 'mcp-inspector-level', textContent: level === null ? '' : levelName[level] || level })
				const detail = [blocked !== null ? context.gateText(blocked) : source, scope].filter((text) => text !== '').join(' — ')
				return context.el('li', { className: 'mcp-inspector-module' }, [context.el('span', { className: 'mcp-inspector-module-name', textContent: context.moduleName(module) }), state, context.el('span', { className: 'mcp-hint', textContent: detail })])
			})
			const list = context.el('ul', { className: 'mcp-inspector-modules' }, items)
			return general ? context.el('div', {}, [context.el('p', { className: 'mcp-hint', textContent: t('mcp', 'What each module grants, once the general check is fixed:') }), list]) : list
		}

		/**
		 * Where a module's individual values come from, as the matrix says it: an exception when one of them differs from what
		 * the person inherits, what they repeat when every one only repeats it, and "set for this person" until that is known.
		 * @param {object[]} rows effective access rows for one module
		 * @return {string}
		 */
		function individualText(rows) {
			const individual = rows.filter((row) => row.grant?.source === 'individual_allow' || row.grant?.source === 'individual_deny')
			const known = individual.map((row) => context.kinds?.get(row.module + '/' + row.operation))
			if (context.kinds === null || known.some((kind) => !kind)) return t('mcp', 'Set for this person')
			if (known.some((kind) => kind.kind === 'exception')) return t('mcp', 'Exception: differs from what the person inherits')
			const repeated = Object.fromEntries(rows.map((row) => {
				const index = individual.indexOf(row)
				return [row.operation, index < 0 ? row.grant : known[index].after || { source: 'default', groupIds: [] }]
			}))
			const origin = window.OCA.Mcp.Admin.levels.originOf(rows.map((row) => row.operation), repeated)
			const text = {
				group: t('mcp', 'Group: {groups}', { groups: origin.groupIds.join(', ') }),
				unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') }),
			}[origin.source] || t('mcp', 'Default')
			return text + ' — ' + t('mcp', 'also set for this person, with the same value')
		}

		/** Store and draw the inspected access result, catalog, verdict and operation details.
		 * @param {object} data settings or access response returned by the API
		 * @return {void}
		 */
		function renderAccess(data) {
			context.shown = data
			context.catalog = {}
			const rows = data.operations.map((row) => {
				;(context.catalog[row.module] = context.catalog[row.module] || []).push(row.operation)
				const scope = row.scope && row.scope.mode === 'restricted' ? ' · ' + t('mcp', 'Restricted to {count}', { count: row.scope.count }) : ''
				return context.el('tr', {}, [
					context.el('th', { scope: 'row', textContent: context.moduleName(row.module) + ': ' + context.operationName(row.operation) }),
					context.el('td', { textContent: context.resultText(row.allowed), className: 'mcp-inspector-' + (row.allowed === true ? 'allowed' : row.allowed === false ? 'blocked' : 'unknown') }),
					context.el('td', { textContent: row.blockingGate === null ? '—' : context.gateText(row.blockingGate) }),
					context.el('td', { textContent: context.grantText(row) + scope }),
				])
			})
			const head = context.el('tr', {}, [t('mcp', 'Operation'), t('mcp', 'Result'), t('mcp', 'First check that blocks'), t('mcp', 'Grant')].map((text) => context.el('th', { scope: 'col', textContent: text })))
			const table = context.el('table', { className: 'mcp-inspector-table' }, [context.el('caption', { textContent: t('mcp', 'Effective access of {user}', { user: context.inspected }) }), context.el('thead', {}, [head]), context.el('tbody', {}, rows)])
			context.access.replaceChildren(...context.renderVerdict(data.operations), context.renderModules(data.operations),
				context.el('details', { className: 'mcp-inspector-all' }, [context.el('summary', { textContent: t('mcp', 'Every operation') }), table]))
		}

		Object.assign(context, { renderModules, individualText, renderAccess })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
