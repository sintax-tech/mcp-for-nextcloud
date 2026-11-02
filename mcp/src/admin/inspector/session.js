/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** inspector session operations.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
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

		/**
		 * @param {*} tag operation input
		 * @param {*} props operation input
		 * @param {*} children operation input
		 * @return {*} operation result
		 */
		function el(tag, props = {}, children = []) {
			const node = document.createElement(tag)
			Object.assign(node, props)
			node.append(...children)
			return node
		}

		/**
		 * @param {*} message operation input
		 * @param {boolean} error operation input
		 * @return {*} operation result
		 */
		function say(message, error = false) {
			status.textContent = ''
			alert.textContent = ''
			;(error ? alert : status).textContent = message
		}

		/**
		 * @param {*} path operation input
		 * @param {*} query operation input
		 * @return {Promise<*>} operation result
		 */
		async function get(path, query) {
			const response = await fetch(OC.generateUrl('/apps/mcp' + path) + '?' + new URLSearchParams(query), {
				headers: { Accept: 'application/json', requesttoken: OC.requestToken },
			})
			const data = await response.json().catch(() => ({}))
			if (!response.ok) throw Object.assign(new Error(String(response.status)), { status: response.status, data })
			return data
		}

		/**
		 * @param {*} error operation input
		 * @return {*} operation result
		 */
		function failure(error) {
			if (error.status === 403) return t('mcp', 'Only administrators can inspect access.')
			if (error.status === 400) return t('mcp', 'Check the user ID and the value entered, then try again.')
			if (error.status === 503) return t('mcp', 'The MCP policy cannot be read right now, so nothing can be confirmed. Try again shortly.')
			return t('mcp', 'The inspection failed. Try again.')
		}

		/**
		 * @param {*} kind operation input
		 * @param {*} mine operation input
		 * @param {*} user operation input
		 * @return {*} operation result
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
