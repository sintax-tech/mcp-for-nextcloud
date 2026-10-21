// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createScope } from '../../platform/lifecycle.js'
export function mountMatrixFilters({ root, t, getData, moduleLabels, reload, onPresentationChange }) {
	const scope = createScope(root)
	const state = { search: '', group: '', filter: 'eligible', page: 1, app: '', view: 'levels', groupsLoaded: false }

	const searchInput = root.querySelector('#mcp-search')
	const groupSelect = root.querySelector('#mcp-group')
	const filterSelect = root.querySelector('#mcp-filter')
	const appSelect = root.querySelector('#mcp-app')
	const viewButtons = { levels: root.querySelector('#mcp-view-levels'), operations: root.querySelector('#mcp-view-operations') }
	// A fresh controller must not display filters from a disposed query.
	searchInput.value = state.search
	groupSelect.replaceChildren(groupSelect.querySelector('option[value=""]'))
	groupSelect.value = state.group
	const prevButtons = [...root.querySelectorAll('#mcp-prev-bottom')]
	const nextButtons = [...root.querySelectorAll('#mcp-next-bottom')]
	function el(tag, props, children) {
		const node = document.createElement(tag)
		Object.assign(node, props || {})
		for (const child of children || []) {
			node.append(child)
		}
		return node
	}
	function renderGroups() {
		if (state.groupsLoaded) {
			return
		}
		for (const group of getData().groups) {
			groupSelect.append(el('option', { value: group.id, textContent: group.displayName }))
		}
		state.groupsLoaded = true
	}
	function renderApps() {
		const labels = moduleLabels()
		const modules = Object.keys(getData().catalog)
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
	function setView(view, app) {
		state.view = view
		// The levels view shows every module, so the app filter of the operations view leaves the address with it.
		if (view === 'levels') {
			state.app = ''
		} else if (app !== undefined) {
			state.app = app
		}
		if (getData()) {
			onPresentationChange()
		}
		writeAppToHash(false)
	}
	function readAppFromHash() {
		const [tab, query = ''] = window.location.hash.slice(1).split('?')
		if (tab !== 'access') {
			return
		}
		const params = new URLSearchParams(query)
		const view = params.get('view') === 'operations' ? 'operations' : 'levels'
		state.app = params.get('app') || ''
		if (!getData()) {
			state.view = view
			return
		}
		if (view !== state.view) {
			state.view = view
			onPresentationChange()
			return
		}
		renderApps()
		onPresentationChange()
	}
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
	scope.on(appSelect, 'change', () => {
		state.app = appSelect.value
		onPresentationChange()
		writeAppToHash(false)
	})
	scope.on(window, 'hashchange', readAppFromHash)
	readAppFromHash()
	let searchTimer = null
	scope.on(searchInput, 'input', () => {
		scope.clearTimeout(searchTimer)
		searchTimer = scope.timeout(() => {
			state.search = searchInput.value.trim()
			state.page = 1
			reload()
		}, 300)
	})
	scope.on(groupSelect, 'change', () => {
		state.group = groupSelect.value
		state.page = 1
		reload()
	})
	// The matrix opens on the users who can connect; "Everyone" stays one choice away.
	filterSelect.value = state.filter
	scope.on(filterSelect, 'change', () => {
		state.filter = filterSelect.value
		state.page = 1
		reload()
	})
	for (const button of prevButtons) {
		scope.on(button, 'click', () => {
			state.page = Math.max(1, state.page - 1)
			reload()
		})
	}
	for (const button of nextButtons) {
		scope.on(button, 'click', () => {
			state.page += 1
			reload()
		})
	}
    for (const [view, button] of Object.entries(viewButtons)) {
        scope.on(button, 'click', () => { if (state.view !== view) setView(view) })
    }

	return { get values() { return { search: state.search, group: state.group, filter: state.filter, page: state.page, app: state.app, view: state.view } }, setView, renderOptions() { renderGroups(); renderApps() }, destroy: scope.destroy }
}
