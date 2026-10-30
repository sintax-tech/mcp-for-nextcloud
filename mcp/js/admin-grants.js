/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Admin permission matrix (users × permissions) for the MCP app.
 *
 * Loads one page of users from GET /apps/mcp/api/grants and saves each checkbox immediately through
 * PUT /apps/mcp/api/grants/{uid}, POST /apps/mcp/api/grants/commands and PUT /apps/mcp/api/service.
 * Every save carries the policy revision the page was built from (a decimal string, never parsed as a
 * number); when the server answers 409 or 428 someone else saved first, so the page reloads instead
 * of repeating the write. The matrix revision is only ever the one of the matrix on screen: from the
 * GET that was rendered, or from a matrix save that committed from it. The server-log block keeps its
 * own, from its own GET. A revision found in an error body is never adopted.
 * A write this page committed is announced to the other blocks of the page (event "mcp-revision-moved", with the
 * revision it was sent with and the one it made). The OAuth clients, server-log and resource scope blocks, when still
 * at the first, adopt the second and keep their unsaved changes: under compare-and-swap nothing but that write lies
 * between the two. The matrix never adopts one; it is reloaded after the writes of the other blocks. A move this page
 * did not make (another window or administrator) is never announced, so the next save of a block still answers 409.
 * Plain browser JavaScript, no build step; strings go through t('mcp', …) for translation.
 */
