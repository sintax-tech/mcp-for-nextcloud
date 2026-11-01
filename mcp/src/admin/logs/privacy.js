/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Original privacy editor.
 * @param {object} context live bindings of this editor
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.logs = admin.modules.logs || {}
	admin.modules.logs.privacy = function (context) {
		/**
		 * Server log section: the switch that hides the text of log messages in the log tools, through GET and PUT
		 * /apps/mcp/api/logs-privacy.
		 */
		async function initLogsPrivacy() {
			const box = document.getElementById('mcp-logs-hide-file-names')
			const status = document.getElementById('mcp-logs-hide-file-names-status')
			if (!box) {
				return
			}
			// The "Learn more" button opens the explanation that ships in the template; without JS it stays open.
			const more = document.getElementById('mcp-logs-privacy-more')
			const detail = document.getElementById('mcp-logs-privacy-detail')
			if (more && detail) {
				detail.hidden = true
				more.hidden = false
				more.addEventListener('click', () => {
					detail.hidden = !detail.hidden
					more.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true')
				})
			}
			try {
				box.checked = (await context.api('GET', '/api/logs-privacy')).hideFileNames
			} catch (e) {
				box.disabled = true
				context.notifyError(t('mcp', 'Could not load the log privacy setting.'))
				return
			}
			box.addEventListener('change', async () => {
				const value = box.checked
				box.disabled = true
				try {
					box.checked = (await context.api('PUT', '/api/logs-privacy', { hideFileNames: value })).hideFileNames
					status.textContent = t('mcp', 'Saved')
					context.flash(status, true)
				} catch (e) {
					box.checked = !value
					status.textContent = t('mcp', 'Not saved')
					context.flash(status, false)
					context.notifyError(t('mcp', 'Could not change the log privacy setting.'))
				} finally {
					box.disabled = false
				}
			})
		}

		Object.assign(context, { initLogsPrivacy })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
