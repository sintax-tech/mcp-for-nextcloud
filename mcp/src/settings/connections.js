/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createScope } from '../platform/lifecycle.js'

export function mountConnections({ root, mode, request, t, notifyError: reportError, onCounts = () => {} }) {
	const scope = createScope(root)
	const api = scope.request((method, path) => request(method, path, undefined, { json: false }))
	scope.use(() => root.querySelector('tbody').replaceChildren())
	const admin = mode === 'admin'
	const base = admin ? '/api/connections' : '/api/my/connections'
	const state = { search: '', page: 1, data: null }
	const table = root.querySelector('#mcp-connections-table')
	const searchInput = root.querySelector('#mcp-connections-search')
	if (searchInput) searchInput.value = state.search
	const prevButton = root.querySelector('#mcp-connections-prev')
	const nextButton = root.querySelector('#mcp-connections-next')
	const pageInfo = root.querySelector('#mcp-connections-page-info')
	const status = root.querySelector('#mcp-connections-status')
	const notifyError = message => reportError(message, status)
	// t() escapes variables as HTML by default; the confirm dialog and the status region take plain text, where
	// that would show "D&#039;Avila". Never pass this for a result that is inserted as HTML.
	const PLAIN_TEXT = { escape: false }

	function el(tag, props, children) {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(props || {})) node[key] = typeof value === 'function' ? scope.guard(value) : value
		for (const child of children || []) {
			node.append(child)
		}
		return node
	}

	function when(seconds) {
		const locale = (document.documentElement.dataset.locale || '').replace('_', '-') || undefined
		try {
			return new Date(seconds * 1000).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' })
		} catch (e) {
			return new Date(seconds * 1000).toLocaleString()
		}
	}

	function clientName(client) {
		if (client.kind === 'native') return t('mcp', 'Local program (native client)')
        if (client.kind === 'custom') return client.name || t('mcp', 'Self-hosted client')
        return client.host
	}

	function clientCell(client) {
		return el('td', { title: client.id, textContent: clientName(client) })
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
			if (scope.disposed) return
			const retry = el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: load })
			table.tBodies[0].replaceChildren(el('tr', {}, [el('td', { colSpan: admin ? 5 : 4, className: 'mcp-empty mcp-load-error' },
				[t('mcp', 'Could not load the connections.'), ' ', retry])]))
		} finally {
			if (!scope.disposed) {
				table.setAttribute('aria-busy', 'false')
			}
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
			onCounts(await api('DELETE', base + '/' + encodeURIComponent(String(connection.id))))
			say(t('mcp', 'Connection revoked.'))
		} catch (e) {
			if (scope.disposed) return
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
			onCounts(await api('DELETE', '/api/connections/users/' + encodeURIComponent(connection.uid)))
			say(t('mcp', 'Connections of {user} revoked.', { user: connection.displayName }, undefined, PLAIN_TEXT))
		} catch (e) {
			if (scope.disposed) return
			notifyError(t('mcp', 'Could not revoke the connections of {user}.', { user: connection.displayName }))
		}
		state.page = 1
		await load()
	}

	if (searchInput) {
		let timer = null
		scope.on(searchInput, 'input', () => {
			scope.clearTimeout(timer)
			timer = scope.timeout(() => {
				state.search = searchInput.value.trim()
				state.page = 1
				load()
			}, 300)
		})
	}
	scope.on(prevButton, 'click', () => {
		state.page = Math.max(1, state.page - 1)
		load()
	})
	scope.on(nextButton, 'click', () => {
		state.page += 1
		load()
	})

	const ready = load()
	return { ready, reload: load, destroy: scope.destroy }
}
