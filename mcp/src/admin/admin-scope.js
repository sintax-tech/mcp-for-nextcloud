/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Resource scope panel of the admin page (issue #4, B7; contracts B.6). Plain JavaScript, no build. One user at a
 * time: per module of the scope (Files, Calendar, Tasks) the administrator chooses "All resources" or "Only
 * selected" and picks folders, calendars or task lists from the user's own Nextcloud resources, through the admin
 * routes of the scope (GET /api/scope/{uid}/{module}, its candidates, PUT with expectedRevision, undo through
 * /api/grants/undo). Nothing here decides access: the server validates every item and the tools apply the scope.
 *
 * A module whose activation barrier is still closed on the server (`active: false`) is shown with its saved state
 * and browsable, but cannot be saved, and says so: the tools of that module do not apply a scope yet. A conflict
 * (409) never repeats the write: the current scope is read again and shown. Each module keeps the revision its own
 * draft was read at, so a draft made before a change elsewhere is refused by the server instead of overwriting it.
 * A write of this page (a module here, or the matrix, OAuth clients or server log of src/admin/admin-grants.js) is announced
 * with the revision it was sent with and the one it made (event "mcp-revision-moved"); a module still at the first
 * takes the second and keeps its draft, since nothing but that write lies between them.
 * Exposes window.OCA.Mcp.Admin.scope for the matrix, which shows "Restricted to N" (or "Scope unknown") in the module's first
 * cell and opens this panel from it.
 *
 * The panel is a drawer at the side of the page (S9): the Access tab shows one sentence and a button, and the drawer
 * opens from that button, from a "Restricted to N" cell or from the actions menu of a matrix row. Its modules are tabs
 * that say the saved state ("Files: 3", "Calendar: all") and whether a draft is not saved. Escape and the close button
 * close it, asking first when a draft would be lost, and the focus goes back to the control that opened it. The drawer
 * is not modal, so the matrix stays usable beside it: opening another person while a draft is open asks first too, and
 * close() says whether the drawer really closed, so another drawer never opens over a draft that was kept.
 */
(function () {
	'use strict'
	const MODULES = ['files', 'calendar', 'tasks']
	const UNDO_SECONDS = 10
	/** Delay before a message is written, so the same message twice in a row is announced twice (as in the matrix). */
	const SAY_DELAY = 60
	const root = document.getElementById('mcp-scope')
	const form = document.getElementById('mcp-scope-form')
	const userInput = document.getElementById('mcp-scope-user')
	const status = document.getElementById('mcp-scope-status')
	const alertBox = document.getElementById('mcp-scope-alert')
	const container = document.getElementById('mcp-scope-modules')
	const tabs = document.getElementById('mcp-scope-tabs')
	const drawerTitle = document.getElementById('mcp-scope-title')
	const closeButton = document.getElementById('mcp-scope-close')
	const entryButton = document.getElementById('mcp-scope-entry')
	/** The module whose tab is open, and the control the focus goes back to when the drawer closes. */
	let currentTab = 'files'
	let returnFocus = null
	let returnRow = null
	let uid = null
	let seq = 0
	let sayTimer = null
	/** @type {Object<string, object>} state of each module of the open user */
	let modules = {}
	/** Page event of a write this page committed: detail {from, to}; src/admin/admin-grants.js sends and follows it too. */
	const REVISION_MOVED = 'mcp-revision-moved'

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		Object.assign(node, props)
		node.append(...children)
		return node
	}
	function say(message, error = false) {
		clearTimeout(sayTimer)
		status.textContent = ''
		alertBox.textContent = ''
		if (message === '') return
		const region = error ? alertBox : status
		sayTimer = setTimeout(() => { region.textContent = message }, SAY_DELAY)
	}
	async function api(method, path, query, body) {
		const url = OC.generateUrl('/apps/mcp' + path) + (query ? '?' + new URLSearchParams(query) : '')
		const response = await fetch(url, {
			method,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		// A 2xx HTML response cannot confirm a save or undo; retain HTTP status handling for errors.
		const data = await response.json().catch((error) => {
			if (response.ok) throw Object.assign(new Error('unconfirmed'), { cause: error })
			return {}
		})
		if (!response.ok) throw Object.assign(new Error(String(response.status)), { status: response.status, data })
		return data
	}
	function scopePath(module, suffix = '') {
		return '/api/scope/' + encodeURIComponent(uid) + '/' + module + suffix
	}
	function title(module) {
		return { files: t('mcp', 'Files: folders'), calendar: t('mcp', 'Calendar: calendars'), tasks: t('mcp', 'Tasks: task lists') }[module]
	}
	function emptyText(module) {
		return {
			files: t('mcp', 'Nothing selected: the Files tools of this user reach no folder.'),
			calendar: t('mcp', 'Nothing selected: the Calendar tools of this user reach no calendar.'),
			tasks: t('mcp', 'Nothing selected: the Tasks tools of this user reach no task list.'),
		}[module]
	}
	function failure(error) {
		if (error.status === 403) return t('mcp', 'Only administrators can change the resource scope.')
		if (error.status === 400) return t('mcp', 'Check the user ID and try again.')
		if (error.status === 503) return t('mcp', 'The MCP policy cannot be read right now. Nothing was changed. Try again shortly.')
		return t('mcp', 'The request failed. Try again.')
	}
	/** What a saved or selected item is called on screen: its path for a folder, its name otherwise. */
	function label(item) {
		if (item.found === false) return t('mcp', 'Resource no longer found ({id})', { id: item.id }, undefined, { escape: false })
		return item.path || item.name
	}
	function badges(item) {
		const out = []
		if (item.found === false) out.push(el('span', { className: 'mcp-badge', textContent: t('mcp', 'not found: it has no effect') }))
		if (item.excluded) out.push(el('span', { className: 'mcp-badge', textContent: t('mcp', 'excluded by a tag: choosing it has no effect') }))
		if (item.inaccessible) out.push(el('span', { className: 'mcp-badge mcp-badge-error', textContent: t('mcp', 'the user cannot reach it in Nextcloud') }))
		return out
	}

	/**
	 * A message of one module: shown under it and announced through the page's live regions, which exist from the
	 * start (a region created with its text already in it is not reliably announced).
	 */
	function note(state, message, error = false) {
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
					module: data.module, active: data.active, limit: data.limit, revision: data.revision,
					mode: data.mode, savedMode: data.mode, items: [...data.items], savedItems: [...data.items],
					search: '', parents: [], results: null, page: 1, undo: null, saving: false, stale: false,
				}
			})
			MODULES.forEach((module) => container.append(renderModule(modules[module])))
			renderTabs()
			say(t('mcp', 'Resource scope of {user}.', { user: uid }, undefined, { escape: false }))
			const heading = document.getElementById('mcp-scope-heading-' + (focusModule || MODULES[0]))
			if (focusModule && heading) {
				heading.scrollIntoView?.({ block: 'start' })
				heading.focus?.()
			}
		} catch (error) {
			if (mine !== seq) return
			uid = null
			say(failure(error), true)
		} finally {
			if (mine === seq) container.setAttribute('aria-busy', 'false')
		}
	}

	/** Reads one module again from the server; its draft is replaced by the saved scope and its revision. */
	async function reload(state) {
		const data = await api('GET', scopePath(state.module))
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
		for (const module of MODULES) {
			const state = modules[module]
			if (state === origin || !dirty(state)) {
				await reload(state).catch(() => {})
			} else if (current === undefined || state.revision !== current) {
				state.stale = true
				state.line = t('mcp', 'Changed before the policy moved, so saving will be refused. Discard the changes to load the saved scope.')
				rerender(state)
			}
		}
	}

	function rerender(state, focusId) {
		const old = document.getElementById('mcp-scope-module-' + state.module)
		const fresh = renderModule(state)
		if (old) old.replaceWith(fresh)
		renderTabs()
		if (focusId) document.getElementById(focusId)?.focus?.()
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
			tab.addEventListener('click', () => selectTab(module, true))
			tab.addEventListener('keydown', (event) => {
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
			const section = document.getElementById('mcp-scope-module-' + other)
			if (section) section.hidden = Boolean(tabs) && other !== module
		}
		renderTabs()
		if (focus) document.getElementById('mcp-scope-tab-' + module)?.focus?.()
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
		window.OCA.Mcp.Admin.inspector?.close?.()
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
		window.dispatchEvent?.(new Event('mcp-drawer-closed'))
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
		const rows = [...(document.getElementById('mcp-matrix')?.tBodies?.[0]?.rows || [])]
		const row = returnRow === null ? null : rows.find((candidate) => candidate.dataset.uid === returnRow)
		;(row?.querySelector('button.mcp-row-actions-button') || entryButton)?.focus?.()
	}

	function renderModule(state) {
		const module = state.module
		const section = el('section', { id: 'mcp-scope-module-' + module, className: 'mcp-scope-module', hidden: Boolean(tabs) && module !== currentTab })
		const heading = el('h3', { id: 'mcp-scope-heading-' + module, textContent: title(module), tabIndex: -1 })
		section.setAttribute('aria-labelledby', heading.id)
		section.append(heading)
		if (state.stale) {
			section.append(el('p', { className: 'mcp-scope-inactive', textContent: t('mcp', 'Changed before the policy moved, so saving will be refused. Discard the changes to load the saved scope.') }))
		}
		if (!state.active) {
			section.append(el('p', { className: 'mcp-scope-inactive', textContent: t('mcp', 'Not available in this version: the tools of this module do not apply a scope yet, so nothing can be saved. You can still browse.') }))
		}
		const fieldset = el('fieldset', {}, [el('legend', { textContent: t('mcp', 'Resources the MCP tools of {user} may reach', { user: uid }, undefined, { escape: false }) })])
		;[['unrestricted', t('mcp', 'All resources')], ['restricted', t('mcp', 'Only selected')]].forEach(([value, text]) => {
			const id = 'mcp-scope-mode-' + module + '-' + value
			const radio = el('input', { type: 'radio', name: 'mcp-scope-mode-' + module, id, value, checked: state.mode === value })
			radio.addEventListener('change', () => {
				if (!radio.checked) return
				state.mode = value
				rerender(state, id)
			})
			fieldset.append(el('span', { className: 'mcp-scope-choice' }, [radio, el('label', { htmlFor: id, textContent: text })]))
		})
		section.append(fieldset)
		if (state.mode === 'restricted') {
			section.append(renderSelection(state), renderPicker(state))
		}
		// Nothing can be sent twice: Save, Discard and Undo wait for the answer of the request in flight.
		const save = el('button', { type: 'button', id: 'mcp-scope-save-' + module, textContent: t('mcp', 'Save'), disabled: state.saving || !state.active || !dirty(state) })
		save.addEventListener('click', () => saveModule(state))
		const discard = el('button', { type: 'button', id: 'mcp-scope-discard-' + module, textContent: t('mcp', 'Discard changes'), disabled: state.saving || (!dirty(state) && !state.stale) })
		discard.addEventListener('click', async () => {
			if (state.stale) {
				// A draft of an older revision: what is saved now comes from the server, not from the old copy.
				await reload(state).catch((error) => note(state, failure(error), true))
				rerender(state, 'mcp-scope-mode-' + module + '-' + state.mode)
				return
			}
			Object.assign(state, { mode: state.savedMode, items: [...state.savedItems] })
			rerender(state, 'mcp-scope-mode-' + module + '-' + state.mode)
		})
		const actions = el('div', { className: 'mcp-scope-actions' }, [save, discard])
		if (state.undo) {
			const offer = state.undo
			const undo = el('button', { type: 'button', id: 'mcp-scope-undo-' + module, textContent: t('mcp', 'Undo'), disabled: state.saving })
			undo.addEventListener('click', () => undoModule(state))
			// The ten seconds pause while the pointer or the focus is on the button (WCAG 2.2.1).
			const hold = (key, value) => () => {
				offer[key] = value
				if (!value) expireUndo(state, offer)
			}
			undo.addEventListener('mouseenter', hold('hover', true))
			undo.addEventListener('mouseleave', hold('hover', false))
			undo.addEventListener('focus', hold('focus', true))
			undo.addEventListener('blur', hold('focus', false))
			actions.append(undo)
		}
		section.append(actions)
		section.append(el('p', { id: 'mcp-scope-line-' + module, className: 'mcp-scope-line', textContent: state.line || '', tabIndex: -1 }))
		return section
	}

	function renderSelection(state) {
		const module = state.module
		const wrap = el('div', { className: 'mcp-scope-selection' })
		wrap.append(el('p', { textContent: state.items.length === 0 ? emptyText(module) : t('mcp', '{count} of at most {limit} selected', { count: state.items.length, limit: state.limit }, undefined, { escape: false }) }))
		if (state.items.length > 0) {
			const list = el('ul', { className: 'mcp-scope-list' })
			list.setAttribute('aria-label', t('mcp', 'Selected resources'))
			state.items.forEach((item, index) => {
				const remove = el('button', { type: 'button', id: 'mcp-scope-remove-' + module + '-' + item.id, textContent: t('mcp', 'Remove') })
				remove.setAttribute('aria-label', t('mcp', 'Remove {name}', { name: label(item) }, undefined, { escape: false }))
				remove.addEventListener('click', () => {
					state.items = state.items.filter((other) => other.id !== item.id)
					// The focus goes to the next item, else the previous one, else the mode choice.
					const next = state.items[index] || state.items[index - 1]
					rerender(state, next ? 'mcp-scope-remove-' + module + '-' + next.id : 'mcp-scope-mode-' + module + '-restricted')
				})
				list.append(el('li', {}, [el('span', { textContent: label(item) }), ...badges(item), remove]))
			})
			wrap.append(list)
		}
		return wrap
	}

	function renderPicker(state) {
		const module = state.module
		const picker = el('div', { className: 'mcp-scope-picker' })
		const searchId = 'mcp-scope-search-' + module
		const search = el('input', { type: 'search', id: searchId, value: state.search, autocomplete: 'off', spellcheck: false })
		const go = el('button', { type: 'button', textContent: t('mcp', 'Search') })
		const run = () => { state.search = search.value; state.page = 1; state.results = null; browse(state, searchId) }
		go.addEventListener('click', run)
		search.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); run() } })
		picker.append(el('label', { htmlFor: searchId, textContent: module === 'files' ? t('mcp', 'Find a folder of the user') : t('mcp', 'Find a calendar or task list of the user') }), search, go)
		if (module === 'files') {
			const here = state.parents[state.parents.length - 1]
			picker.append(el('p', { className: 'mcp-scope-where', textContent: here ? t('mcp', 'In folder {path}', { path: here.path }, undefined, { escape: false }) : t('mcp', 'In the top folder of the user') }))
			if (here) {
				const up = el('button', { type: 'button', id: 'mcp-scope-up-files', textContent: t('mcp', 'Up one folder') })
				up.addEventListener('click', () => { state.parents.pop(); state.page = 1; state.results = null; browse(state, 'mcp-scope-up-files') })
				picker.append(up, addButton(state, here, t('mcp', 'Add this folder')))
			}
		}
		const results = el('ul', { className: 'mcp-scope-results' })
		results.setAttribute('aria-label', t('mcp', 'Resources found'))
		const count = el('p', { className: 'mcp-scope-count' })
		count.setAttribute('aria-live', 'polite')
		if (state.results === null) {
			count.textContent = t('mcp', 'Search, or press Search with an empty field to list everything.')
		} else {
			count.textContent = state.results.total === 0 ? t('mcp', 'Nothing found.') : t('mcp', '{shown} of {total} shown', { shown: state.results.items.length, total: state.results.total }, undefined, { escape: false })
			state.results.items.forEach((item) => {
				const row = el('li', {}, [el('span', { textContent: label(item) }), ...badges(item), addButton(state, item)])
				if (module === 'files') {
					const open = el('button', { type: 'button', textContent: t('mcp', 'Open') })
					open.setAttribute('aria-label', t('mcp', 'Open {name}', { name: label(item) }, undefined, { escape: false }))
					open.addEventListener('click', () => { state.parents.push(item); state.page = 1; state.results = null; state.search = ''; browse(state, searchId) })
					row.append(open)
				}
				results.append(row)
			})
			if (state.results.hasMore) {
				const more = el('button', { type: 'button', id: 'mcp-scope-more-' + module, textContent: t('mcp', 'Show more') })
				more.addEventListener('click', () => { state.page += 1; browse(state, 'mcp-scope-more-' + module, true) })
				picker.append(results, count, more)
				return picker
			}
		}
		picker.append(results, count)
		return picker
	}

	function addButton(state, item, text) {
		const chosen = state.items.some((other) => other.id === item.id)
		const add = el('button', { type: 'button', textContent: text || (chosen ? t('mcp', 'Added') : t('mcp', 'Add')), disabled: chosen })
		add.setAttribute('aria-label', chosen ? t('mcp', '{name} is selected', { name: label(item) }, undefined, { escape: false }) : t('mcp', 'Add {name}', { name: label(item) }, undefined, { escape: false }))
		add.addEventListener('click', () => {
			if (state.items.length >= state.limit) {
				note(state, t('mcp', 'At most {limit} resources can be selected.', { limit: state.limit }, undefined, { escape: false }), true)
				rerender(state)
				return
			}
			state.items = [...state.items, { id: item.id, name: item.name, path: item.path, found: true, excluded: item.excluded }]
			note(state, t('mcp', '{name} added. Save to apply it.', { name: label(item) }, undefined, { escape: false }))
			rerender(state, 'mcp-scope-search-' + state.module)
		})
		return add
	}

	async function browse(state, focusId, append = false) {
		const query = { search: state.search, page: String(state.page) }
		const here = state.parents[state.parents.length - 1]
		if (state.module === 'files' && here) query.parent = here.id
		try {
			const data = await api('GET', scopePath(state.module, '/candidates'), query)
			state.results = append && state.results ? Object.assign(data, { items: [...state.results.items, ...data.items] }) : data
			state.line = ''
		} catch (error) {
			state.results = null
			note(state, failure(error), true)
		}
		rerender(state, focusId)
	}

	async function saveModule(state) {
		if (state.saving) return
		// The revision this module's draft was read at, never one another module moved to since.
		const body = state.mode === 'unrestricted'
			? { expectedRevision: state.revision, mode: 'unrestricted' }
			: { expectedRevision: state.revision, mode: 'restricted', items: state.items.map((item) => item.id) }
		state.saving = true
		note(state, t('mcp', 'Saving…'))
		rerender(state)
		try {
			const result = await api('PUT', scopePath(state.module), null, body)
			state.saving = false
			announce(body.expectedRevision, result.revision)
			await reloadAll(state, result.revision)
			note(state, result.changed ? t('mcp', 'Saved. It applies from the next MCP call of the user; sessions are not ended.') : t('mcp', 'Nothing changed.'))
			if (result.changed && result.receiptId) offerUndo(state, result.receiptId, result.revision)
			rerender(state, 'mcp-scope-line-' + state.module)
			window.dispatchEvent(new Event('mcp-scope-saved'))
		} catch (error) {
			state.saving = false
			await saveFailed(state, error)
		}
	}

	async function saveFailed(state, error) {
		const data = error.data || {}
		if (error.message === 'unconfirmed') {
			note(state, t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.'), true)
			const line = state.line
			await reloadAll(state)
			state.line = line
		} else if (error.status === 409) {
			// Never written again from here: the admin sees what is saved now and decides.
			note(state, t('mcp', 'The policy changed after this scope was loaded, so nothing was saved. The saved scope is shown again.'), true)
			const line = state.line
			await reloadAll(state)
			state.line = line
		} else if (error.status === 422 && data.error === 'ScopeInactive') {
			state.active = false
			note(state, t('mcp', 'Not saved: the tools of this module do not apply a resource scope in this version.'), true)
		} else if (error.status === 422 && data.error === 'Inaccessible') {
			const missing = new Set(data.items || [])
			state.items = state.items.map((item) => missing.has(item.id) ? Object.assign({}, item, { inaccessible: true }) : item)
			note(state, t('mcp', 'Not saved: the user cannot reach the marked resources in Nextcloud. Remove them and save again.'), true)
		} else if (error.status === 422 && data.error === 'TooManyResources') {
			note(state, t('mcp', 'Not saved: at most {limit} resources can be selected.', { limit: data.limit || state.limit }, undefined, { escape: false }), true)
		} else if (error.status === 503 && data.uncertain) {
			note(state, t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.'), true)
			const line = state.line
			await reloadAll(state)
			state.line = line
		} else {
			note(state, failure(error), true)
		}
		rerender(state, 'mcp-scope-line-' + state.module)
	}

	function offerUndo(state, receiptId, savedRevision) {
		const offer = { receiptId, savedRevision, hover: false, focus: false, expired: false }
		state.undo = offer
		setTimeout(() => {
			offer.expired = true
			expireUndo(state, offer)
		}, UNDO_SECONDS * 1000)
	}

	/** Removes an expired undo once neither the pointer nor the focus holds it; the focus never falls to the page. */
	function expireUndo(state, offer) {
		if (state.undo !== offer || !offer.expired || offer.hover || offer.focus) return
		const focused = document.activeElement && document.activeElement.id === 'mcp-scope-undo-' + state.module
		state.undo = null
		rerender(state, focused ? 'mcp-scope-line-' + state.module : undefined)
	}

	async function undoModule(state) {
		if (state.saving) return
		const { receiptId, savedRevision } = state.undo
		state.undo = null
		state.saving = true
		rerender(state)
		let current
		try {
			current = (await api('POST', '/api/grants/undo', null, { receiptId, expectedRevision: savedRevision })).revision
			announce(savedRevision, current)
			note(state, t('mcp', 'Change undone.'))
			window.dispatchEvent(new Event('mcp-scope-saved'))
		} catch (error) {
			note(state, error.message === 'unconfirmed'
				? t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.')
				: error.status === 409
					? t('mcp', 'The policy changed after this change, so it can no longer be undone.')
					: error.status === 410
						? t('mcp', 'This change can no longer be undone: the time to undo it has passed.')
						: failure(error), true)
		}
		state.saving = false
		const line = state.line
		await reloadAll(state, current)
		state.line = line
		rerender(state, 'mcp-scope-line-' + state.module)
	}

	/**
	 * Tells the other blocks of the page that a write of this panel moved the revision from `from` to `to`.
	 *
	 * @param {string} from revision the write was sent with
	 * @param {string} to revision the server answered with
	 */
	function announce(from, to) {
		if (typeof from === 'string' && typeof to === 'string' && from !== to) {
			window.dispatchEvent(new CustomEvent(REVISION_MOVED, { detail: { from, to } }))
		}
	}

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
		button.addEventListener('click', () => open(target, module, button))
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

	window.OCA.Mcp.Admin.scope = { indicator, open, close: closeDrawer }
	// A module exactly at the revision a write of this page committed from takes the one it made; its draft stays.
	window.addEventListener(REVISION_MOVED, (event) => {
		for (const state of Object.values(modules)) {
			if (state.revision === event.detail.from) state.revision = event.detail.to
		}
	})
	// A matrix drawn before this script ran is drawn again, now with the scope marks.
	window.OCA.Mcp.Admin.matrix?.refresh?.()
	if (!root) return
	entryButton?.addEventListener('click', () => {
		openDrawer(entryButton)
		userInput.focus?.()
	})
	closeButton?.addEventListener('click', () => closeDrawer())
	root.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && root.classList?.contains('mcp-drawer-open')) {
			event.preventDefault?.()
			closeDrawer()
		}
	})
	form.addEventListener('submit', (event) => {
		event.preventDefault()
		const target = userInput.value.trim()
		if (target === '') {
			say(t('mcp', 'Enter the user ID.'), true)
			return
		}
		if (!mayReplace()) return
		openUser(target, null)
	})
})()
