/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Generic searchable multi-select picker of the admin page, exposed as window.McpAdminPicker.
 *
 * One control: the selected options as removable chips on top, a search field that only filters the
 * existing options (typing never creates one) and a collapsed listbox that opens on focus. It
 * implements the WAI-ARIA combobox + multi-selectable listbox pattern (aria-expanded,
 * aria-activedescendant, arrows, Enter, Escape, Backspace on an empty field). Saving is the caller's
 * async function; while it runs the whole control is locked (aria-busy), and a failure rolls the
 * selection back to the previous state and reports through the caller's onError hook. Nothing here
 * knows about logs or tags, so the 1.1.0 group editor reuses it as-is. Plain browser JavaScript, no
 * build step; strings go through t('mcp', …) for translation.
 */
(function () {
	'use strict'

	let pickerCount = 0

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
	 */
	function create(spec) {
		const container = spec.container
		const uid = 'mcp-picker-' + (++pickerCount)
		const listId = uid + '-list'

		let options = spec.options
		let selected = new Set(spec.selectedIds.filter((id) => options.some((option) => option.id === id)))
		let busy = false
		let fallbackText = options.length === 0 ? spec.emptyOptionsText : null
		let activeIndex = -1

		const fallback = el('p', { className: 'mcp-picker-fallback mcp-empty', hidden: 'hidden' })
		const chips = el('div', { className: 'mcp-picker-chips' })
		const input = el('input', {
			type: 'text',
			className: 'mcp-picker-search',
			role: 'combobox',
			'aria-expanded': 'false',
			'aria-controls': listId,
			'aria-label': spec.searchLabel,
			placeholder: spec.searchLabel,
			autocomplete: 'off',
			spellcheck: 'false',
		})
		const list = el('ul', {
			id: listId,
			className: 'mcp-picker-list',
			role: 'listbox',
			'aria-multiselectable': 'true',
			'aria-label': spec.listLabel,
			hidden: 'hidden',
		})
		const noMatch = el('li', { className: 'mcp-picker-no-match', textContent: spec.noMatchText, hidden: 'hidden' })
		const box = el('div', { className: 'mcp-picker-box' }, [chips, input])
		container.classList.add('mcp-picker')
		container.setAttribute('aria-busy', 'false')
		container.replaceChildren(fallback, box, list)

		/** @param {string} text status text for the save feedback element */
		function setStatus(text) {
			if (spec.status) {
				spec.status.textContent = text
			}
		}

		/** @return {HTMLElement[]} the list items the filter currently shows */
		function visibleItems() {
			return Array.from(list.querySelectorAll('[role="option"]')).filter((item) => !item.hidden)
		}

		function openList() {
			if (options.length === 0) {
				return
			}
			list.hidden = false
			input.setAttribute('aria-expanded', 'true')
		}

		function closeList() {
			list.hidden = true
			input.setAttribute('aria-expanded', 'false')
			input.removeAttribute('aria-activedescendant')
			activeIndex = -1
			for (const item of list.querySelectorAll('.mcp-picker-active')) {
				item.classList.remove('mcp-picker-active')
			}
		}

		/** @param {number} delta keyboard steps within the visible options, wrapping around */
		function moveActive(delta) {
			const items = visibleItems()
			if (items.length === 0) {
				input.removeAttribute('aria-activedescendant')
				activeIndex = -1
				return
			}
			activeIndex = (activeIndex + delta + items.length) % items.length
			items.forEach((item, index) => item.classList.toggle('mcp-picker-active', index === activeIndex))
			input.setAttribute('aria-activedescendant', items[activeIndex].id)
			items[activeIndex].scrollIntoView({ block: 'nearest' })
		}

		/** Rebuilds the option items and keeps the current filter applied. */
		function renderList() {
			const activeId = visibleItems()[activeIndex]?.dataset.optionId
			list.replaceChildren()
			options.forEach((option, index) => {
				const item = el('li', {
					id: listId + '-opt-' + index,
					className: 'mcp-picker-option',
					role: 'option',
					'aria-selected': selected.has(option.id) ? 'true' : 'false',
				}, [option.label])
				if (option.badge) {
					item.append(el('span', {
						className: 'mcp-tag-type ' + (option.badge.className || ''),
						title: option.badge.title || '',
						textContent: option.badge.text,
					}))
				}
				item.dataset.optionId = option.id
				list.append(item)
			})
			list.append(noMatch)
			applyFilter()
			const items = visibleItems()
			const restoredIndex = items.findIndex((item) => item.dataset.optionId === activeId)
			if (restoredIndex >= 0 && !list.hidden) {
				activeIndex = restoredIndex
				items[activeIndex].classList.add('mcp-picker-active')
				input.setAttribute('aria-activedescendant', items[activeIndex].id)
			}
		}

		/** Filters with a plain substring on the labels; typing never creates an option. */
		function applyFilter() {
			const query = input.value.trim().toLowerCase()
			let matches = 0
			for (const item of list.querySelectorAll('[role="option"]')) {
				const option = options[Number(item.id.slice(listId.length + 5))]
				const show = query === '' || option.label.toLowerCase().includes(query)
				item.hidden = !show
				if (show) {
					matches++
				}
			}
			noMatch.hidden = matches !== 0
			activeIndex = -1
			input.removeAttribute('aria-activedescendant')
		}

		/** Rebuilds the chips of the selected options. */
		function renderChips() {
			chips.replaceChildren()
			for (const option of options) {
				if (!selected.has(option.id)) {
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
				remove.addEventListener('click', () => toggle(option.id))
				chip.append(remove)
				chips.append(chip)
			}
		}

		/** Shows every control part, the empty text or the fallback text as the state asks. */
		function paint() {
			if (fallbackText !== null) {
				fallback.textContent = fallbackText
				fallback.hidden = false
				box.hidden = true
				closeList()
				list.hidden = true
				return
			}
			fallback.hidden = true
			box.hidden = false
			renderChips()
			renderList()
		}

		/** @param {boolean} locked locks every control while a save runs */
		function setBusy(locked) {
			busy = locked
			container.setAttribute('aria-busy', locked ? 'true' : 'false')
			input.disabled = locked
			box.classList.toggle('mcp-picker-busy', locked)
			for (const button of chips.querySelectorAll('button')) {
				button.disabled = locked
			}
		}

		/**
		 * Persists a new selection: confirms first when the caller asks, locks the control, keeps the
		 * previous state on failure and reports through the status element and the onError hook.
		 *
		 * @param {Set<string>} next the intended selection
		 * @param {boolean} force permits an explicit save while the options show a fallback
		 */
		async function commit(next, force = false) {
			if (busy || (fallbackText !== null && !force)) {
				return
			}
			if (spec.confirmSave && !(await spec.confirmSave(Array.from(next)))) {
				return
			}
			// Rebuilding chips and disabling the input can send keyboard focus to the body.
			const restoreFocus = document.activeElement === input || chips.contains(document.activeElement)
			const before = selected
			selected = new Set(next)
			paint()
			spec.onChange?.(Array.from(selected))
			setBusy(true)
			setStatus(t('mcp', 'Saving…'))
			try {
				const result = await spec.save(Array.from(selected), Array.from(before))
				setStatus(t('mcp', 'Saved'))
				if (spec.clearSavedAfterMs) {
					const savedText = t('mcp', 'Saved')
					setTimeout(() => {
						if (spec.status && spec.status.textContent === savedText) {
							spec.status.textContent = ''
						}
					}, spec.clearSavedAfterMs)
				}
				await spec.onSaved?.(result)
			} catch (error) {
				// Rollback first, so an onError hook that resynchronizes wins over the old state.
				selected = before
				paint()
				spec.onChange?.(Array.from(selected))
				setStatus(spec.errorStatus ?? t('mcp', 'Not saved'))
				await spec.onError?.(error)
			} finally {
				setBusy(false)
				if (restoreFocus && !box.hidden
					&& (document.activeElement === document.body || document.activeElement === input)) {
					input.focus()
				}
			}
		}

		/** @param {string} id option to add to or drop from the selection */
		function toggle(id) {
			const next = new Set(selected)
			if (next.has(id)) {
				next.delete(id)
			} else {
				next.add(id)
			}
			commit(next)
		}

		input.addEventListener('focus', openList)
		input.addEventListener('input', () => {
			applyFilter()
			openList()
		})
		input.addEventListener('keydown', (event) => {
			switch (event.key) {
			case 'ArrowDown':
				event.preventDefault()
				openList()
				moveActive(1)
				break
			case 'ArrowUp':
				event.preventDefault()
				openList()
				moveActive(-1)
				break
			case 'Enter':
				event.preventDefault()
				if (list.hidden) {
					openList()
					moveActive(1)
				} else if (activeIndex >= 0) {
					const item = visibleItems()[activeIndex]
					if (item) {
						toggle(item.dataset.optionId)
					}
				}
				break
			case 'Escape':
				if (input.value !== '') {
					input.value = ''
					applyFilter()
				} else {
					closeList()
				}
				break
			case 'Backspace':
				if (input.value === '' && selected.size > 0) {
					const last = options.filter((option) => selected.has(option.id)).pop()
					if (last) {
						toggle(last.id)
					}
				}
				break
			}
		})
		list.addEventListener('mousedown', (event) => {
			// mousedown, not click: the focus stays in the search field.
			const item = event.target.closest('[role="option"]')
			if (!item || busy) {
				return
			}
			event.preventDefault()
			toggle(item.dataset.optionId)
		})
		document.addEventListener('click', (event) => {
			if (!container.contains(event.target)) {
				closeList()
			}
		})

		paint()

		return {
			/**
			 * Replaces the options and the selection, for example after a reload or a server answer.
			 *
			 * @param {Array} nextOptions same shape as spec.options
			 * @param {string[]} nextSelectedIds
			 */
			setOptions(nextOptions, nextSelectedIds) {
				options = nextOptions
				selected = new Set(nextSelectedIds.filter((id) => options.some((option) => option.id === id)))
				fallbackText = options.length === 0 ? spec.emptyOptionsText : null
				if (busy) {
					setBusy(false)
				}
				paint()
			},
			/** Saves again; force bypasses the fallback message, keeping confirmation and locking. */
			commit({ force = false } = {}) {
				return commit(new Set(selected), force)
			},
			/** Shows a plain message instead of the control, for a load failure or an empty option list. */
			showFallback(text) {
				fallbackText = text
				paint()
			},
			/** @return {string[]} the currently selected option ids */
			getSelected() {
				return Array.from(selected)
			},
		}
	}

	window.McpAdminPicker = { create }
})()
