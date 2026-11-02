/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix elements unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach DOM construction and save feedback animation to the matrix context.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.elements = function (context) {
		/** Restart the saved or failed animation on the supplied feedback element.
		 * @param {HTMLElement} cell cell that shows the save result
		 * @param {boolean} ok whether the save worked
		 * @return {void}
		 */
		function flash(cell, ok) {
			cell.classList.remove('mcp-ok', 'mcp-error')
			void cell.offsetWidth
			cell.classList.add(ok ? 'mcp-ok' : 'mcp-error')
		}

		/** Create a DOM element, apply its properties and append its child nodes or text.
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
