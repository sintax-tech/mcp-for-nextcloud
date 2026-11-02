/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** level_popover session operations.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.levelPopover = admin.modules.levelPopover || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.levelPopover.session = function (context) {
		/** Individual sources of the API; anything else is inherited. */
		const INDIVIDUAL = ['individual_allow', 'individual_deny', 'invalid']

		/** The open popover: {uid, module, trigger, inherited, loading, unavailable, saving}, or null. */
		let current = null

		/**
		 * @param {*} tag operation input
		 * @param {*} props operation input
		 * @param {*} children operation input
		 * @return {*} operation result
		 */
		function el(tag, props, children) {
			const node = document.createElement(tag)
			Object.assign(node, props || {})
			for (const child of children || []) {
				node.append(child)
			}
			return node
		}

		/** @return {object|undefined} the user of the table on screen
		 * @param {*} uid operation input
		 */
		function userOf(uid) {
			return context.matrix.data()?.users.find((user) => user.uid === uid)
		}

		/** @return {string[]} the operations of a module, or ["eligible"] for "Can connect" (module null)
		 * @param {*} module operation input
		 */
		function operationsOf(module) {
			return module === null ? ['eligible'] : context.matrix.data().catalog[module]
		}

		/** @return {boolean|null} the effective value of one operation
		 * @param {*} user operation input
		 * @param {*} module operation input
		 * @param {*} operation operation input
		 */
		function valueOf(user, module, operation) {
			return module === null ? user.eligible : user.grants[module][operation]
		}

		/** @return {{source: string, groupIds: string[]}} the origin of one operation
		 * @param {*} user operation input
		 * @param {*} module operation input
		 * @param {*} operation operation input
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
