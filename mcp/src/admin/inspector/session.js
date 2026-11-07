/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the inspector session unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/** Expose inspector controls, live request sequences and access result state.
	 * @param {object} context inspector controls, live request sequences and attached presentation operations
	 * @return {void}
	 */
	admin.modules.inspector.session = function (context) {
		const form = document.getElementById('mcp-inspector-form')
		const user = document.getElementById('mcp-inspector-user')
		const status = document.getElementById('mcp-inspector-status')
		const alert = document.getElementById('mcp-inspector-alert')
		const access = document.getElementById('mcp-inspector-access')
		const resources = document.getElementById('mcp-inspector-resources')
		const pathForm = document.getElementById('mcp-inspector-path-form')
		const pathInput = document.getElementById('mcp-inspector-path')
		const pathResult = document.getElementById('mcp-inspector-path-result')
		const calendarForm = document.getElementById('mcp-inspector-calendar-form')
		const calendarModule = document.getElementById('mcp-inspector-calendar-module')
		const calendarSelect = document.getElementById('mcp-inspector-calendar')
		const calendarOperation = document.getElementById('mcp-inspector-calendar-operation')
		const calendarResult = document.getElementById('mcp-inspector-calendar-result')
		let inspected = null
		let catalog = {}
		let seq = 0
		/**
		 * What each individual value of the person on screen is (src/admin/admin-exceptions.js, the same preview and rule as the
		 * matrix): per "module/operation", {kind: exception|redundant, after}. Null until known, and when it cannot be read:
		 * every individual value is then said as set for this person, never as repeating what they inherit.
		 */
		let kinds = null
		/** The answer on screen, drawn again once the kinds are known. */
		let shown = null

		const asked = { calendars: 0, path: 0, calendar: 0 }

		/** Create a DOM element, apply its properties and append its child nodes or text.
		 * @param {string} tag HTML element tag name
		 * @param {object} [props] DOM properties assigned to the element
		 * @param {Array<Node|string>} [children] child nodes and text appended in order
		 * @return {HTMLElement}
		 */
		function el(tag, props = {}, children = []) {
			const node = document.createElement(tag)
			Object.assign(node, props)
			node.append(...children)
			return node
		}

		/** Write inspection feedback into the existing status or alert live region.
		 * @param {string} message feedback text; an empty string clears the message
		 * @param {boolean} [error=false] announce through the alert region instead of the status region
		 * @return {void}
		 */
		function say(message, error = false) {
			status.textContent = ''
			alert.textContent = ''
			;(error ? alert : status).textContent = message
		}

		/** Fetch a JSON API response and reject unsuccessful requests with their response details.
		 * @param {string} path API path below /apps/mcp
		 * @param {Object<string, string>|null|undefined} query URL query parameters when present
		 * @return {Promise<object>}
		 */
		async function get(path, query) {
			const response = await fetch(OC.generateUrl('/apps/mcp' + path) + '?' + new URLSearchParams(query), {
				headers: { Accept: 'application/json', requesttoken: OC.requestToken },
			})
			const data = await response.json().catch(() => ({}))
			if (!response.ok) throw Object.assign(new Error(String(response.status)), { status: response.status, data })
			return data
		}

		/** Translate a failed resource request into an administrator-facing explanation.
		 * @param {Error & {status?: number, data?: object}} error request failure with optional HTTP status and response details
		 * @return {string}
		 */
		function failure(error) {
			if (error.status === 403) return t('mcp', 'Only administrators can inspect access.')
			if (error.status === 400) return t('mcp', 'Check the user ID and the value entered, then try again.')
			if (error.status === 503) return t('mcp', 'The MCP policy cannot be read right now, so nothing can be confirmed. Try again shortly.')
			return t('mcp', 'The inspection failed. Try again.')
		}

		/** Check whether a resource response belongs to an older request or a different inspected user.
		 * @param {string} kind resource request channel whose sequence is checked
		 * @param {number} mine sequence captured when this request began
		 * @param {string} user user ID captured when the resource request began
		 * @return {boolean}
		 */
		function stale(kind, mine, user) {
			return mine !== asked[kind] || user !== inspected
		}

		Object.defineProperties(context, {
			form: { configurable: true, get: () => form },
			user: { configurable: true, get: () => user },
			status: { configurable: true, get: () => status },
			alert: { configurable: true, get: () => alert },
			access: { configurable: true, get: () => access },
			resources: { configurable: true, get: () => resources },
			pathForm: { configurable: true, get: () => pathForm },
			pathInput: { configurable: true, get: () => pathInput },
			pathResult: { configurable: true, get: () => pathResult },
			calendarForm: { configurable: true, get: () => calendarForm },
			calendarModule: { configurable: true, get: () => calendarModule },
			calendarSelect: { configurable: true, get: () => calendarSelect },
			calendarOperation: { configurable: true, get: () => calendarOperation },
			calendarResult: { configurable: true, get: () => calendarResult },
			inspected: { configurable: true, get: () => inspected, set: (value) => { inspected = value } },
			catalog: { configurable: true, get: () => catalog, set: (value) => { catalog = value } },
			seq: { configurable: true, get: () => seq, set: (value) => { seq = value } },
			kinds: { configurable: true, get: () => kinds, set: (value) => { kinds = value } },
			shown: { configurable: true, get: () => shown, set: (value) => { shown = value } },
			asked: { configurable: true, get: () => asked },
		})

		Object.assign(context, { el, say, get, failure, stale })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
