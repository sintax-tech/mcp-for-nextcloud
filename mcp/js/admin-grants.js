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

	const state = { search: '', group: '', filter: '', page: 1, data: null, groupsLoaded: false }
	const table = document.getElementById('mcp-matrix')
	const searchInput = document.getElementById('mcp-search')
	const groupSelect = document.getElementById('mcp-group')
	const filterSelect = document.getElementById('mcp-filter')
	const compactBox = document.getElementById('mcp-compact')
	const countInfo = document.getElementById('mcp-count')
	const prevButtons = [document.getElementById('mcp-prev'), document.getElementById('mcp-prev-bottom')]
	const nextButtons = [document.getElementById('mcp-next'), document.getElementById('mcp-next-bottom')]
	const pageInfos = [document.getElementById('mcp-page-info'), document.getElementById('mcp-page-info-bottom')]
	/** localStorage key of the compact-rows preference; a convenience of this browser only. */
	const COMPACT_KEY = 'mcp-admin-compact'
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
			share: t('mcp', 'share'),
			link: t('mcp', 'link'),
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
		const hints = {
			read: t('mcp', 'List, search and read'),
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

	/** Reads the current page from the server and re-renders the table; a failure is shown in the table with a retry. */
	async function load() {
		table.setAttribute('aria-busy', 'true')
		table.classList.add('mcp-loading')
		const query = new URLSearchParams({ search: state.search, group: state.group, filter: state.filter, page: String(state.page) })
		try {
			state.data = await api('GET', '/api/grants?' + query.toString())
			render()
		} catch (e) {
			renderError()
			notifyError(t('mcp', 'Could not load the users.'))
		} finally {
			table.setAttribute('aria-busy', 'false')
			table.classList.remove('mcp-loading')
		}
	}

	/** Replaces the body with an error row and a retry button, keeping the last header. */
	function renderError() {
		const retry = el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: load })
		const cell = el('td', { colSpan: table.tHead.rows[1] ? table.tHead.rows[1].cells.length + 3 : 1, className: 'mcp-empty mcp-load-error' },
			[t('mcp', 'Could not load the users.'), ' ', retry])
		table.tBodies[0].replaceChildren(el('tr', {}, [cell]))
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
	 * Header menu ("All") that allows or denies permissions for every user on the page.
	 *
	 * @param {string|null} module module id, null for eligibility
	 * @param {Array<{operations: string[], label: string}>} entries one line per permission (or group of them)
	 * @param {string} title accessible name of the menu
	 * @return {HTMLElement} a native <details> menu
	 */
	function bulkMenu(module, entries, title) {
		const list = el('div', { className: 'mcp-bulk-list' })
		for (const entry of entries) {
			const make = (granted, text) => el('button', {
				type: 'button',
				className: 'mcp-bulk',
				textContent: text,
				onclick: (event) => {
					event.target.closest('details').open = false
					bulk(module, entry.operations, granted, entry.label)
				},
			})
			list.append(el('div', { className: 'mcp-bulk-line' }, [
				el('span', { className: 'mcp-bulk-name', textContent: entry.label }),
				make(true, t('mcp', 'Allow')),
				make(false, t('mcp', 'Deny')),
			]))
		}
		const summary = el('summary', { className: 'mcp-bulk-summary', textContent: t('mcp', 'All') })
		summary.title = title
		summary.setAttribute('aria-label', title)
		return el('details', { className: 'mcp-bulk-menu' }, [summary, list])
	}

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

	/** Renders header and rows from state.data. */
	function render() {
		const data = state.data
		renderGroups()
		const modules = moduleLabels()
		const operations = operationLabels()

		const top = el('tr')
		const sub = el('tr')
		top.append(el('th', { rowSpan: 2, className: 'mcp-user-col', scope: 'col', textContent: t('mcp', 'User') }))
		const canConnect = t('mcp', 'Can connect')
		top.append(el('th', { rowSpan: 2, scope: 'col', title: t('mcp', 'The administrator allows this user to connect an MCP client') }, [
			canConnect, ' ', bulkMenu(null, [{ operations: ['eligible'], label: canConnect }], t('mcp', 'Change “{permission}” for every user on this page', { permission: canConnect })),
		]))
		Object.entries(data.catalog).forEach(([module, ops], index) => {
			const band = 'mcp-band-' + (index % 2)
			const name = modules[module] || module
			const entries = ops.map((operation) => ({ operations: [operation], label: name + ': ' + (operations[operation] || operation) }))
			entries.push({ operations: ops, label: t('mcp', '{module}: every permission', { module: name }) })
			top.append(el('th', { colSpan: ops.length, scope: 'colgroup', className: 'mcp-module ' + band }, [
				name, ' ', bulkMenu(module, entries, t('mcp', 'Change {module} permissions for every user on this page', { module: name })),
			]))
			ops.forEach((operation, i) => {
				sub.append(el('th', {
					scope: 'col',
					className: band + (i === 0 ? ' mcp-module' : ''),
					title: operationHint(module, operation),
					textContent: operations[operation] || operation,
				}))
			})
		})
		top.append(el('th', { rowSpan: 2, scope: 'col', textContent: t('mcp', 'Connected') }))
		table.tHead.replaceChildren(top, sub)
		stickSecondHeaderRow()

		const rows = data.users.map(renderRow)
		if (rows.length === 0) {
			const columns = 3 + Object.values(data.catalog).reduce((sum, ops) => sum + ops.length, 0)
			rows.push(el('tr', {}, [el('td', { colSpan: columns, className: 'mcp-empty', textContent: t('mcp', 'No users found.') })]))
		}
		table.tBodies[0].replaceChildren(...rows)

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
		} else if (user.eligible && user.connected) {
			name.append(el('span', { className: 'mcp-badge mcp-badge-connected', textContent: t('mcp', 'connected') }))
		}
		row.append(name)
		row.append(checkboxCell(user, !user.enabled, user.eligible, t('mcp', 'Can connect'), { eligible: null }))
		Object.entries(state.data.catalog).forEach(([module, ops], index) => {
			ops.forEach((operation, i) => {
				const available = user.appsEnabled[module]
				const cell = checkboxCell(user, !user.enabled || !user.eligible || !available, user.grants[module][operation], module + ':' + operation, { module, operation, granted: null })
				cell.classList.add('mcp-band-' + (index % 2))
				if (i === 0) {
					cell.classList.add('mcp-module')
				}
				if (!available) {
					cell.title = module === 'logs'
						? t('mcp', 'Only administrators and members of the groups selected in “Server log” can read the log; the saved permission is kept.')
						: t('mcp', 'This app is unavailable for this user; the saved permission is kept.')
					cell.classList.add('mcp-dim')
					const hint = el('span', { className: 'mcp-dim-hint', textContent: ' ⓘ' })
					hint.setAttribute('aria-label', cell.title)
					cell.append(hint)
				}
				row.append(cell)
			})
		})
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
	 * @param {string[]} operationList operation ids, or ["eligible"]; several are saved one after the other
	 * @param {boolean} granted new value
	 * @param {string} label permission name
	 */
	async function bulk(module, operationList, granted, label) {
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
			for (const operation of operationList) {
				await api('POST', '/api/grants/bulk', { uids, module, operation, granted })
			}
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
	try {
		compactBox.checked = window.localStorage.getItem(COMPACT_KEY) === '1'
	} catch (e) {
		compactBox.checked = false
	}
	table.classList.toggle('mcp-compact', compactBox.checked)
	compactBox.addEventListener('change', () => {
		table.classList.toggle('mcp-compact', compactBox.checked)
		stickSecondHeaderRow()
		try {
			window.localStorage.setItem(COMPACT_KEY, compactBox.checked ? '1' : '0')
		} catch (e) {
			// The preference is a convenience; without storage it lasts until the page is reloaded.
		}
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


	async function initTagsSection() {
		const container = document.getElementById('mcp-tags-list')
		const warning = document.getElementById('mcp-tags-warning')
		const status = document.getElementById('mcp-tags-status')
		if (!container) {
			return
		}

		try {
			const res = await api('GET', '/api/admin/tags')
			container.innerHTML = ''
			container.setAttribute('aria-busy', 'false')

			if (!res.tags || res.tags.length === 0) {
				container.appendChild(el('p', { className: 'mcp-empty' }, [t('mcp', 'No system tags found. Create tags in Files settings.')]))
				return
			}

			const selectedSet = new Set((res.selected || []).map(String))

			function updateWarning() {
				if (!warning) {
					return
				}
				const hasCollab = res.tags.some(tag => tag.type === 'collaborative' && selectedSet.has(String(tag.id)))
				warning.style.display = hasCollab ? 'block' : 'none'
			}

			updateWarning()

			for (const tag of res.tags) {
				const tagId = String(tag.id)
				const isChecked = selectedSet.has(tagId)

				const checkbox = el('input', {
					type: 'checkbox',
					className: 'checkbox',
					id: 'mcp-tag-' + tagId,
					value: tagId,
					checked: isChecked,
				})

				const typeBadge = el('span', {
					className: 'mcp-tag-type mcp-tag-type-' + tag.type,
					title: tag.type === 'invisible'
						? t('mcp', 'Visible and assignable only by administrators')
						: (tag.type === 'restricted'
							? t('mcp', 'Visible to users, assignable only by administrators')
							: t('mcp', 'Users can assign and remove this tag')),
				}, [
					tag.type === 'invisible' ? t('mcp', 'Invisible') : (tag.type === 'restricted' ? t('mcp', 'Restricted') : t('mcp', 'Collaborative'))
				])

				const label = el('label', { htmlFor: 'mcp-tag-' + tagId, className: 'mcp-tag-item' }, [
					checkbox,
					tag.name,
					typeBadge,
				])

				checkbox.addEventListener('change', async () => {
					if (checkbox.checked) {
						selectedSet.add(tagId)
					} else {
						selectedSet.delete(tagId)
					}
					updateWarning()

					checkbox.disabled = true
					if (status) {
						status.textContent = t('mcp', 'Saving…')
					}

					try {
						await api('PUT', '/api/admin/tags', { tagIds: Array.from(selectedSet) })
						if (status) {
							status.textContent = t('mcp', 'Saved')
							setTimeout(() => { if (status.textContent === t('mcp', 'Saved')) status.textContent = '' }, 2000)
						}
					} catch {
						notifyError(t('mcp', 'Could not update hidden tags.'))
						if (checkbox.checked) {
							selectedSet.delete(tagId)
						} else {
							selectedSet.add(tagId)
						}
						checkbox.checked = selectedSet.has(tagId)
						updateWarning()
					} finally {
						checkbox.disabled = false
					}
				})

				container.appendChild(label)
			}
		} catch {
			container.innerHTML = ''
			container.setAttribute('aria-busy', 'false')
			container.appendChild(el('p', { className: 'mcp-empty' }, [t('mcp', 'Could not load system tags.')]))
		}
	}

	/**
	 * OAuth clients section: allowed client_id hosts and the native client switch, through
	 * GET and PUT /apps/mcp/api/oauth-clients.
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

		/** @param {object} data settings returned by the API */
		function show(data) {
			hostsInput.value = data.hosts.join(', ')
			nativeBox.checked = data.nativeClientEnabled
			details.hidden = !data.nativeClientEnabled
			clientId.textContent = data.nativeClientId
			redirects.replaceChildren(...data.nativeRedirectUris.map((uri) => el('li', {}, [el('code', { textContent: uri })])))
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

		try {
			show(await api('GET', '/api/oauth-clients'))
		} catch (e) {
			notifyError(t('mcp', 'Could not load the OAuth client settings.'))
			return
		}

		hostsSave.addEventListener('click', async () => {
			const hosts = hostsInput.value.split(',').map((host) => host.trim().toLowerCase()).filter((host) => host !== '')
			hostsSave.disabled = true
			try {
				show(await api('PUT', '/api/oauth-clients', { hosts }))
				report(hostsStatus, true)
			} catch (e) {
				report(hostsStatus, false, t('mcp', 'Invalid host list: use lowercase host names only, without https://, port, path or *, and keep at least one.'))
			} finally {
				hostsSave.disabled = false
			}
		})

		nativeBox.addEventListener('change', async () => {
			const value = nativeBox.checked
			nativeBox.disabled = true
			try {
				show(await api('PUT', '/api/oauth-clients', { nativeClientEnabled: value }))
				report(nativeStatus, true)
			} catch (e) {
				nativeBox.checked = !value
				report(nativeStatus, false)
				notifyError(t('mcp', 'Could not change the native client.'))
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
	 * Server log section: the groups whose members may read the log besides the administrators, through
	 * GET and PUT /apps/mcp/api/logs-access. Each checkbox is saved at once with the whole list.
	 */
	async function initLogsAccess() {
		const container = document.getElementById('mcp-logs-groups')
		const status = document.getElementById('mcp-logs-status')
		if (!container) {
			return
		}
		let selected = new Set()

		/** @param {object} data state returned by the API */
		function show(data) {
			selected = new Set(data.groups)
			container.setAttribute('aria-busy', 'false')
			if (data.allGroups.length === 0) {
				container.replaceChildren(el('p', { className: 'mcp-empty', textContent: t('mcp', 'No groups found; only administrators can read the log.') }))
				return
			}
			container.replaceChildren(...data.allGroups.map((group) => {
				const box = el('input', { type: 'checkbox', className: 'checkbox', id: 'mcp-logs-group-' + group.id, checked: selected.has(group.id) })
				box.addEventListener('change', () => save(box, group.id))
				return el('label', { htmlFor: box.id, className: 'mcp-tag-item' }, [box, group.displayName])
			}))
		}

		/**
		 * @param {HTMLInputElement} box checkbox that changed
		 * @param {string} gid group id of the checkbox
		 */
		async function save(box, gid) {
			const next = new Set(selected)
			if (box.checked) {
				next.add(gid)
			} else {
				next.delete(gid)
			}
			box.disabled = true
			status.textContent = t('mcp', 'Saving…')
			try {
				show(await api('PUT', '/api/logs-access', { groups: Array.from(next) }))
				status.textContent = t('mcp', 'Saved')
				// The matrix shows the log column per user from the same gate, so it is reloaded.
				load()
			} catch (e) {
				box.checked = !box.checked
				box.disabled = false
				status.textContent = t('mcp', 'Not saved')
				notifyError(t('mcp', 'Could not save the groups that may read the server log.'))
			}
		}

		try {
			show(await api('GET', '/api/logs-access'))
		} catch (e) {
			container.setAttribute('aria-busy', 'false')
			container.replaceChildren(el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load the groups.') }))
		}
	}

	load()
	initTagsSection()
	initOauthClients()
	initCheckoutLimit()
	initLogsAccess()
})()
