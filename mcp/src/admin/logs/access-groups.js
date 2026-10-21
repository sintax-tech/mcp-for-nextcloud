/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createPolicyState, mustReloadPolicy } from '../../platform/policy-state.js'
import { createScope } from '../../platform/lifecycle.js'

export function mountLogAccess({ root, request, t, n, notifyError, createPicker, reloadMatrix, policyChannel }) {
	const scope = createScope(root)
	const api = scope.request(request)
    const policy = createPolicyState(policyChannel)
    scope.use(policy.destroy)
	function el(tag, props, children) {
		const node = document.createElement(tag)
		Object.assign(node, props || {})
		for (const child of children || []) {
			node.append(child)
		}
		return node
	}
	async function start() {
		const container = root.querySelector('#mcp-logs-groups')
		const status = root.querySelector('#mcp-logs-status')
		const summary = root.querySelector('#mcp-logs-summary')
		if (!container || !createPicker) {
			return
		}
		let version = 0
		let picker = null

		/** @param {object} data state returned by the API */
		function show(data) {
			version = data.version
			const options = data.allGroups.map((group) => ({ id: group.id, label: group.displayName }))
			for (const id of data.missing) {
				options.push({ id: id, label: t('mcp', '{group} (group removed)', { group: id }, undefined, { escape: false }) })
			}
			if (!picker) {
				picker = createPicker({
					container,
					status,
					options,
					selectedIds: data.groups,
					searchLabel: t('mcp', 'Search groups'),
					listLabel: t('mcp', 'Server log groups'),
					emptyOptionsText: t('mcp', 'No groups found; only administrators can read the log.'),
					noMatchText: t('mcp', 'No match.'),
					save: async (nextIds, previousIds) => {
						const sent = policy.revision
                        if (sent === null) throw new Error('Policy snapshot unavailable')
                        const result = await api('PUT', '/api/logs-access', { groups: nextIds, previous: previousIds, version, expectedRevision: sent })
                        if (!policy.saved(sent, result.revision, { announce: nextIds.length !== previousIds.length || nextIds.some(id => !previousIds.includes(id)) })) return null
                        return result
					},
					onSaved: async (data) => {
						if (data === null) await reload()
                        else show(data)
						// The matrix shows the log column per user from the same gate, so it is reloaded.
						reloadMatrix()
					},
					onError: async (e) => {
						if (mustReloadPolicy(e)) {
                            policy.invalidate()
							notifyError(t('mcp', 'The groups were changed in another window or by another administrator; the current list was loaded.'))
							reloadMatrix()
						} else {
							notifyError(t('mcp', 'Could not save the groups that may read the server log.'))
						}
						await reload()
					},
				})
				scope.use(picker.destroy)
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
			if (scope.disposed) return
				// Optional explanatory text must never prevent managing the actual groups.
				summary.textContent = ''
			}
		}

		/** Loads the list again, after a conflict or a failure. */
		async function reload() {
			try {
                const ticket = policy.read()
                const result = await api('GET', '/api/logs-access')
                if (policy.accept(ticket, result.revision)) show(result)
			} catch (e) {
			if (scope.disposed) return
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
	const ready = start()
	return { ready, destroy: scope.destroy }
}
