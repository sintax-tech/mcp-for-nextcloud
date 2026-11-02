/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the level popover session unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.levelPopover = admin.modules.levelPopover || {}
	/** Expose the active popover state and attach matrix user and grant lookups.
	 * @param {object} context matrix and level catalogs, popover element and live active cell bindings
	 * @return {void}
	 */
	admin.modules.levelPopover.session = function (context) {
		/** Individual sources of the API; anything else is inherited. */
		const INDIVIDUAL = ['individual_allow', 'individual_deny', 'invalid']

		/** The open popover: {uid, module, trigger, inherited, loading, unavailable, saving}, or null. */
		let current = null

		/** Create a DOM element, apply its properties and append its child nodes or text.
		 * @param {string} tag HTML element tag name
		 * @param {object} [props] DOM properties assigned to the element
		 * @param {Array<Node|string>} [children] child nodes and text appended in order
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

		/** Find a user row in the matrix's currently loaded page.
		 * @return {object|undefined} the user of the table on screen
		 * @param {string} uid Nextcloud user ID
		 */
		function userOf(uid) {
			return context.matrix.data()?.users.find((user) => user.uid === uid)
		}

		/** Read the module operation catalog, using eligibility for the connection cell.
		 * @return {string[]} the operations of a module, or ["eligible"] for "Can connect" (module null)
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 */
		function operationsOf(module) {
			return module === null ? ['eligible'] : context.matrix.data().catalog[module]
		}

		/** Read a user's effective eligibility or operation value from the matrix row.
		 * @return {boolean|null} the effective value of one operation
		 * @param {object} user matrix user row with grants, origins and availability
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {string} operation catalog operation ID
		 */
		function valueOf(user, module, operation) {
			return module === null ? user.eligible : user.grants[module][operation]
		}

		/** Read the cell's grant origin, falling back to the default source.
		 * @return {{source: string, groupIds: string[]}} the origin of one operation
		 * @param {object} user matrix user row with grants, origins and availability
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {string} operation catalog operation ID
		 */
		function originOfCell(user, module, operation) {
			const origin = module === null ? user.origins?.eligible : user.origins?.grants?.[module]?.[operation]
			return origin || { source: 'default', groupIds: [] }
		}

		Object.defineProperties(context, {
			INDIVIDUAL: { configurable: true, get: () => INDIVIDUAL },
			current: { configurable: true, get: () => current, set: (value) => { current = value } },
		})

		Object.assign(context, { el, userOf, operationsOf, valueOf, originOfCell })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
