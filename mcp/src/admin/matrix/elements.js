/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Matrix elements operations.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.matrix.elements = function (context) {
		/**
		 * @param {HTMLElement} cell cell that shows the save result
		 * @param {boolean} ok whether the save worked
		 * @return {*} operation result
		 */
		function flash(cell, ok) {
			cell.classList.remove('mcp-ok', 'mcp-error')
			void cell.offsetWidth
			cell.classList.add(ok ? 'mcp-ok' : 'mcp-error')
		}


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

		Object.assign(context, { el, flash })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
