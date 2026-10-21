/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Resource scope panel of the admin page (issue #4, B7; contracts B.6). Mounted ES module. One user at a
 * time: per module of the scope (Files, Calendar, Tasks) the administrator chooses "All resources" or "Only
 * selected" and picks folders, calendars or task lists from the user's own Nextcloud resources, through the admin
 * routes of the scope (GET /api/scope/{uid}/{module}, its candidates, PUT with expectedRevision, undo through
 * /api/grants/undo). Nothing here decides access: the server validates every item and the tools apply the scope.
 *
 * A module whose activation barrier is still closed on the server (`active: false`) is shown with its saved state
 * and browsable, but cannot be saved, and says so: the tools of that module do not apply a scope yet. A conflict
 * (409) never repeats the write: the current scope is read again and shown. Each module keeps the revision its own
 * draft was read at, so a draft made before a change elsewhere is refused by the server instead of overwriting it.
 * A write of this page (a module here, or the matrix, OAuth clients or server log of src/admin/access/controller.js) is announced
 * with the revision it was sent with and the one it made (event "mcp-revision-moved"); a module still at the first
 * takes the second and keeps its draft, since nothing but that write lies between them.
 * Exposes the resource scope interface for the matrix, which shows "Restricted to N" (or "Scope unknown") in the module's first
 * cell and opens this panel from it.
 *
 * The panel is a drawer at the side of the page (S9): the Access tab shows one sentence and a button, and the drawer
 * opens from that button, from a "Restricted to N" cell or from the actions menu of a matrix row. Its modules are tabs
 * that say the saved state ("Files: 3", "Calendar: all") and whether a draft is not saved. Escape and the close button
 * close it, asking first when a draft would be lost, and the focus goes back to the control that opened it. The drawer
 * is not modal, so the matrix stays usable beside it: opening another person while a draft is open asks first too, and
 * close() says whether the drawer really closed, so another drawer never opens over a draft that was kept.
 */
import { createScope } from '../../platform/lifecycle.js'
import { createScopeView } from './view.js'
export function mountResourceScope({ root: pageRoot, request, t, n, events, policyChannel, getMatrix, getInspector }) {
    const lifetime = createScope()
    const find = id => pageRoot.querySelector('#' + id)

	const MODULES = ['files', 'calendar', 'tasks']
	const UNDO_SECONDS = 10
	/** Delay before a message is written, so the same message twice in a row is announced twice (as in the matrix). */
	const SAY_DELAY = 60
	const root = find('mcp-scope')
	const form = find('mcp-scope-form')
	const userInput = find('mcp-scope-user')
	const status = find('mcp-scope-status')
	const alertBox = find('mcp-scope-alert')
	const container = find('mcp-scope-modules')
	const tabs = find('mcp-scope-tabs')
	const drawerTitle = find('mcp-scope-title')
	const closeButton = find('mcp-scope-close')
	const entryButton = find('mcp-scope-entry')
    const { renderModule } = createScopeView({ lifetime, t, el, tabs, title, dirty, rerender, saveModule, reload, note, failure, undoModule, expireUndo, browse, getUser: () => uid, getCurrentTab: () => currentTab })
	/** The module whose tab is open, and the control the focus goes back to when the drawer closes. */
	let currentTab = 'files'
	let returnFocus = null
	let returnRow = null
	let uid = null
	let seq = 0
	let sayTimer = null
	/** @type {Object<string, object>} state of each module of the open user */
	let modules = {}

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(props)) node[key] = typeof value === 'function' ? lifetime.guard(value) : value
		node.append(...children)
		return node
	}
	function say(message, error = false) {
		lifetime.clearTimeout(sayTimer)
		status.textContent = ''
		alertBox.textContent = ''
		if (message === '') return
		const region = error ? alertBox : status
        // Paint the final text immediately so the announcement delay cannot move a pointer target.
        // Keep only this temporary text out of the accessibility tree; the live region itself stays present.
        const visible = el('span', { textContent: message })
        visible.setAttribute('aria-hidden', 'true')
        region.append(visible)
        sayTimer = lifetime.timeout(() => { region.replaceChildren(document.createTextNode(message)) }, SAY_DELAY)
	}
	const api = lifetime.request(request)
	function scopePath(module, suffix = '', target = uid) {
		return '/api/scope/' + encodeURIComponent(target) + '/' + module + suffix
	}
    function isCurrent(state) {
        return !lifetime.disposed && state && state.uid === uid && state.generation === seq && modules[state.module] === state
    }

	function title(module) {
		return { files: t('mcp', 'Files: folders'), calendar: t('mcp', 'Calendar: calendars'), tasks: t('mcp', 'Tasks: task lists') }[module]
	}

	function failure(error) {
		if (error.message === '403') return t('mcp', 'Only administrators can change the resource scope.')
		if (error.message === '400') return t('mcp', 'Check the user ID and try again.')
		if (error.message === '503') return t('mcp', 'The MCP policy cannot be read right now. Nothing was changed. Try again shortly.')
		return t('mcp', 'The request failed. Try again.')
	}

	/**
	 * A message of one module: shown under it and announced through the page's live regions, which exist from the
	 * start (a region created with its text already in it is not reliably announced).
	 */
	function note(state, message, error = false) {
        if (!isCurrent(state)) return
		state.line = message
		if (message !== '') say(title(state.module) + ': ' + message, error)
	}

	/** @return {boolean} whether the draft differs from what is saved */
	function dirty(state) {
		const ids = (items) => items.map((item) => item.id).sort().join(',')
		return state.mode !== state.savedMode || (state.mode === 'restricted' && ids(state.items) !== ids(state.savedItems))
	}

	async function openUser(target, focusModule) {
		const mine = ++seq
		uid = target
		modules = {}
		currentTab = focusModule || MODULES[0]
		tabs?.replaceChildren()
		container.replaceChildren()
		container.setAttribute('aria-busy', 'true')
		say(t('mcp', 'Loading the resource scope…'))
		try {
			const loaded = await Promise.all(MODULES.map((module) => api('GET', scopePath(module))))
			if (mine !== seq) return
			loaded.forEach((data) => {
				modules[data.module] = {
					uid: target, generation: mine, module: data.module, active: data.active, limit: data.limit, revision: data.revision,
					mode: data.mode, savedMode: data.mode, items: [...data.items], savedItems: [...data.items],
					search: '', parents: [], results: null, page: 1, undo: null, saving: false, stale: false,
				}
			})
			MODULES.forEach((module) => container.append(renderModule(modules[module])))
			renderTabs()
			say(t('mcp', 'Resource scope of {user}.', { user: uid }, undefined, { escape: false }))
			const heading = find('mcp-scope-heading-' + (focusModule || MODULES[0]))
			if (focusModule && heading) {
				heading.scrollIntoView?.({ block: 'start' })
				heading.focus?.()
			}
		} catch (error) {
            if (lifetime.disposed) return
			if (mine !== seq) return
			uid = null
			say(failure(error), true)
		} finally {
            if (lifetime.disposed) return
			if (mine === seq) container.setAttribute('aria-busy', 'false')
		}
	}

	/** Reads one module again from the server; its draft is replaced by the saved scope and its revision. */
	async function reload(state) {
        if (!isCurrent(state)) return
		const data = await api('GET', scopePath(state.module, '', state.uid))
        if (!isCurrent(state)) return
		Object.assign(state, { active: data.active, revision: data.revision, stale: false,
			mode: data.mode, savedMode: data.mode, items: [...data.items], savedItems: [...data.items] })
		rerender(state)
	}

	/**
	 * The policy revision moved (a save, an undo, a conflict): the three modules are read again. A module with an
	 * unsaved draft keeps it. When it already took the revision this page's own write made (`current`), the draft is
	 * still valid and stays as it is. Otherwise it keeps the revision it was read at, and says so: saving it is refused
	 * by the server (409) rather than accepted against a scope it never saw, and discarding it loads the saved scope.
	 *
	 * @param {object} origin the module that saved, undid or was refused
	 * @param {string} [current] revision made by this page's own write, when it committed
	 */
	async function reloadAll(origin, current) {
        if (!isCurrent(origin)) return false
        let readable = true
		for (const state of Object.values(modules)) {
            if (!isCurrent(origin)) return false
			if (state === origin || !dirty(state)) {
				try {
                    await reload(state)
                    if (!isCurrent(origin)) return false
                } catch (error) {
                    if (!isCurrent(state)) return
                    state.stale = true
                    readable = false
                    note(state, failure(error), true)
                    rerender(state)
                }
			} else if (current === undefined || state.revision !== current) {
				state.stale = true
				state.line = t('mcp', 'Changed before the policy moved, so saving will be refused. Discard the changes to load the saved scope.')
				rerender(state)
			}
		}
        return readable
	}

	function rerender(state, focusId) {
        if (!isCurrent(state)) return
		const old = find('mcp-scope-module-' + state.module)
		const fresh = renderModule(state)
		if (old) old.replaceWith(fresh)
		renderTabs()
		if (focusId) find(focusId)?.focus?.()
	}

	/** @return {string} the tab of a module: its saved state, and whether a draft is not saved */
	function tabLabel(state) {
		const name = { files: t('mcp', 'Files'), calendar: t('mcp', 'Calendar'), tasks: t('mcp', 'Tasks') }[state.module]
		const saved = state.savedMode === 'restricted'
			? t('mcp', '{module}: {count}', { module: name, count: state.savedItems.length }, undefined, { escape: false })
			: t('mcp', '{module}: all', { module: name }, undefined, { escape: false })
		return dirty(state) ? saved + ' (' + t('mcp', 'not saved') + ')' : saved
	}

	/** The module tabs of the drawer (WAI-ARIA tabs: the arrow keys, Home and End move between them). */
	function renderTabs() {
		if (!tabs) return
		const shown = MODULES.filter((module) => modules[module])
		tabs.replaceChildren(...shown.map((module, index) => {
			const selected = module === currentTab
			const tab = el('button', { type: 'button', id: 'mcp-scope-tab-' + module, textContent: tabLabel(modules[module]), tabIndex: selected ? 0 : -1 })
			tab.setAttribute('role', 'tab')
			tab.setAttribute('aria-selected', String(selected))
			tab.setAttribute('aria-controls', 'mcp-scope-module-' + module)
			lifetime.on(tab, 'click', () => selectTab(module, true))
			lifetime.on(tab, 'keydown', (event) => {
				const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: shown.length - 1 }[event.key]
				if (next === undefined) return
				event.preventDefault?.()
				selectTab(shown[(next + shown.length) % shown.length], true)
			})
			return tab
		}))
	}

	/** Shows one module's section and marks its tab. */
	function selectTab(module, focus) {
		currentTab = module
		for (const other of MODULES) {
			const section = find('mcp-scope-module-' + other)
			if (section) section.hidden = Boolean(tabs) && other !== module
		}
		renderTabs()
		if (focus) find('mcp-scope-tab-' + module)?.focus?.()
	}

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

	/** Opens the drawer; the focus goes back to `trigger` when it closes. */
	function openDrawer(trigger) {
		// Only one drawer is open at a time: the inspector closes when the scope opens.
		getInspector()?.close?.()
		returnFocus = trigger || document.activeElement || null
		// The row of a "…" button that opened the drawer: the table may be drawn again meanwhile, and its new button is
		// the one that gets the focus back.
		returnRow = returnFocus?.closest?.('tr')?.dataset?.uid ?? null
		root.classList?.add('mcp-drawer-open')
		root.setAttribute('role', 'dialog')
		placeDrawer()
	}

	/** @return {boolean} whether a module has a draft that is not saved */
	function hasDraft() {
		return Object.values(modules).some(dirty)
	}

	/** Drops the drafts the administrator agreed to lose, so they never come back when the drawer opens again. */
	function forget() {
		seq++
		uid = null
		modules = {}
		tabs?.replaceChildren()
		container.replaceChildren()
	}

	/**
	 * Closes the drawer, asking first when a draft of a module would be lost.
	 *
	 * @return {boolean} whether the drawer is closed now: false when the administrator kept the draft
	 */
	function closeDrawer() {
		if (!root.classList?.contains('mcp-drawer-open')) return true
		if (hasDraft()) {
			if (!window.confirm(t('mcp', 'Close the resource scope? The changes that are not saved are lost.'))) return false
			forget()
		}
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
	 * Whether the drafts of the open user may be lost to open another one (or the same one read again): asked, never
	 * assumed.
	 *
	 * @return {boolean}
	 */
	function mayReplace() {
		return !hasDraft() || window.confirm(t('mcp', 'Open another person? The changes that are not saved for {user} are lost.', { user: uid }, undefined, { escape: false }))
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

	async function browse(state, focusId, append = false) {
        if (!isCurrent(state)) return
		const query = { search: state.search, page: String(state.page) }
		const here = state.parents[state.parents.length - 1]
		if (state.module === 'files' && here) query.parent = here.id
		try {
			const data = await api('GET', scopePath(state.module, '/candidates', state.uid) + '?' + new URLSearchParams(query))
            if (!isCurrent(state)) return
			state.results = append && state.results ? Object.assign(data, { items: [...state.results.items, ...data.items] }) : data
			state.line = ''
		} catch (error) {
            if (!isCurrent(state)) return
			state.results = null
			note(state, failure(error), true)
		}
		rerender(state, focusId)
	}

	async function saveModule(state) {
		if (!isCurrent(state) || state.saving) return
		// The revision this module's draft was read at, never one another module moved to since.
		const body = state.mode === 'unrestricted'
			? { expectedRevision: state.revision, mode: 'unrestricted' }
			: { expectedRevision: state.revision, mode: 'restricted', items: state.items.map((item) => item.id) }
		state.saving = true
		note(state, t('mcp', 'Saving…'))
		rerender(state)
		try {
			const result = await api('PUT', scopePath(state.module, '', state.uid), body, { uncertainWrite: true })
			state.saving = false
			announce(body.expectedRevision, result.revision)
            if (!isCurrent(state)) {
                events.dispatchEvent(new Event('mcp-scope-saved'))
                return
            }
			const readable = await reloadAll(state, result.revision)
            if (!isCurrent(state)) return
			if (readable) note(state, result.changed ? t('mcp', 'Saved. It applies from the next MCP call of the user; sessions are not ended.') : t('mcp', 'Nothing changed.'))
			if (result.changed && result.receiptId) offerUndo(state, result.receiptId, result.revision)
			rerender(state, 'mcp-scope-line-' + state.module)
			events.dispatchEvent(new Event('mcp-scope-saved'))
		} catch (error) {
            if (!isCurrent(state)) return
			state.saving = false
			await saveFailed(state, error)
		}
	}

	async function saveFailed(state, error) {
        if (!isCurrent(state)) return
		const data = error.data || {}
		if (error.message === '409') {
			// Never written again from here: the admin sees what is saved now and decides.
			note(state, t('mcp', 'The policy changed after this scope was loaded, so nothing was saved. The saved scope is shown again.'), true)
			const line = state.line
			await reloadAll(state)
            if (!isCurrent(state)) return
			state.line = line
		} else if (error.message === '422' && data.error === 'ScopeInactive') {
			state.active = false
			note(state, t('mcp', 'Not saved: the tools of this module do not apply a resource scope in this version.'), true)
		} else if (error.message === '422' && data.error === 'Inaccessible') {
			const missing = new Set(data.items || [])
			state.items = state.items.map((item) => missing.has(item.id) ? Object.assign({}, item, { inaccessible: true }) : item)
			note(state, t('mcp', 'Not saved: the user cannot reach the marked resources in Nextcloud. Remove them and save again.'), true)
		} else if (error.message === '422' && data.error === 'TooManyResources') {
			note(state, t('mcp', 'Not saved: at most {limit} resources can be selected.', { limit: data.limit || state.limit }, undefined, { escape: false }), true)
		} else if (error.message === '503' && data.uncertain) {
			note(state, t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.'), true)
			const line = state.line
			await reloadAll(state)
            if (!isCurrent(state)) return
			state.line = line
		} else {
			note(state, failure(error), true)
		}
		rerender(state, 'mcp-scope-line-' + state.module)
	}

	function offerUndo(state, receiptId, savedRevision) {
        if (!isCurrent(state)) return
		const offer = { receiptId, savedRevision, hover: false, focus: false, expired: false }
		state.undo = offer
		lifetime.timeout(() => {
			offer.expired = true
			expireUndo(state, offer)
		}, UNDO_SECONDS * 1000)
	}

	/** Removes an expired undo once neither the pointer nor the focus holds it; the focus never falls to the page. */
	function expireUndo(state, offer) {
        if (!isCurrent(state)) return
		if (state.undo !== offer || !offer.expired || offer.hover || offer.focus) return
		const focused = document.activeElement && document.activeElement.id === 'mcp-scope-undo-' + state.module
		state.undo = null
		// Expiry changes only the undo control; replacing the tabs during blur loses the destination focus.
        find('mcp-scope-undo-' + state.module)?.remove()
        if (focused) find('mcp-scope-line-' + state.module)?.focus()
	}

	async function undoModule(state) {
		if (!isCurrent(state) || state.saving || !state.undo) return
		const { receiptId, savedRevision } = state.undo
		state.undo = null
		state.saving = true
		rerender(state)
		let current
		try {
			current = (await api('POST', '/api/grants/undo', { receiptId, expectedRevision: savedRevision }, { uncertainWrite: true })).revision
			announce(savedRevision, current)
            if (!isCurrent(state)) {
                events.dispatchEvent(new Event('mcp-scope-saved'))
                return
            }
			note(state, t('mcp', 'Change undone.'))
			events.dispatchEvent(new Event('mcp-scope-saved'))
		} catch (error) {
            if (!isCurrent(state)) return
			note(state, error.message === '503' && error.data?.uncertain
                ? t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.')
                : error.message === '409'
				? t('mcp', 'The policy changed after this change, so it can no longer be undone.')
				: error.message === '410'
					? t('mcp', 'This change can no longer be undone: the time to undo it has passed.')
					: failure(error), true)
		}
		state.saving = false
		const line = state.line
		await reloadAll(state, current)
        if (!isCurrent(state)) return
		state.line = line
		rerender(state, 'mcp-scope-line-' + state.module)
	}

	/**
	 * Tells the other blocks of the page that a write of this panel moved the revision from `from` to `to`.
	 *
	 * @param {string} from revision the write was sent with
	 * @param {string} to revision the server answered with
	 */
	function announce(from, to) { policyChannel.announce(from, to) }

	/**
	 * The matrix cell mark of a module that is restricted, or whose scope cannot be read: visible text, no colour or
	 * icon alone, and a way into the panel. An unrestricted module gets no mark.
	 *
	 * @param {string} target uid of the row
	 * @param {string} module scope module
	 * @param {{mode: string, count: ?number}} scope the cell's scope from the matrix
	 * @return {HTMLButtonElement|null}
	 */
	function indicator(target, module, scope) {
		const unknown = scope.mode === 'unavailable'
		if (!unknown && scope.mode !== 'restricted') return null
		const mark = el('span', { className: 'mcp-scope-mark', textContent: unknown ? '?' : '◩' })
		mark.setAttribute('aria-hidden', 'true')
		const button = el('button', { type: 'button', className: 'mcp-scope-indicator' }, [mark, ' ' + (unknown ? t('mcp', 'Scope unknown') : t('mcp', 'Restricted to {count}', { count: scope.count }, undefined, { escape: false }))])
		button.title = unknown
			? t('mcp', 'The scope cannot be read now, so the tools of this module refuse until it can. Open the resource scope.')
			: t('mcp', 'The MCP tools of this user reach only the selected resources of this module. Open the resource scope.')
		lifetime.on(button, 'click', () => open(target, module, button))
		return button
	}

	/**
	 * Opens the drawer on one user, at one module when given.
	 *
	 * @param {string} target user ID
	 * @param {string} [module] module whose tab opens
	 * @param {HTMLElement} [trigger] control the focus goes back to when the drawer closes
	 */
	function open(target, module, trigger) {
		if (!root) return false
		// The same person in the open drawer: only the tab changes, and the draft stays.
		if (root.classList?.contains('mcp-drawer-open') && target === uid && Object.keys(modules).length > 0) {
			if (module) selectTab(module, true)
			return true
		}
		// The drawer is not modal: another person can be opened from the matrix while a draft is open.
		if (!mayReplace()) {
			trigger?.focus?.()
			return false
		}
		openDrawer(trigger)
		userInput.value = target
		drawerTitle?.focus?.()
		openUser(target, module)
		return true
	}

	const controller = { indicator, open, close: closeDrawer }
	// A module exactly at the revision a write of this page committed from takes the one it made; its draft stays.
	lifetime.use(policyChannel.follow((from, to) => {
		for (const state of Object.values(modules)) {
			if (state.revision === from) state.revision = to
		}
	}))
	// A matrix drawn before this script ran is drawn again, now with the scope marks.
	if (!root) return
	lifetime.on(entryButton, 'click', () => {
		openDrawer(entryButton)
		userInput.focus?.()
	})
	lifetime.on(closeButton, 'click', () => closeDrawer())
	lifetime.on(root, 'keydown', (event) => {
		if (event.key === 'Escape' && root.classList?.contains('mcp-drawer-open')) {
			event.preventDefault?.()
			closeDrawer()
		}
	})
	lifetime.on(form, 'submit', (event) => {
		event.preventDefault()
		const target = userInput.value.trim()
		if (target === '') {
			say(t('mcp', 'Enter the user ID.'), true)
			return
		}
		if (!mayReplace()) return
		openUser(target, null)
	})

	const ready = Promise.resolve()

    return { ...controller, ready, destroy() {
        lifetime.destroy(); seq++; uid = null; modules = {}
        root.classList.remove('mcp-drawer-open'); root.removeAttribute('role'); root.style.removeProperty('top')
        tabs.replaceChildren(); container.replaceChildren(); container.removeAttribute('aria-busy')
        status.textContent = ''; alertBox.textContent = ''
    } }
}
