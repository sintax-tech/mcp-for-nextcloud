/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** picker list operations.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.picker = admin.modules.picker || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.picker.list = function (context) {
		/** @return {HTMLElement[]} the list items the filter currently shows */
		function visibleItems() {
			return Array.from(context.list.querySelectorAll('[role="option"]')).filter((item) => !item.hidden)
		}

		/**
		 * @return {*} operation result
		 */
		function openList() {
			if (context.options.length === 0) {
				return
			}
			context.list.hidden = false
			context.input.setAttribute('aria-expanded', 'true')
		}

		/**
		 * @return {*} operation result
		 */
		function closeList() {
			context.list.hidden = true
			context.input.setAttribute('aria-expanded', 'false')
			context.input.removeAttribute('aria-activedescendant')
			context.activeIndex = -1
			for (const item of context.list.querySelectorAll('.mcp-picker-active')) {
				item.classList.remove('mcp-picker-active')
			}
		}

		/** @param {number} delta keyboard steps within the visible options, wrapping around
		 * @return {*} operation result
		 */
		function moveActive(delta) {
			const items = context.visibleItems()
			if (items.length === 0) {
				context.input.removeAttribute('aria-activedescendant')
				context.activeIndex = -1
				return
			}
			context.activeIndex = (context.activeIndex + delta + items.length) % items.length
			items.forEach((item, index) => item.classList.toggle('mcp-picker-active', index === context.activeIndex))
			context.input.setAttribute('aria-activedescendant', items[context.activeIndex].id)
			items[context.activeIndex].scrollIntoView({ block: 'nearest' })
		}

		/** Rebuilds the option items and keeps the current filter applied.
		 * @return {*} operation result
		 */
		function renderList() {
			const activeId = context.visibleItems()[context.activeIndex]?.dataset.optionId
			context.list.replaceChildren()
			context.options.forEach((option, index) => {
				const item = el('li', {
					id: context.listId + '-opt-' + index,
					className: 'mcp-picker-option',
					role: 'option',
					'aria-selected': context.selected.has(option.id) ? 'true' : 'false',
				}, [option.label])
				if (option.badge) {
					item.append(el('span', {
						className: 'mcp-tag-type ' + (option.badge.className || ''),
						title: option.badge.title || '',
						textContent: option.badge.text,
					}))
				}
				item.dataset.optionId = option.id
				context.list.append(item)
			})
			context.list.append(context.noMatch)
			context.applyFilter()
			const items = context.visibleItems()
			const restoredIndex = items.findIndex((item) => item.dataset.optionId === activeId)
			if (restoredIndex >= 0 && !context.list.hidden) {
				context.activeIndex = restoredIndex
				items[context.activeIndex].classList.add('mcp-picker-active')
				context.input.setAttribute('aria-activedescendant', items[context.activeIndex].id)
			}
		}

		/** Filters with a plain substring on the labels; typing never creates an option.
		 * @return {*} operation result
		 */
		function applyFilter() {
			const query = context.input.value.trim().toLowerCase()
			let matches = 0
			for (const item of context.list.querySelectorAll('[role="option"]')) {
				const option = context.options[Number(item.id.slice(context.listId.length + 5))]
				const show = query === '' || option.label.toLowerCase().includes(query)
				item.hidden = !show
				if (show) {
					matches++
				}
			}
			context.noMatch.hidden = matches !== 0
			context.activeIndex = -1
			context.input.removeAttribute('aria-activedescendant')
		}

		Object.assign(context, { visibleItems, openList, closeList, moveActive, renderList, applyFilter })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
