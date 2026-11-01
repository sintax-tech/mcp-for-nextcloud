/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Picker composition and public creation entry.
 * @param {object} admin app registry
 * @return {void} publishes the picker
 */
(function (admin) {
	'use strict'

	/**
	 * @param {object} spec
	 * @param {HTMLElement} spec.container mount point; its children are replaced by the control
	 * @param {Array<{id: string, label: string, badge?: {text: string, title?: string, className?: string}}>} spec.options
	 *        every option; a selected id without an option here is dropped
	 * @param {string[]} spec.selectedIds currently selected option ids
	 * @param {string} spec.searchLabel accessible label and placeholder of the search field
	 * @param {string} spec.listLabel accessible label of the listbox
	 * @param {string} spec.emptyOptionsText shown when there are no options at all
	 * @param {string} spec.noMatchText shown when the filter matches no option
	 * @param {(nextIds: string[], previousIds: string[]) => Promise<*>} spec.save persists the selection
	 *        and throws on failure; any value it resolves with reaches onSaved
	 * @param {(nextIds: string[]) => boolean|Promise<boolean>} [spec.confirmSave] a false answer cancels
	 *        the change without an error (for example the empty-selection confirmation)
	 * @param {(result: *) => void|Promise<void>} [spec.onSaved] runs after a successful save
	 * @param {(error: *) => void|Promise<void>} [spec.onError] runs after a failed save; it may call
	 *        setOptions to resynchronize the control (for example after a conflict)
	 * @param {(selectedIds: string[]) => void} [spec.onChange] runs whenever the shown selection changes
	 * @param {HTMLElement} [spec.status] element that receives the saving/saved/error feedback
	 * @param {number} [spec.clearSavedAfterMs] clears the "Saved" status after this many ms
	 * @param {string} [spec.errorStatus] status text after a failed save (default: the "Not saved" text)
	 * @return {*} operation result
	 */
	function create(spec) {
		const context = { spec }
		admin.modules.picker.session(context)
		admin.modules.picker.list(context)
		admin.modules.picker.rendering(context)
		admin.modules.picker.saving(context)
		context.input.addEventListener('focus', context.openList)
		context.input.addEventListener('input', () => {
			context.applyFilter()
			context.openList()
		})
		context.input.addEventListener('keydown', (event) => {
			switch (event.key) {
			case 'ArrowDown':
				event.preventDefault()
				context.openList()
				context.moveActive(1)
				break
			case 'ArrowUp':
				event.preventDefault()
				context.openList()
				context.moveActive(-1)
				break
			case 'Enter':
				event.preventDefault()
				if (context.list.hidden) {
					context.openList()
					context.moveActive(1)
				} else if (context.activeIndex >= 0) {
					const item = context.visibleItems()[context.activeIndex]
					if (item) {
						context.toggle(item.dataset.optionId)
					}
				}
				break
			case 'Escape':
				if (context.input.value !== '') {
					context.input.value = ''
					context.applyFilter()
				} else {
					context.closeList()
				}
				break
			case 'Backspace':
				if (context.input.value === '' && context.selected.size > 0) {
					const last = context.options.filter((option) => context.selected.has(option.id)).pop()
					if (last) {
						context.toggle(last.id)
					}
				}
				break
			}
		})
		context.list.addEventListener('mousedown', (event) => {
			// mousedown, not click: the focus stays in the search field.
			const item = event.target.closest('[role="option"]')
			if (!item || context.busy) {
				return
			}
			event.preventDefault()
			context.toggle(item.dataset.optionId)
		})
		document.addEventListener('click', (event) => {
			if (!context.container.contains(event.target)) {
				context.closeList()
			}
		})

		context.paint()

		return {
			/**
			 * Replaces the options and the selection, for example after a reload or a server answer.
			 *
			 * @param {Array} nextOptions same shape as spec.options
			 * @param {string[]} nextSelectedIds
			 */
			setOptions(nextOptions, nextSelectedIds) {
				context.options = nextOptions
				context.selected = new Set(nextSelectedIds.filter((id) => context.options.some((option) => option.id === id)))
				context.fallbackText = context.options.length === 0 ? context.spec.emptyOptionsText : null
				if (context.busy) {
					context.setBusy(false)
				}
				context.paint()
			},
			/** Saves again; force bypasses the fallback message, keeping confirmation and locking. */
			commit({ force = false } = {}) {
				return context.commit(new Set(context.selected), force)
			},
			/** Shows a plain message instead of the control, for a load failure or an empty option list. */
			showFallback(text) {
				context.fallbackText = text
				context.paint()
			},
			/** @return {string[]} the currently selected option ids */
			getSelected() {
				return Array.from(context.selected)
			},
		}
	}

	admin.picker = { create }
})((globalThis.window || globalThis).OCA.Mcp.Admin)
