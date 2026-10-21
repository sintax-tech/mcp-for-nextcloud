/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * The user's own resource scope, read only (issue #4, B7; contracts B.6): for Files, Calendar and Tasks, whether the
 * MCP tools reach every resource or only the ones the administrator selected. GET /api/my/scope answers for the
 * signed-in user only. A scope that cannot be read is said so, never shown as unrestricted.
 */
import { createScope } from '../platform/lifecycle.js'
export function mountPersonalScope({ root, request, t }) {
    const lifetime = createScope()
    const api = lifetime.request(request)

	const status = root.querySelector('#mcp-my-scope-status')
	const list = root.querySelector('#mcp-my-scope-list')
	if (!status || !list) return

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		Object.assign(node, props)
		node.append(...children)
		return node
	}
	function line(module, scope) {
		const names = scope.items.map((item) => item.found === false ? t('mcp', 'a resource that no longer exists') : (item.path || item.name))
		if (scope.mode !== 'restricted') {
			return { files: t('mcp', 'Files: every folder'), calendar: t('mcp', 'Calendar: every calendar'), tasks: t('mcp', 'Tasks: every task list') }[module]
		}
		if (names.length === 0) {
			return { files: t('mcp', 'Files: no folder'), calendar: t('mcp', 'Calendar: no calendar'), tasks: t('mcp', 'Tasks: no task list') }[module]
		}
		return { files: t('mcp', 'Files: only {names}', { names: names.join(', ') }, undefined, { escape: false }), calendar: t('mcp', 'Calendar: only {names}', { names: names.join(', ') }, undefined, { escape: false }), tasks: t('mcp', 'Tasks: only {names}', { names: names.join(', ') }, undefined, { escape: false }) }[module]
	}

	async function load() {
		status.textContent = t('mcp', 'Loading…')
		try {
			const data = await api('GET', '/api/my/scope')
			list.replaceChildren(...['files', 'calendar', 'tasks'].map((module) => el('li', { textContent: line(module, data.modules[module]) })))
			status.textContent = ''
		} catch (error) {
            if (lifetime.disposed) return
			list.replaceChildren()
			status.textContent = t('mcp', 'Your resource scope cannot be read right now. Try again later.')
		}
	}
	return { ready: load(), destroy() { lifetime.destroy(); list.replaceChildren(); status.textContent = '' } }
}
