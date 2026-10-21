/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createScope } from '../../platform/lifecycle.js'

export function mountEndpointCopy({ root, t, notifyError }) {
	const scope = createScope()
	for (const button of root.querySelectorAll('.mcp-copy')) {
		scope.on(button, 'click', () => {
			const text = document.getElementById(button.dataset.copy).textContent
			navigator.clipboard.writeText(text).then(
				scope.guard(() => { button.textContent = t('mcp', 'Copied') }),
				scope.guard(() => notifyError(t('mcp', 'Could not copy the endpoint.'))),
			)
		})
	}
	return { destroy: scope.destroy }
}
