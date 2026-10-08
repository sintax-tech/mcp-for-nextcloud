/**
 * Active OAuth connections of the MCP app, shared by the admin page (every user, GET/DELETE /apps/mcp/api/connections)
 * and the personal page (only the signed-in user, GET/DELETE /apps/mcp/api/my/connections). The root element's
 * data-scope ("admin" or "personal") picks the API and the columns.
 * Plain browser JavaScript, no build step; strings go through t('mcp', …) for translation.
 */
(function () {
	'use strict'

	const root = document.getElementById('mcp-connections')
	if (!root) {
		return
	}

	const admin = root.dataset.scope === 'admin'
	const base = admin ? '/api/connections' : '/api/my/connections'
	const state = { search: '', page: 1, data: null }
	const table = document.getElementById('mcp-connections-table')
	const searchInput = document.getElementById('mcp-connections-search')
	const prevButton = document.getElementById('mcp-connections-prev')
	const nextButton = document.getElementById('mcp-connections-next')
	const pageInfo = document.getElementById('mcp-connections-page-info')
	const status = document.getElementById('mcp-connections-status')
	const connectionsCount = document.getElementById('mcp-count-connections')
	// t() escapes variables as HTML by default; the confirm dialog and the status region take plain text, where
	// that would show "D&#039;Avila". Never pass this for a result that is inserted as HTML.
	const PLAIN_TEXT = { escape: false }

	/**
	 * @param {string} method HTTP method
	 * @param {string} path path below /apps/mcp
	 * @return {Promise<object>} decoded JSON response
	 */
	async function api(method, path) {
		const response = await fetch(OC.generateUrl('/apps/mcp' + path), {
			method,
			headers: { Accept: 'application/json', requesttoken: OC.requestToken },
		})
		if (!response.ok) {
			throw new Error(String(response.status))
		}
		return response.json()
	}

	/** @param {string} message error shown to the user */
	function notifyError(message) {
		// The old notification global is gone since Nextcloud 34 and OCP.Toast is deprecated, so without it the message goes to the
		// list's own live region instead of being lost.
		if (window.OCP && OCP.Toast) {
			OCP.Toast.error(message)
		} else {
			status.textContent = message
		}
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

	/**
	 * @param {number} seconds Unix time
	 * @return {string} date and time in the user's locale
	 */
	function when(seconds) {
		const locale = (document.documentElement.dataset.locale || '').replace('_', '-') || undefined
		try {
			return new Date(seconds * 1000).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' })
		} catch (e) {
			return new Date(seconds * 1000).toLocaleString()
		}
	}

	/**
	 * @param {object} client client of a connection from the API
	 * @return {string} what people call it: the host, or a fixed label for the native client
	 */
	function clientName(client) {
		return client.kind === 'native' ? t('mcp', 'Local program (native client)') : client.host
	}

	/**
	 * @param {object} client client of a connection from the API
	 * @return {HTMLElement} its name, with the full client_id as tooltip
	 */
	function clientCell(client) {
		return el('td', { title: client.id, textContent: clientName(client) })
	}

	/** @param {{counts?: {connections?: number}}} result DELETE response; refreshes the Status card when it carries the new total */
	function updateCounts(result) {
		if (connectionsCount && result && result.counts && Number.isInteger(result.counts.connections)) {
			connectionsCount.textContent = String(result.counts.connections)
		}
	}

	/** @param {string} text message for the screen reader status region */
	function say(text) {
		if (status) {
			status.textContent = text
		}
	}

	/** Reads the current page and re-renders the table; a failure is shown in the table with a retry. */
	async function load() {
		table.setAttribute('aria-busy', 'true')
		const query = new URLSearchParams({ page: String(state.page) })
		if (admin) {
			query.set('search', state.search)
		}
		try {
			state.data = await api('GET', base + '?' + query.toString())
			if (state.data.connections.length === 0 && state.page > 1) {
				// The last row of the last page was revoked: show the previous page instead of an empty one.
				state.page -= 1
				await load()
				return
			}
			render()
		} catch (e) {
			const retry = el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: load })
			table.tBodies[0].replaceChildren(el('tr', {}, [el('td', { colSpan: admin ? 5 : 4, className: 'mcp-empty mcp-load-error' },
				[t('mcp', 'Could not load the connections.'), ' ', retry])]))
		} finally {
			table.setAttribute('aria-busy', 'false')
		}
	}

	/** Renders the rows and the pager from state.data. */
	function render() {
		const data = state.data
		const rows = data.connections.map((connection) => {
			const cells = []
			if (admin) {
				cells.push(el('th', { scope: 'row' }, [
					el('span', { className: 'mcp-name', textContent: connection.displayName }),
					el('span', { className: 'mcp-uid', textContent: connection.uid }),
				]))
			}
			cells.push(clientCell(connection.client))
			cells.push(el('td', { textContent: when(connection.createdAt) }))
			cells.push(el('td', { textContent: when(connection.expiresAt) }))
			// Every row has the same visible button text, so the accessible name says which connection it revokes.
			const client = clientName(connection.client)
			const actions = el('td', { className: 'mcp-actions' }, [el('button', {
				type: 'button',
				textContent: t('mcp', 'Revoke'),
				onclick: () => revoke(connection),
			})])
			actions.firstChild.setAttribute('aria-label', admin
				? t('mcp', 'Revoke {client} for {user}', { client, user: connection.displayName }, undefined, PLAIN_TEXT)
				: t('mcp', 'Revoke {client}', { client }, undefined, PLAIN_TEXT))
			if (admin) {
				const all = el('button', {
					type: 'button',
					textContent: t('mcp', 'Revoke all of this user'),
					onclick: () => revokeUser(connection),
				})
				all.setAttribute('aria-label', t('mcp', 'Revoke all connections of {user}', { user: connection.displayName }, undefined, PLAIN_TEXT))
				actions.append(' ', all)
			}
			cells.push(actions)
			return el('tr', {}, cells)
		})
		if (rows.length === 0) {
			const text = admin
				? (state.search === '' ? t('mcp', 'No active connections.') : t('mcp', 'No connection matches the search.'))
				: t('mcp', 'You have no active connections.')
			rows.push(el('tr', {}, [el('td', { colSpan: admin ? 5 : 4, className: 'mcp-empty', textContent: text })]))
		}
		table.tBodies[0].replaceChildren(...rows)
		prevButton.disabled = data.page <= 1
		nextButton.disabled = !data.hasMore
		pageInfo.textContent = t('mcp', 'Page {page}', { page: data.page })
	}

	/** @param {object} connection row from the API */
	async function revoke(connection) {
		const name = clientName(connection.client)
		const question = admin
			? t('mcp', 'Revoke the connection of {user} through {client}? The client is signed out at once.', { user: connection.displayName, client: name }, undefined, PLAIN_TEXT)
			: t('mcp', 'Revoke the connection through {client}? The client is signed out at once.', { client: name }, undefined, PLAIN_TEXT)
		if (!window.confirm(question)) {
			return
		}
		try {
			updateCounts(await api('DELETE', base + '/' + encodeURIComponent(String(connection.id))))
			say(t('mcp', 'Connection revoked.'))
		} catch (e) {
			notifyError(t('mcp', 'Could not revoke the connection.'))
		}
		await load()
	}

	/** @param {object} connection any row of the user whose connections are revoked */
	async function revokeUser(connection) {
		if (!window.confirm(t('mcp', 'Revoke every connection of {user}? Their clients are signed out at once; the user can sign in again.', { user: connection.displayName }, undefined, PLAIN_TEXT))) {
			return
		}
		try {
			updateCounts(await api('DELETE', '/api/connections/users/' + encodeURIComponent(connection.uid)))
			say(t('mcp', 'Connections of {user} revoked.', { user: connection.displayName }, undefined, PLAIN_TEXT))
		} catch (e) {
			notifyError(t('mcp', 'Could not revoke the connections of {user}.', { user: connection.displayName }))
		}
		state.page = 1
		await load()
	}

	if (searchInput) {
		let timer = null
		searchInput.addEventListener('input', () => {
			clearTimeout(timer)
			timer = setTimeout(() => {
				state.search = searchInput.value.trim()
				state.page = 1
				load()
			}, 300)
		})
	}
	prevButton.addEventListener('click', () => {
		state.page = Math.max(1, state.page - 1)
		load()
	})
	nextButton.addEventListener('click', () => {
		state.page += 1
		load()
	})

	load()
})()
