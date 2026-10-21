/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createScope } from '../../platform/lifecycle.js'
import { flashStatus as flash } from '../status-feedback.js'

export function mountLogPrivacy({ root, request, t, notifyError }) {
	const scope = createScope(root)
	const api = scope.request(request)
	async function start() {
		const box = root.querySelector('#mcp-logs-hide-file-names')
		const status = root.querySelector('#mcp-logs-hide-file-names-status')
		if (!box) {
			return
		}
		// The "Learn more" button opens the explanation that ships in the template; without JS it stays open.
		const more = root.querySelector('#mcp-logs-privacy-more')
		const detail = root.querySelector('#mcp-logs-privacy-detail')
		if (more && detail) {
			detail.hidden = true
			more.hidden = false
			scope.on(more, 'click', () => {
				detail.hidden = !detail.hidden
				more.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true')
			})
		}
		try {
			box.checked = (await api('GET', '/api/logs-privacy')).hideFileNames
		} catch (e) {
			if (scope.disposed) return
			box.disabled = true
			notifyError(t('mcp', 'Could not load the log privacy setting.'))
			return
		}
		scope.on(box, 'change', async () => {
			const value = box.checked
			box.disabled = true
			try {
				box.checked = (await api('PUT', '/api/logs-privacy', { hideFileNames: value })).hideFileNames
				status.textContent = t('mcp', 'Saved')
				flash(status, true)
			} catch (e) {
			if (scope.disposed) return
				box.checked = !value
				status.textContent = t('mcp', 'Not saved')
				flash(status, false)
				notifyError(t('mcp', 'Could not change the log privacy setting.'))
			} finally {
				if (!scope.disposed) {
					box.disabled = false
				}
			}
		})

	}
	const ready = start()
	return { ready, destroy: scope.destroy }
}
