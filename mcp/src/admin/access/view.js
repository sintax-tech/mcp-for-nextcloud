// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createScope } from '../../platform/lifecycle.js'
import * as Levels from './levels.js'
import { createLevelCells } from './level-cells.js'
export function createMatrixView({ root, t, presentation, getData, getFilters, savePermission, isBulkBusy, reload, notifyError, events, getOverrides, getExceptions, getRowActions, getResourceScope, getLevelPopover }) {
    const scope = createScope(root)
    const { moduleLabels, operationLabels, operationHint, rowUnavailableReason, unavailableReason, levelLabels } = presentation
    const FIXED_COLUMNS = 4
    const table = root.querySelector('#mcp-matrix')
    const countInfo = root.querySelector('#mcp-count')
    const prevButtons = [...root.querySelectorAll('#mcp-prev-bottom')]
    const nextButtons = [...root.querySelectorAll('#mcp-next-bottom')]
    const pageInfos = [...root.querySelectorAll('#mcp-page-info-bottom')]
    const viewButtons = { levels: root.querySelector('#mcp-view-levels'), operations: root.querySelector('#mcp-view-operations') }
    const legends = { levels: root.querySelector('#mcp-legend-levels'), operations: root.querySelector('#mcp-legend-operations') }
    const appFilter = root.querySelector('#mcp-app-filter')
    const onlyButtons = { all: root.querySelector('#mcp-only-all'), exceptions: root.querySelector('#mcp-only-exceptions') }
    let exceptionsOnly = false
    const state = { get data() { return getData() }, get app() { return getFilters().app }, get view() { return getFilters().view }, get exceptionsOnly() { return exceptionsOnly }, set exceptionsOnly(on) { exceptionsOnly = on } }
    const OWN_SOURCES = ['individual_allow', 'individual_deny', 'invalid']

    let observedHeaderRow = null
    let headerObserver = null
    const reloadLocked = reload
    const { levelCell, eligibleCell } = createLevelCells({ t, el, paintState, reloadLocked, moduleLabels, operationLabels, unavailableReason, levelLabels, getExceptions, getResourceScope, getLevelPopover })
	const PERMISSION_STATES = {
		allowed: { className: 'mcp-state-allowed', name: () => t('mcp', 'Allowed') },
		denied: { className: 'mcp-state-denied', name: () => t('mcp', 'Denied') },
		unavailable: { className: 'mcp-state-unavailable', name: () => t('mcp', 'Unavailable') },
	}
	function flash(cell, ok) {
		cell.classList.remove('mcp-ok', 'mcp-error')
		void cell.offsetWidth
		cell.classList.add(ok ? 'mcp-ok' : 'mcp-error')
	}
	function el(tag, props, children) {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(props || {})) node[key] = typeof value === 'function' ? scope.guard(value) : value
		for (const child of children || []) {
			node.append(child)
		}
		return node
	}
	function renderError() {
		const retry = el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: reload })
		const cell = el('td', { colSpan: state.data ? FIXED_COLUMNS + visiblePermissionColumns() : 1, className: 'mcp-empty mcp-load-error' },
			[t('mcp', 'Could not load the users.'), ' ', retry])
		table.tBodies[0].replaceChildren(el('tr', {}, [cell]))
		applyAppFilter()
	}
	function applyAppFilter() {
		const app = shownApp()
		for (const cell of table.querySelectorAll('[data-module]')) {
			cell.hidden = app !== '' && cell.dataset.module !== app
			if (cell.hidden) {
				for (const menu of cell.querySelectorAll('details[open]')) {
					menu.open = false
				}
			}
		}
		const permissions = visiblePermissionColumns()
		for (const cell of table.querySelectorAll('td.mcp-empty')) {
			cell.colSpan = FIXED_COLUMNS + permissions
		}
		for (const cell of table.querySelectorAll('td.mcp-row-unavailable')) {
			cell.colSpan = permissions
		}
		stickSecondHeaderRow()
	}
	function isLevelsView() {
		return state.view === 'levels' && Boolean(Levels)
	}
	function shownApp() {
		return isLevelsView() ? '' : state.app
	}
	function stickSecondHeaderRow() {
		const [top, sub] = table.tHead.rows
		if (!top || !sub) {
			return
		}
		const offset = top.getBoundingClientRect().height
		for (const cell of sub.cells) {
			cell.style.top = offset + 'px'
		}
	}
	function observeHeaderRow() {
		if (!window.ResizeObserver) {
			return
		}
		const top = table.tHead?.rows?.[0]
		if (!top || top === observedHeaderRow) {
			return
		}
		if (!headerObserver) {
			headerObserver = new window.ResizeObserver(stickSecondHeaderRow)
		}
		headerObserver.disconnect()
		observedHeaderRow = top
		headerObserver.observe(top)
	}
	function render() {
		const data = state.data
		const modules = moduleLabels()
		const operations = operationLabels()
		const levelsView = isLevelsView()
		for (const [view, button] of Object.entries(viewButtons)) {
			button?.setAttribute('aria-pressed', String(view === (levelsView ? 'levels' : 'operations')))
		}
		for (const [view, legend] of Object.entries(legends)) {
			if (legend) legend.hidden = view !== (levelsView ? 'levels' : 'operations')
		}
		if (appFilter) {
			appFilter.hidden = levelsView
		}
		table.classList.toggle('mcp-levels', levelsView)

		const top = el('tr')
		const sub = el('tr')
		const rowSpan = levelsView ? 1 : 2
		const selectHeader = el('th', { rowSpan, className: 'mcp-select-col', scope: 'col' }, [el('span', { className: 'mcp-sr-only', textContent: t('mcp', 'Select') })])
		// The "select every user on this page" box of src/admin/access/overrides.js heads the selection column.
		getOverrides()?.decorateHeader?.(selectHeader)
		top.append(selectHeader)
		top.append(el('th', { rowSpan, className: 'mcp-user-col', scope: 'col', textContent: t('mcp', 'User') }))
		const canConnect = t('mcp', 'Can connect')
		top.append(el('th', { rowSpan, scope: 'col', title: t('mcp', 'The administrator allows this user to connect an MCP client'), textContent: canConnect }))
		Object.entries(data.catalog).forEach(([module, ops], index) => {
			const band = 'mcp-band-' + (index % 2)
			const name = modules[module] || module
			const header = el('th', { colSpan: levelsView ? 1 : ops.length, scope: levelsView ? 'col' : 'colgroup', className: 'mcp-module ' + band, textContent: name })
			header.dataset.module = module
			top.append(header)
			if (levelsView) {
				return
			}
			ops.forEach((operation, i) => {
				const cell = el('th', {
					scope: 'col',
					className: band + (i === 0 ? ' mcp-module' : ''),
					title: operationHint(module, operation),
					textContent: operations[operation] || operation,
				})
				cell.dataset.module = module
				sub.append(cell)
			})
		})
		top.append(el('th', { rowSpan, scope: 'col', className: 'mcp-actions-col' }, [el('span', { className: 'mcp-sr-only', textContent: t('mcp', 'Actions') })]))
		table.tHead.replaceChildren(...(levelsView ? [top] : [top, sub]))
		if (isBulkBusy()) {
			setBulkBusy(true)
		}
		stickSecondHeaderRow()
		observeHeaderRow()

		const rows = data.users.map(renderRow)
		if (rows.length === 0) {
			const columns = FIXED_COLUMNS + (levelsView ? Object.keys(data.catalog).length : Object.values(data.catalog).reduce((sum, ops) => sum + ops.length, 0))
			rows.push(el('tr', {}, [el('td', { colSpan: columns, className: 'mcp-empty', textContent: t('mcp', 'No users found.') })]))
		}
		table.tBodies[0].replaceChildren(...rows)
		applyAppFilter()
		applyExceptionsFilter()

		const pageText = data.total === null
			? t('mcp', 'Page {page}', { page: data.page })
			: t('mcp', 'Page {page} of {pages}', { page: data.page, pages: Math.max(1, Math.ceil(data.total / data.pageSize)) })
		for (const button of prevButtons) {
			button.disabled = data.page <= 1
		}
		for (const button of nextButtons) {
			button.disabled = !data.hasMore
		}
		for (const info of pageInfos) {
			info.textContent = pageText
		}
		const from = (data.page - 1) * data.pageSize + 1
		const to = from + data.users.length - 1
		countInfo.textContent = data.users.length === 0
			? ''
			: (data.total === null
				? t('mcp', 'Users {from}–{to}', { from, to })
				: t('mcp', 'Users {from}–{to} of {total}', { from, to, total: data.total }))
		events.dispatchEvent(new Event('mcp-matrix-rendered'))
	}
	function renderRow(user) {
		const row = buildRow(user)
		getOverrides()?.decorateRow(row, user)
		return row
	}
	function buildRow(user) {
		const row = el('tr', { className: user.enabled ? '' : 'mcp-disabled-user' })
		row.dataset.uid = user.uid
		// The selection box of src/admin/access/overrides.js goes here, in its own column instead of next to the name.
		row.append(el('td', { className: 'mcp-select-col' }))
		row.append(el('th', { scope: 'row', className: 'mcp-user-col' }, [el('span', { className: 'mcp-name', textContent: user.displayName }), userLine(user)]))
		row.append(isLevelsView() ? eligibleCell(user) : permissionCell(user, null, user.eligible, t('mcp', 'Can connect'), { eligible: null }))
		const rowReason = rowUnavailableReason(user)
		if (rowReason !== null) {
			// One notice instead of a mark in every column; the saved permissions come back with "Can connect".
			const notice = el('td', { className: 'mcp-cell mcp-row-unavailable', colSpan: visiblePermissionColumns(), textContent: rowReason })
			notice.title = user.enabled
				? t('mcp', 'The saved permissions are kept and shown again when “Can connect” is on.')
				: t('mcp', 'The saved permissions are kept and shown again when the account is enabled and “Can connect” is on.')
			row.append(notice)
			row.append(actionsCell(user))
			return row
		}
		const modules = moduleLabels()
		const operations = operationLabels()
		if (isLevelsView()) {
			Object.entries(state.data.catalog).forEach(([module, ops], index) => row.append(levelCell(user, module, ops, index)))
			row.append(actionsCell(user))
			return row
		}
		Object.entries(state.data.catalog).forEach(([module, ops], index) => {
			ops.forEach((operation, i) => {
				// The same name as the column header and the reset button, never the raw id ("files:read").
				const label = (modules[module] || module) + ': ' + (operations[operation] || operation)
				const cell = permissionCell(user, module, user.grants[module][operation], label, { module, operation, granted: null })
				cell.dataset.module = module
				cell.hidden = state.app !== '' && state.app !== module
				cell.classList.add('mcp-band-' + (index % 2))
				if (i === 0) {
					cell.classList.add('mcp-module')
					// "Restricted to N" or "Scope unknown" of a resource scope, drawn and opened by src/admin/scope/controller.js.
					const mark = user.scope?.[module] && getResourceScope() ? getResourceScope().indicator(user.uid, module, user.scope[module]) : null
					if (mark) cell.append(mark)
				}
				row.append(cell)
			})
		})
		row.append(actionsCell(user))
		return row
	}
	function userLine(user) {
		if (!user.enabled) {
			return el('span', { className: 'mcp-uid mcp-user-disabled', textContent: t('mcp', 'Account disabled') })
		}
		if (user.eligible && user.connected) {
			const mark = el('span', { className: 'mcp-connected-mark', textContent: '●' })
			mark.setAttribute('aria-hidden', 'true')
			const line = el('span', { className: 'mcp-uid mcp-user-connected' }, [mark, ' ', t('mcp', 'Connected')])
			line.title = user.uid
			return line
		}
		return el('span', { className: 'mcp-uid', textContent: user.uid })
	}
	function actionsCell(user) {
		const cell = el('td', { className: 'mcp-actions-col' })
		const button = getRowActions()?.button(user)
		if (button) cell.append(button)
		return cell
	}
	function hasException(user) {
		const exceptions = getExceptions()
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
	function applyExceptionsFilter() {
		if (!onlyButtons.exceptions || !state.data) return
		const known = state.data.users.map((user) => [user.uid, hasException(user)])
		const pending = known.some(([, value]) => value === null)
		const count = known.filter(([, value]) => value === true).length
		onlyButtons.exceptions.textContent = pending
			? t('mcp', 'With exceptions')
			: t('mcp', 'With exceptions ({count})', { count }, undefined, { escape: false })
		onlyButtons.exceptions.title = t('mcp', 'People on this page with an individual value that differs from what they inherit')
		for (const [only, button] of Object.entries(onlyButtons)) {
			button?.setAttribute('aria-pressed', String((only === 'exceptions') === state.exceptionsOnly))
		}
		const show = new Set(known.filter(([, value]) => !state.exceptionsOnly || value !== false).map(([uid]) => uid))
		let shown = 0
		for (const row of table.tBodies[0].rows) {
			if (row.dataset.uid === undefined) continue
			row.hidden = !show.has(row.dataset.uid)
			if (!row.hidden) shown++
		}
		table.tBodies[0].querySelector('tr.mcp-only-empty')?.remove()
		if (state.exceptionsOnly && shown === 0 && state.data.users.length > 0) {
			table.tBodies[0].append(el('tr', { className: 'mcp-only-empty' }, [el('td', {
				colSpan: FIXED_COLUMNS + visiblePermissionColumns(),
				className: 'mcp-empty',
				textContent: t('mcp', 'No one on this page has an exception.'),
			})]))
		}
	}
	function visiblePermissionColumns() {
		if (isLevelsView()) {
			return Object.keys(state.data?.catalog || {}).length
		}
		return Object.entries(state.data?.catalog || {})
			.filter(([module]) => state.app === '' || module === state.app)
			.reduce((sum, [, operations]) => sum + operations.length, 0)
	}
	function paintState(cell, key, detail) {
		for (const other of Object.values(PERMISSION_STATES)) {
			cell.classList.remove(other.className)
		}
		cell.classList.add(PERMISSION_STATES[key].className)
		cell.title = detail ? PERMISSION_STATES[key].name() + ': ' + detail : PERMISSION_STATES[key].name()
	}
	function permissionCell(user, module, checked, label, body) {
		const cell = el('td', { className: 'mcp-cell' })
		const reason = checked === null ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : unavailableReason(user, module)
		if (reason !== null) {
			const saved = checked === null ? t('mcp', 'Unknown') : checked ? t('mcp', 'Saved value: allowed.') : t('mcp', 'Saved value: denied.')
			paintState(cell, 'unavailable', reason + ' ' + saved)
			const mark = el('span', { className: 'mcp-state-mark', textContent: checked === null ? '—' : '⊘' })
			mark.setAttribute('aria-hidden', 'true')
			cell.append(mark, el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + label + ': ' + cell.title }))
			if (checked === null) cell.append(el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: reloadLocked }))
			// A saved individual override stays visible and removable here, even though the cell cannot be edited.
			getOverrides()?.decorateCell(cell, null, user, module, module === null ? 'eligible' : body.operation)
			return cell
		}
		const box = el('input', { type: 'checkbox', checked })
		box.setAttribute('aria-label', user.displayName + ' — ' + label)
		paintState(cell, checked ? 'allowed' : 'denied')
		const origin = module === null ? user.origins?.eligible : user.origins?.grants?.[module]?.[body.operation]
		if (origin?.source === 'group') {
			const source = t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') })
			cell.title += ' — ' + source
			box.setAttribute('aria-label', user.displayName + ' — ' + label + ' — ' + source)
		}
		box.addEventListener('change', scope.guard(async () => {
			const value = box.checked
			if (isBulkBusy()) {
				// The table on screen is known to be outdated; the reloaded one replaces this checkbox.
				box.checked = !value
				return
			}
			// Taking "Can connect" away signs the person out of every MCP client: never without asking.
			if (module === null && !value && !window.confirm(t('mcp', 'Turn off “Can connect” for {user}?', { user: user.displayName }, undefined, { escape: false }) + '\n'
				+ t('mcp', 'This signs the person out of every MCP client, and undoing it does not sign them back in.'))) {
				box.checked = true
				return
			}
			const payload = Object.fromEntries(Object.entries(body).map(([key, v]) => [key, v === null ? value : v]))
			const hadFocus = document.activeElement === box
			// The focus comes back to this checkbox only when the admin did not move it during the save.
			const focusStayed = () => hadFocus && (document.activeElement === null || document.activeElement === document.body || document.activeElement === box)
			box.disabled = true
			try {
                const updated = await savePermission(user, payload)
                if (scope.disposed) return
				const restore = focusStayed()
				const fresh = renderRow(updated)
				cell.parentElement.replaceWith(fresh)
				const freshCell = fresh.children[cell.cellIndex]
				flash(freshCell, true)
				if (restore) {
					freshCell.querySelector('input')?.focus()
				}
			} catch (e) {
				const restore = focusStayed()
				box.checked = !value
				box.disabled = false
				if (restore) {
					box.focus()
				}
				flash(cell, false)
				if (!scope.disposed) {
					notifyError(t('mcp', 'Could not save the permission for {user}.', { user: user.displayName }))
				}
			}
		}))
		cell.append(box)
		// The override state and the per-cell reset come from src/admin/access/overrides.js, when it is loaded.
		getOverrides()?.decorateCell(cell, box, user, module, module === null ? 'eligible' : body.operation)
		return cell
	}







	function revealFocus(event) {
		const wrap = event.currentTarget
		const target = event.target
		const cell = target?.closest?.('td, th')
		const row = cell?.parentElement
		if (!cell || !row) return
		const rect = target.getBoundingClientRect()
		// The sticky cells before this one in the row sit at its start side, the ones after it at its end side; which
		// physical side that is depends on the direction of the page.
		const rtl = getComputedStyle(row).direction === 'rtl'
		// The visible part of the block itself: the browser leaves a control that shows in part where it is.
		const block = wrap.getBoundingClientRect()
		let left = block.left + (wrap.clientLeft || 0)
		let right = left + wrap.clientWidth
		let before = true
		for (const other of row.children) {
			if (other === cell) {
				before = false
				continue
			}
			if (getComputedStyle(other).position !== 'sticky') continue
			const box = other.getBoundingClientRect()
			if (before !== rtl) left = Math.max(left, box.right)
			else right = Math.min(right, box.left)
		}
		if (rect.left < left) {
			wrap.scrollLeft -= left - rect.left + 4
		} else if (rect.right > right) {
			// A control wider than the room left keeps its start in view.
			wrap.scrollLeft += Math.min(rect.right - right + 4, rect.left - left)
		}
	}

    for (const [only, button] of Object.entries(onlyButtons)) scope.on(button, 'click', () => { state.exceptionsOnly = only === 'exceptions'; applyExceptionsFilter() })
    scope.on(table.parentElement, 'focusin', revealFocus)
    function setBulkBusy(on) { for (const button of root.querySelectorAll('.mcp-bulk')) button.disabled = on }
    function setLoading(on) { table.setAttribute('aria-busy', String(on)); table.classList.toggle('mcp-loading', on) }
    scope.use(() => { headerObserver?.disconnect(); table.tHead.replaceChildren(); table.tBodies[0].replaceChildren(); setLoading(false) })
    return { render, renderError, applyAppFilter, setBulkBusy, setLoading, destroy: scope.destroy }
}
