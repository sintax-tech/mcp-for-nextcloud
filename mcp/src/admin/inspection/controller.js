/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Effective access inspector (ticket 13; issue #4 A5, B6). Mounted ES module. It only reads: the effective
 * access of one user per operation, and why one path or one calendar is reached or not. Every text comes from the
 * codes the server answers, the same codes the tools are decided by; nothing here decides access.
 *
 * It opens as a drawer at the side of the page (S10), from its button on the Access tab or from the actions menu of a
 * matrix row, and answers first whether the person can use the tools at all: when one general check blocks every
 * operation (the account, the service, "Can connect" or the personal connection), one sentence says which and how to
 * fix it, next to the four general checks. Then one line per module gives the level the person has, where it comes
 * from, the resource scope and what blocks it; the table of every operation stays one click away. An individual value
 * that only repeats what the person inherits is said as the matrix says it ("also set for this person, with the same
 * value"), through the same read-only preview (src/admin/access/exceptions.js); until that is known it is "set for this person".
 */
import { createScope } from '../../platform/lifecycle.js'
import { createInspectionView } from './view.js'
export function mountInspector({ root: pageRoot, request, t, n, events, policyChannel, getExceptions, getResourceScope }) {
    const lifetime = createScope()
    const { moduleName, operationName, gateText, grantText, resultText, explanation, renderVerdict, renderModules } = createInspectionView({ t, el, getUser: () => inspected, getKinds: () => kinds })
    const find = id => pageRoot.querySelector('#' + id)

	const root = find('mcp-inspector')
	if (!root) return
	const form = find('mcp-inspector-form')
	const user = find('mcp-inspector-user')
	const status = find('mcp-inspector-status')
	const alert = find('mcp-inspector-alert')
	const access = find('mcp-inspector-access')
	const resources = find('mcp-inspector-resources')
	const pathForm = find('mcp-inspector-path-form')
	const pathInput = find('mcp-inspector-path')
	const pathResult = find('mcp-inspector-path-result')
	const calendarForm = find('mcp-inspector-calendar-form')
	const calendarModule = find('mcp-inspector-calendar-module')
	const calendarSelect = find('mcp-inspector-calendar')
	const calendarOperation = find('mcp-inspector-calendar-operation')
	const calendarResult = find('mcp-inspector-calendar-result')
	let inspected = null
	let catalog = {}
	let seq = 0
	/**
	 * What each individual value of the person on screen is (src/admin/access/exceptions.js, the same preview and rule as the
	 * matrix): per "module/operation", {kind: exception|redundant, after}. Null until known, and when it cannot be read:
	 * every individual value is then said as set for this person, never as repeating what they inherit.
	 */
	let kinds = null
	/** The answer on screen, drawn again once the kinds are known. */
	let shown = null

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(props)) node[key] = typeof value === 'function' ? lifetime.guard(value) : value
		node.append(...children)
		return node
	}
	function say(message, error = false) {
		status.textContent = ''
		alert.textContent = ''
		;(error ? alert : status).textContent = message
	}
	const api = lifetime.request(request)
	const get = (path, query) => api('GET', path + '?' + new URLSearchParams(query))
	function failure(error) {
		if (error.message === '403') return t('mcp', 'Only administrators can inspect access.')
		if (error.message === '400') return t('mcp', 'Check the user ID and the value entered, then try again.')
		if (error.message === '503') return t('mcp', 'The MCP policy cannot be read right now, so nothing can be confirmed. Try again shortly.')
		return t('mcp', 'The inspection failed. Try again.')
	}

	/** The general checks, in the order the tools apply them; one of them blocking blocks every operation. */

	/**
	 * Asks what the individual values of the answer on screen repeat, then draws it again; an answer for another person
	 * or another inspection is dropped.
	 */
	async function classifyShown(data, mine) {
		const exceptions = getExceptions()
		if (!exceptions?.classifyPerson || typeof data.revision !== 'string') return
		const cells = data.operations.map((row) => ({ module: row.module, operation: row.operation, allowed: row.grant?.allowed ?? null, source: row.grant?.source }))
		if (!cells.some((cell) => cell.source === 'individual_allow' || cell.source === 'individual_deny')) return
		try {
			const answer = await exceptions.classifyPerson(inspected, cells, data.revision)
			if (lifetime.disposed || mine !== seq || shown !== data) return
			kinds = answer
		} catch (e) {
            if (lifetime.disposed) return
			return
		}
		// Drawn again in place: the table of every operation stays open, and a focused summary keeps the focus.
		const details = access.querySelector?.('details.mcp-inspector-all')
		const wasOpen = Boolean(details?.open)
		const hadFocus = document.activeElement !== undefined && document.activeElement === details?.querySelector?.('summary')
		renderAccess(data)
		const again = access.querySelector?.('details.mcp-inspector-all')
		if (again && wasOpen) again.open = true
		if (hadFocus) again?.querySelector?.('summary')?.focus?.()
	}

	function renderAccess(data) {
		shown = data
		catalog = {}
		const rows = data.operations.map((row) => {
			;(catalog[row.module] = catalog[row.module] || []).push(row.operation)
			const scope = row.scope && row.scope.mode === 'restricted' ? ' · ' + t('mcp', 'Restricted to {count}', { count: row.scope.count }) : ''
			return el('tr', {}, [
				el('th', { scope: 'row', textContent: moduleName(row.module) + ': ' + operationName(row.operation) }),
				el('td', { textContent: resultText(row.allowed), className: 'mcp-inspector-' + (row.allowed === true ? 'allowed' : row.allowed === false ? 'blocked' : 'unknown') }),
				el('td', { textContent: row.blockingGate === null ? '—' : gateText(row.blockingGate) }),
				el('td', { textContent: grantText(row) + scope }),
			])
		})
		const head = el('tr', {}, [t('mcp', 'Operation'), t('mcp', 'Result'), t('mcp', 'First check that blocks'), t('mcp', 'Grant')].map((text) => el('th', { scope: 'col', textContent: text })))
		const table = el('table', { className: 'mcp-inspector-table' }, [el('caption', { textContent: t('mcp', 'Effective access of {user}', { user: inspected }) }), el('thead', {}, [head]), el('tbody', {}, rows)])
		access.replaceChildren(...renderVerdict(data.operations), renderModules(data.operations),
			el('details', { className: 'mcp-inspector-all' }, [el('summary', { textContent: t('mcp', 'Every operation') }), table]))
	}

	async function inspect() {
		const uid = user.value.trim()
		if (uid === '') {
			say(t('mcp', 'Enter the user ID to inspect.'), true)
			return
		}
		const mine = ++seq
		access.setAttribute('aria-busy', 'true')
		say(t('mcp', 'Inspecting…'))
		try {
			const data = await get('/api/inspector/access', { uid })
			if (mine !== seq) return
			inspected = uid
			kinds = null
			renderAccess(data)
			classifyShown(data, mine)
			resources.hidden = false
			pathResult.textContent = ''
			calendarResult.textContent = ''
			await loadCalendars()
            if (lifetime.disposed || mine !== seq) return
			say(t('mcp', 'Access of {user} as the tools see it now.', { user: uid }))
		} catch (error) {
            if (lifetime.disposed) return
			if (mine !== seq) return
			inspected = null
			kinds = null
			shown = null
			access.replaceChildren()
			resources.hidden = true
			say(failure(error), true)
		} finally {
            if (lifetime.disposed) return
			if (mine === seq) access.setAttribute('aria-busy', 'false')
		}
	}

	/** A request to the user or module that is no longer on screen is answered too late: its answer is dropped. */
	const asked = { calendars: 0, path: 0, calendar: 0 }
	function stale(kind, mine, user) {
		return mine !== asked[kind] || user !== inspected
	}

	async function loadCalendars() {
		const module = calendarModule.value
		const mine = ++asked.calendars
		const user = inspected
		calendarOperation.replaceChildren(...(catalog[module] || ['read']).map((operation) => el('option', { value: operation, textContent: operationName(operation) })))
		calendarSelect.replaceChildren()
		try {
			const data = await get('/api/scope/' + encodeURIComponent(user) + '/' + module + '/candidates', {})
			if (stale('calendars', mine, user) || module !== calendarModule.value) return
			calendarSelect.replaceChildren(...data.items.map((item) => el('option', { value: item.id, textContent: item.name })))
			if (data.items.length === 0) calendarResult.textContent = t('mcp', 'This user has no calendar or task list of this kind in Nextcloud.')
		} catch (error) {
            if (lifetime.disposed) return
			if (stale('calendars', mine, user)) return
			say(failure(error), true)
		}
	}

	async function explain(kind, target, path, query, module) {
		const mine = ++asked[kind]
		const user = inspected
		target.textContent = t('mcp', 'Checking…')
		try {
			const answer = await get(path, Object.assign({ uid: user }, query))
			if (stale(kind, mine, user)) return
			target.textContent = explanation(answer)
			if (answer.reason === 'out_of_scope' && getResourceScope()) {
				const change = el('button', { type: 'button', textContent: t('mcp', 'Open the resource scope') })
				lifetime.on(change, 'click', () => getResourceScope().open(user, module))
				target.append(' ', change)
			}
		} catch (error) {
            if (lifetime.disposed) return
			if (stale(kind, mine, user)) return
			// An error is announced like every other error of the inspector, in its alert region.
			target.textContent = ''
			say(failure(error), true)
		}
	}

	lifetime.on(form, 'submit', (event) => { event.preventDefault(); inspect() })
	const drawerTitle = find('mcp-inspector-title')
	const closeButton = find('mcp-inspector-close')
	const entryButton = find('mcp-inspector-entry')
	let returnFocus = null
	let returnRow = null

	/**
	 * Puts the open drawer right under the Nextcloud header. A drawer is position: fixed, but Nextcloud 35 blurs the content
	 * behind it (backdrop-filter on #content-vue), which makes that box, not the window, the one it is placed in: its top
	 * would then be counted from the box, one header too low. The offset is measured, not assumed, since a theme may turn
	 * the blur off.
	 */
	function placeDrawer() {
		root.style?.removeProperty?.('top')
		const rect = root.getBoundingClientRect?.()
		if (!rect) return
		const header = document.getElementById('header')?.getBoundingClientRect?.().bottom ?? 0
		const shift = rect.top - header
		if (Math.abs(shift) >= 1) root.style.top = (parseFloat(getComputedStyle(root).top) - shift) + 'px'
	}

	/**
	 * Opens the drawer; the focus goes back to `trigger` when it closes. Only one drawer is open at a time: when the
	 * administrator keeps a draft of the resource scope, its drawer stays and this one does not open.
	 *
	 * @return {boolean} whether the drawer opened
	 */
	function openDrawer(trigger) {
		if (getResourceScope()?.close?.() === false) {
			trigger?.focus?.()
			return false
		}
		returnFocus = trigger || document.activeElement || null
		// The row of a "…" button that opened the drawer: the table may be drawn again meanwhile, and its new button is
		// the one that gets the focus back.
		returnRow = returnFocus?.closest?.('tr')?.dataset?.uid ?? null
		root.classList?.add('mcp-drawer-open')
		root.setAttribute('role', 'dialog')
		placeDrawer()
		return true
	}

	/**
	 * Closes the drawer and gives the focus back. The inspector only reads, so nothing can be lost.
	 *
	 * @return {boolean} always true: the drawer is closed now
	 */
	function closeDrawer() {
		if (!root.classList?.contains('mcp-drawer-open')) return true
		root.classList.remove('mcp-drawer-open')
		root.removeAttribute?.('role')
		root.style?.removeProperty?.('top')
		const target = returnFocus
		returnFocus = null
		focusBack(target)
		events.dispatchEvent?.(new Event('mcp-drawer-closed'))
		return true
	}

	/**
	 * Gives the focus back to the control that opened the drawer, or, when the table was drawn again and that control is
	 * gone, to the same row's "…" button, then to the drawer's own button on the Access tab: never to the page.
	 */
	function focusBack(target) {
		if (target && target.isConnected !== false) {
			target.focus?.()
			return
		}
		const rows = [...(find('mcp-matrix')?.tBodies?.[0]?.rows || [])]
		const row = returnRow === null ? null : rows.find((candidate) => candidate.dataset.uid === returnRow)
		;(row?.querySelector('button.mcp-row-actions-button') || entryButton)?.focus?.()
	}

	lifetime.on(entryButton, 'click', () => {
		if (openDrawer(entryButton)) user.focus?.()
	})
	lifetime.on(closeButton, 'click', () => closeDrawer())
	lifetime.on(root, 'keydown', (event) => {
		if (event.key === 'Escape' && root.classList?.contains('mcp-drawer-open')) {
			event.preventDefault?.()
			closeDrawer()
		}
	})

	// The actions menu of a matrix row inspects that user (src/admin/access/row-actions.js), in the drawer.
	const controller = {
		open(target, trigger) {
			if (!openDrawer(trigger)) return false
			user.value = target
			drawerTitle?.focus?.()
			inspect()
			return true
		},
		close: closeDrawer,
	}
	lifetime.on(pathForm, 'submit', (event) => {
		event.preventDefault()
		const path = pathInput.value.trim()
		if (inspected === null || path === '') return
		explain('path', pathResult, '/api/inspector/path', { path: path.startsWith('/') ? path : '/' + path }, 'files')
	})
	lifetime.on(calendarModule, 'change', () => { if (inspected !== null) loadCalendars() })
	lifetime.on(calendarForm, 'submit', (event) => {
		event.preventDefault()
		if (inspected === null || calendarSelect.value === '') return
		explain('calendar', calendarResult, '/api/inspector/calendar', { module: calendarModule.value, calendarId: calendarSelect.value, operation: calendarOperation.value }, calendarModule.value)
	})

	const ready = Promise.resolve()

    return { ...controller, ready, destroy() {
        lifetime.destroy(); seq++; inspected = null; kinds = null; shown = null
        root.classList.remove('mcp-drawer-open'); root.removeAttribute('role'); root.style.removeProperty('top')
        access.replaceChildren(); access.removeAttribute('aria-busy'); resources.hidden = true
        status.textContent = ''; alert.textContent = ''; pathResult.textContent = ''; calendarResult.textContent = ''
    } }
}
