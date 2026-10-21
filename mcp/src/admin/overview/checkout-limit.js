/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createScope } from '../../platform/lifecycle.js'
import { flashStatus as flash } from '../status-feedback.js'

export function mountCheckoutLimit({ root, request, t, notifyError }) {
	const scope = createScope(root)
	const api = scope.request(request)
	async function start() {
		const input = root.querySelector('#mcp-checkout-limit')
		const save = root.querySelector('#mcp-checkout-limit-save')
		const status = root.querySelector('#mcp-checkout-limit-status')
		const info = root.querySelector('#mcp-checkout-limit-info')
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
			if (scope.disposed) return
			info.textContent = t('mcp', 'Could not load the checkout upload limit.')
			return
		}

		scope.on(save, 'click', async () => {
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
			if (scope.disposed) return
				status.textContent = t('mcp', 'Not saved')
				flash(status, false)
				notifyError(t('mcp', 'Could not save the checkout upload limit.'))
			} finally {
				if (!scope.disposed) {
					save.disabled = false
				}
			}
		})

	}
	const ready = start()
	return { ready, destroy: scope.destroy }
}