(function () {
	'use strict'

	const root = document.getElementById('mcp-admin')
	if (!root) {
		return
	}

	/** Columns the matrix always shows besides the permissions: selection, user, "Can connect" and the row's actions. */
	const FIXED_COLUMNS = 4
	/**
	 * view is "levels" (one access level per module, js/admin-levels.js) or "operations" (a box per operation); app filters
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

	/** @return {Object<string, string>} module labels */
	function moduleLabels() {
		return {
			files: t('mcp', 'Files'),
			notes: t('mcp', 'Notes'),
			deck: t('mcp', 'Deck'),
			calendar: t('mcp', 'Calendar'),
			contacts: t('mcp', 'Contacts'),
			tasks: t('mcp', 'Tasks'),
			talk: t('mcp', 'Talk'),
			people: t('mcp', 'People'),
			logs: t('mcp', 'Server log'),
			mail: t('mcp', 'Mail'),
			tables: t('mcp', 'Tables'), forms: t('mcp', 'Forms'), polls: t('mcp', 'Polls'),
		}
	}

	/** @return {Object<string, string>} operation labels */
	function operationLabels() {
		return {
			...window.McpAdminPollsGrants?.operationLabels(),
			read: t('mcp', 'read'),
			results: t('mcp', 'results'),
			edit: t('mcp', 'edit'),
			create: t('mcp', 'create'),
			move: t('mcp', 'move'),
			delete: t('mcp', 'delete'),
			restore: t('mcp', 'restore'),
			transfer: t('mcp', 'transfer'),
			reply: t('mcp', 'reply'),
			attach: t('mcp', 'attach'),
			quote: t('mcp', 'quote'),
			share: t('mcp', 'share'),
			link: t('mcp', 'link'),
			messages: t('mcp', 'messages'),
			attachments: t('mcp', 'attachments'),
			flags: t('mcp', 'flags'),
			draft: t('mcp', 'draft'),
			send: t('mcp', 'send'),
		}
	}

	/**
	 * What each operation lets the AI do, shown as the column tooltip.
	 *
	 * @param {string} module module id
	 * @param {string} operation operation id
	 * @return {string} description
	 */
	function operationHint(module, operation) {
		if (module === 'talk' && operation === 'create') {
			return t('mcp', 'Open new conversations, which invites people')
		}
		if (module === 'files' && operation === 'create') {
			return t('mcp', 'Create folders, copies and new files')
		}
		if (module === 'logs' && operation === 'read') {
			return t('mcp', 'Read and analyze the server log; starts off')
		}
		if (module === 'calendar' && operation === 'transfer') {
			return t('mcp', 'Move an event to another person’s calendar')
		}
		const mailHint = window.McpAdminPollsGrants?.operationHint(module, operation) || (window.McpAdminMailGrants ? window.McpAdminMailGrants.operationHint(module, operation) : null)
		if (mailHint) return mailHint
		const hints = {
			read: t('mcp', 'List, search and read'),
			results: t('mcp', 'Reserved for reading responses allowed by native Forms permissions; starts off'),
			edit: t('mcp', 'Change existing items'),
			create: t('mcp', 'Create new items'),
			move: t('mcp', 'Move or rename items'),
			delete: t('mcp', 'Delete items'),
			restore: t('mcp', 'Restore a file to a stored version'),
			reply: t('mcp', 'Send messages and replies'),
			attach: t('mcp', 'Share a file into a conversation'),
			quote: t('mcp', 'Quote part of a file in a message'),
			share: t('mcp', 'Share own files with people and groups'),
			link: t('mcp', 'Create public links to own files'),
		}
		return hints[operation] || ''
	}

	/**
	 * @param {string} method HTTP method
	 * @param {string} path path below /apps/mcp
	 * @param {object} [body] JSON body
	 * @return {Promise<object>} decoded JSON response
	 */
	async function api(method, path, body) {
		const response = await fetch(OC.generateUrl('/apps/mcp' + path), {
			method,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		if (!response.ok) {
			const error = new Error(String(response.status))
			error.data = await response.json().catch(() => null)
			throw error
		}
		return response.json().catch((cause) => {
			// A proxy/session may return 2xx HTML after a write committed. Reuse the matrix's uncertain-save recovery.
			const matrixWrite = (method === 'PUT' && (path.startsWith('/api/grants/') || path === '/api/service'))
				|| (method === 'POST' && (path === '/api/grants/commands' || path === '/api/grants/undo'))
			if (matrixWrite) throw Object.assign(new Error('503'), { data: { uncertain: true }, cause })
			// Reads (including command previews) and other settings keep their ordinary load/request error path.
			throw cause
		})
	}

	/**
	 * True while the matrix reloads after a conflict or an uncertain save: every matrix write waits
	 * for the reloaded table instead of going out with a page that is known to be outdated.
	 */
	let matrixLocked = false

	/** Reloads the matrix with every matrix write blocked until the new page is on screen. */
	async function reloadLocked() {
		matrixLocked = true
		try {
			await load()
		} finally {
			matrixLocked = false
		}
	}

	/**
	 * Tells the admin what a failed save means when the policy moved or the storage failed, and reloads
	 * the matrix in those cases so nothing is written twice.
	 *
	 * @param {Error} e error thrown by api()
	 * @return {Promise<boolean>} whether the error was handled here (the matrix is then reloaded)
	 */
	async function policyError(e) {
		if (e.message === '409' || e.message === '428') {
			notifyError(t('mcp', 'The permissions were changed in another window or by another administrator. The table was reloaded with the saved values; nothing was overwritten.'))
		} else if (e.message === '503' && e.data && e.data.uncertain) {
			notifyError(t('mcp', 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.'))
		} else {
			return false
		}
		await reloadLocked()
		return true
	}

	/** @param {string} message error shown to the admin */
	function notifyError(message) {
		// The old notification global is gone since Nextcloud 34 and OCP.Toast is deprecated, so without it the browser's own dialog
		// still shows the message instead of losing it.
		if (window.OCP && OCP.Toast) {
			OCP.Toast.error(message)
		} else {
			window.alert(message)
		}
	}

	/** Page event of a write this page committed: detail {from, to}. js/admin-scope.js listens to it too. */
	const REVISION_MOVED = 'mcp-revision-moved'

	/**
	 * Tells the other blocks of this page that a write of this page moved the policy revision from `from` to `to`.
	 * Only the answer of a save qualifies: the server committed it from `from`, so `to` holds that write and nothing else.
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
	 * Whether `to` is exactly one commit after `from`. The OAuth clients and server-log saves answer with the revision of
	 * a read made after their commit, which may already hold another administrator's write. A commit advances the
	 * revision by one, and only when the request differs from what is stored at `from`; so a save whose request differs
	 * from the settings this block confirmed at `from` made one step itself, and one step in all is that save alone. A
	 * request that changes nothing commits nothing: its answer is never announced, whatever it shows. Compared as exact
	 * integers, never as floating-point numbers.
	 *
	 * @param {string} from revision the save was sent with
	 * @param {string} to revision of the answer
	 * @return {boolean}
	 */
	function oneStep(from, to) {
		try {
			return BigInt(to) === BigInt(from) + 1n
		} catch {
			return false
		}
	}

	/**
	 * PHP's trim() without a character list: removes only " \t\n\r\0\x0B" from both ends. String.prototype.trim()
	 * keeps NUL and removes Unicode spaces PHP keeps, so it cannot stand in for the server's normalization.
	 *
	 * @param {string} text
	 * @return {string}
	 */
	function phpTrim(text) {
		return text.replace(/^[ \t\n\r\x00\x0B]+|[ \t\n\r\x00\x0B]+$/g, '')
	}

	/**
	 * Keeps a block's own revision in step with the writes of this page: when the block is exactly at the revision a
	 * write committed from, it takes the one that write made, and its unsaved changes stay valid.
	 *
	 * @param {Function} get () => the block's revision
	 * @param {Function} set (revision) => void
	 */
	function followRevision(get, set) {
		window.addEventListener(REVISION_MOVED, (event) => {
			if (get() === event.detail.from) {
				set(event.detail.to)
			}
		})
	}

	/**
	 * @param {HTMLElement} cell cell that shows the save result
	 * @param {boolean} ok whether the save worked
	 */
	function flash(cell, ok) {
		cell.classList.remove('mcp-ok', 'mcp-error')
		void cell.offsetWidth
		cell.classList.add(ok ? 'mcp-ok' : 'mcp-error')
	}

	/**
	 * @param {string} tag element name
	 * @param {object} [props] properties to assign
	 * @param {Array<Node|string>} [children] child nodes or text
	 * @return {HTMLElement}
	 */
	function el(tag, props, children) {
		const node = document.createElement(tag)
		Object.assign(node, props || {})
		for (const child of children || []) {
			node.append(child)
		}
		return node
	}

	/** Number of the latest load(); an older one that answers later is dropped. */
	let loadSeq = 0

	/**
	 * Reads the current page from the server and re-renders the table; a failure is shown in the table with a retry.
	 * The page and its revision always come from the same response, and only the newest request is rendered.
	 */
	async function load() {
		const seq = ++loadSeq
		table.setAttribute('aria-busy', 'true')
		table.classList.add('mcp-loading')
		const query = new URLSearchParams({ search: state.search, group: state.group, filter: state.filter, page: String(state.page) })
		try {
			const data = await api('GET', '/api/grants?' + query.toString())
			if (seq !== loadSeq) {
				return
			}
			state.data = data
			render()
		} catch (e) {
			if (seq !== loadSeq) {
				return
			}
			renderError()
			notifyError(t('mcp', 'Could not load the users.'))
		} finally {
			if (seq === loadSeq) {
				table.setAttribute('aria-busy', 'false')
				table.classList.remove('mcp-loading')
			}
		}
	}

	/** Replaces the body with an error row and a retry button, keeping the last header. */
	function renderError() {
		const retry = el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: load })
		const cell = el('td', { colSpan: state.data ? FIXED_COLUMNS + visiblePermissionColumns() : 1, className: 'mcp-empty mcp-load-error' },
			[t('mcp', 'Could not load the users.'), ' ', retry])
		table.tBodies[0].replaceChildren(el('tr', {}, [cell]))
		applyAppFilter()
	}

	/** Fills the group filter once, keeping the current selection. */
	function renderGroups() {
		if (state.groupsLoaded) {
			return
		}
		for (const group of state.data.groups) {
			groupSelect.append(el('option', { value: group.id, textContent: group.displayName }))
		}
		state.groupsLoaded = true
	}

	/** Populate app choices from the actual catalog, preserving a valid selection. */
	function renderApps() {
		const labels = moduleLabels()
		const modules = Object.keys(state.data.catalog)
		if (!modules.includes(state.app)) {
			const stale = state.app !== ''
			state.app = ''
			if (stale) {
				// An address naming an unknown app is corrected in place, without a new history entry.
				writeAppToHash(true)
			}
		}
		appSelect.replaceChildren(el('option', { value: '', textContent: t('mcp', 'All apps') }),
			...modules.map((module) => el('option', { value: module, textContent: labels[module] || module })))
		appSelect.value = state.app
		appSelect.disabled = false
	}

	/** Filter only presentation; user queries and permission payloads stay unchanged. */
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

	/** Read the Access tab's app parameter on reload and history navigation. */
	function readAppFromHash() {
		const [tab, query = ''] = window.location.hash.slice(1).split('?')
		if (tab !== 'access') {
			return
		}
		const params = new URLSearchParams(query)
		const view = params.get('view') === 'operations' ? 'operations' : 'levels'
		state.app = params.get('app') || ''
		if (!state.data) {
			state.view = view
			return
		}
		if (view !== state.view) {
			state.view = view
			render()
			return
		}
		renderApps()
		applyAppFilter()
	}

	/** @return {boolean} whether the matrix shows one level per module */
	function isLevelsView() {
		return state.view === 'levels' && Boolean(window.McpAdminLevels)
	}

	/** @return {string} the app whose columns are shown alone, '' for all; the levels view always shows every module */
	function shownApp() {
		return isLevelsView() ? '' : state.app
	}

	/**
	 * Switches between the levels and the operations view, keeping the page, the filters and the data on screen.
	 *
	 * @param {string} view "levels" or "operations"
	 * @param {string} [app] module to show alone in the operations view
	 */
	function setView(view, app) {
		state.view = view
		// The levels view shows every module, so the app filter of the operations view leaves the address with it.
		if (view === 'levels') {
			state.app = ''
		} else if (app !== undefined) {
			state.app = app
		}
		if (state.data) {
			render()
		}
		writeAppToHash(false)
	}

	/**
	 * Writes the chosen app into the address (#access?app=<module>, no parameter for all apps).
	 *
	 * @param {boolean} replace correct the current entry instead of adding a history entry
	 */
	function writeAppToHash(replace) {
		const [tab, query = ''] = window.location.hash.slice(1).split('?')
		if (tab !== 'access') {
			return
		}
		const params = new URLSearchParams(query)
		if (state.app === '') {
			params.delete('app')
		} else {
			params.set('app', state.app)
		}
		if (state.view === 'operations') {
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

	appSelect.addEventListener('change', () => {
		state.app = appSelect.value
		applyAppFilter()
		writeAppToHash(false)
	})
	for (const [view, button] of Object.entries(viewButtons)) {
		button?.addEventListener('click', () => {
			if (state.view !== view) {
				setView(view)
			}
		})
	}
	window.addEventListener('hashchange', readAppFromHash)
	readAppFromHash()

	/** Makes the second header row stick right below the first one, whose height depends on the density. */
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

	/** The first header row currently observed, so a re-created row (every render) is observed again. */
	let observedHeaderRow = null
	let headerObserver = null

	/**
	 * Re-measures the first header row whenever it resizes. The Access panel starts hidden, so the
	 * render there measures zero; showing the tab (or changing density or font size) resizes the row
	 * and the observer re-sticks the second row below it. A browser without ResizeObserver keeps the
	 * render-time measure, which is correct whenever the panel is visible.
	 */
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

	/** Renders header and rows from state.data. */
	function render() {
		const data = state.data
		// After a conflict the saved switch may differ from what this page last showed.
		if (!serviceBox.disabled) {
			serviceBox.checked = data.serviceEnabled
		}
		renderGroups()
		renderApps()
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
		// The "select every user on this page" box of js/admin-overrides.js heads the selection column.
		window.McpAdminOverrides?.decorateHeader?.(selectHeader)
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
		if (bulkBusy) {
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
		window.dispatchEvent(new Event('mcp-matrix-rendered'))
	}

	/**
	 * @param {object} user row from the API
	 * @return {HTMLTableRowElement}
	 */
	function renderRow(user) {
		const row = buildRow(user)
		window.McpAdminOverrides?.decorateRow(row, user)
		return row
	}

	/**
	 * @param {object} user row from the API
	 * @return {HTMLTableRowElement}
	 */
	function buildRow(user) {
		const row = el('tr', { className: user.enabled ? '' : 'mcp-disabled-user' })
		row.dataset.uid = user.uid
		// The selection box of js/admin-overrides.js goes here, in its own column instead of next to the name.
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
					// "Restricted to N" or "Scope unknown" of a resource scope, drawn and opened by js/admin-scope.js.
					const mark = user.scope?.[module] && window.McpScopeUi ? window.McpScopeUi.indicator(user.uid, module, user.scope[module]) : null
					if (mark) cell.append(mark)
				}
				row.append(cell)
			})
		})
		row.append(actionsCell(user))
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

	/** @return {HTMLTableCellElement} the cell with the row's actions menu (js/admin-row-actions.js) */
	function actionsCell(user) {
		const cell = el('td', { className: 'mcp-actions-col' })
		const button = window.McpRowActions?.button(user)
		if (button) cell.append(button)
		return cell
	}

	/** Sources of a value the person has individually. */
	const OWN_SOURCES = ['individual_allow', 'individual_deny', 'invalid']

	/**
	 * Whether a user has a real exception on the page (js/admin-exceptions.js): an individual value that differs from what
	 * they would inherit. null while that is not known.
	 *
	 * @param {object} user row from the API
	 * @return {boolean|null}
	 */
	function hasException(user) {
		const exceptions = window.McpAdminExceptions
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
	 */
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

	for (const [only, button] of Object.entries(onlyButtons)) {
		button?.addEventListener('click', () => {
			state.exceptionsOnly = only === 'exceptions'
			applyExceptionsFilter()
		})
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
		if (isLevelsView()) {
			return Object.keys(state.data?.catalog || {}).length
		}
		return Object.entries(state.data?.catalog || {})
			.filter(([module]) => state.app === '' || module === state.app)
			.reduce((sum, [, operations]) => sum + operations.length, 0)
	}

	/**
	 * Why a user's permission cannot be changed here, or null when it can. The first reason wins:
	 * a disabled account, then "Can connect" off, then the app (or the log role) unavailable to the user.
	 *
	 * @param {object} user row from the API
	 * @param {string|null} module module id, null for "Can connect"
	 * @return {string|null} reason shown to the admin
	 */
	function unavailableReason(user, module) {
		if (!user.enabled) {
			return t('mcp', 'The account is disabled.')
		}
		if (module === null) {
			return null
		}
		if (user.eligible === false) {
			return t('mcp', 'This user may not connect: “Can connect” is off.')
		}
		if (!user.appsEnabled[module]) {
			return module === 'logs'
				? t('mcp', 'Only administrators and members of the groups selected in “Server log” can read the log; the saved permission is kept.')
				: t('mcp', 'This app is unavailable for this user; the saved permission is kept.')
		}
		return null
	}

	/**
	 * The states a permission cell can show, drawn in one place: allowed and denied are a checkbox
	 * (checked or empty), unavailable is a "no entry" mark that never looks like an empty checkbox.
	 * Each state has a name for the tooltip and for screen readers, so none depends on colour.
	 */
	const PERMISSION_STATES = {
		allowed: { className: 'mcp-state-allowed', name: () => t('mcp', 'Allowed') },
		denied: { className: 'mcp-state-denied', name: () => t('mcp', 'Denied') },
		unavailable: { className: 'mcp-state-unavailable', name: () => t('mcp', 'Unavailable') },
	}

	/**
	 * Paints one state on a cell: its class, tooltip and the name read by screen readers.
	 *
	 * @param {HTMLTableCellElement} cell the cell
	 * @param {string} key key of PERMISSION_STATES
	 * @param {string} [detail] extra sentence (the reason of an unavailable cell)
	 */
	function paintState(cell, key, detail) {
		for (const other of Object.values(PERMISSION_STATES)) {
			cell.classList.remove(other.className)
		}
		cell.classList.add(PERMISSION_STATES[key].className)
		cell.title = detail ? PERMISSION_STATES[key].name() + ': ' + detail : PERMISSION_STATES[key].name()
	}

	/**
	 * A permission cell: a checkbox that saves at once, or the unavailable mark with its reason.
	 *
	 * @param {object} user row from the API
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {boolean} checked saved value
	 * @param {string} label accessible name of the permission
	 * @param {object} body request body template; the null field receives the new value
	 * @return {HTMLTableCellElement}
	 */
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
			window.McpAdminOverrides?.decorateCell(cell, null, user, module, module === null ? 'eligible' : body.operation)
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
		box.addEventListener('change', async () => {
			const value = box.checked
			if (matrixLocked) {
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
			if (value && module !== null && window.McpAdminMailGrants && !window.McpAdminMailGrants.confirmWrites([{ module, operation: body.operation }])) {
				box.checked = false
				return
			}
			const payload = Object.fromEntries(Object.entries(body).map(([key, v]) => [key, v === null ? value : v]))
			const hadFocus = document.activeElement === box
			// The focus comes back to this checkbox only when the admin did not move it during the save.
			const focusStayed = () => hadFocus && (document.activeElement === null || document.activeElement === document.body || document.activeElement === box)
			box.disabled = true
			try {
				const sent = state.data.revision
				const updated = await api('PUT', '/api/grants/' + encodeURIComponent(user.uid), { ...payload, expectedRevision: sent })
				// Committed from the revision on screen, so the page plus this row is exactly the new revision.
				state.data.revision = updated.revision
				announce(sent, updated.revision)
				const index = state.data.users.findIndex((u) => u.uid === user.uid)
				state.data.users[index] = updated
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
				if (!(await policyError(e))) {
					notifyError(t('mcp', 'Could not save the permission for {user}.', { user: user.displayName }))
				}
			}
		})
		cell.append(box)
		// The override state and the per-cell reset come from js/admin-overrides.js, when it is loaded.
		window.McpAdminOverrides?.decorateCell(cell, box, user, module, module === null ? 'eligible' : body.operation)
		return cell
	}

	/** @return {Object<string, string>} names of the access levels of js/admin-levels.js */
	function levelLabels() {
		return {
			...window.McpAdminPollsGrants?.levelLabels(),
			none: t('mcp', 'None'),
			read: t('mcp', 'Read'),
			edit: t('mcp', 'Edit'),
			full: t('mcp', 'Full'),
			forms_write: t('mcp', 'Read and write forms'),
			forms_write_results: t('mcp', 'Read and write forms and read responses'),
			folders: t('mcp', 'Accounts and folders'),
			messages: t('mcp', 'Messages'),
			attachments: t('mcp', 'Messages and attachments'),
			flags: t('mcp', 'Messages, attachments and flags'),
			move: t('mcp', 'Messages, attachments, flags and moving'),
			draft: t('mcp', 'Messages, attachments, flags, moving and drafts'),
			send: t('mcp', 'Messages, attachments, flags, moving, drafts and sending'),
			custom: t('mcp', 'Custom'),
		}
	}

	/**
	 * Where a module's values come from, as a sentence for the tooltip and screen readers.
	 *
	 * @param {{source: string, groupIds: string[]}} origin what McpAdminLevels.originOf() returned
	 * @return {string}
	 */
	function originText(origin) {
		switch (origin.source) {
		case 'individual': return t('mcp', 'Set for this person')
		case 'invalid': return t('mcp', 'Invalid stored value (denied)')
		case 'group': return t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') })
		case 'unknown': return t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') })
		default: return t('mcp', 'Default')
		}
	}

	/**
	 * One module of one user in the levels view: the level as a word (None, Read, Edit, Full, Custom) and where it comes
	 * from as an icon, so no state depends on colour. An individual value carries a person icon and a group a group icon;
	 * the default has none. A module the user cannot use keeps the "no entry" mark without a control, and an unknown value
	 * the dash, as in the operations view. Choosing the cell opens that module's operations for editing.
	 *
	 * @param {object} user row from the API
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @param {number} index position of the module, for the alternating band
	 * @return {HTMLTableCellElement}
	 */
	function levelCell(user, module, operations, index) {
		const levels = window.McpAdminLevels
		const cell = el('td', { className: 'mcp-cell mcp-level-cell mcp-module mcp-band-' + (index % 2) })
		cell.dataset.module = module
		cell.dataset.levelKey = module
		const name = moduleLabels()[module] || module
		const level = levels.levelOf(module, operations, user.grants[module])
		const reason = unavailableReason(user, module)
		if (reason !== null || level === 'unknown') {
			const unknown = reason === null
			paintState(cell, 'unavailable', unknown ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : reason)
			const mark = el('span', { className: 'mcp-state-mark', textContent: unknown ? '—' : '⊘' })
			mark.setAttribute('aria-hidden', 'true')
			const word = el('span', { className: 'mcp-level-word', textContent: unknown ? t('mcp', 'Unknown') : t('mcp', 'Unavailable') })
			word.setAttribute('aria-hidden', 'true')
			cell.append(mark, ' ', word, el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + name + ': ' + cell.title }))
			if (unknown) cell.append(el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: reloadLocked }))
		} else {
			const { origin, mark: kind } = originMark(user, module, operations)
			const labels = operationLabels()
			const allowed = operations.filter((operation) => user.grants[module][operation] === true)
			const levelName = levelLabels()[level] || level
			const summary = allowed.length === 0
				? t('mcp', 'Allows nothing')
				: t('mcp', 'Allows: {operations}', { operations: allowed.map((operation) => labels[operation] || operation).join(', ') }, undefined, { escape: false })
			paintState(cell, allowed.length === 0 ? 'denied' : 'allowed')
			const source = markText(origin, kind)
			const individual = kind === 'exception'
			const icon = markIcon(origin, kind)
			const parts = []
			if (icon !== null) {
				const glyph = el('span', { className: 'mcp-origin-icon ' + icon })
				glyph.setAttribute('aria-hidden', 'true')
				parts.push(glyph)
			}
			parts.push(el('span', { className: 'mcp-level-word', textContent: levelName }))
			const button = levelButton(user, module, 'mcp-level mcp-level-' + level + (individual ? ' mcp-level-individual' : ''), parts,
				levelName + '. ' + summary + ' — ' + source,
				t('mcp', '{user}, {module}: {level}. {source}.', { user: user.displayName, module: name, level: levelName, source }, undefined, { escape: false }))
			cell.title = button.title
			cell.dataset.level = level
			cell.dataset.origin = origin.source
			cell.dataset.mark = kind
			cell.append(button)
		}
		// "Restricted to N" or "Scope unknown" of a resource scope, drawn and opened by js/admin-scope.js.
		const mark = user.scope?.[module] && window.McpScopeUi ? window.McpScopeUi.indicator(user.uid, module, user.scope[module]) : null
		if (mark) cell.append(mark)
		return cell
	}

	/** Sources of an individual value, as the API sends them. */
	const INDIVIDUAL_SOURCES = ['individual_allow', 'individual_deny', 'invalid']

	/**
	 * Where a cell's values come from and how to mark it. Only a real exception, an individual value that differs from what
	 * the person would inherit (js/admin-exceptions.js), gets the person icon; individual values that only repeat the
	 * inheritance are drawn like what they repeat, and while that is not known yet they get no mark. Without the
	 * classification every individual value is an exception, never fewer.
	 *
	 * @param {object} user row from the API
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {string[]} operations the module's operations, or ["eligible"]
	 * @return {{origin: {source: string, groupIds: string[]}, mark: string}} mark is exception, redundant, pending, or the
	 *     source (group, unknown, default) of a cell with no individual value
	 */
	function originMark(user, module, operations) {
		const levels = window.McpAdminLevels
		const origins = module === null ? { eligible: user.origins?.eligible } : user.origins?.grants?.[module]
		const origin = levels.originOf(operations, origins)
		if (origin.source !== 'individual' && origin.source !== 'invalid') {
			return { origin, mark: origin.source }
		}
		const exceptions = window.McpAdminExceptions
		const individual = operations.filter((operation) => INDIVIDUAL_SOURCES.includes(origins?.[operation]?.source))
		const kinds = exceptions ? individual.map((operation) => exceptions.kindOf(user.uid, module, operation)) : []
		if (!exceptions || origin.source === 'invalid' || kinds.some((kind) => kind.kind === 'exception')) {
			return { origin, mark: 'exception' }
		}
		if (kinds.some((kind) => kind.kind === 'pending')) {
			return { origin, mark: 'pending' }
		}
		const repeated = Object.fromEntries(operations.map((operation) => {
			const index = individual.indexOf(operation)
			return [operation, index < 0 ? origins?.[operation] : kinds[index].after || { source: 'default', groupIds: [] }]
		}))
		return { origin: levels.originOf(operations, repeated), mark: 'redundant' }
	}

	/** @return {string|null} the icon class of a mark: a person for an exception, a group for a group, none otherwise */
	function markIcon(origin, mark) {
		if (mark === 'exception') return 'icon-user'
		if (mark === 'pending') return null
		return origin.source === 'group' || origin.source === 'unknown' ? 'icon-group' : null
	}

	/**
	 * @return {string} where a cell's values come from, in words: a screen reader hears "Exception" where the eye sees the
	 *     person icon, and the inspector says the same
	 */
	function markText(origin, mark) {
		if (mark === 'pending') return t('mcp', 'Set for this person')
		if (mark === 'exception' && origin.source === 'individual') return t('mcp', 'Exception: differs from what the person inherits')
		if (mark === 'redundant') return originText(origin) + ' — ' + t('mcp', 'also set for this person, with the same value')
		return originText(origin)
	}

	/**
	 * The control of a level cell: it opens the popover of js/admin-level-popover.js, where the level is chosen.
	 *
	 * @param {object} user row from the API
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {string} className classes of the button
	 * @param {Array<Node>} parts icon and word
	 * @param {string} title tooltip
	 * @param {string} label accessible name, which says the state in words
	 * @return {HTMLButtonElement}
	 */
	function levelButton(user, module, className, parts, title, label) {
		const button = el('button', { type: 'button', className, title }, parts)
		button.onclick = () => window.McpLevelPopover?.open(button, user.uid, module)
		button.setAttribute('aria-label', label)
		button.setAttribute('aria-haspopup', 'dialog')
		button.setAttribute('aria-expanded', 'false')
		return button
	}

	/**
	 * "Can connect" in the levels view: Yes or No in words, its origin as an icon like the module cells, and the popover to
	 * change it. A disabled account has no control, as in the operations view.
	 *
	 * @param {object} user row from the API
	 * @return {HTMLTableCellElement}
	 */
	function eligibleCell(user) {
		const cell = el('td', { className: 'mcp-cell mcp-level-cell' })
		cell.dataset.levelKey = 'eligible'
		const canConnect = t('mcp', 'Can connect')
		const reason = user.eligible === null ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : unavailableReason(user, null)
		if (reason !== null) {
			const unknown = user.eligible === null
			paintState(cell, 'unavailable', reason)
			const mark = el('span', { className: 'mcp-state-mark', textContent: unknown ? '—' : '⊘' })
			mark.setAttribute('aria-hidden', 'true')
			const word = el('span', { className: 'mcp-level-word', textContent: unknown ? t('mcp', 'Unknown') : t('mcp', 'Unavailable') })
			word.setAttribute('aria-hidden', 'true')
			cell.append(mark, ' ', word, el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + canConnect + ': ' + cell.title }))
			if (unknown) cell.append(el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: reloadLocked }))
			return cell
		}
		const { origin, mark: kind } = originMark(user, null, ['eligible'])
		const individual = kind === 'exception'
		const icon = markIcon(origin, kind)
		const parts = []
		if (icon !== null) {
			const glyph = el('span', { className: 'mcp-origin-icon ' + icon })
			glyph.setAttribute('aria-hidden', 'true')
			parts.push(glyph)
		}
		const word = user.eligible ? t('mcp', 'Yes') : t('mcp', 'No')
		parts.push(el('span', { className: 'mcp-level-word', textContent: word }))
		paintState(cell, user.eligible ? 'allowed' : 'denied')
		const source = markText(origin, kind)
		const button = levelButton(user, null, 'mcp-level mcp-level-' + (user.eligible ? 'read' : 'none') + (individual ? ' mcp-level-individual' : ''), parts,
			canConnect + ': ' + word + ' — ' + source,
			t('mcp', '{user}, {module}: {level}. {source}.', { user: user.displayName, module: canConnect, level: word, source }, undefined, { escape: false }))
		cell.title = button.title
		cell.dataset.level = user.eligible ? 'read' : 'none'
		cell.dataset.origin = origin.source
		cell.dataset.mark = kind
		cell.append(button)
		return cell
	}

	/** Seconds the undo of a bulk change stays offered; it waits while the pointer or focus is on it. */
	const UNDO_SECONDS = 10
	const notice = document.getElementById('mcp-bulk-notice')
	const noticeText = document.getElementById('mcp-bulk-notice-text')
	const undoButton = document.getElementById('mcp-bulk-undo')
	let noticeTimer = null
	let pendingUndo = null
	/** True while a bulk change or its undo saves and the matrix reloads: only one runs at a time. */
	let bulkBusy = false

	/**
	 * Locks the "All" menus and the undo while a bulk change or an undo is saving, so an older
	 * operation can never finish on top of a newer one's notice, undo or focus.
	 *
	 * @param {boolean} on whether an operation is running
	 */
	function setBulkBusy(on) {
		bulkBusy = on
		const reason = on ? t('mcp', 'Wait until the change in progress is saved.') : ''
		for (const button of root.querySelectorAll('.mcp-bulk, #mcp-bulk-undo')) {
			button.disabled = on
			button.setAttribute('aria-disabled', on ? 'true' : 'false')
			button.title = reason
		}
		// The undo window only runs while its button can be used: it stops during the lock and
		// starts again, in full, once the lock is released.
		if (on) {
			clearTimeout(noticeTimer)
		} else if (notice.classList.contains('mcp-notice-on')) {
			armNotice()
		}
	}

	/** Clears the notice and forgets the undo it offered. */
	function hideNotice() {
		clearTimeout(noticeTimer)
		pendingUndo = null
		notice.classList.remove('mcp-notice-on')
		noticeText.textContent = ''
		undoButton.hidden = true
	}

	/** Starts the countdown, unless the admin is on the notice or a bulk change is still running. */
	function armNotice() {
		clearTimeout(noticeTimer)
		if (!bulkBusy && !notice.matches(':hover') && !notice.contains(document.activeElement)) {
			noticeTimer = setTimeout(hideNotice, UNDO_SECONDS * 1000)
		}
	}

	/**
	 * Shows a message under the matrix, with an Undo button when an undo is given.
	 *
	 * @param {string} message text of the notice
	 * @param {Function|null} undo restores the previous values
	 */
	function showNotice(message, undo) {
		// A newer notice takes the single undo slot; say so instead of dropping the older undo silently.
		const replaced = pendingUndo !== null && pendingUndo !== undo
		pendingUndo = undo
		noticeText.textContent = replaced ? message + ' ' + t('mcp', 'The previous undo is no longer available.') : message
		undoButton.hidden = undo === null
		notice.classList.add('mcp-notice-on')
		armNotice()
	}

	notice.addEventListener('mouseenter', () => clearTimeout(noticeTimer))
	notice.addEventListener('focusin', () => clearTimeout(noticeTimer))
	notice.addEventListener('mouseleave', () => notice.classList.contains('mcp-notice-on') && armNotice())
	notice.addEventListener('focusout', (event) => {
		if (notice.classList.contains('mcp-notice-on') && !notice.contains(event.relatedTarget)) {
			armNotice()
		}
	})
	undoButton.addEventListener('click', () => {
		const undo = pendingUndo
		if (!undo || bulkBusy) {
			return
		}
		// The button disappears, so the focus moves to the notice text, which announces the outcome
		// and stays on screen while it has the focus.
		pendingUndo = null
		clearTimeout(noticeTimer)
		undoButton.hidden = true
		noticeText.focus({ preventScroll: true })
		undo()
	})

	/**
	 * What a bulk change would do: per operation, the users whose saved value differs from the target and that value; users
	 * with an unavailable or unknown cell are left out, users already set are counted.
	 *
	 * @param {string|null} module module id, null for eligibility
	 * @param {string[]} operationList operation ids, or ["eligible"]
	 * @param {boolean|Object<string, boolean>} granted the new value of every operation, or one per operation
	 * @param {string[]|null} [uids] the users to change, the whole page when null
	 * @return {{changes: Array<{operation: string, granted: boolean, previous: Map<string, boolean>}>, users: number, unavailable: number, unchanged: number}}
	 */
	function bulkPlan(module, operationList, granted, uids = null) {
		const target = (operation) => typeof granted === 'boolean' ? granted : granted[operation] === true
		const changes = operationList.map((operation) => ({ operation, granted: target(operation), previous: new Map() }))
		let users = 0
		let unavailable = 0
		let unchanged = 0
		for (const user of state.data.users) {
			if (uids !== null && !uids.includes(user.uid)) {
				continue
			}
			if (unavailableReason(user, module) !== null || operationList.some((operation) =>
				(module === null ? user.eligible : user.grants[module][operation]) === null)) {
				unavailable++
				continue
			}
			let changed = false
			for (const change of changes) {
				const current = module === null ? user.eligible : user.grants[module][change.operation]
				if (current !== change.granted) {
					change.previous.set(user.uid, current)
					changed = true
				}
			}
			changed ? users++ : unchanged++
		}
		return { changes: changes.filter((change) => change.previous.size > 0), users, unavailable, unchanged }
	}

	/**
	 * Saves a bulk change as one command: the server writes every cell or none, and returns the
	 * receipt that undoes exactly these cells.
	 *
	 * @param {string|null} module module id, null for eligibility
	 * @param {Array<{operation: string, uids: string[], granted: boolean}>} requests cells to save
	 * @return {Promise<object>} the saved command: {revision, changed, receiptId}
	 */
	async function sendCommand(module, requests) {
		const commands = requests.flatMap((request) => request.uids.map((uid) => ({
			uid,
			module,
			operation: request.operation,
			state: request.granted ? 'allow' : 'deny',
		})))
		const sent = state.data.revision
		const saved = await api('POST', '/api/grants/commands', { expectedRevision: sent, commands })
		announce(sent, saved.revision)
		return saved
	}

	/**
	 * The selection bar's "Set" (js/admin-overrides.js): gives the selected users one level of a module, or turns "Can
	 * connect" on or off. It confirms exactly what changes, how many are left out and why, and how many already have it,
	 * sends nothing when nobody changes, saves one command and offers the undo. Every cell is an explicit allow or deny,
	 * never an inheritance, as the level popover does for a removal (F1).
	 *
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {string} level a level of js/admin-levels.js; for "Can connect", "read" is on and "none" is off
	 * @param {string[]} uids the selected users of the page
	 */
	async function bulk(module, level, uids) {
		// The plan is computed from the table on screen, so never from one that is being replaced.
		if (bulkBusy || matrixLocked) {
			return
		}
		const operationList = module === null ? ['eligible'] : state.data.catalog[module]
		const allowed = module === null ? (level === 'read' ? ['eligible'] : []) : window.McpAdminLevels.operationsFor(module, operationList, level)
		const plan = bulkPlan(module, operationList, Object.fromEntries(operationList.map((operation) => [operation, allowed.includes(operation)])), uids)
		const levelName = module === null ? (level === 'read' ? t('mcp', 'Yes') : t('mcp', 'No')) : levelLabels()[level] || level
		const vars = { permission: module === null ? t('mcp', 'Can connect') : moduleLabels()[module] || module, level: levelName }
		const lines = []
		if (plan.unavailable > 0) {
			lines.push(n('mcp', '%n selected person is left out: this permission is unavailable or unknown for them.', '%n selected people are left out: this permission is unavailable or unknown for them.', plan.unavailable, vars, { escape: false }))
		}
		if (plan.unchanged > 0) {
			lines.push(n('mcp', '%n selected person already has it.', '%n selected people already have it.', plan.unchanged, vars, { escape: false }))
		}
		if (plan.users === 0) {
			showNotice([t('mcp', 'Nothing to change for the selected people: “{permission}” is already {level}.', vars, undefined, { escape: false }), ...lines].join(' '), null)
			return
		}
		const question = n('mcp', 'Set “{permission}” to {level} for %n selected person?', 'Set “{permission}” to {level} for %n selected people?', plan.users, vars, { escape: false })
		if (module === null && level !== 'read') {
			lines.push(t('mcp', 'They are signed out of their MCP clients, and undoing this does not sign them back in.'))
		}
		const mailWarning = module !== null && window.McpAdminMailGrants ? window.McpAdminMailGrants.writeWarning(plan.changes.filter((change) => change.granted).map((change) => ({ module, operation: change.operation }))) : null
		if (mailWarning) lines.push(mailWarning)
		if (!window.confirm([question, ...lines].join('\n'))) {
			return
		}
		const requests = plan.changes.map((change) => ({ operation: change.operation, uids: [...change.previous.keys()], granted: change.granted }))
		let undo = null
		let reloaded = false
		setBulkBusy(true)
		try {
			try {
				const saved = await sendCommand(module, requests)
				undo = saved.receiptId === null ? null : () => undoBulk(saved.receiptId, saved.revision)
				showNotice(n('mcp', '“{permission}” set to {level} for %n person.', '“{permission}” set to {level} for %n people.', plan.users, vars, { escape: false }), undo)
			} catch (e) {
				// The command is all or nothing, but an unreadable response cannot confirm its outcome.
				reloaded = await policyError(e)
				if (!reloaded) {
					notifyError(t('mcp', 'Could not update the selected people.'))
				}
			}
			if (!reloaded) {
				await reloadLocked()
			}
		} finally {
			setBulkBusy(false)
		}
		if (undo !== null && pendingUndo === undo) {
			// The focus goes to the undo, scrolling the notice under the table into view when it is below the fold.
			undoButton.focus()
		}
	}

	/**
	 * Undoes a bulk change through its receipt: the server puts back each cell's exact saved value,
	 * absence included, and refuses when anything changed since that change.
	 *
	 * @param {string} receiptId receipt of the saved command
	 * @param {string} savedRevision revision that command committed
	 */
	async function undoBulk(receiptId, savedRevision) {
		setBulkBusy(true)
		try {
			try {
				const undone = await api('POST', '/api/grants/undo', { receiptId, expectedRevision: savedRevision })
				announce(savedRevision, undone.revision)
				showNotice(t('mcp', 'Change undone.'), null)
			} catch (e) {
				if (e.message === '503' && !(e.data && e.data.uncertain)) {
					// Nothing was written (what the users inherit could not be read): the table stays as it is, and the same
					// undo is offered again instead of a reload that would hide that it is still possible.
					const retry = t('mcp', 'The change could not be undone because what these users inherit could not be read. Nothing was changed. Try again.')
					notifyError(retry)
					showNotice(retry, () => undoBulk(receiptId, savedRevision))
					return
				}
				const failure = e.message === '503' && e.data && e.data.uncertain
					? t('mcp', 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.')
					: e.message === '409'
						? t('mcp', 'The permissions changed after this change, so it can no longer be undone. The table was reloaded with the saved values.')
						: e.message === '410'
							? t('mcp', 'This change can no longer be undone: the time to undo it has passed.')
							: t('mcp', 'Could not undo the change. The table was reloaded with the saved values.')
				notifyError(failure)
				showNotice(failure, null)
			}
			await reloadLocked()
		} finally {
			setBulkBusy(false)
		}
	}

	serviceBox.addEventListener('change', async () => {
		const value = serviceBox.checked
		if (matrixLocked || state.data === null) {
			serviceBox.checked = !value
			return
		}
		serviceBox.disabled = true
		try {
			const sent = state.data.revision
			const result = await api('PUT', '/api/service', { enabled: value, expectedRevision: sent })
			// Committed from the revision on screen: the switch is the only thing that changed.
			state.data.revision = result.revision
			announce(sent, result.revision)
			state.data.serviceEnabled = result.enabled
			serviceBox.checked = result.enabled
			serviceStatus.textContent = t('mcp', 'Saved')
			flash(serviceStatus, true)
		} catch (e) {
			serviceBox.checked = !value
			serviceStatus.textContent = t('mcp', 'Not saved')
			flash(serviceStatus, false)
			// Enabled again first, so the reloaded page can paint the saved switch.
			serviceBox.disabled = false
			if (!(await policyError(e))) {
				notifyError(t('mcp', 'Could not change the MCP service.'))
			}
		} finally {
			serviceBox.disabled = false
		}
	})

	window.addEventListener('mcp-group-policy-saved', reloadLocked)
	window.addEventListener('mcp-scope-saved', reloadLocked)

	/**
	 * Keeps the control that takes the focus clear of the sticky columns of its row (the selection box and the name at the
	 * start, the "…" menu at the end, js/admin.css), and inside the block. The browser scrolls a focused control into the
	 * matrix block, but not out from under those columns, and it leaves one that shows in part where it is, so a cell
	 * reached by Tab could be hidden (WCAG 2.4.11). Measured on screen, so it holds at any width and in either direction.
	 *
	 * @param {FocusEvent} event focusin on the scrolling block of the matrix
	 */
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
	table.parentElement?.addEventListener('focusin', revealFocus)

	let searchTimer = null
	searchInput.addEventListener('input', () => {
		clearTimeout(searchTimer)
		searchTimer = setTimeout(() => {
			state.search = searchInput.value.trim()
			state.page = 1
			load()
		}, 300)
	})
	groupSelect.addEventListener('change', () => {
		state.group = groupSelect.value
		state.page = 1
		load()
	})
	// The matrix opens on the users who can connect; "Everyone" stays one choice away.
	filterSelect.value = state.filter
	filterSelect.addEventListener('change', () => {
		state.filter = filterSelect.value
		state.page = 1
		load()
	})
	for (const button of prevButtons) {
		button.addEventListener('click', () => {
			state.page = Math.max(1, state.page - 1)
			load()
		})
	}
	for (const button of nextButtons) {
		button.addEventListener('click', () => {
			state.page += 1
			load()
		})
	}

	for (const button of root.querySelectorAll('.mcp-copy')) {
		button.addEventListener('click', () => {
			const text = document.getElementById(button.dataset.copy).textContent
			navigator.clipboard.writeText(text).then(
				() => { button.textContent = t('mcp', 'Copied') },
				() => notifyError(t('mcp', 'Could not copy the endpoint.')),
			)
		})
	}


	async function initTagsSection() {
		const container = document.getElementById('mcp-tags-list')
		const warning = document.getElementById('mcp-tags-warning')
		const status = document.getElementById('mcp-tags-status')
		const invalid = document.getElementById('mcp-tags-invalid')
		const repair = document.getElementById('mcp-tags-repair')
		// The gravest state of the app is an unreadable exclusion setting (fail-closed: every file is
		// refused). The repair block lives in the Data protection tab, so the Status block of Overview
		// also shows it and the tab carries a marker.
		const overviewAlert = document.getElementById('mcp-tags-invalid-overview')
		const tabAlert = document.getElementById('mcp-tab-protection-alert')
		if (!container || !window.McpAdminPicker) {
			return
		}

		/** @param {boolean} isInvalid shows or hides every sign of the unreadable setting */
		function setInvalidState(isInvalid) {
			if (invalid) {
				invalid.style.display = isInvalid ? 'block' : 'none'
			}
			if (overviewAlert) {
				overviewAlert.hidden = !isInvalid
			}
			if (tabAlert) {
				tabAlert.hidden = !isInvalid
			}
		}

		let allTags = []

		/**
		 * The type badge of a tag option, with its explanation in the title.
		 *
		 * @param {string} type collaborative, restricted or invisible
		 * @return {{text: string, title: string, className: string}}
		 */
		function tagBadge(type) {
			if (type === 'invisible') {
				return { text: t('mcp', 'Invisible'), title: t('mcp', 'Visible and assignable only by administrators'), className: 'mcp-tag-type-invisible' }
			}
			if (type === 'restricted') {
				return { text: t('mcp', 'Restricted'), title: t('mcp', 'Visible to users, assignable only by administrators and the groups they allow'), className: 'mcp-tag-type-restricted' }
			}
			return { text: t('mcp', 'Collaborative'), title: t('mcp', 'Users can assign and remove this tag'), className: 'mcp-tag-type-collaborative' }
		}

		/** @param {string[]} selectedIds warns when a collaborative tag, removable by editors, is selected */
		function updateWarning(selectedIds) {
			if (!warning) {
				return
			}
			const hasCollab = allTags.some((tag) => tag.type === 'collaborative' && selectedIds.includes(String(tag.id)))
			warning.style.display = hasCollab ? 'block' : 'none'
		}

		/**
		 * PUT /api/admin/tags with the same payload as before. A failure is explained here and rethrown,
		 * so the picker rolls the selection back; on success the invalid-setting block goes away.
		 *
		 * @param {string[]} tagIds the ids to save
		 */
		async function saveTags(tagIds) {
			try {
				await api('PUT', '/api/admin/tags', { tagIds })
			} catch (e) {
				// 503: the system tags could not be checked and the server kept the previous setting.
				notifyError(e.message === '503'
					? t('mcp', 'Could not check the system tags, so the exclusion tags were not changed. Try again later.')
					: t('mcp', 'Could not update exclusion tags.'))
				throw e
			}
			setInvalidState(false)
		}

		try {
			const res = await api('GET', '/api/admin/tags')
			allTags = res.tags || []

			const picker = window.McpAdminPicker.create({
				container,
				status,
				options: allTags.map((tag) => ({ id: String(tag.id), label: tag.name, badge: tagBadge(tag.type) })),
				selectedIds: (res.selected || []).map(String),
				searchLabel: t('mcp', 'Search tags'),
				listLabel: t('mcp', 'Exclusion tags'),
				emptyOptionsText: t('mcp', 'No system tags found. Create tags in Files settings.'),
				noMatchText: t('mcp', 'No match.'),
				// An empty list turns the protection off; from an invalid setting that goes from hiding
				// everything to hiding nothing, so it is never saved without the administrator saying so.
				confirmSave: (nextIds) => nextIds.length > 0
					|| window.confirm(t('mcp', 'Saving with no exclusion tags turns the protection off: every file becomes visible to the MCP tools again. Continue?')),
				save: saveTags,
				onChange: updateWarning,
				clearSavedAfterMs: 2000,
				errorStatus: '',
			})

			setInvalidState(Boolean(res.invalid))
			if (res.invalid && repair) {
				repair.addEventListener('click', async () => {
					repair.disabled = true
					try {
						await picker.commit({ force: true })
					} finally {
						repair.disabled = false
					}
				})
			}

			updateWarning(picker.getSelected())
		} catch {
			container.setAttribute('aria-busy', 'false')
			container.replaceChildren(el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load system tags.') }))
		}
	}

	/**
	 * OAuth clients section: allowed client_id hosts, the native client switch and the self-hosted clients, each with
	 * its name, Enabled setting and redirect URIs, through GET and PUT /apps/mcp/api/oauth-clients. The saves of this
	 * block go one at a time, and each queues an intent (this client, this change), not a body: the list it sends is
	 * built when its turn comes, from the clients the server confirmed last, and carries the revision of that same
	 * answer. So two quick changes never resend a list from before the first one (turning B off cannot turn A back on).
	 * A page that is out of date (another window or administrator saved first) gets 409 and is rebuilt from the
	 * server; the intents queued behind the refused one are dropped with it, never sent on the reloaded page. A card
	 * with typed changes the server does not have (a new client, or a changed name or redirect URIs) stays on screen
	 * through that rebuild, marked as not saved, for the admin to check and save again. A save of another block of this
	 * page moves the revision too; that move is adopted (see announce()), so it never refuses a save here.
	 */
	async function initOauthClients() {
		const hostsInput = document.getElementById('mcp-oauth-hosts')
		const hostsSave = document.getElementById('mcp-oauth-hosts-save')
		const hostsStatus = document.getElementById('mcp-oauth-hosts-status')
		const nativeBox = document.getElementById('mcp-native-client')
		const nativeStatus = document.getElementById('mcp-native-client-status')
		const details = document.getElementById('mcp-native-client-details')
		const clientId = document.getElementById('mcp-native-client-id')
		const redirects = document.getElementById('mcp-native-redirects')
		const customList = document.getElementById('mcp-custom-clients')
		const customAdd = document.getElementById('mcp-custom-add')
		/** Self-hosted clients as the server last returned them; each card edits one of them (or a new one). */
		let customClients = []
		let cardCount = 0
		// The block's own revision, from its own GET or save; never the matrix's, never an error body's.
		let oauthRevision = null
		let queue = Promise.resolve()
		// Advances when the page is reloaded after a refused save: intents queued before that are dropped.
		let epoch = 0
		/** Per card: the client it was built from, and what is typed in it now. */
		const drafts = new WeakMap()
		/** The server's last answer: what is stored at this block's revision. */
		let confirmed = null
		followRevision(() => oauthRevision, (revision) => { oauthRevision = revision })

		/** @param {object} data settings returned by the API */
		function show(data) {
			oauthRevision = data.revision
			confirmed = data
			hostsInput.value = data.hosts.join(', ')
			nativeBox.checked = data.nativeClientEnabled
			details.hidden = !data.nativeClientEnabled
			clientId.textContent = data.nativeClientId
			redirects.replaceChildren(...data.nativeRedirectUris.map((uri) => el('li', {}, [el('code', { textContent: uri })])))
			customClients = data.customClients
		}

		/**
		 * @param {HTMLElement} status status span next to the control
		 * @param {boolean} ok whether the save worked
		 * @param {string} [message] text shown on failure
		 */
		function report(status, ok, message) {
			status.textContent = ok ? t('mcp', 'Saved') : (message || t('mcp', 'Not saved'))
			flash(status, ok)
		}

		/**
		 * Runs one task of the block after the previous one has finished.
		 *
		 * @param {Function} task async work
		 * @return {Promise<*>} what the task returned
		 */
		function enqueue(task) {
			const run = queue.then(task)
			queue = run.catch(() => {})
			return run
		}

		/**
		 * Sends one change when its turn comes: the body is built then by `build`, from the clients the server confirmed
		 * last, with the revision of that same answer, which is then replaced by the revision and clients the server
		 * answers with. The policy revision moved, so the matrix is reloaded to keep its own saves valid. An intent
		 * queued before a reload is dropped (error "stale"); a refused save drops the intents queued behind it.
		 *
		 * @param {Function} build (clients) => the changed settings
		 * @return {Promise<object>} the settings the server saved
		 */
		function put(build) {
			const queuedAt = epoch
			return enqueue(async () => {
				if (queuedAt !== epoch) {
					throw new Error('stale')
				}
				try {
					const sent = oauthRevision
					const change = build(customClients)
					const committed = changes(change, confirmed)
					const data = await api('PUT', '/api/oauth-clients', { ...change, expectedRevision: sent })
					oauthRevision = data.revision
					customClients = data.customClients
					confirmed = data
					if (committed && oneStep(sent, data.revision)) {
						announce(sent, data.revision)
					}
					load()
					return data
				} catch (e) {
					if (e.message === '409' || e.message === '428' || (e.message === '503' && e.data && e.data.uncertain)) {
						epoch++
					}
					throw e
				}
			})
		}

		/**
		 * Whether a request differs from the settings stored at the block's revision, compared the way the server compares
		 * them (OAuthClientsService): the server then commits it and the revision advances. Hosts are trimmed, lowercased
		 * (ASCII only) and without duplicates, and the default list is stored as no list (GrantsController::hosts,
		 * writeHosts); clients keep their order, with names and redirect URIs trimmed and URIs without duplicates
		 * (CustomClient::validate, validateUris). Every trim is PHP's own (phpTrim()). A value still holding a control
		 * character after it, which the server refuses, counts as unsure. False when unsure, so nothing is announced then.
		 *
		 * @param {object} change the settings sent (hosts, nativeClientEnabled or customClients)
		 * @param {object} stored the server's last answer
		 * @return {boolean}
		 */
		function changes(change, stored) {
			const control = (text) => /[\x00-\x1f\x7f]/.test(text)
			const uris = (list) => [...new Set(list.map(phpTrim))]
			if (change.nativeClientEnabled !== undefined && change.nativeClientEnabled !== stored.nativeClientEnabled) {
				return true
			}
			if (change.hosts !== undefined) {
				// strtolower() of PHP 8 changes A-Z only.
				const hosts = [...new Set(change.hosts.map((host) => phpTrim(host).replace(/[A-Z]/g, (letter) => letter.toLowerCase())))]
				if (hosts.some(control)) {
					return false
				}
				// Stored as no list when the request is the default set; only known while the default is what is stored.
				const isDefault = stored.hostsDefault && hosts.length === stored.hosts.length && hosts.every((host) => stored.hosts.includes(host))
				if (stored.hostsDefault ? !isDefault : hosts.join(',') !== stored.hosts.join(',')) {
					return true
				}
			}
			if (change.customClients !== undefined) {
				if (change.customClients.some((client) => control(phpTrim(client.name)) || uris(client.redirectUris).some(control))) {
					return false
				}
				const key = (clients) => JSON.stringify(clients.map((client) => [client.id ?? null, phpTrim(client.name), client.enabled, uris(client.redirectUris)]))
				if (key(change.customClients) !== key(stored.customClients)) {
					return true
				}
			}
			return false
		}

		/**
		 * When a save failed because the page was out of date (409, or 428 without a revision) or the server could not
		 * confirm it (503 uncertain), shows what is stored now, in the queue's order, and tells the admin nothing was
		 * overwritten. The cards with unsaved typed changes are kept (see keepUnsaved()). An intent dropped behind such a
		 * save is handled by that reload.
		 *
		 * @param {Error} e error thrown by put()
		 * @return {Promise<boolean>} whether the error was handled here (the settings are then reloaded)
		 */
		async function recover(e) {
			if (e.message === 'stale') {
				return true
			}
			if (e.message === '409' || e.message === '428') {
				notifyError(t('mcp', 'The OAuth client settings were changed in another window or by another administrator. The current settings were loaded; nothing was overwritten.'))
			} else if (e.message === '503' && e.data && e.data.uncertain) {
				notifyError(t('mcp', 'The server could not confirm whether the change was saved. The current settings were loaded.'))
			} else {
				return false
			}
			try {
				const unsaved = [...customList.children].filter((card) => drafts.get(card)?.edited())
				show(await enqueue(() => api('GET', '/api/oauth-clients')))
				customList.replaceChildren(...keepUnsaved(unsaved))
			} catch (reload) {
				notifyError(t('mcp', 'Could not load the OAuth client settings.'))
			}
			return true
		}

		/**
		 * The cards of the clients the server has now, with the cards whose typed changes it does not have put back: a
		 * saved client keeps its edited card in its place, a new card comes after the saved ones, and the edits of a
		 * client deleted elsewhere come back as a new card. Each card put back says it was not saved.
		 *
		 * @param {HTMLElement[]} unsaved cards with typed changes, read before the reload
		 * @return {HTMLElement[]} the cards to show
		 */
		function keepUnsaved(unsaved) {
			const kept = new Map(unsaved.filter((card) => drafts.get(card).client.id !== null).map((card) => [drafts.get(card).client.id, card]))
			const cards = customClients.map((client) => kept.get(client.id) || customCard(client))
			const extra = unsaved.filter((card) => !cards.includes(card)).map((card) => {
				if (drafts.get(card).client.id === null) {
					return card
				}
				const fresh = customCard({ ...drafts.get(card).read(), id: null })
				report(fresh.querySelector('.mcp-status'), false, t('mcp', 'Not saved: this client was deleted in the meantime. Your changes are kept here as a new client; saving adds it again.'))
				return fresh
			})
			for (const card of unsaved.filter((card) => cards.includes(card) || extra.includes(card))) {
				report(card.querySelector('.mcp-status'), false, t('mcp', 'Not saved: the settings changed after this page was loaded. Your changes are kept here; check them and save again.'))
			}
			return [...cards, ...extra]
		}

		/**
		 * Saves one change to the list of self-hosted clients.
		 *
		 * @param {Function} change (clients) => every client to keep, a new one without id; applied to the clients the
		 *     server confirmed last, when the save's turn comes
		 * @return {Promise<object|undefined>} the client the server added, if any
		 */
		async function saveCustom(change) {
			let before = new Set()
			await put((clients) => {
				before = new Set(clients.map((client) => client.id))
				return { customClients: change(clients).map(({ id, name, enabled, redirectUris }) => ({ id, name, enabled, redirectUris })) }
			})
			return customClients.find((client) => !before.has(client.id))
		}

		/**
		 * One self-hosted client: name, Enabled, client_id with Copy (once saved), redirect URIs, Save and Delete.
		 * Saving or deleting replaces only this card, so unsaved edits in the other cards stay.
		 *
		 * @param {object} client a stored client, or {id: null, ...} for a new one
		 * @return {HTMLElement} the card
		 */
		function customCard(client) {
			const n = ++cardCount
			const saved = client.id !== null
			const name = el('input', { type: 'text', id: 'mcp-custom-name-' + n, value: client.name, maxLength: 64, autocomplete: 'off', spellcheck: false })
			const enabled = el('input', { type: 'checkbox', className: 'checkbox', id: 'mcp-custom-enabled-' + n, checked: client.enabled })
			const uris = el('textarea', {
				id: 'mcp-custom-uris-' + n, className: 'mcp-custom-redirects', rows: 3, spellcheck: false, autocomplete: 'off',
				placeholder: 'https://harness.example.com/oauth/callback', value: client.redirectUris.join('\n'),
			})
			const status = el('span', { className: 'mcp-status' })
			status.setAttribute('aria-live', 'polite')
			const save = el('button', { type: 'button', textContent: t('mcp', 'Save') })
			const remove = el('button', { type: 'button', textContent: t('mcp', 'Delete') })
			const rows = [
				el('p', {}, [el('label', { htmlFor: name.id, textContent: t('mcp', 'Name') }), ' ', name]),
				el('p', {}, [enabled, el('label', { htmlFor: enabled.id, textContent: t('mcp', 'Enabled') })]),
			]
			if (saved) {
				const code = el('code', { textContent: client.clientId })
				const copy = el('button', { type: 'button', className: 'mcp-copy', textContent: t('mcp', 'Copy') })
				copy.addEventListener('click', () => {
					navigator.clipboard.writeText(client.clientId).then(
						() => { copy.textContent = t('mcp', 'Copied') },
						() => notifyError(t('mcp', 'Could not copy the client_id.')),
					)
				})
				rows.push(el('p', {}, ['client_id: ', code, ' ', copy]))
			}
			rows.push(
				el('p', {}, [el('label', { htmlFor: uris.id, textContent: t('mcp', 'Accepted redirect URIs') })]),
				uris,
				el('p', { className: 'settings-hint mcp-hint', textContent: t('mcp', 'One URI per line, exactly as the client sends it, port included: https (http only for 127.0.0.1, localhost or [::1]), no wildcard or fragment, up to 2000 bytes.') }),
				el('p', {}, [save, ' ', remove, ' ', status]),
			)
			const card = el('div', { className: 'mcp-custom-client' }, rows)

			/** @return {object} the client as edited in the card */
			function read() {
				return {
					id: client.id,
					name: name.value.trim(),
					enabled: enabled.checked,
					redirectUris: uris.value.split('\n').map((uri) => uri.trim()).filter((uri) => uri !== ''),
				}
			}

			/** @return {boolean} whether the card holds what the server does not have: any new card, or a changed name or URI list */
			function edited() {
				const entry = read()
				return !saved || entry.name !== client.name || entry.redirectUris.join('\n') !== client.redirectUris.join('\n')
			}
			drafts.set(card, { client, read, edited })

			save.addEventListener('click', async () => {
				const entry = read()
				save.disabled = true
				try {
					const added = await saveCustom((clients) => saved
						? clients.map((other) => other.id === client.id ? entry : other)
						: [...clients, entry])
					const next = customCard(saved ? customClients.find((other) => other.id === client.id) : added)
					card.replaceWith(next)
					next.querySelector('.mcp-status').textContent = t('mcp', 'Saved')
					flash(next.querySelector('.mcp-status'), true)
				} catch (e) {
					if (!(await recover(e))) {
						report(status, false, t('mcp', 'Not saved: each client needs a name of up to 64 characters that no other client has, and 1 to 20 redirect URIs (absolute https URIs, without user name, wildcard or fragment).'))
					}
				} finally {
					save.disabled = false
				}
			})

			// A saved client's Enabled setting applies at once, like the native client's; a new one's is saved with it.
			enabled.addEventListener('change', async () => {
				if (!saved) {
					return
				}
				const value = enabled.checked
				enabled.disabled = true
				try {
					await saveCustom((clients) => clients.map((other) => other.id === client.id ? { ...other, enabled: value } : other))
					report(status, true)
				} catch (e) {
					enabled.checked = !value
					if (!(await recover(e))) {
						report(status, false)
						notifyError(t('mcp', 'Could not change the self-hosted client.'))
					}
				} finally {
					enabled.disabled = false
				}
			})

			remove.addEventListener('click', async () => {
				if (!saved) {
					card.remove()
					return
				}
				if (!window.confirm(t('mcp', 'Delete the client “{name}”? Its connections are signed out at once.', { name: client.name }))) {
					return
				}
				remove.disabled = true
				try {
					await saveCustom((clients) => clients.filter((other) => other.id !== client.id))
					card.remove()
				} catch (e) {
					if (!(await recover(e))) {
						report(status, false)
						notifyError(t('mcp', 'Could not delete the self-hosted client.'))
					}
				} finally {
					remove.disabled = false
				}
			})
			return card
		}

		try {
			show(await api('GET', '/api/oauth-clients'))
		} catch (e) {
			notifyError(t('mcp', 'Could not load the OAuth client settings.'))
			return
		}
		customList.replaceChildren(...customClients.map(customCard))
		customAdd.addEventListener('click', () => {
			const card = customCard({ id: null, name: '', enabled: true, redirectUris: [] })
			customList.append(card)
			card.querySelector('input[type="text"]').focus()
		})

		hostsSave.addEventListener('click', async () => {
			const hosts = hostsInput.value.split(',').map((host) => host.trim().toLowerCase()).filter((host) => host !== '')
			hostsSave.disabled = true
			try {
				show(await put(() => ({ hosts })))
				report(hostsStatus, true)
			} catch (e) {
				if (!(await recover(e))) {
					report(hostsStatus, false, t('mcp', 'Invalid host list: use lowercase host names only, without https://, port, path or *, and keep at least one.'))
				}
			} finally {
				hostsSave.disabled = false
			}
		})

		nativeBox.addEventListener('change', async () => {
			const value = nativeBox.checked
			nativeBox.disabled = true
			try {
				show(await put(() => ({ nativeClientEnabled: value })))
				report(nativeStatus, true)
			} catch (e) {
				nativeBox.checked = !value
				if (!(await recover(e))) {
					report(nativeStatus, false)
					notifyError(t('mcp', 'Could not change the native client.'))
				}
			} finally {
				nativeBox.disabled = false
			}
		})
	}

	/**
	 * Checkout upload limit of the status block, in whole MiB, through GET and PUT /apps/mcp/api/checkout-limit.
	 * Next to it: the effective limit and PHP's post_max_size ceiling.
	 */
	async function initCheckoutLimit() {
		const input = document.getElementById('mcp-checkout-limit')
		const save = document.getElementById('mcp-checkout-limit-save')
		const status = document.getElementById('mcp-checkout-limit-status')
		const info = document.getElementById('mcp-checkout-limit-info')
		const mib = (bytes) => Math.round(bytes / 1048576 * 10) / 10

		/** @param {object} data limit returned by the API, in bytes */
		function show(data) {
			// Round up and never show 0: a stored value below 1 MiB would fail the field's own min of 1 on the next save.
			input.value = String(Math.max(1, Math.ceil(data.configuredBytes / 1048576)))
			const effective = t('mcp', 'Effective limit: {size} MiB.', { size: mib(data.effectiveBytes) })
			const php = data.phpBytes > 0
				? t('mcp', 'PHP allows up to {size} MiB (post_max_size).', { size: mib(data.phpBytes) })
				: t('mcp', 'PHP sets no request size limit.')
			info.textContent = effective + ' ' + php
		}

		try {
			show(await api('GET', '/api/checkout-limit'))
		} catch (e) {
			info.textContent = t('mcp', 'Could not load the checkout upload limit.')
			return
		}

		save.addEventListener('click', async () => {
			const value = Number(input.value)
			if (!Number.isInteger(value) || value < 1) {
				status.textContent = t('mcp', 'Enter a whole number of MiB, at least 1.')
				flash(status, false)
				return
			}
			save.disabled = true
			try {
				show(await api('PUT', '/api/checkout-limit', { mib: value }))
				status.textContent = t('mcp', 'Saved')
				flash(status, true)
			} catch (e) {
				status.textContent = t('mcp', 'Not saved')
				flash(status, false)
				notifyError(t('mcp', 'Could not save the checkout upload limit.'))
			} finally {
				save.disabled = false
			}
		})
	}

	/**
	 * Server log section: the switch that hides the text of log messages in the log tools, through GET and PUT
	 * /apps/mcp/api/logs-privacy.
	 */
	async function initLogsPrivacy() {
		const box = document.getElementById('mcp-logs-hide-file-names')
		const status = document.getElementById('mcp-logs-hide-file-names-status')
		if (!box) {
			return
		}
		// The "Learn more" button opens the explanation that ships in the template; without JS it stays open.
		const more = document.getElementById('mcp-logs-privacy-more')
		const detail = document.getElementById('mcp-logs-privacy-detail')
		if (more && detail) {
			detail.hidden = true
			more.hidden = false
			more.addEventListener('click', () => {
				detail.hidden = !detail.hidden
				more.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true')
			})
		}
		try {
			box.checked = (await api('GET', '/api/logs-privacy')).hideFileNames
		} catch (e) {
			box.disabled = true
			notifyError(t('mcp', 'Could not load the log privacy setting.'))
			return
		}
		box.addEventListener('change', async () => {
			const value = box.checked
			box.disabled = true
			try {
				box.checked = (await api('PUT', '/api/logs-privacy', { hideFileNames: value })).hideFileNames
				status.textContent = t('mcp', 'Saved')
				flash(status, true)
			} catch (e) {
				box.checked = !value
				status.textContent = t('mcp', 'Not saved')
				flash(status, false)
				notifyError(t('mcp', 'Could not change the log privacy setting.'))
			} finally {
				box.disabled = false
			}
		})
	}

	/**
	 * Server log section: the groups whose members may read the log besides the administrators, through
	 * GET and PUT /apps/mcp/api/logs-access. The picker locks while a save runs and each save carries
	 * the list it changed (`previous`), so the server answers 409 when another window or administrator
	 * saved first and a removed group is never put back. A listed group that was deleted shows as an
	 * option marked "(group removed)", to be removed.
	 */
	async function initLogsAccess() {
		const container = document.getElementById('mcp-logs-groups')
		const status = document.getElementById('mcp-logs-status')
		const summary = document.getElementById('mcp-logs-summary')
		if (!container || !window.McpAdminPicker) {
			return
		}
		let version = 0
		// The block's own revision, from its own GET or save; never the matrix's, never an error body's.
		let logsRevision = null
		let picker = null
		followRevision(() => logsRevision, (revision) => { logsRevision = revision })

		/** @param {object} data state returned by the API */
		function show(data) {
			version = data.version
			logsRevision = data.revision
			const options = data.allGroups.map((group) => ({ id: group.id, label: group.displayName }))
			for (const id of data.missing) {
				options.push({ id: id, label: t('mcp', '{group} (group removed)', { group: id }, undefined, { escape: false }) })
			}
			if (!picker) {
				picker = window.McpAdminPicker.create({
					container,
					status,
					options,
					selectedIds: data.groups,
					searchLabel: t('mcp', 'Search groups'),
					listLabel: t('mcp', 'Server log groups'),
					emptyOptionsText: t('mcp', 'No groups found; only administrators can read the log.'),
					noMatchText: t('mcp', 'No match.'),
					save: async (nextIds, previousIds) => {
						const sent = logsRevision
						// The server stores the groups as a set and commits only a set other than `previous` (LogsAccess).
						const committed = new Set(nextIds).size !== new Set(previousIds).size || nextIds.some((id) => !previousIds.includes(id))
						const data = await api('PUT', '/api/logs-access', { groups: nextIds, previous: previousIds, version: version, expectedRevision: sent })
						if (committed && oneStep(sent, data.revision)) {
							announce(sent, data.revision)
						}
						return data
					},
					onSaved: async (data) => {
						show(data)
						// The matrix shows the log column per user from the same gate, so it is reloaded.
						load()
					},
					onError: async (e) => {
						if (e.message === '409' || e.message === '428') {
							notifyError(t('mcp', 'The groups were changed in another window or by another administrator; the current list was loaded.'))
							load()
						} else {
							notifyError(t('mcp', 'Could not save the groups that may read the server log.'))
						}
						await reload()
					},
				})
			} else {
				picker.setOptions(options, data.groups)
			}
			paintSummary(data.groups, data.allGroups.map((group) => ({ id: group.id, label: group.displayName })))
		}

		/**
		 * The effective readers, as one line: groups are a gate on top of the per-user grant of the matrix.
		 *
		 * @param {string[]} groups the saved group ids
		 * @param {Array<{id: string, label: string}>} options the known groups, for their display names
		 */
		function paintSummary(groups, options) {
			if (!summary) {
				return
			}
			const readers = options.filter((option) => groups.includes(option.id))
			const names = readers.map((option) => option.label).join(', ')
			try {
				summary.textContent = readers.length === 0
					? t('mcp', 'Who may read the log: administrators only. Each of them also needs the “Server log” permission in the “Access and permissions” tab.')
					: n('mcp',
						'Who may read the log: administrators and the members of %n group ({names}). Each of them also needs the “Server log” permission in the “Access and permissions” tab.',
						'Who may read the log: administrators and the members of %n groups ({names}). Each of them also needs the “Server log” permission in the “Access and permissions” tab.',
						readers.length, { names }, { escape: false })
			} catch {
				// Optional explanatory text must never prevent managing the actual groups.
				summary.textContent = ''
			}
		}

		/** Loads the list again, after a conflict or a failure. */
		async function reload() {
			try {
				show(await api('GET', '/api/logs-access'))
			} catch (e) {
				if (picker) {
					picker.showFallback(t('mcp', 'Could not load the groups.'))
				} else {
					container.setAttribute('aria-busy', 'false')
					container.replaceChildren(el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load the groups.') }))
				}
			}
		}

		await reload()
	}

	/**
	 * What js/admin-overrides.js needs from the matrix: the page on screen, the shared request, lock and undo notice,
	 * and a re-render, so it can paint rows that were drawn before it loaded. It never touches the matrix state itself.
	 */
	window.McpAdminMatrix = {
		data: () => state.data,
		api,
		announce,
		reload: reloadLocked,
		policyError,
		notifyError,
		isBusy: () => bulkBusy || matrixLocked,
		setBusy: setBulkBusy,
		labels: () => ({ modules: moduleLabels(), operations: operationLabels() }),
		/**
		 * @param {string} message text of the notice
		 * @param {{receiptId: string|null, revision: string}} saved the saved command
		 * @return {Function|null} the undo that was offered, to give back to focusUndo()
		 */
		offerUndo(message, saved) {
			const undo = saved.receiptId === null ? null : () => undoBulk(saved.receiptId, saved.revision)
			showNotice(message, undo)
			return undo
		},
		/** @param {Function|null} undo what offerUndo() returned: the focus moves to it while it is still the offered undo */
		focusUndo(undo) {
			if (undo !== null && pendingUndo === undo) {
				undoButton.focus()
			}
		},
		refresh: () => state.data !== null && render(),
		bulk,
		levelLabels,
	}

	load()
	initTagsSection()
	initOauthClients()
	initCheckoutLimit()
	initLogsAccess()
	initLogsPrivacy()
})()
