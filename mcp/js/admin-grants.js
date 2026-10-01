/**
 * Admin permission matrix (users × permissions) for the MCP app.
 *
 * Loads one page of users from GET /apps/mcp/api/grants and saves each checkbox immediately through
 * PUT /apps/mcp/api/grants/{uid}, POST /apps/mcp/api/grants/bulk and PUT /apps/mcp/api/service.
 * Plain browser JavaScript, no build step; strings go through t('mcp', …) for translation.
 */
(function () {
	'use strict'

	const root = document.getElementById('mcp-admin')
	if (!root) {
		return
	}

	const state = { search: '', group: '', page: 1, data: null, groupsLoaded: false }
	const table = document.getElementById('mcp-matrix')
	const searchInput = document.getElementById('mcp-search')
	const groupSelect = document.getElementById('mcp-group')
	const prevButton = document.getElementById('mcp-prev')
	const nextButton = document.getElementById('mcp-next')
	const pageInfo = document.getElementById('mcp-page-info')
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
		}
	}

	/** @return {Object<string, string>} operation labels */
	function operationLabels() {
		return {
			read: t('mcp', 'read'),
			edit: t('mcp', 'edit'),
			create: t('mcp', 'create'),
			move: t('mcp', 'move'),
			delete: t('mcp', 'delete'),
			transfer: t('mcp', 'transfer'),
			reply: t('mcp', 'reply'),
			attach: t('mcp', 'attach'),
			quote: t('mcp', 'quote'),
		}
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
			throw new Error(String(response.status))
		}
		return response.json()
	}

	/** @param {string} message error shown to the admin */
	function notifyError(message) {
		if (window.OCP && OCP.Toast) {
			OCP.Toast.error(message)
		} else if (OC.Notification) {
			OC.Notification.showTemporary(message)
		}
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

	/** Reads the current page from the server and re-renders the table. */
	async function load() {
		table.setAttribute('aria-busy', 'true')
		const query = new URLSearchParams({ search: state.search, group: state.group, page: String(state.page) })
		try {
			state.data = await api('GET', '/api/grants?' + query.toString())
			render()
		} catch (e) {
			notifyError(t('mcp', 'Could not load the users.'))
		} finally {
			table.setAttribute('aria-busy', 'false')
		}
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

	/**
	 * @param {string} operation operation id or "eligible"
	 * @param {string|null} module module id, null for eligibility
	 * @param {string} label permission name shown in the confirmation
	 * @return {HTMLElement} "all / none" buttons for the users on this page
	 */
	function bulkButtons(operation, module, label) {
		const make = (granted, text, title) => el('button', {
			type: 'button',
			className: 'mcp-bulk',
			textContent: text,
			title,
			onclick: () => bulk(module, operation, granted, label),
		})
		return el('span', { className: 'mcp-bulk-group' }, [
			make(true, '✓', t('mcp', 'Allow for all users on this page')),
			make(false, '✕', t('mcp', 'Deny for all users on this page')),
		])
	}

	/** Renders header and rows from state.data. */
	function render() {
		const data = state.data
		renderGroups()
		const modules = moduleLabels()
		const operations = operationLabels()

		const top = el('tr')
		const sub = el('tr')
		top.append(el('th', { rowSpan: 2, className: 'mcp-user-col', scope: 'col', textContent: t('mcp', 'User') }))
		top.append(el('th', { rowSpan: 2, scope: 'col' }, [t('mcp', 'Can connect'), el('br'), bulkButtons('eligible', null, t('mcp', 'Can connect'))]))
		for (const [module, ops] of Object.entries(data.catalog)) {
			const head = el('th', { colSpan: ops.length, scope: 'colgroup', className: 'mcp-module', textContent: modules[module] || module })
			top.append(head)
			for (const operation of ops) {
				const label = (modules[module] || module) + ': ' + (operations[operation] || operation)
				sub.append(el('th', { scope: 'col' }, [operations[operation] || operation, el('br'), bulkButtons(operation, module, label)]))
			}
		}
		top.append(el('th', { rowSpan: 2, scope: 'col', textContent: t('mcp', 'Connected') }))
		table.tHead.replaceChildren(top, sub)

		const rows = data.users.map(renderRow)
		if (rows.length === 0) {
			const columns = 3 + Object.values(data.catalog).reduce((sum, ops) => sum + ops.length, 0)
			rows.push(el('tr', {}, [el('td', { colSpan: columns, className: 'mcp-empty', textContent: t('mcp', 'No users found.') })]))
		}
		table.tBodies[0].replaceChildren(...rows)

		prevButton.disabled = data.page <= 1
		nextButton.disabled = !data.hasMore
		pageInfo.textContent = data.total === null
			? t('mcp', 'Page {page}', { page: data.page })
			: t('mcp', 'Page {page} of {pages}', { page: data.page, pages: Math.max(1, Math.ceil(data.total / data.pageSize)) })
	}

	/**
	 * @param {object} user row from the API
	 * @return {HTMLTableRowElement}
	 */
	function renderRow(user) {
		const row = el('tr', { className: user.enabled ? '' : 'mcp-disabled-user' })
		row.dataset.uid = user.uid
		const name = el('th', { scope: 'row', className: 'mcp-user-col' }, [
			el('span', { className: 'mcp-name', textContent: user.displayName }),
			el('span', { className: 'mcp-uid', textContent: user.uid }),
		])
		if (!user.enabled) {
			name.append(el('span', { className: 'mcp-badge', textContent: t('mcp', 'disabled') }))
		}
		row.append(name)
		row.append(checkboxCell(user, !user.enabled, user.eligible, t('mcp', 'Can connect'), { eligible: null }))
		for (const [module, ops] of Object.entries(state.data.catalog)) {
			for (const operation of ops) {
				const available = user.appsEnabled[module]
				const cell = checkboxCell(user, !user.enabled || !user.eligible || !available, user.grants[module][operation], module + ':' + operation, { module, operation, granted: null })
				if (!available) {
					cell.title = t('mcp', 'This app is unavailable for this user; the saved permission is kept.')
					cell.classList.add('mcp-dim')
					const hint = el('span', { className: 'mcp-dim-hint', textContent: ' ⓘ' })
					hint.setAttribute('aria-label', cell.title)
					cell.append(hint)
				}
				row.append(cell)
			}
		}
		row.append(el('td', { className: 'mcp-connected', textContent: user.connected ? t('mcp', 'yes') : t('mcp', 'no') }))
		return row
	}

	/**
	 * @param {object} user row from the API
	 * @param {boolean} disabled whether the checkbox is locked
	 * @param {boolean} checked current value
	 * @param {string} label accessible name
	 * @param {object} body request body template; the null field receives the new value
	 * @return {HTMLTableCellElement}
	 */
	function checkboxCell(user, disabled, checked, label, body) {
		const cell = el('td', { className: 'mcp-cell' })
		const box = el('input', { type: 'checkbox', checked, disabled })
		box.setAttribute('aria-label', user.displayName + ' — ' + label)
		box.addEventListener('change', async () => {
			const value = box.checked
			const payload = Object.fromEntries(Object.entries(body).map(([key, v]) => [key, v === null ? value : v]))
			box.disabled = true
			try {
				const updated = await api('PUT', '/api/grants/' + encodeURIComponent(user.uid), payload)
				const index = state.data.users.findIndex((u) => u.uid === user.uid)
				state.data.users[index] = updated
				const fresh = renderRow(updated)
				cell.parentElement.replaceWith(fresh)
				flash(fresh.children[cell.cellIndex], true)
			} catch (e) {
				box.checked = !value
				box.disabled = false
				flash(cell, false)
				notifyError(t('mcp', 'Could not save the permission for {user}.', { user: user.displayName }))
			}
		})
		cell.append(box)
		return cell
	}

	/**
	 * @param {string|null} module module id, null for eligibility
	 * @param {string} operation operation id or "eligible"
	 * @param {boolean} granted new value
	 * @param {string} label permission name
	 */
	async function bulk(module, operation, granted, label) {
		const uids = state.data.users.filter((u) => u.enabled && (module === null || u.appsEnabled[module])).map((u) => u.uid)
		if (uids.length === 0) {
			return
		}
		const question = module === null
			? (granted
				? t('mcp', 'Allow “{permission}” for the {count} users on this page?', { permission: label, count: uids.length })
				: t('mcp', 'Deny “{permission}” for the {count} users on this page?', { permission: label, count: uids.length }))
			: (granted
				? t('mcp', 'Allow “{permission}” for {count} users with this app available on this page?', { permission: label, count: uids.length })
				: t('mcp', 'Deny “{permission}” for {count} users with this app available on this page?', { permission: label, count: uids.length }))
		if (!window.confirm(question)) {
			return
		}
		try {
			await api('POST', '/api/grants/bulk', { uids, module, operation, granted })
		} catch (e) {
			notifyError(t('mcp', 'Could not update the users on this page.'))
		}
		await load()
	}

	serviceBox.addEventListener('change', async () => {
		const value = serviceBox.checked
		serviceBox.disabled = true
		try {
			const result = await api('PUT', '/api/service', { enabled: value })
			serviceBox.checked = result.enabled
			serviceStatus.textContent = t('mcp', 'Saved')
			flash(serviceStatus, true)
		} catch (e) {
			serviceBox.checked = !value
			serviceStatus.textContent = t('mcp', 'Not saved')
			flash(serviceStatus, false)
			notifyError(t('mcp', 'Could not change the MCP service.'))
		} finally {
			serviceBox.disabled = false
		}
	})

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
	prevButton.addEventListener('click', () => {
		state.page = Math.max(1, state.page - 1)
		load()
	})
	nextButton.addEventListener('click', () => {
		state.page += 1
		load()
	})
	for (const button of root.querySelectorAll('.mcp-copy')) {
		button.addEventListener('click', () => {
			const text = document.getElementById(button.dataset.copy).textContent
			navigator.clipboard.writeText(text).then(
				() => { button.textContent = t('mcp', 'Copied') },
				() => notifyError(t('mcp', 'Could not copy the endpoint.')),
			)
		})
	}

	load()
})()
