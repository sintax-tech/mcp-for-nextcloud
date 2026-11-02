/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** picker rendering operations.
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
	admin.modules.picker.rendering = function (context) {
		/** Rebuilds the chips of the selected options.
		 * @return {*} operation result
		 */
		function renderChips() {
			context.chips.replaceChildren()
			for (const option of context.options) {
				if (!context.selected.has(option.id)) {
					continue
				}
				const chip = el('span', { className: 'mcp-picker-chip' }, [option.label])
				if (option.badge) {
					chip.append(el('span', {
						className: 'mcp-tag-type ' + (option.badge.className || ''),
						title: option.badge.title || '',
						textContent: option.badge.text,
					}))
				}
				const remove = el('button', {
					type: 'button',
					className: 'mcp-picker-remove',
					'aria-label': t('mcp', 'Remove {name}', { name: option.label }, undefined, { escape: false }),
				}, ['×'])
				remove.addEventListener('click', () => context.toggle(option.id))
				chip.append(remove)
				context.chips.append(chip)
			}
		}

		/** Shows every control part, the empty text or the fallback text as the state asks.
		 * @return {*} operation result
		 */
		function paint() {
			if (context.fallbackText !== null) {
				context.fallback.textContent = context.fallbackText
				context.fallback.hidden = false
				context.box.hidden = true
				context.closeList()
				context.list.hidden = true
				return
			}
			context.fallback.hidden = true
			context.box.hidden = false
			context.renderChips()
			context.renderList()
		}

		/** @param {boolean} locked locks every control while a save runs
		 * @return {*} operation result
		 */
		function setBusy(locked) {
			context.busy = locked
			context.container.setAttribute('aria-busy', locked ? 'true' : 'false')
			context.input.disabled = locked
			context.box.classList.toggle('mcp-picker-busy', locked)
			for (const button of context.chips.querySelectorAll('button')) {
				button.disabled = locked
			}
		}

		Object.assign(context, { renderChips, paint, setBusy })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
