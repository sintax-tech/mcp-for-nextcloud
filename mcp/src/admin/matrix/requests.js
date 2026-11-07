/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix requests unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach JSON requests, policy error recovery and the live matrix write lock.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.requests = function (context) {
		let matrixLocked = false

		/** Fetch a JSON API response and reject unsuccessful requests with their response details.
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
				const error = new Error(String(response.status))
				error.data = await response.json().catch(() => null)
				throw error
			}
			return response.json().catch((cause) => {
				// A proxy/session may return 2xx HTML after a write committed. Reuse the matrix's uncertain-save recovery.
				const matrixWrite = (method === 'PUT' && (path.startsWith('/api/grants/') || path === '/api/service'))
					|| (method === 'POST' && (path === '/api/grants/commands' || path === '/api/grants/undo'))
				if (matrixWrite) throw Object.assign(new Error('503'), { data: { uncertain: true }, cause })
				// Reads (including command previews) and other settings keep their ordinary load/request error path.
				throw cause
			})
		}

		/** Reloads the matrix with every matrix write blocked until the new page is on screen.
		 * @return {Promise<void>}
		 */
		async function reloadLocked() {
			matrixLocked = true
			try {
				await context.load()
			} finally {
				matrixLocked = false
			}
		}

		/**
		 * Tells the admin what a failed save means when the policy moved or the storage failed, and reloads
		 * the matrix in those cases so nothing is written twice.
		 * @param {Error} e error thrown by api()
		 * @return {Promise<boolean>} whether the error was handled here (the matrix is then reloaded)
		 */
		async function policyError(e) {
			if (e.message === '409' || e.message === '428') {
				context.notifyError(t('mcp', 'The permissions were changed in another window or by another administrator. The table was reloaded with the saved values; nothing was overwritten.'))
			} else if (e.message === '503' && e.data && e.data.uncertain) {
				context.notifyError(t('mcp', 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.'))
			} else {
				return false
			}
			await context.reloadLocked()
			return true
		}

		/** Show an error through the available Nextcloud toast or the browser alert fallback.
		 * @param {string} message error shown to the admin
		 * @return {void}
		 */
		function notifyError(message) {
			// The old notification global is gone since Nextcloud 34 and OCP.Toast is deprecated, so without it the browser's own dialog
			// still shows the message instead of losing it.
			if (window.OCP && OCP.Toast) {
				OCP.Toast.error(message)
			} else {
				window.alert(message)
			}
		}

		Object.defineProperties(context, {
			matrixLocked: { configurable: true, get: () => matrixLocked, set: (value) => { matrixLocked = value } },
		})

		Object.assign(context, { api, notifyError, policyError, reloadLocked })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
