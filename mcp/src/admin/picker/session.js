/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** picker session operations.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	let pickerCount = 0

	admin.modules.picker = admin.modules.picker || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.picker.session = function (context) {
		/**
		 * @param {string} tag tag name
		 * @param {object} attrs attributes; textContent sets text, and any aria-* name becomes an attribute
		 * @param {Array<Node|string>} children
		 * @return {HTMLElement}
		 */
		function el(tag, attrs, children) {
			const node = document.createElement(tag)
			for (const [name, value] of Object.entries(attrs)) {
				if (name === 'textContent') {
					node.textContent = value
				} else if (name === 'className') {
					node.className = value
				} else {
					node.setAttribute(name, value)
				}
			}
			for (const child of children || []) {
				node.append(child)
			}
			return node
		}

		const container = context.spec.container
		const uid = 'mcp-picker-' + (++pickerCount)
		const listId = uid + '-list'

		let options = context.spec.options
		let selected = new Set(context.spec.selectedIds.filter((id) => options.some((option) => option.id === id)))
		let busy = false
		let fallbackText = options.length === 0 ? context.spec.emptyOptionsText : null
		let activeIndex = -1

		const fallback = el('p', { className: 'mcp-picker-fallback mcp-empty', hidden: 'hidden' })
		const chips = el('div', { className: 'mcp-picker-chips' })
		const input = el('input', {
			type: 'text',
			className: 'mcp-picker-search',
			role: 'combobox',
			'aria-expanded': 'false',
			'aria-controls': listId,
			'aria-label': context.spec.searchLabel,
			placeholder: context.spec.searchLabel,
			autocomplete: 'off',
			spellcheck: 'false',
		})
		const list = el('ul', {
			id: listId,
			className: 'mcp-picker-list',
			role: 'listbox',
			'aria-multiselectable': 'true',
			'aria-label': context.spec.listLabel,
			hidden: 'hidden',
		})
		const noMatch = el('li', { className: 'mcp-picker-no-match', textContent: context.spec.noMatchText, hidden: 'hidden' })
		const box = el('div', { className: 'mcp-picker-box' }, [chips, input])
		container.classList.add('mcp-picker')
		container.setAttribute('aria-busy', 'false')
		container.replaceChildren(fallback, box, list)


		/** @param {string} text status text for the save feedback element
		 * @return {*} operation result
		 */
		function setStatus(text) {
			if (context.spec.status) {
				context.spec.status.textContent = text
			}
		}

		Object.defineProperties(context, {
			container: { configurable: true, get: () => container },
			uid: { configurable: true, get: () => uid },
			listId: { configurable: true, get: () => listId },
			options: { configurable: true, get: () => options, set: (value) => { options = value } },
			selected: { configurable: true, get: () => selected, set: (value) => { selected = value } },
			busy: { configurable: true, get: () => busy, set: (value) => { busy = value } },
			fallbackText: { configurable: true, get: () => fallbackText, set: (value) => { fallbackText = value } },
			activeIndex: { configurable: true, get: () => activeIndex, set: (value) => { activeIndex = value } },
			fallback: { configurable: true, get: () => fallback },
			chips: { configurable: true, get: () => chips },
			input: { configurable: true, get: () => input },
			list: { configurable: true, get: () => list },
			noMatch: { configurable: true, get: () => noMatch },
			box: { configurable: true, get: () => box },
		})

		Object.assign(context, { el, setStatus })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
