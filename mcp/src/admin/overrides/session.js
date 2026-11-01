/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Individual override session operations.
 * @param {object} context live bindings of this editor
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.overrides = admin.modules.overrides || {}
	admin.modules.overrides.session = function (context) {
		/** Sources of a cell's origin that are an individual override, by kind. Everything else is inherited. */
		const OVERRIDES = { individual_allow: 'allow', individual_deny: 'deny', invalid: 'invalid' }
		/** Most cells and users one command accepts (PolicyWriter::MAX_CELLS and MAX_UIDS). */
		const MAX_CELLS = 2500
		const MAX_USERS = 50
		/** Milliseconds between clearing a live region and writing into it, so an identical message is announced again. */
		const SAY_DELAY = 60

		/** Users selected on the page, by uid. Only the users on screen count: see pageUsers(). */
		const selected = new Set()
		/** The open preview: {plan, trigger, retry} or null. */
		let review = null
		let saving = false
		let sayTimer = null


		/**
		 * @param {string} tag element name
		 * @param {object} [props] properties to assign
		 * @param {Array<Node|string>} [children] child nodes or text
		 * @return {HTMLElement}
		 */
		function el(tag, props, children) {
			const node = document.createElement(tag)
			Object.assign(node, props || {})
			for (const child of children || []) {
				node.append(child)
			}
			return node
		}


		/** @return {object[]} the users of the table on screen */
		function pageUsers() {
			return context.matrix.data()?.users || []
		}


		/**
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {string} operation operation id, "eligible" for "Can connect"
		 * @return {string} the permission as the table names it
		 */
		function permissionName(module, operation) {
			if (module === null) {
				return t('mcp', 'Can connect')
			}
			const labels = context.matrix.labels()
			return (labels.modules[module] || module) + ': ' + (labels.operations[operation] || operation)
		}


		/**
		 * Writes into the live regions. A region is cleared at once and written shortly after, so the same text twice in a
		 * row (a second identical error) is announced again, and a message that was replaced in the meantime never appears.
		 * The regions are fixed: a status one (polite) and an alert one (assertive), whose role never changes.
		 *
		 * @param {string} message text, '' to clear both
		 * @param {boolean} [error] an error goes to the alert region
		 */
		function say(message, error = false) {
			clearTimeout(sayTimer)
			context.statusLine.textContent = ''
			context.alertLine.textContent = ''
			if (message === '') {
				return
			}
			const region = error ? context.alertLine : context.statusLine
			sayTimer = setTimeout(() => {
				region.textContent = message
			}, SAY_DELAY)
		}

		Object.defineProperties(context, {
			OVERRIDES: { configurable: true, get: () => OVERRIDES },
			MAX_CELLS: { configurable: true, get: () => MAX_CELLS },
			MAX_USERS: { configurable: true, get: () => MAX_USERS },
			SAY_DELAY: { configurable: true, get: () => SAY_DELAY },
			selected: { configurable: true, get: () => selected },
			review: { configurable: true, get: () => review, set: (value) => { review = value } },
			saving: { configurable: true, get: () => saving, set: (value) => { saving = value } },
			sayTimer: { configurable: true, get: () => sayTimer, set: (value) => { sayTimer = value } },
		})

		Object.assign(context, { el, pageUsers, permissionName, say })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
