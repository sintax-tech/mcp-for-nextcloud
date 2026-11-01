/* admin/admin-picker.js */
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
;
/* admin/access/levels/polls.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Polls: four named, independent presets instead of cumulative tiers, so creating polls never rides on
 * a protected read. src/admin/admin-levels.js asks window.McpAdminPollsGrants for the Polls tiers and levels.
 */
/** Polls-specific protected-read names; rendering never writes or expands a stored policy. */
(function (root) {
	'use strict'

	/** @return {Object<string,string>} explicit cumulative names, never Edit or Full */
	function levelLabels() {
		return {
			polls_read: t('mcp', 'Read polls'),
			polls_read_create: t('mcp', 'Read polls and create private polls'),
			polls_read_results: t('mcp', 'Read polls, votes and results'),
			polls_read_results_links: t('mcp', 'Read polls, votes, results and existing links (credentials)'),
		}
	}

	/** @return {Object<string,string>} common results key matches Forms; links is distinct from Files.link */
	function operationLabels() {
		return { results: t('mcp', 'results'), links: t('mcp', 'Read existing links (credentials)') }
	}

	/**
	 * @param {string} module catalog module
	 * @param {string} operation exact grant
	 * @return {string|null} operation-specific warning, without changing Forms or Files hints
	 */
	function operationHint(module, operation) {
		if (module !== 'polls') return null
		if (operation === 'create') return t('mcp', 'Create private polls with initial options after a plan and confirmation; starts off; does not grant votes, results or credential links')
		if (operation === 'results') return t('mcp', 'Read votes and results allowed by native Polls permissions; starts off')
		if (operation === 'links') return t('mcp', 'Read existing participation links, including personal credentials, only with native edit permission; creates no links; starts off')
		return null
	}

	/**
	 * Independent named choices; creation never rides on a protected-read preset.
	 * @param {string[]} operations actual catalog
	 * @return {Array<{level:string, operations:string[]}>} exact preset sets
	 */
	function tiers(operations) {
		const rows = [
			{ level: 'polls_read', operations: ['read'] },
			{ level: 'polls_read_create', operations: ['read', 'create'] },
			{ level: 'polls_read_results', operations: ['read', 'results'] },
			{ level: 'polls_read_results_links', operations: ['read', 'results', 'links'] },
		]
		return rows.filter(row => row.operations.every(operation => operations.includes(operation)))
	}

	/**
	 * @param {string[]} operations catalog
	 * @param {string} level named independent choice
	 * @return {string[]} exact allowed set, in catalog order
	 */
	function operationsFor(operations, level) {
		if (level === 'none') return []
		const found = tiers(operations).find(row => row.level === level)
		if (!found) throw new Error('Unknown level ' + level + ' for polls')
		return operations.filter(operation => found.operations.includes(operation))
	}

	root.McpAdminPollsGrants = { levelLabels, operationLabels, operationHint, tiers, operationsFor }
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/files.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Files: Read, then Edit adds editing, creating, moving and restoring, then Full adds sharing, links and deleting.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.files = [['read'], ['edit', 'create', 'move', 'restore'], ['share', 'link', 'delete']]
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/notes.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Notes: Read, then Edit adds creating, editing and moving, then Full adds deleting.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.notes = [['read'], ['create', 'edit', 'move'], ['delete']]
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/deck.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Deck: Read, then Edit adds creating, editing and moving, then Full adds deleting.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.deck = [['read'], ['create', 'edit', 'move'], ['delete']]
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/calendar.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Calendar: Read, then Edit adds creating, editing and moving, then Full adds deleting and transferring.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.calendar = [['read'], ['create', 'edit', 'move'], ['delete', 'transfer']]
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/contacts.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Contacts: Read, then Edit adds creating and editing, then Full adds deleting.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.contacts = [['read'], ['create', 'edit'], ['delete']]
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/tasks.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Tasks: Read, then Edit adds creating and editing, then Full adds deleting.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.tasks = [['read'], ['create', 'edit'], ['delete']]
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/talk.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Talk: Read, then Edit adds replying, attaching and quoting, then Full adds create.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.talk = [['read'], ['reply', 'attach', 'quote'], ['create']]
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/people.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of People: one operation, so only Read.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.people = [['read']]
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/logs.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Logs: one operation, so only Read.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.logs = [['read']]
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/mail.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * What the Mail operations let the AI do, for the column tooltips of src/admin/admin-grants.js: `read` lists accounts and
 * folders, `messages` (off by default) lists, searches and reads the messages themselves, `attachments` (off by default)
 * reads the text of an attachment, and the Mail writes, off by default: `flags` marks a message read or unread and
 * flagged, `move` moves it to another folder of its account, `draft` saves a new draft, never sent, `send` sends a new message. Granting a Mail write, in the matrix, its popover, a bulk
 * change or a group policy, first shows the warning of {@see writeWarning}.
 */
(function (root) {
	'use strict'

	/** The Mail operations that change a mailbox. */
	const WRITE_OPERATIONS = ['flags', 'move', 'draft', 'send']

	/**
	 * The tooltip of a Mail operation.
	 *
	 * @param {string} module module id
	 * @param {string} operation operation id
	 * @return {string|null} the description, or null for any other module or operation
	 */
	function operationHint(module, operation) {
		if (module !== 'mail') return null
		if (operation === 'read') return t('mcp', 'List mail accounts and folders, never messages')
		if (operation === 'messages') return t('mcp', 'List, search and read messages, without marking them read; starts off')
		if (operation === 'attachments') return t('mcp', 'Read the text of attachments, never the files, without marking messages read; starts off')
		if (operation === 'flags') return t('mcp', 'Mark messages read or unread and flag them, on the mail server, after a plan the user confirms; starts off')
		if (operation === 'move') return t('mcp', 'Move messages to another folder of the same account, on the mail server, after a plan the user confirms; starts off')
		if (operation === 'draft') return t('mcp', 'Create new drafts in the Drafts folder, never sent, after a plan the user confirms; starts off')
		if (operation === 'send') return t('mcp', 'Send new messages through the mail account, which cannot be undone, after a plan the user confirms; starts off')
		return null
	}

	/**
	 * The warning to read before granting Mail writes, or null when the grants name none.
	 *
	 * @param {Array<{module: string, operation: string}>} granted operations about to be allowed
	 * @return {string|null} the warning
	 */
	function writeWarning(granted) {
		if (!granted.some((grant) => grant.module === 'mail' && WRITE_OPERATIONS.includes(grant.operation))) return null
		return t('mcp', 'This lets the AI change mailboxes on the mail server: mark messages read or unread, flag them, move them to other folders, save new drafts or send messages, which cannot be undone. A message can carry hidden instructions that ask the AI to do so; every change still shows a plan the user has to confirm. Grant it only to people who want the AI to act on their mail.')
	}

	/**
	 * Asks the administrator before granting Mail writes.
	 *
	 * @param {Array<{module: string, operation: string}>} granted operations about to be allowed
	 * @return {boolean} true when no Mail write is granted, or the administrator agreed
	 */
	function confirmWrites(granted) {
		const warning = writeWarning(granted)
		return warning === null || root.confirm(warning)
	}

	/*
	 * The Mail tiers for src/admin/admin-levels.js. Mail only reads at first: its first tier lists accounts and folders,
	 * its second opens the messages, its third adds the text of attachments, and the next ones add the Mail writes, so
	 * none of its levels is called Full. src/admin/admin-grants.js translates the names.
	 */
	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.mail = [['read'], ['messages'], ['attachments'], ['flags'], ['move'], ['draft'], ['send']]
	catalog.tierNames.mail = ['folders', 'messages', 'attachments', 'flags', 'move', 'draft', 'send']

	root.McpAdminMailGrants = { WRITE_OPERATIONS, operationHint, writeWarning, confirmWrites }
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/tables.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Tables: Read, then Full adds creating and editing together.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.tables = [['read'], ['create', 'edit']]
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/forms.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Forms: Read, then writing forms, then writing forms and reading responses; src/admin/admin-grants.js translates the names.
 * Registered in window.McpAdminLevelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.forms = [['read'], ['create', 'edit'], ['results']]
	catalog.tierNames.forms = ['read', 'forms_write', 'forms_write_results']
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/admin-levels.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of the admin page: one level per module (None, Read, Edit, Full) instead of a box per operation.
 *
 * A level is only a way to show and choose a set of operations; the server never sees it. Each module's operations are
 * split into cumulative tiers (Read, then Edit adds its tier, then Full adds the last one), so a level is the union of
 * the tiers up to it. A set of allowed operations that is no such union is "custom" and is shown and edited operation
 * by operation. The tiers follow GrantPolicy::CATALOG; an operation the tiers do not name (a newer catalog) falls into
 * the last tier, so it is never hidden and never granted by a lower level.
 *
 * The level functions are pure, with no DOM and no strings: src/admin/admin-grants.js draws the cells and names the levels.
 * The tiers of each module live in its own file, src/admin/access/levels/<app>.js, which registers them in
 * window.McpAdminLevelCatalog before this file runs (src/admin.manifest lists them first). Polls has named presets
 * instead of tiers (src/admin/access/levels/polls.js, window.McpAdminPollsGrants) and Mail adds its write warnings
 * (src/admin/access/levels/mail.js, window.McpAdminMailGrants); the other admin scripts read both.
 */
(function (root) {
	'use strict'

	const catalog = root.McpAdminLevelCatalog || { tiers: {}, tierNames: {} }

	/** Cumulative tiers per module, from src/admin/access/levels/<app>.js: [Read, Edit, Full]. Modules with one operation have only Read. */
	const TIERS = catalog.tiers

	/** Names of the tiers by how many a module has. */
	const TIER_NAMES = { 1: ['read'], 2: ['read', 'full'], 3: ['read', 'edit', 'full'] }

	/**
	 * Names of the tiers of the modules whose tiers are not Read, Edit and Full, in tier order (Forms and Mail, from
	 * their files). src/admin/admin-grants.js translates them.
	 */
	const MODULE_TIER_NAMES = catalog.tierNames

	/**
	 * @param {string} module module id
	 * @param {number} count number of tiers the module has
	 * @return {string[]|null} the module's own tier names when their number matches, or null to use the general ones
	 */
	function tierNames(module, count) {
		const own = MODULE_TIER_NAMES[module]
		return own && own.length === count ? own : null
	}

	/**
	 * The tiers of a module, restricted to the operations the catalog has, with any operation they do not name added to
	 * the last tier.
	 *
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @return {Array<{level: string, operations: string[]}>} one entry per tier, cumulative in this order
	 */
	function tiers(module, operations) {
		if (module === 'polls') return root.McpAdminPollsGrants?.tiers(operations) || []
		const known = TIERS[module] || [operations.filter((operation) => operation === 'read'), operations.filter((operation) => operation !== 'read')]
		const listed = known.map((tier) => tier.filter((operation) => operations.includes(operation)))
		const rest = operations.filter((operation) => !listed.some((tier) => tier.includes(operation)))
		if (rest.length > 0) {
			if (listed.length === 0) listed.push([])
			listed[listed.length - 1] = listed[listed.length - 1].concat(rest)
		}
		const kept = listed.filter((tier) => tier.length > 0)
		const names = tierNames(module, kept.length) || TIER_NAMES[kept.length] || kept.map((_, index) => 'tier' + index)
		return kept.map((tier, index) => ({ level: names[index], operations: tier }))
	}

	/**
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @return {string[]} the levels that can be chosen, from "none" up
	 */
	function levels(module, operations) {
		return ['none', ...tiers(module, operations).map((tier) => tier.level)]
	}

	/**
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @param {string} level a level of levels()
	 * @return {string[]} the operations that level allows, in catalog order
	 */
	function operationsFor(module, operations, level) {
		if (module === 'polls') return root.McpAdminPollsGrants?.operationsFor(operations, level) || []
		const all = tiers(module, operations)
		const upTo = all.findIndex((tier) => tier.level === level)
		if (level !== 'none' && upTo < 0) {
			throw new Error('Unknown level ' + level + ' for ' + module)
		}
		const allowed = new Set(all.slice(0, upTo + 1).flatMap((tier) => tier.operations))
		return operations.filter((operation) => allowed.has(operation))
	}

	/**
	 * The level a user has in a module, from the effective value of each operation.
	 *
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @param {Object<string, boolean|null>} values effective value per operation; null is unknown
	 * @return {string} a level of levels(), "custom" when the allowed set is no level, or "unknown" when any value is
	 */
	function levelOf(module, operations, values) {
		if (operations.some((operation) => values[operation] === null || values[operation] === undefined)) {
			return 'unknown'
		}
		const allowed = operations.filter((operation) => values[operation] === true)
		for (const level of levels(module, operations)) {
			const expected = operationsFor(module, operations, level)
			if (expected.length === allowed.length && expected.every((operation) => allowed.includes(operation))) {
				return level
			}
		}
		return 'custom'
	}

	/**
	 * Where a module's values come from, summed over its operations: an individual value on any operation makes the module
	 * individual (an invalid stored value first), then a group whose policy cannot be read, then a group, then the
	 * default.
	 *
	 * @param {string[]} operations the module's operations in the catalog
	 * @param {Object<string, {source: string, groupIds: string[]}>} origins origin per operation, as the API sends it
	 * @return {{source: string, groupIds: string[]}} source is invalid, individual, unknown, group or default
	 */
	function originOf(operations, origins) {
		const sources = operations.map((operation) => origins?.[operation] || { source: 'default', groupIds: [] })
		const groups = (kind) => [...new Set(sources.filter((origin) => origin.source === kind).flatMap((origin) => origin.groupIds || []))].sort()
		if (sources.some((origin) => origin.source === 'invalid')) return { source: 'invalid', groupIds: [] }
		if (sources.some((origin) => origin.source === 'individual_allow' || origin.source === 'individual_deny')) return { source: 'individual', groupIds: [] }
		if (sources.some((origin) => origin.source === 'unknown')) return { source: 'unknown', groupIds: groups('unknown') }
		if (sources.some((origin) => origin.source === 'group')) return { source: 'group', groupIds: groups('group') }
		return { source: 'default', groupIds: [] }
	}

	root.McpAdminLevels = { TIERS, MODULE_TIER_NAMES, tierNames, tiers, levels, operationsFor, levelOf, originOf }
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/admin-grants.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Admin permission matrix (users × permissions) for the MCP app.
 *
 * Loads one page of users from GET /apps/mcp/api/grants and saves each checkbox immediately through
 * PUT /apps/mcp/api/grants/{uid}, POST /apps/mcp/api/grants/commands and PUT /apps/mcp/api/service.
 * Every save carries the policy revision the page was built from (a decimal string, never parsed as a
 * number); when the server answers 409 or 428 someone else saved first, so the page reloads instead
 * of repeating the write. The matrix revision is only ever the one of the matrix on screen: from the
 * GET that was rendered, or from a matrix save that committed from it. The server-log block keeps its
 * own, from its own GET. A revision found in an error body is never adopted.
 * A write this page committed is announced to the other blocks of the page (event "mcp-revision-moved", with the
 * revision it was sent with and the one it made). The OAuth clients, server-log and resource scope blocks, when still
 * at the first, adopt the second and keep their unsaved changes: under compare-and-swap nothing but that write lies
 * between the two. The matrix never adopts one; it is reloaded after the writes of the other blocks. A move this page
 * did not make (another window or administrator) is never announced, so the next save of a block still answers 409.
 * Plain browser JavaScript, no build step; strings go through t('mcp', …) for translation.
 */
(function () {
	'use strict'

	const root = document.getElementById('mcp-admin')
	if (!root) {
		return
	}

	/** Columns the matrix always shows besides the permissions: selection, user, "Can connect" and the row's actions. */
	const FIXED_COLUMNS = 4
	/**
	 * view is "levels" (one access level per module, src/admin/admin-levels.js) or "operations" (a box per operation); app filters
	 * the operations view only. Both live in the address as #access?view=operations&app=<module>.
	 */
	const state = { search: '', group: '', filter: 'eligible', page: 1, app: '', view: 'levels', exceptionsOnly: false, data: null, groupsLoaded: false }
	const table = document.getElementById('mcp-matrix')
	const searchInput = document.getElementById('mcp-search')
	const groupSelect = document.getElementById('mcp-group')
	const filterSelect = document.getElementById('mcp-filter')
	const appSelect = document.getElementById('mcp-app')
	const appFilter = document.getElementById('mcp-app-filter')
	const viewButtons = { levels: document.getElementById('mcp-view-levels'), operations: document.getElementById('mcp-view-operations') }
	const legends = { levels: document.getElementById('mcp-legend-levels'), operations: document.getElementById('mcp-legend-operations') }
	const countInfo = document.getElementById('mcp-count')
	// The pager sits under the table only; the count of users stays above it.
	const prevButtons = [document.getElementById('mcp-prev-bottom')].filter(Boolean)
	const nextButtons = [document.getElementById('mcp-next-bottom')].filter(Boolean)
	const pageInfos = [document.getElementById('mcp-page-info-bottom')].filter(Boolean)
	const onlyButtons = { all: document.getElementById('mcp-only-all'), exceptions: document.getElementById('mcp-only-exceptions') }
	const serviceBox = document.getElementById('mcp-service')
	const serviceStatus = document.getElementById('mcp-service-status')

	/** @return {Object<string, string>} module labels */
	function moduleLabels() {
		return {
			files: t('mcp', 'Files'),
			notes: t('mcp', 'Notes'),
			deck: t('mcp', 'Deck'),
			calendar: t('mcp', 'Calendar'),
			contacts: t('mcp', 'Contacts'),
			tasks: t('mcp', 'Tasks'),
			talk: t('mcp', 'Talk'),
			people: t('mcp', 'People'),
			logs: t('mcp', 'Server log'),
			mail: t('mcp', 'Mail'),
			tables: t('mcp', 'Tables'), forms: t('mcp', 'Forms'), polls: t('mcp', 'Polls'),
		}
	}

	/** @return {Object<string, string>} operation labels */
	function operationLabels() {
		return {
			...window.McpAdminPollsGrants?.operationLabels(),
			read: t('mcp', 'read'),
			results: t('mcp', 'results'),
			edit: t('mcp', 'edit'),
			create: t('mcp', 'create'),
			move: t('mcp', 'move'),
			delete: t('mcp', 'delete'),
			restore: t('mcp', 'restore'),
			transfer: t('mcp', 'transfer'),
			reply: t('mcp', 'reply'),
			attach: t('mcp', 'attach'),
			quote: t('mcp', 'quote'),
			share: t('mcp', 'share'),
			link: t('mcp', 'link'),
			messages: t('mcp', 'messages'),
			attachments: t('mcp', 'attachments'),
			flags: t('mcp', 'flags'),
			draft: t('mcp', 'draft'),
			send: t('mcp', 'send'),
		}
	}

	/**
	 * What each operation lets the AI do, shown as the column tooltip.
	 *
	 * @param {string} module module id
	 * @param {string} operation operation id
	 * @return {string} description
	 */
	function operationHint(module, operation) {
		if (module === 'talk' && operation === 'create') {
			return t('mcp', 'Open new conversations, which invites people')
		}
		if (module === 'files' && operation === 'create') {
			return t('mcp', 'Create folders, copies and new files')
		}
		if (module === 'logs' && operation === 'read') {
			return t('mcp', 'Read and analyze the server log; starts off')
		}
		if (module === 'calendar' && operation === 'transfer') {
			return t('mcp', 'Move an event to another person’s calendar')
		}
		const mailHint = window.McpAdminPollsGrants?.operationHint(module, operation) || (window.McpAdminMailGrants ? window.McpAdminMailGrants.operationHint(module, operation) : null)
		if (mailHint) return mailHint
		const hints = {
			read: t('mcp', 'List, search and read'),
			results: t('mcp', 'Reserved for reading responses allowed by native Forms permissions; starts off'),
			edit: t('mcp', 'Change existing items'),
			create: t('mcp', 'Create new items'),
			move: t('mcp', 'Move or rename items'),
			delete: t('mcp', 'Delete items'),
			restore: t('mcp', 'Restore a file to a stored version'),
			reply: t('mcp', 'Send messages and replies'),
			attach: t('mcp', 'Share a file into a conversation'),
			quote: t('mcp', 'Quote part of a file in a message'),
			share: t('mcp', 'Share own files with people and groups'),
			link: t('mcp', 'Create public links to own files'),
		}
		return hints[operation] || ''
	}

	/**
	 * @param {string} method HTTP method
	 * @param {string} path path below /apps/mcp
	 * @param {object} [body] JSON body
	 * @return {Promise<object>} decoded JSON response
	 */
	async function api(method, path, body) {
		const response = await fetch(OC.generateUrl('/apps/mcp' + path), {
			method,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		if (!response.ok) {
			const error = new Error(String(response.status))
			error.data = await response.json().catch(() => null)
			throw error
		}
		return response.json().catch((cause) => {
			// A proxy/session may return 2xx HTML after a write committed. Reuse the matrix's uncertain-save recovery.
			const matrixWrite = (method === 'PUT' && (path.startsWith('/api/grants/') || path === '/api/service'))
				|| (method === 'POST' && (path === '/api/grants/commands' || path === '/api/grants/undo'))
			if (matrixWrite) throw Object.assign(new Error('503'), { data: { uncertain: true }, cause })
			// Reads (including command previews) and other settings keep their ordinary load/request error path.
			throw cause
		})
	}

	/**
	 * True while the matrix reloads after a conflict or an uncertain save: every matrix write waits
	 * for the reloaded table instead of going out with a page that is known to be outdated.
	 */
	let matrixLocked = false

	/** Reloads the matrix with every matrix write blocked until the new page is on screen. */
	async function reloadLocked() {
		matrixLocked = true
		try {
			await load()
		} finally {
			matrixLocked = false
		}
	}

	/**
	 * Tells the admin what a failed save means when the policy moved or the storage failed, and reloads
	 * the matrix in those cases so nothing is written twice.
	 *
	 * @param {Error} e error thrown by api()
	 * @return {Promise<boolean>} whether the error was handled here (the matrix is then reloaded)
	 */
	async function policyError(e) {
		if (e.message === '409' || e.message === '428') {
			notifyError(t('mcp', 'The permissions were changed in another window or by another administrator. The table was reloaded with the saved values; nothing was overwritten.'))
		} else if (e.message === '503' && e.data && e.data.uncertain) {
			notifyError(t('mcp', 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.'))
		} else {
			return false
		}
		await reloadLocked()
		return true
	}

	/** @param {string} message error shown to the admin */
	function notifyError(message) {
		// The old notification global is gone since Nextcloud 34 and OCP.Toast is deprecated, so without it the browser's own dialog
		// still shows the message instead of losing it.
		if (window.OCP && OCP.Toast) {
			OCP.Toast.error(message)
		} else {
			window.alert(message)
		}
	}

	/** Page event of a write this page committed: detail {from, to}. src/admin/admin-scope.js listens to it too. */
	const REVISION_MOVED = 'mcp-revision-moved'

	/**
	 * Tells the other blocks of this page that a write of this page moved the policy revision from `from` to `to`.
	 * Only the answer of a save qualifies: the server committed it from `from`, so `to` holds that write and nothing else.
	 *
	 * @param {string} from revision the write was sent with
	 * @param {string} to revision the server answered with
	 */
	function announce(from, to) {
		if (typeof from === 'string' && typeof to === 'string' && from !== to) {
			window.dispatchEvent(new CustomEvent(REVISION_MOVED, { detail: { from, to } }))
		}
	}

	/**
	 * Whether `to` is exactly one commit after `from`. The OAuth clients and server-log saves answer with the revision of
	 * a read made after their commit, which may already hold another administrator's write. A commit advances the
	 * revision by one, and only when the request differs from what is stored at `from`; so a save whose request differs
	 * from the settings this block confirmed at `from` made one step itself, and one step in all is that save alone. A
	 * request that changes nothing commits nothing: its answer is never announced, whatever it shows. Compared as exact
	 * integers, never as floating-point numbers.
	 *
	 * @param {string} from revision the save was sent with
	 * @param {string} to revision of the answer
	 * @return {boolean}
	 */
	function oneStep(from, to) {
		try {
			return BigInt(to) === BigInt(from) + 1n
		} catch {
			return false
		}
	}

	/**
	 * PHP's trim() without a character list: removes only " \t\n\r\0\x0B" from both ends. String.prototype.trim()
	 * keeps NUL and removes Unicode spaces PHP keeps, so it cannot stand in for the server's normalization.
	 *
	 * @param {string} text
	 * @return {string}
	 */
	function phpTrim(text) {
		return text.replace(/^[ \t\n\r\x00\x0B]+|[ \t\n\r\x00\x0B]+$/g, '')
	}

	/**
	 * Keeps a block's own revision in step with the writes of this page: when the block is exactly at the revision a
	 * write committed from, it takes the one that write made, and its unsaved changes stay valid.
	 *
	 * @param {Function} get () => the block's revision
	 * @param {Function} set (revision) => void
	 */
	function followRevision(get, set) {
		window.addEventListener(REVISION_MOVED, (event) => {
			if (get() === event.detail.from) {
				set(event.detail.to)
			}
		})
	}

	/**
	 * @param {HTMLElement} cell cell that shows the save result
	 * @param {boolean} ok whether the save worked
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

	/** Number of the latest load(); an older one that answers later is dropped. */
	let loadSeq = 0

	/**
	 * Reads the current page from the server and re-renders the table; a failure is shown in the table with a retry.
	 * The page and its revision always come from the same response, and only the newest request is rendered.
	 */
	async function load() {
		const seq = ++loadSeq
		table.setAttribute('aria-busy', 'true')
		table.classList.add('mcp-loading')
		const query = new URLSearchParams({ search: state.search, group: state.group, filter: state.filter, page: String(state.page) })
		try {
			const data = await api('GET', '/api/grants?' + query.toString())
			if (seq !== loadSeq) {
				return
			}
			state.data = data
			render()
		} catch (e) {
			if (seq !== loadSeq) {
				return
			}
			renderError()
			notifyError(t('mcp', 'Could not load the users.'))
		} finally {
			if (seq === loadSeq) {
				table.setAttribute('aria-busy', 'false')
				table.classList.remove('mcp-loading')
			}
		}
	}

	/** Replaces the body with an error row and a retry button, keeping the last header. */
	function renderError() {
		const retry = el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: load })
		const cell = el('td', { colSpan: state.data ? FIXED_COLUMNS + visiblePermissionColumns() : 1, className: 'mcp-empty mcp-load-error' },
			[t('mcp', 'Could not load the users.'), ' ', retry])
		table.tBodies[0].replaceChildren(el('tr', {}, [cell]))
		applyAppFilter()
	}

	/** Fills the group filter once, keeping the current selection. */
	function renderGroups() {
		if (state.groupsLoaded) {
			return
		}
		for (const group of state.data.groups) {
			groupSelect.append(el('option', { value: group.id, textContent: group.displayName }))
		}
		state.groupsLoaded = true
	}

	/** Populate app choices from the actual catalog, preserving a valid selection. */
	function renderApps() {
		const labels = moduleLabels()
		const modules = Object.keys(state.data.catalog)
		if (!modules.includes(state.app)) {
			const stale = state.app !== ''
			state.app = ''
			if (stale) {
				// An address naming an unknown app is corrected in place, without a new history entry.
				writeAppToHash(true)
			}
		}
		appSelect.replaceChildren(el('option', { value: '', textContent: t('mcp', 'All apps') }),
			...modules.map((module) => el('option', { value: module, textContent: labels[module] || module })))
		appSelect.value = state.app
		appSelect.disabled = false
	}

	/** Filter only presentation; user queries and permission payloads stay unchanged. */
	function applyAppFilter() {
		const app = shownApp()
		for (const cell of table.querySelectorAll('[data-module]')) {
			cell.hidden = app !== '' && cell.dataset.module !== app
			if (cell.hidden) {
				for (const menu of cell.querySelectorAll('details[open]')) {
					menu.open = false
				}
			}
		}
		const permissions = visiblePermissionColumns()
		for (const cell of table.querySelectorAll('td.mcp-empty')) {
			cell.colSpan = FIXED_COLUMNS + permissions
		}
		for (const cell of table.querySelectorAll('td.mcp-row-unavailable')) {
			cell.colSpan = permissions
		}
		stickSecondHeaderRow()
	}

	/** Read the Access tab's app parameter on reload and history navigation. */
	function readAppFromHash() {
		const [tab, query = ''] = window.location.hash.slice(1).split('?')
		if (tab !== 'access') {
			return
		}
		const params = new URLSearchParams(query)
		const view = params.get('view') === 'operations' ? 'operations' : 'levels'
		state.app = params.get('app') || ''
		if (!state.data) {
			state.view = view
			return
		}
		if (view !== state.view) {
			state.view = view
			render()
			return
		}
		renderApps()
		applyAppFilter()
	}

	/** @return {boolean} whether the matrix shows one level per module */
	function isLevelsView() {
		return state.view === 'levels' && Boolean(window.McpAdminLevels)
	}

	/** @return {string} the app whose columns are shown alone, '' for all; the levels view always shows every module */
	function shownApp() {
		return isLevelsView() ? '' : state.app
	}

	/**
	 * Switches between the levels and the operations view, keeping the page, the filters and the data on screen.
	 *
	 * @param {string} view "levels" or "operations"
	 * @param {string} [app] module to show alone in the operations view
	 */
	function setView(view, app) {
		state.view = view
		// The levels view shows every module, so the app filter of the operations view leaves the address with it.
		if (view === 'levels') {
			state.app = ''
		} else if (app !== undefined) {
			state.app = app
		}
		if (state.data) {
			render()
		}
		writeAppToHash(false)
	}

	/**
	 * Writes the chosen app into the address (#access?app=<module>, no parameter for all apps).
	 *
	 * @param {boolean} replace correct the current entry instead of adding a history entry
	 */
	function writeAppToHash(replace) {
		const [tab, query = ''] = window.location.hash.slice(1).split('?')
		if (tab !== 'access') {
			return
		}
		const params = new URLSearchParams(query)
		if (state.app === '') {
			params.delete('app')
		} else {
			params.set('app', state.app)
		}
		if (state.view === 'operations') {
			params.set('view', 'operations')
		} else {
			params.delete('view')
		}
		const next = '#access' + (params.toString() ? '?' + params.toString() : '')
		if (next === window.location.hash) {
			return
		}
		if (replace) {
			window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + next)
		} else {
			window.location.hash = next
		}
	}

	appSelect.addEventListener('change', () => {
		state.app = appSelect.value
		applyAppFilter()
		writeAppToHash(false)
	})
	for (const [view, button] of Object.entries(viewButtons)) {
		button?.addEventListener('click', () => {
			if (state.view !== view) {
				setView(view)
			}
		})
	}
	window.addEventListener('hashchange', readAppFromHash)
	readAppFromHash()

	/** Makes the second header row stick right below the first one, whose height depends on the density. */
	function stickSecondHeaderRow() {
		const [top, sub] = table.tHead.rows
		if (!top || !sub) {
			return
		}
		const offset = top.getBoundingClientRect().height
		for (const cell of sub.cells) {
			cell.style.top = offset + 'px'
		}
	}

	/** The first header row currently observed, so a re-created row (every render) is observed again. */
	let observedHeaderRow = null
	let headerObserver = null

	/**
	 * Re-measures the first header row whenever it resizes. The Access panel starts hidden, so the
	 * render there measures zero; showing the tab (or changing density or font size) resizes the row
	 * and the observer re-sticks the second row below it. A browser without ResizeObserver keeps the
	 * render-time measure, which is correct whenever the panel is visible.
	 */
	function observeHeaderRow() {
		if (!window.ResizeObserver) {
			return
		}
		const top = table.tHead?.rows?.[0]
		if (!top || top === observedHeaderRow) {
			return
		}
		if (!headerObserver) {
			headerObserver = new window.ResizeObserver(stickSecondHeaderRow)
		}
		headerObserver.disconnect()
		observedHeaderRow = top
		headerObserver.observe(top)
	}

	/** Renders header and rows from state.data. */
	function render() {
		const data = state.data
		// After a conflict the saved switch may differ from what this page last showed.
		if (!serviceBox.disabled) {
			serviceBox.checked = data.serviceEnabled
		}
		renderGroups()
		renderApps()
		const modules = moduleLabels()
		const operations = operationLabels()
		const levelsView = isLevelsView()
		for (const [view, button] of Object.entries(viewButtons)) {
			button?.setAttribute('aria-pressed', String(view === (levelsView ? 'levels' : 'operations')))
		}
		for (const [view, legend] of Object.entries(legends)) {
			if (legend) legend.hidden = view !== (levelsView ? 'levels' : 'operations')
		}
		if (appFilter) {
			appFilter.hidden = levelsView
		}
		table.classList.toggle('mcp-levels', levelsView)

		const top = el('tr')
		const sub = el('tr')
		const rowSpan = levelsView ? 1 : 2
		const selectHeader = el('th', { rowSpan, className: 'mcp-select-col', scope: 'col' }, [el('span', { className: 'mcp-sr-only', textContent: t('mcp', 'Select') })])
		// The "select every user on this page" box of src/admin/admin-overrides.js heads the selection column.
		window.McpAdminOverrides?.decorateHeader?.(selectHeader)
		top.append(selectHeader)
		top.append(el('th', { rowSpan, className: 'mcp-user-col', scope: 'col', textContent: t('mcp', 'User') }))
		const canConnect = t('mcp', 'Can connect')
		top.append(el('th', { rowSpan, scope: 'col', title: t('mcp', 'The administrator allows this user to connect an MCP client'), textContent: canConnect }))
		Object.entries(data.catalog).forEach(([module, ops], index) => {
			const band = 'mcp-band-' + (index % 2)
			const name = modules[module] || module
			const header = el('th', { colSpan: levelsView ? 1 : ops.length, scope: levelsView ? 'col' : 'colgroup', className: 'mcp-module ' + band, textContent: name })
			header.dataset.module = module
			top.append(header)
			if (levelsView) {
				return
			}
			ops.forEach((operation, i) => {
				const cell = el('th', {
					scope: 'col',
					className: band + (i === 0 ? ' mcp-module' : ''),
					title: operationHint(module, operation),
					textContent: operations[operation] || operation,
				})
				cell.dataset.module = module
				sub.append(cell)
			})
		})
		top.append(el('th', { rowSpan, scope: 'col', className: 'mcp-actions-col' }, [el('span', { className: 'mcp-sr-only', textContent: t('mcp', 'Actions') })]))
		table.tHead.replaceChildren(...(levelsView ? [top] : [top, sub]))
		if (bulkBusy) {
			setBulkBusy(true)
		}
		stickSecondHeaderRow()
		observeHeaderRow()

		const rows = data.users.map(renderRow)
		if (rows.length === 0) {
			const columns = FIXED_COLUMNS + (levelsView ? Object.keys(data.catalog).length : Object.values(data.catalog).reduce((sum, ops) => sum + ops.length, 0))
			rows.push(el('tr', {}, [el('td', { colSpan: columns, className: 'mcp-empty', textContent: t('mcp', 'No users found.') })]))
		}
		table.tBodies[0].replaceChildren(...rows)
		applyAppFilter()
		applyExceptionsFilter()

		const pageText = data.total === null
			? t('mcp', 'Page {page}', { page: data.page })
			: t('mcp', 'Page {page} of {pages}', { page: data.page, pages: Math.max(1, Math.ceil(data.total / data.pageSize)) })
		for (const button of prevButtons) {
			button.disabled = data.page <= 1
		}
		for (const button of nextButtons) {
			button.disabled = !data.hasMore
		}
		for (const info of pageInfos) {
			info.textContent = pageText
		}
		const from = (data.page - 1) * data.pageSize + 1
		const to = from + data.users.length - 1
		countInfo.textContent = data.users.length === 0
			? ''
			: (data.total === null
				? t('mcp', 'Users {from}–{to}', { from, to })
				: t('mcp', 'Users {from}–{to} of {total}', { from, to, total: data.total }))
		window.dispatchEvent(new Event('mcp-matrix-rendered'))
	}

	/**
	 * @param {object} user row from the API
	 * @return {HTMLTableRowElement}
	 */
	function renderRow(user) {
		const row = buildRow(user)
		window.McpAdminOverrides?.decorateRow(row, user)
		return row
	}

	/**
	 * @param {object} user row from the API
	 * @return {HTMLTableRowElement}
	 */
	function buildRow(user) {
		const row = el('tr', { className: user.enabled ? '' : 'mcp-disabled-user' })
		row.dataset.uid = user.uid
		// The selection box of src/admin/admin-overrides.js goes here, in its own column instead of next to the name.
		row.append(el('td', { className: 'mcp-select-col' }))
		row.append(el('th', { scope: 'row', className: 'mcp-user-col' }, [el('span', { className: 'mcp-name', textContent: user.displayName }), userLine(user)]))
		row.append(isLevelsView() ? eligibleCell(user) : permissionCell(user, null, user.eligible, t('mcp', 'Can connect'), { eligible: null }))
		const rowReason = rowUnavailableReason(user)
		if (rowReason !== null) {
			// One notice instead of a mark in every column; the saved permissions come back with "Can connect".
			const notice = el('td', { className: 'mcp-cell mcp-row-unavailable', colSpan: visiblePermissionColumns(), textContent: rowReason })
			notice.title = user.enabled
				? t('mcp', 'The saved permissions are kept and shown again when “Can connect” is on.')
				: t('mcp', 'The saved permissions are kept and shown again when the account is enabled and “Can connect” is on.')
			row.append(notice)
			row.append(actionsCell(user))
			return row
		}
		const modules = moduleLabels()
		const operations = operationLabels()
		if (isLevelsView()) {
			Object.entries(state.data.catalog).forEach(([module, ops], index) => row.append(levelCell(user, module, ops, index)))
			row.append(actionsCell(user))
			return row
		}
		Object.entries(state.data.catalog).forEach(([module, ops], index) => {
			ops.forEach((operation, i) => {
				// The same name as the column header and the reset button, never the raw id ("files:read").
				const label = (modules[module] || module) + ': ' + (operations[operation] || operation)
				const cell = permissionCell(user, module, user.grants[module][operation], label, { module, operation, granted: null })
				cell.dataset.module = module
				cell.hidden = state.app !== '' && state.app !== module
				cell.classList.add('mcp-band-' + (index % 2))
				if (i === 0) {
					cell.classList.add('mcp-module')
					// "Restricted to N" or "Scope unknown" of a resource scope, drawn and opened by src/admin/admin-scope.js.
					const mark = user.scope?.[module] && window.McpScopeUi ? window.McpScopeUi.indicator(user.uid, module, user.scope[module]) : null
					if (mark) cell.append(mark)
				}
				row.append(cell)
			})
		})
		row.append(actionsCell(user))
		return row
	}

	/**
	 * The second line under a name: "Account disabled", "Connected" (with its mark, in the success colour as text) or, for
	 * everyone else, the user ID. Being connected is a fact worth seeing; not being connected is the normal case and says
	 * nothing more than the ID.
	 *
	 * @param {object} user row from the API
	 * @return {HTMLElement}
	 */
	function userLine(user) {
		if (!user.enabled) {
			return el('span', { className: 'mcp-uid mcp-user-disabled', textContent: t('mcp', 'Account disabled') })
		}
		if (user.eligible && user.connected) {
			const mark = el('span', { className: 'mcp-connected-mark', textContent: '●' })
			mark.setAttribute('aria-hidden', 'true')
			const line = el('span', { className: 'mcp-uid mcp-user-connected' }, [mark, ' ', t('mcp', 'Connected')])
			line.title = user.uid
			return line
		}
		return el('span', { className: 'mcp-uid', textContent: user.uid })
	}

	/** @return {HTMLTableCellElement} the cell with the row's actions menu (src/admin/admin-row-actions.js) */
	function actionsCell(user) {
		const cell = el('td', { className: 'mcp-actions-col' })
		const button = window.McpRowActions?.button(user)
		if (button) cell.append(button)
		return cell
	}

	/** Sources of a value the person has individually. */
	const OWN_SOURCES = ['individual_allow', 'individual_deny', 'invalid']

	/**
	 * Whether a user has a real exception on the page (src/admin/admin-exceptions.js): an individual value that differs from what
	 * they would inherit. null while that is not known.
	 *
	 * @param {object} user row from the API
	 * @return {boolean|null}
	 */
	function hasException(user) {
		const exceptions = window.McpAdminExceptions
		if (!exceptions) return null
		const cells = []
		if (OWN_SOURCES.includes(user.origins?.eligible?.source)) cells.push([null, 'eligible'])
		for (const [module, operations] of Object.entries(user.origins?.grants || {})) {
			for (const [operation, origin] of Object.entries(operations)) {
				if (OWN_SOURCES.includes(origin?.source)) cells.push([module, operation])
			}
		}
		const kinds = cells.map(([module, operation]) => exceptions.kindOf(user.uid, module, operation).kind)
		if (kinds.includes('exception')) return true
		return kinds.includes('pending') ? null : false
	}

	/**
	 * The "All | With exceptions (N)" filter: it counts the users of this page who have a real exception and, when chosen,
	 * shows only them. It works on the page on screen, so it says so; nothing is asked of the server.
	 */
	function applyExceptionsFilter() {
		if (!onlyButtons.exceptions || !state.data) return
		const known = state.data.users.map((user) => [user.uid, hasException(user)])
		const pending = known.some(([, value]) => value === null)
		const count = known.filter(([, value]) => value === true).length
		onlyButtons.exceptions.textContent = pending
			? t('mcp', 'With exceptions')
			: t('mcp', 'With exceptions ({count})', { count }, undefined, { escape: false })
		onlyButtons.exceptions.title = t('mcp', 'People on this page with an individual value that differs from what they inherit')
		for (const [only, button] of Object.entries(onlyButtons)) {
			button?.setAttribute('aria-pressed', String((only === 'exceptions') === state.exceptionsOnly))
		}
		const show = new Set(known.filter(([, value]) => !state.exceptionsOnly || value !== false).map(([uid]) => uid))
		let shown = 0
		for (const row of table.tBodies[0].rows) {
			if (row.dataset.uid === undefined) continue
			row.hidden = !show.has(row.dataset.uid)
			if (!row.hidden) shown++
		}
		table.tBodies[0].querySelector('tr.mcp-only-empty')?.remove()
		if (state.exceptionsOnly && shown === 0 && state.data.users.length > 0) {
			table.tBodies[0].append(el('tr', { className: 'mcp-only-empty' }, [el('td', {
				colSpan: FIXED_COLUMNS + visiblePermissionColumns(),
				className: 'mcp-empty',
				textContent: t('mcp', 'No one on this page has an exception.'),
			})]))
		}
	}

	for (const [only, button] of Object.entries(onlyButtons)) {
		button?.addEventListener('click', () => {
			state.exceptionsOnly = only === 'exceptions'
			applyExceptionsFilter()
		})
	}

	/**
	 * Why none of a user's permissions can be changed: a disabled account or "Can connect" off. The
	 * row then shows this one notice across the permission columns instead of a mark in each of them.
	 *
	 * @param {object} user row from the API
	 * @return {string|null} short notice, or null when the permissions are shown cell by cell
	 */
	function rowUnavailableReason(user) {
		if (!user.enabled) {
			return t('mcp', 'Account disabled: no MCP access.')
		}
		if (user.eligible === false) {
			return t('mcp', 'No MCP access: turn on “Can connect”.')
		}
		return null
	}

	/** @return {number} permission columns shown with the current app filter */
	function visiblePermissionColumns() {
		if (isLevelsView()) {
			return Object.keys(state.data?.catalog || {}).length
		}
		return Object.entries(state.data?.catalog || {})
			.filter(([module]) => state.app === '' || module === state.app)
			.reduce((sum, [, operations]) => sum + operations.length, 0)
	}

	/**
	 * Why a user's permission cannot be changed here, or null when it can. The first reason wins:
	 * a disabled account, then "Can connect" off, then the app (or the log role) unavailable to the user.
	 *
	 * @param {object} user row from the API
	 * @param {string|null} module module id, null for "Can connect"
	 * @return {string|null} reason shown to the admin
	 */
	function unavailableReason(user, module) {
		if (!user.enabled) {
			return t('mcp', 'The account is disabled.')
		}
		if (module === null) {
			return null
		}
		if (user.eligible === false) {
			return t('mcp', 'This user may not connect: “Can connect” is off.')
		}
		if (!user.appsEnabled[module]) {
			return module === 'logs'
				? t('mcp', 'Only administrators and members of the groups selected in “Server log” can read the log; the saved permission is kept.')
				: t('mcp', 'This app is unavailable for this user; the saved permission is kept.')
		}
		return null
	}

	/**
	 * The states a permission cell can show, drawn in one place: allowed and denied are a checkbox
	 * (checked or empty), unavailable is a "no entry" mark that never looks like an empty checkbox.
	 * Each state has a name for the tooltip and for screen readers, so none depends on colour.
	 */
	const PERMISSION_STATES = {
		allowed: { className: 'mcp-state-allowed', name: () => t('mcp', 'Allowed') },
		denied: { className: 'mcp-state-denied', name: () => t('mcp', 'Denied') },
		unavailable: { className: 'mcp-state-unavailable', name: () => t('mcp', 'Unavailable') },
	}

	/**
	 * Paints one state on a cell: its class, tooltip and the name read by screen readers.
	 *
	 * @param {HTMLTableCellElement} cell the cell
	 * @param {string} key key of PERMISSION_STATES
	 * @param {string} [detail] extra sentence (the reason of an unavailable cell)
	 */
	function paintState(cell, key, detail) {
		for (const other of Object.values(PERMISSION_STATES)) {
			cell.classList.remove(other.className)
		}
		cell.classList.add(PERMISSION_STATES[key].className)
		cell.title = detail ? PERMISSION_STATES[key].name() + ': ' + detail : PERMISSION_STATES[key].name()
	}

	/**
	 * A permission cell: a checkbox that saves at once, or the unavailable mark with its reason.
	 *
	 * @param {object} user row from the API
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {boolean} checked saved value
	 * @param {string} label accessible name of the permission
	 * @param {object} body request body template; the null field receives the new value
	 * @return {HTMLTableCellElement}
	 */
	function permissionCell(user, module, checked, label, body) {
		const cell = el('td', { className: 'mcp-cell' })
		const reason = checked === null ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : unavailableReason(user, module)
		if (reason !== null) {
			const saved = checked === null ? t('mcp', 'Unknown') : checked ? t('mcp', 'Saved value: allowed.') : t('mcp', 'Saved value: denied.')
			paintState(cell, 'unavailable', reason + ' ' + saved)
			const mark = el('span', { className: 'mcp-state-mark', textContent: checked === null ? '—' : '⊘' })
			mark.setAttribute('aria-hidden', 'true')
			cell.append(mark, el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + label + ': ' + cell.title }))
			if (checked === null) cell.append(el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: reloadLocked }))
			// A saved individual override stays visible and removable here, even though the cell cannot be edited.
			window.McpAdminOverrides?.decorateCell(cell, null, user, module, module === null ? 'eligible' : body.operation)
			return cell
		}
		const box = el('input', { type: 'checkbox', checked })
		box.setAttribute('aria-label', user.displayName + ' — ' + label)
		paintState(cell, checked ? 'allowed' : 'denied')
		const origin = module === null ? user.origins?.eligible : user.origins?.grants?.[module]?.[body.operation]
		if (origin?.source === 'group') {
			const source = t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') })
			cell.title += ' — ' + source
			box.setAttribute('aria-label', user.displayName + ' — ' + label + ' — ' + source)
		}
		box.addEventListener('change', async () => {
			const value = box.checked
			if (matrixLocked) {
				// The table on screen is known to be outdated; the reloaded one replaces this checkbox.
				box.checked = !value
				return
			}
			// Taking "Can connect" away signs the person out of every MCP client: never without asking.
			if (module === null && !value && !window.confirm(t('mcp', 'Turn off “Can connect” for {user}?', { user: user.displayName }, undefined, { escape: false }) + '\n'
				+ t('mcp', 'This signs the person out of every MCP client, and undoing it does not sign them back in.'))) {
				box.checked = true
				return
			}
			if (value && module !== null && window.McpAdminMailGrants && !window.McpAdminMailGrants.confirmWrites([{ module, operation: body.operation }])) {
				box.checked = false
				return
			}
			const payload = Object.fromEntries(Object.entries(body).map(([key, v]) => [key, v === null ? value : v]))
			const hadFocus = document.activeElement === box
			// The focus comes back to this checkbox only when the admin did not move it during the save.
			const focusStayed = () => hadFocus && (document.activeElement === null || document.activeElement === document.body || document.activeElement === box)
			box.disabled = true
			try {
				const sent = state.data.revision
				const updated = await api('PUT', '/api/grants/' + encodeURIComponent(user.uid), { ...payload, expectedRevision: sent })
				// Committed from the revision on screen, so the page plus this row is exactly the new revision.
				state.data.revision = updated.revision
				announce(sent, updated.revision)
				const index = state.data.users.findIndex((u) => u.uid === user.uid)
				state.data.users[index] = updated
				const restore = focusStayed()
				const fresh = renderRow(updated)
				cell.parentElement.replaceWith(fresh)
				const freshCell = fresh.children[cell.cellIndex]
				flash(freshCell, true)
				if (restore) {
					freshCell.querySelector('input')?.focus()
				}
			} catch (e) {
				const restore = focusStayed()
				box.checked = !value
				box.disabled = false
				if (restore) {
					box.focus()
				}
				flash(cell, false)
				if (!(await policyError(e))) {
					notifyError(t('mcp', 'Could not save the permission for {user}.', { user: user.displayName }))
				}
			}
		})
		cell.append(box)
		// The override state and the per-cell reset come from src/admin/admin-overrides.js, when it is loaded.
		window.McpAdminOverrides?.decorateCell(cell, box, user, module, module === null ? 'eligible' : body.operation)
		return cell
	}

	/** @return {Object<string, string>} names of the access levels of src/admin/admin-levels.js */
	function levelLabels() {
		return {
			...window.McpAdminPollsGrants?.levelLabels(),
			none: t('mcp', 'None'),
			read: t('mcp', 'Read'),
			edit: t('mcp', 'Edit'),
			full: t('mcp', 'Full'),
			forms_write: t('mcp', 'Read and write forms'),
			forms_write_results: t('mcp', 'Read and write forms and read responses'),
			folders: t('mcp', 'Accounts and folders'),
			messages: t('mcp', 'Messages'),
			attachments: t('mcp', 'Messages and attachments'),
			flags: t('mcp', 'Messages, attachments and flags'),
			move: t('mcp', 'Messages, attachments, flags and moving'),
			draft: t('mcp', 'Messages, attachments, flags, moving and drafts'),
			send: t('mcp', 'Messages, attachments, flags, moving, drafts and sending'),
			custom: t('mcp', 'Custom'),
		}
	}

	/**
	 * Where a module's values come from, as a sentence for the tooltip and screen readers.
	 *
	 * @param {{source: string, groupIds: string[]}} origin what McpAdminLevels.originOf() returned
	 * @return {string}
	 */
	function originText(origin) {
		switch (origin.source) {
		case 'individual': return t('mcp', 'Set for this person')
		case 'invalid': return t('mcp', 'Invalid stored value (denied)')
		case 'group': return t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') })
		case 'unknown': return t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') })
		default: return t('mcp', 'Default')
		}
	}

	/**
	 * One module of one user in the levels view: the level as a word (None, Read, Edit, Full, Custom) and where it comes
	 * from as an icon, so no state depends on colour. An individual value carries a person icon and a group a group icon;
	 * the default has none. A module the user cannot use keeps the "no entry" mark without a control, and an unknown value
	 * the dash, as in the operations view. Choosing the cell opens that module's operations for editing.
	 *
	 * @param {object} user row from the API
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @param {number} index position of the module, for the alternating band
	 * @return {HTMLTableCellElement}
	 */
	function levelCell(user, module, operations, index) {
		const levels = window.McpAdminLevels
		const cell = el('td', { className: 'mcp-cell mcp-level-cell mcp-module mcp-band-' + (index % 2) })
		cell.dataset.module = module
		cell.dataset.levelKey = module
		const name = moduleLabels()[module] || module
		const level = levels.levelOf(module, operations, user.grants[module])
		const reason = unavailableReason(user, module)
		if (reason !== null || level === 'unknown') {
			const unknown = reason === null
			paintState(cell, 'unavailable', unknown ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : reason)
			const mark = el('span', { className: 'mcp-state-mark', textContent: unknown ? '—' : '⊘' })
			mark.setAttribute('aria-hidden', 'true')
			const word = el('span', { className: 'mcp-level-word', textContent: unknown ? t('mcp', 'Unknown') : t('mcp', 'Unavailable') })
			word.setAttribute('aria-hidden', 'true')
			cell.append(mark, ' ', word, el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + name + ': ' + cell.title }))
			if (unknown) cell.append(el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: reloadLocked }))
		} else {
			const { origin, mark: kind } = originMark(user, module, operations)
			const labels = operationLabels()
			const allowed = operations.filter((operation) => user.grants[module][operation] === true)
			const levelName = levelLabels()[level] || level
			const summary = allowed.length === 0
				? t('mcp', 'Allows nothing')
				: t('mcp', 'Allows: {operations}', { operations: allowed.map((operation) => labels[operation] || operation).join(', ') }, undefined, { escape: false })
			paintState(cell, allowed.length === 0 ? 'denied' : 'allowed')
			const source = markText(origin, kind)
			const individual = kind === 'exception'
			const icon = markIcon(origin, kind)
			const parts = []
			if (icon !== null) {
				const glyph = el('span', { className: 'mcp-origin-icon ' + icon })
				glyph.setAttribute('aria-hidden', 'true')
				parts.push(glyph)
			}
			parts.push(el('span', { className: 'mcp-level-word', textContent: levelName }))
			const button = levelButton(user, module, 'mcp-level mcp-level-' + level + (individual ? ' mcp-level-individual' : ''), parts,
				levelName + '. ' + summary + ' — ' + source,
				t('mcp', '{user}, {module}: {level}. {source}.', { user: user.displayName, module: name, level: levelName, source }, undefined, { escape: false }))
			cell.title = button.title
			cell.dataset.level = level
			cell.dataset.origin = origin.source
			cell.dataset.mark = kind
			cell.append(button)
		}
		// "Restricted to N" or "Scope unknown" of a resource scope, drawn and opened by src/admin/admin-scope.js.
		const mark = user.scope?.[module] && window.McpScopeUi ? window.McpScopeUi.indicator(user.uid, module, user.scope[module]) : null
		if (mark) cell.append(mark)
		return cell
	}

	/** Sources of an individual value, as the API sends them. */
	const INDIVIDUAL_SOURCES = ['individual_allow', 'individual_deny', 'invalid']

	/**
	 * Where a cell's values come from and how to mark it. Only a real exception, an individual value that differs from what
	 * the person would inherit (src/admin/admin-exceptions.js), gets the person icon; individual values that only repeat the
	 * inheritance are drawn like what they repeat, and while that is not known yet they get no mark. Without the
	 * classification every individual value is an exception, never fewer.
	 *
	 * @param {object} user row from the API
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {string[]} operations the module's operations, or ["eligible"]
	 * @return {{origin: {source: string, groupIds: string[]}, mark: string}} mark is exception, redundant, pending, or the
	 *     source (group, unknown, default) of a cell with no individual value
	 */
	function originMark(user, module, operations) {
		const levels = window.McpAdminLevels
		const origins = module === null ? { eligible: user.origins?.eligible } : user.origins?.grants?.[module]
		const origin = levels.originOf(operations, origins)
		if (origin.source !== 'individual' && origin.source !== 'invalid') {
			return { origin, mark: origin.source }
		}
		const exceptions = window.McpAdminExceptions
		const individual = operations.filter((operation) => INDIVIDUAL_SOURCES.includes(origins?.[operation]?.source))
		const kinds = exceptions ? individual.map((operation) => exceptions.kindOf(user.uid, module, operation)) : []
		if (!exceptions || origin.source === 'invalid' || kinds.some((kind) => kind.kind === 'exception')) {
			return { origin, mark: 'exception' }
		}
		if (kinds.some((kind) => kind.kind === 'pending')) {
			return { origin, mark: 'pending' }
		}
		const repeated = Object.fromEntries(operations.map((operation) => {
			const index = individual.indexOf(operation)
			return [operation, index < 0 ? origins?.[operation] : kinds[index].after || { source: 'default', groupIds: [] }]
		}))
		return { origin: levels.originOf(operations, repeated), mark: 'redundant' }
	}

	/** @return {string|null} the icon class of a mark: a person for an exception, a group for a group, none otherwise */
	function markIcon(origin, mark) {
		if (mark === 'exception') return 'icon-user'
		if (mark === 'pending') return null
		return origin.source === 'group' || origin.source === 'unknown' ? 'icon-group' : null
	}

	/**
	 * @return {string} where a cell's values come from, in words: a screen reader hears "Exception" where the eye sees the
	 *     person icon, and the inspector says the same
	 */
	function markText(origin, mark) {
		if (mark === 'pending') return t('mcp', 'Set for this person')
		if (mark === 'exception' && origin.source === 'individual') return t('mcp', 'Exception: differs from what the person inherits')
		if (mark === 'redundant') return originText(origin) + ' — ' + t('mcp', 'also set for this person, with the same value')
		return originText(origin)
	}

	/**
	 * The control of a level cell: it opens the popover of src/admin/admin-level-popover.js, where the level is chosen.
	 *
	 * @param {object} user row from the API
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {string} className classes of the button
	 * @param {Array<Node>} parts icon and word
	 * @param {string} title tooltip
	 * @param {string} label accessible name, which says the state in words
	 * @return {HTMLButtonElement}
	 */
	function levelButton(user, module, className, parts, title, label) {
		const button = el('button', { type: 'button', className, title }, parts)
		button.onclick = () => window.McpLevelPopover?.open(button, user.uid, module)
		button.setAttribute('aria-label', label)
		button.setAttribute('aria-haspopup', 'dialog')
		button.setAttribute('aria-expanded', 'false')
		return button
	}

	/**
	 * "Can connect" in the levels view: Yes or No in words, its origin as an icon like the module cells, and the popover to
	 * change it. A disabled account has no control, as in the operations view.
	 *
	 * @param {object} user row from the API
	 * @return {HTMLTableCellElement}
	 */
	function eligibleCell(user) {
		const cell = el('td', { className: 'mcp-cell mcp-level-cell' })
		cell.dataset.levelKey = 'eligible'
		const canConnect = t('mcp', 'Can connect')
		const reason = user.eligible === null ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : unavailableReason(user, null)
		if (reason !== null) {
			const unknown = user.eligible === null
			paintState(cell, 'unavailable', reason)
			const mark = el('span', { className: 'mcp-state-mark', textContent: unknown ? '—' : '⊘' })
			mark.setAttribute('aria-hidden', 'true')
			const word = el('span', { className: 'mcp-level-word', textContent: unknown ? t('mcp', 'Unknown') : t('mcp', 'Unavailable') })
			word.setAttribute('aria-hidden', 'true')
			cell.append(mark, ' ', word, el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + canConnect + ': ' + cell.title }))
			if (unknown) cell.append(el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: reloadLocked }))
			return cell
		}
		const { origin, mark: kind } = originMark(user, null, ['eligible'])
		const individual = kind === 'exception'
		const icon = markIcon(origin, kind)
		const parts = []
		if (icon !== null) {
			const glyph = el('span', { className: 'mcp-origin-icon ' + icon })
			glyph.setAttribute('aria-hidden', 'true')
			parts.push(glyph)
		}
		const word = user.eligible ? t('mcp', 'Yes') : t('mcp', 'No')
		parts.push(el('span', { className: 'mcp-level-word', textContent: word }))
		paintState(cell, user.eligible ? 'allowed' : 'denied')
		const source = markText(origin, kind)
		const button = levelButton(user, null, 'mcp-level mcp-level-' + (user.eligible ? 'read' : 'none') + (individual ? ' mcp-level-individual' : ''), parts,
			canConnect + ': ' + word + ' — ' + source,
			t('mcp', '{user}, {module}: {level}. {source}.', { user: user.displayName, module: canConnect, level: word, source }, undefined, { escape: false }))
		cell.title = button.title
		cell.dataset.level = user.eligible ? 'read' : 'none'
		cell.dataset.origin = origin.source
		cell.dataset.mark = kind
		cell.append(button)
		return cell
	}

	/** Seconds the undo of a bulk change stays offered; it waits while the pointer or focus is on it. */
	const UNDO_SECONDS = 10
	const notice = document.getElementById('mcp-bulk-notice')
	const noticeText = document.getElementById('mcp-bulk-notice-text')
	const undoButton = document.getElementById('mcp-bulk-undo')
	let noticeTimer = null
	let pendingUndo = null
	/** True while a bulk change or its undo saves and the matrix reloads: only one runs at a time. */
	let bulkBusy = false

	/**
	 * Locks the "All" menus and the undo while a bulk change or an undo is saving, so an older
	 * operation can never finish on top of a newer one's notice, undo or focus.
	 *
	 * @param {boolean} on whether an operation is running
	 */
	function setBulkBusy(on) {
		bulkBusy = on
		const reason = on ? t('mcp', 'Wait until the change in progress is saved.') : ''
		for (const button of root.querySelectorAll('.mcp-bulk, #mcp-bulk-undo')) {
			button.disabled = on
			button.setAttribute('aria-disabled', on ? 'true' : 'false')
			button.title = reason
		}
		// The undo window only runs while its button can be used: it stops during the lock and
		// starts again, in full, once the lock is released.
		if (on) {
			clearTimeout(noticeTimer)
		} else if (notice.classList.contains('mcp-notice-on')) {
			armNotice()
		}
	}

	/** Clears the notice and forgets the undo it offered. */
	function hideNotice() {
		clearTimeout(noticeTimer)
		pendingUndo = null
		notice.classList.remove('mcp-notice-on')
		noticeText.textContent = ''
		undoButton.hidden = true
	}

	/** Starts the countdown, unless the admin is on the notice or a bulk change is still running. */
	function armNotice() {
		clearTimeout(noticeTimer)
		if (!bulkBusy && !notice.matches(':hover') && !notice.contains(document.activeElement)) {
			noticeTimer = setTimeout(hideNotice, UNDO_SECONDS * 1000)
		}
	}

	/**
	 * Shows a message under the matrix, with an Undo button when an undo is given.
	 *
	 * @param {string} message text of the notice
	 * @param {Function|null} undo restores the previous values
	 */
	function showNotice(message, undo) {
		// A newer notice takes the single undo slot; say so instead of dropping the older undo silently.
		const replaced = pendingUndo !== null && pendingUndo !== undo
		pendingUndo = undo
		noticeText.textContent = replaced ? message + ' ' + t('mcp', 'The previous undo is no longer available.') : message
		undoButton.hidden = undo === null
		notice.classList.add('mcp-notice-on')
		armNotice()
	}

	notice.addEventListener('mouseenter', () => clearTimeout(noticeTimer))
	notice.addEventListener('focusin', () => clearTimeout(noticeTimer))
	notice.addEventListener('mouseleave', () => notice.classList.contains('mcp-notice-on') && armNotice())
	notice.addEventListener('focusout', (event) => {
		if (notice.classList.contains('mcp-notice-on') && !notice.contains(event.relatedTarget)) {
			armNotice()
		}
	})
	undoButton.addEventListener('click', () => {
		const undo = pendingUndo
		if (!undo || bulkBusy) {
			return
		}
		// The button disappears, so the focus moves to the notice text, which announces the outcome
		// and stays on screen while it has the focus.
		pendingUndo = null
		clearTimeout(noticeTimer)
		undoButton.hidden = true
		noticeText.focus({ preventScroll: true })
		undo()
	})

	/**
	 * What a bulk change would do: per operation, the users whose saved value differs from the target and that value; users
	 * with an unavailable or unknown cell are left out, users already set are counted.
	 *
	 * @param {string|null} module module id, null for eligibility
	 * @param {string[]} operationList operation ids, or ["eligible"]
	 * @param {boolean|Object<string, boolean>} granted the new value of every operation, or one per operation
	 * @param {string[]|null} [uids] the users to change, the whole page when null
	 * @return {{changes: Array<{operation: string, granted: boolean, previous: Map<string, boolean>}>, users: number, unavailable: number, unchanged: number}}
	 */
	function bulkPlan(module, operationList, granted, uids = null) {
		const target = (operation) => typeof granted === 'boolean' ? granted : granted[operation] === true
		const changes = operationList.map((operation) => ({ operation, granted: target(operation), previous: new Map() }))
		let users = 0
		let unavailable = 0
		let unchanged = 0
		for (const user of state.data.users) {
			if (uids !== null && !uids.includes(user.uid)) {
				continue
			}
			if (unavailableReason(user, module) !== null || operationList.some((operation) =>
				(module === null ? user.eligible : user.grants[module][operation]) === null)) {
				unavailable++
				continue
			}
			let changed = false
			for (const change of changes) {
				const current = module === null ? user.eligible : user.grants[module][change.operation]
				if (current !== change.granted) {
					change.previous.set(user.uid, current)
					changed = true
				}
			}
			changed ? users++ : unchanged++
		}
		return { changes: changes.filter((change) => change.previous.size > 0), users, unavailable, unchanged }
	}

	/**
	 * Saves a bulk change as one command: the server writes every cell or none, and returns the
	 * receipt that undoes exactly these cells.
	 *
	 * @param {string|null} module module id, null for eligibility
	 * @param {Array<{operation: string, uids: string[], granted: boolean}>} requests cells to save
	 * @return {Promise<object>} the saved command: {revision, changed, receiptId}
	 */
	async function sendCommand(module, requests) {
		const commands = requests.flatMap((request) => request.uids.map((uid) => ({
			uid,
			module,
			operation: request.operation,
			state: request.granted ? 'allow' : 'deny',
		})))
		const sent = state.data.revision
		const saved = await api('POST', '/api/grants/commands', { expectedRevision: sent, commands })
		announce(sent, saved.revision)
		return saved
	}

	/**
	 * The selection bar's "Set" (src/admin/admin-overrides.js): gives the selected users one level of a module, or turns "Can
	 * connect" on or off. It confirms exactly what changes, how many are left out and why, and how many already have it,
	 * sends nothing when nobody changes, saves one command and offers the undo. Every cell is an explicit allow or deny,
	 * never an inheritance, as the level popover does for a removal (F1).
	 *
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {string} level a level of src/admin/admin-levels.js; for "Can connect", "read" is on and "none" is off
	 * @param {string[]} uids the selected users of the page
	 */
	async function bulk(module, level, uids) {
		// The plan is computed from the table on screen, so never from one that is being replaced.
		if (bulkBusy || matrixLocked) {
			return
		}
		const operationList = module === null ? ['eligible'] : state.data.catalog[module]
		const allowed = module === null ? (level === 'read' ? ['eligible'] : []) : window.McpAdminLevels.operationsFor(module, operationList, level)
		const plan = bulkPlan(module, operationList, Object.fromEntries(operationList.map((operation) => [operation, allowed.includes(operation)])), uids)
		const levelName = module === null ? (level === 'read' ? t('mcp', 'Yes') : t('mcp', 'No')) : levelLabels()[level] || level
		const vars = { permission: module === null ? t('mcp', 'Can connect') : moduleLabels()[module] || module, level: levelName }
		const lines = []
		if (plan.unavailable > 0) {
			lines.push(n('mcp', '%n selected person is left out: this permission is unavailable or unknown for them.', '%n selected people are left out: this permission is unavailable or unknown for them.', plan.unavailable, vars, { escape: false }))
		}
		if (plan.unchanged > 0) {
			lines.push(n('mcp', '%n selected person already has it.', '%n selected people already have it.', plan.unchanged, vars, { escape: false }))
		}
		if (plan.users === 0) {
			showNotice([t('mcp', 'Nothing to change for the selected people: “{permission}” is already {level}.', vars, undefined, { escape: false }), ...lines].join(' '), null)
			return
		}
		const question = n('mcp', 'Set “{permission}” to {level} for %n selected person?', 'Set “{permission}” to {level} for %n selected people?', plan.users, vars, { escape: false })
		if (module === null && level !== 'read') {
			lines.push(t('mcp', 'They are signed out of their MCP clients, and undoing this does not sign them back in.'))
		}
		const mailWarning = module !== null && window.McpAdminMailGrants ? window.McpAdminMailGrants.writeWarning(plan.changes.filter((change) => change.granted).map((change) => ({ module, operation: change.operation }))) : null
		if (mailWarning) lines.push(mailWarning)
		if (!window.confirm([question, ...lines].join('\n'))) {
			return
		}
		const requests = plan.changes.map((change) => ({ operation: change.operation, uids: [...change.previous.keys()], granted: change.granted }))
		let undo = null
		let reloaded = false
		setBulkBusy(true)
		try {
			try {
				const saved = await sendCommand(module, requests)
				undo = saved.receiptId === null ? null : () => undoBulk(saved.receiptId, saved.revision)
				showNotice(n('mcp', '“{permission}” set to {level} for %n person.', '“{permission}” set to {level} for %n people.', plan.users, vars, { escape: false }), undo)
			} catch (e) {
				// The command is all or nothing, but an unreadable response cannot confirm its outcome.
				reloaded = await policyError(e)
				if (!reloaded) {
					notifyError(t('mcp', 'Could not update the selected people.'))
				}
			}
			if (!reloaded) {
				await reloadLocked()
			}
		} finally {
			setBulkBusy(false)
		}
		if (undo !== null && pendingUndo === undo) {
			// The focus goes to the undo, scrolling the notice under the table into view when it is below the fold.
			undoButton.focus()
		}
	}

	/**
	 * Undoes a bulk change through its receipt: the server puts back each cell's exact saved value,
	 * absence included, and refuses when anything changed since that change.
	 *
	 * @param {string} receiptId receipt of the saved command
	 * @param {string} savedRevision revision that command committed
	 */
	async function undoBulk(receiptId, savedRevision) {
		setBulkBusy(true)
		try {
			try {
				const undone = await api('POST', '/api/grants/undo', { receiptId, expectedRevision: savedRevision })
				announce(savedRevision, undone.revision)
				showNotice(t('mcp', 'Change undone.'), null)
			} catch (e) {
				if (e.message === '503' && !(e.data && e.data.uncertain)) {
					// Nothing was written (what the users inherit could not be read): the table stays as it is, and the same
					// undo is offered again instead of a reload that would hide that it is still possible.
					const retry = t('mcp', 'The change could not be undone because what these users inherit could not be read. Nothing was changed. Try again.')
					notifyError(retry)
					showNotice(retry, () => undoBulk(receiptId, savedRevision))
					return
				}
				const failure = e.message === '503' && e.data && e.data.uncertain
					? t('mcp', 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.')
					: e.message === '409'
						? t('mcp', 'The permissions changed after this change, so it can no longer be undone. The table was reloaded with the saved values.')
						: e.message === '410'
							? t('mcp', 'This change can no longer be undone: the time to undo it has passed.')
							: t('mcp', 'Could not undo the change. The table was reloaded with the saved values.')
				notifyError(failure)
				showNotice(failure, null)
			}
			await reloadLocked()
		} finally {
			setBulkBusy(false)
		}
	}

	serviceBox.addEventListener('change', async () => {
		const value = serviceBox.checked
		if (matrixLocked || state.data === null) {
			serviceBox.checked = !value
			return
		}
		serviceBox.disabled = true
		try {
			const sent = state.data.revision
			const result = await api('PUT', '/api/service', { enabled: value, expectedRevision: sent })
			// Committed from the revision on screen: the switch is the only thing that changed.
			state.data.revision = result.revision
			announce(sent, result.revision)
			state.data.serviceEnabled = result.enabled
			serviceBox.checked = result.enabled
			serviceStatus.textContent = t('mcp', 'Saved')
			flash(serviceStatus, true)
		} catch (e) {
			serviceBox.checked = !value
			serviceStatus.textContent = t('mcp', 'Not saved')
			flash(serviceStatus, false)
			// Enabled again first, so the reloaded page can paint the saved switch.
			serviceBox.disabled = false
			if (!(await policyError(e))) {
				notifyError(t('mcp', 'Could not change the MCP service.'))
			}
		} finally {
			serviceBox.disabled = false
		}
	})

	window.addEventListener('mcp-group-policy-saved', reloadLocked)
	window.addEventListener('mcp-scope-saved', reloadLocked)

	/**
	 * Keeps the control that takes the focus clear of the sticky columns of its row (the selection box and the name at the
	 * start, the "…" menu at the end, js/admin.css), and inside the block. The browser scrolls a focused control into the
	 * matrix block, but not out from under those columns, and it leaves one that shows in part where it is, so a cell
	 * reached by Tab could be hidden (WCAG 2.4.11). Measured on screen, so it holds at any width and in either direction.
	 *
	 * @param {FocusEvent} event focusin on the scrolling block of the matrix
	 */
	function revealFocus(event) {
		const wrap = event.currentTarget
		const target = event.target
		const cell = target?.closest?.('td, th')
		const row = cell?.parentElement
		if (!cell || !row) return
		const rect = target.getBoundingClientRect()
		// The sticky cells before this one in the row sit at its start side, the ones after it at its end side; which
		// physical side that is depends on the direction of the page.
		const rtl = getComputedStyle(row).direction === 'rtl'
		// The visible part of the block itself: the browser leaves a control that shows in part where it is.
		const block = wrap.getBoundingClientRect()
		let left = block.left + (wrap.clientLeft || 0)
		let right = left + wrap.clientWidth
		let before = true
		for (const other of row.children) {
			if (other === cell) {
				before = false
				continue
			}
			if (getComputedStyle(other).position !== 'sticky') continue
			const box = other.getBoundingClientRect()
			if (before !== rtl) left = Math.max(left, box.right)
			else right = Math.min(right, box.left)
		}
		if (rect.left < left) {
			wrap.scrollLeft -= left - rect.left + 4
		} else if (rect.right > right) {
			// A control wider than the room left keeps its start in view.
			wrap.scrollLeft += Math.min(rect.right - right + 4, rect.left - left)
		}
	}
	table.parentElement?.addEventListener('focusin', revealFocus)

	let searchTimer = null
	searchInput.addEventListener('input', () => {
		clearTimeout(searchTimer)
		searchTimer = setTimeout(() => {
			state.search = searchInput.value.trim()
			state.page = 1
			load()
		}, 300)
	})
	groupSelect.addEventListener('change', () => {
		state.group = groupSelect.value
		state.page = 1
		load()
	})
	// The matrix opens on the users who can connect; "Everyone" stays one choice away.
	filterSelect.value = state.filter
	filterSelect.addEventListener('change', () => {
		state.filter = filterSelect.value
		state.page = 1
		load()
	})
	for (const button of prevButtons) {
		button.addEventListener('click', () => {
			state.page = Math.max(1, state.page - 1)
			load()
		})
	}
	for (const button of nextButtons) {
		button.addEventListener('click', () => {
			state.page += 1
			load()
		})
	}

	for (const button of root.querySelectorAll('.mcp-copy')) {
		button.addEventListener('click', () => {
			const text = document.getElementById(button.dataset.copy).textContent
			navigator.clipboard.writeText(text).then(
				() => { button.textContent = t('mcp', 'Copied') },
				() => notifyError(t('mcp', 'Could not copy the endpoint.')),
			)
		})
	}


	async function initTagsSection() {
		const container = document.getElementById('mcp-tags-list')
		const warning = document.getElementById('mcp-tags-warning')
		const status = document.getElementById('mcp-tags-status')
		const invalid = document.getElementById('mcp-tags-invalid')
		const repair = document.getElementById('mcp-tags-repair')
		// The gravest state of the app is an unreadable exclusion setting (fail-closed: every file is
		// refused). The repair block lives in the Data protection tab, so the Status block of Overview
		// also shows it and the tab carries a marker.
		const overviewAlert = document.getElementById('mcp-tags-invalid-overview')
		const tabAlert = document.getElementById('mcp-tab-protection-alert')
		if (!container || !window.McpAdminPicker) {
			return
		}

		/** @param {boolean} isInvalid shows or hides every sign of the unreadable setting */
		function setInvalidState(isInvalid) {
			if (invalid) {
				invalid.style.display = isInvalid ? 'block' : 'none'
			}
			if (overviewAlert) {
				overviewAlert.hidden = !isInvalid
			}
			if (tabAlert) {
				tabAlert.hidden = !isInvalid
			}
		}

		let allTags = []

		/**
		 * The type badge of a tag option, with its explanation in the title.
		 *
		 * @param {string} type collaborative, restricted or invisible
		 * @return {{text: string, title: string, className: string}}
		 */
		function tagBadge(type) {
			if (type === 'invisible') {
				return { text: t('mcp', 'Invisible'), title: t('mcp', 'Visible and assignable only by administrators'), className: 'mcp-tag-type-invisible' }
			}
			if (type === 'restricted') {
				return { text: t('mcp', 'Restricted'), title: t('mcp', 'Visible to users, assignable only by administrators and the groups they allow'), className: 'mcp-tag-type-restricted' }
			}
			return { text: t('mcp', 'Collaborative'), title: t('mcp', 'Users can assign and remove this tag'), className: 'mcp-tag-type-collaborative' }
		}

		/** @param {string[]} selectedIds warns when a collaborative tag, removable by editors, is selected */
		function updateWarning(selectedIds) {
			if (!warning) {
				return
			}
			const hasCollab = allTags.some((tag) => tag.type === 'collaborative' && selectedIds.includes(String(tag.id)))
			warning.style.display = hasCollab ? 'block' : 'none'
		}

		/**
		 * PUT /api/admin/tags with the same payload as before. A failure is explained here and rethrown,
		 * so the picker rolls the selection back; on success the invalid-setting block goes away.
		 *
		 * @param {string[]} tagIds the ids to save
		 */
		async function saveTags(tagIds) {
			try {
				await api('PUT', '/api/admin/tags', { tagIds })
			} catch (e) {
				// 503: the system tags could not be checked and the server kept the previous setting.
				notifyError(e.message === '503'
					? t('mcp', 'Could not check the system tags, so the exclusion tags were not changed. Try again later.')
					: t('mcp', 'Could not update exclusion tags.'))
				throw e
			}
			setInvalidState(false)
		}

		try {
			const res = await api('GET', '/api/admin/tags')
			allTags = res.tags || []

			const picker = window.McpAdminPicker.create({
				container,
				status,
				options: allTags.map((tag) => ({ id: String(tag.id), label: tag.name, badge: tagBadge(tag.type) })),
				selectedIds: (res.selected || []).map(String),
				searchLabel: t('mcp', 'Search tags'),
				listLabel: t('mcp', 'Exclusion tags'),
				emptyOptionsText: t('mcp', 'No system tags found. Create tags in Files settings.'),
				noMatchText: t('mcp', 'No match.'),
				// An empty list turns the protection off; from an invalid setting that goes from hiding
				// everything to hiding nothing, so it is never saved without the administrator saying so.
				confirmSave: (nextIds) => nextIds.length > 0
					|| window.confirm(t('mcp', 'Saving with no exclusion tags turns the protection off: every file becomes visible to the MCP tools again. Continue?')),
				save: saveTags,
				onChange: updateWarning,
				clearSavedAfterMs: 2000,
				errorStatus: '',
			})

			setInvalidState(Boolean(res.invalid))
			if (res.invalid && repair) {
				repair.addEventListener('click', async () => {
					repair.disabled = true
					try {
						await picker.commit({ force: true })
					} finally {
						repair.disabled = false
					}
				})
			}

			updateWarning(picker.getSelected())
		} catch {
			container.setAttribute('aria-busy', 'false')
			container.replaceChildren(el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load system tags.') }))
		}
	}

	/**
	 * OAuth clients section: allowed client_id hosts, the native client switch and the self-hosted clients, each with
	 * its name, Enabled setting and redirect URIs, through GET and PUT /apps/mcp/api/oauth-clients. The saves of this
	 * block go one at a time, and each queues an intent (this client, this change), not a body: the list it sends is
	 * built when its turn comes, from the clients the server confirmed last, and carries the revision of that same
	 * answer. So two quick changes never resend a list from before the first one (turning B off cannot turn A back on).
	 * A page that is out of date (another window or administrator saved first) gets 409 and is rebuilt from the
	 * server; the intents queued behind the refused one are dropped with it, never sent on the reloaded page. A card
	 * with typed changes the server does not have (a new client, or a changed name or redirect URIs) stays on screen
	 * through that rebuild, marked as not saved, for the admin to check and save again. A save of another block of this
	 * page moves the revision too; that move is adopted (see announce()), so it never refuses a save here.
	 */
	async function initOauthClients() {
		const hostsInput = document.getElementById('mcp-oauth-hosts')
		const hostsSave = document.getElementById('mcp-oauth-hosts-save')
		const hostsStatus = document.getElementById('mcp-oauth-hosts-status')
		const nativeBox = document.getElementById('mcp-native-client')
		const nativeStatus = document.getElementById('mcp-native-client-status')
		const details = document.getElementById('mcp-native-client-details')
		const clientId = document.getElementById('mcp-native-client-id')
		const redirects = document.getElementById('mcp-native-redirects')
		const customList = document.getElementById('mcp-custom-clients')
		const customAdd = document.getElementById('mcp-custom-add')
		/** Self-hosted clients as the server last returned them; each card edits one of them (or a new one). */
		let customClients = []
		let cardCount = 0
		// The block's own revision, from its own GET or save; never the matrix's, never an error body's.
		let oauthRevision = null
		let queue = Promise.resolve()
		// Advances when the page is reloaded after a refused save: intents queued before that are dropped.
		let epoch = 0
		/** Per card: the client it was built from, and what is typed in it now. */
		const drafts = new WeakMap()
		/** The server's last answer: what is stored at this block's revision. */
		let confirmed = null
		followRevision(() => oauthRevision, (revision) => { oauthRevision = revision })

		/** @param {object} data settings returned by the API */
		function show(data) {
			oauthRevision = data.revision
			confirmed = data
			hostsInput.value = data.hosts.join(', ')
			nativeBox.checked = data.nativeClientEnabled
			details.hidden = !data.nativeClientEnabled
			clientId.textContent = data.nativeClientId
			redirects.replaceChildren(...data.nativeRedirectUris.map((uri) => el('li', {}, [el('code', { textContent: uri })])))
			customClients = data.customClients
		}

		/**
		 * @param {HTMLElement} status status span next to the control
		 * @param {boolean} ok whether the save worked
		 * @param {string} [message] text shown on failure
		 */
		function report(status, ok, message) {
			status.textContent = ok ? t('mcp', 'Saved') : (message || t('mcp', 'Not saved'))
			flash(status, ok)
		}

		/**
		 * Runs one task of the block after the previous one has finished.
		 *
		 * @param {Function} task async work
		 * @return {Promise<*>} what the task returned
		 */
		function enqueue(task) {
			const run = queue.then(task)
			queue = run.catch(() => {})
			return run
		}

		/**
		 * Sends one change when its turn comes: the body is built then by `build`, from the clients the server confirmed
		 * last, with the revision of that same answer, which is then replaced by the revision and clients the server
		 * answers with. The policy revision moved, so the matrix is reloaded to keep its own saves valid. An intent
		 * queued before a reload is dropped (error "stale"); a refused save drops the intents queued behind it.
		 *
		 * @param {Function} build (clients) => the changed settings
		 * @return {Promise<object>} the settings the server saved
		 */
		function put(build) {
			const queuedAt = epoch
			return enqueue(async () => {
				if (queuedAt !== epoch) {
					throw new Error('stale')
				}
				try {
					const sent = oauthRevision
					const change = build(customClients)
					const committed = changes(change, confirmed)
					const data = await api('PUT', '/api/oauth-clients', { ...change, expectedRevision: sent })
					oauthRevision = data.revision
					customClients = data.customClients
					confirmed = data
					if (committed && oneStep(sent, data.revision)) {
						announce(sent, data.revision)
					}
					load()
					return data
				} catch (e) {
					if (e.message === '409' || e.message === '428' || (e.message === '503' && e.data && e.data.uncertain)) {
						epoch++
					}
					throw e
				}
			})
		}

		/**
		 * Whether a request differs from the settings stored at the block's revision, compared the way the server compares
		 * them (OAuthClientsService): the server then commits it and the revision advances. Hosts are trimmed, lowercased
		 * (ASCII only) and without duplicates, and the default list is stored as no list (GrantsController::hosts,
		 * writeHosts); clients keep their order, with names and redirect URIs trimmed and URIs without duplicates
		 * (CustomClient::validate, validateUris). Every trim is PHP's own (phpTrim()). A value still holding a control
		 * character after it, which the server refuses, counts as unsure. False when unsure, so nothing is announced then.
		 *
		 * @param {object} change the settings sent (hosts, nativeClientEnabled or customClients)
		 * @param {object} stored the server's last answer
		 * @return {boolean}
		 */
		function changes(change, stored) {
			const control = (text) => /[\x00-\x1f\x7f]/.test(text)
			const uris = (list) => [...new Set(list.map(phpTrim))]
			if (change.nativeClientEnabled !== undefined && change.nativeClientEnabled !== stored.nativeClientEnabled) {
				return true
			}
			if (change.hosts !== undefined) {
				// strtolower() of PHP 8 changes A-Z only.
				const hosts = [...new Set(change.hosts.map((host) => phpTrim(host).replace(/[A-Z]/g, (letter) => letter.toLowerCase())))]
				if (hosts.some(control)) {
					return false
				}
				// Stored as no list when the request is the default set; only known while the default is what is stored.
				const isDefault = stored.hostsDefault && hosts.length === stored.hosts.length && hosts.every((host) => stored.hosts.includes(host))
				if (stored.hostsDefault ? !isDefault : hosts.join(',') !== stored.hosts.join(',')) {
					return true
				}
			}
			if (change.customClients !== undefined) {
				if (change.customClients.some((client) => control(phpTrim(client.name)) || uris(client.redirectUris).some(control))) {
					return false
				}
				const key = (clients) => JSON.stringify(clients.map((client) => [client.id ?? null, phpTrim(client.name), client.enabled, uris(client.redirectUris)]))
				if (key(change.customClients) !== key(stored.customClients)) {
					return true
				}
			}
			return false
		}

		/**
		 * When a save failed because the page was out of date (409, or 428 without a revision) or the server could not
		 * confirm it (503 uncertain), shows what is stored now, in the queue's order, and tells the admin nothing was
		 * overwritten. The cards with unsaved typed changes are kept (see keepUnsaved()). An intent dropped behind such a
		 * save is handled by that reload.
		 *
		 * @param {Error} e error thrown by put()
		 * @return {Promise<boolean>} whether the error was handled here (the settings are then reloaded)
		 */
		async function recover(e) {
			if (e.message === 'stale') {
				return true
			}
			if (e.message === '409' || e.message === '428') {
				notifyError(t('mcp', 'The OAuth client settings were changed in another window or by another administrator. The current settings were loaded; nothing was overwritten.'))
			} else if (e.message === '503' && e.data && e.data.uncertain) {
				notifyError(t('mcp', 'The server could not confirm whether the change was saved. The current settings were loaded.'))
			} else {
				return false
			}
			try {
				const unsaved = [...customList.children].filter((card) => drafts.get(card)?.edited())
				show(await enqueue(() => api('GET', '/api/oauth-clients')))
				customList.replaceChildren(...keepUnsaved(unsaved))
			} catch (reload) {
				notifyError(t('mcp', 'Could not load the OAuth client settings.'))
			}
			return true
		}

		/**
		 * The cards of the clients the server has now, with the cards whose typed changes it does not have put back: a
		 * saved client keeps its edited card in its place, a new card comes after the saved ones, and the edits of a
		 * client deleted elsewhere come back as a new card. Each card put back says it was not saved.
		 *
		 * @param {HTMLElement[]} unsaved cards with typed changes, read before the reload
		 * @return {HTMLElement[]} the cards to show
		 */
		function keepUnsaved(unsaved) {
			const kept = new Map(unsaved.filter((card) => drafts.get(card).client.id !== null).map((card) => [drafts.get(card).client.id, card]))
			const cards = customClients.map((client) => kept.get(client.id) || customCard(client))
			const extra = unsaved.filter((card) => !cards.includes(card)).map((card) => {
				if (drafts.get(card).client.id === null) {
					return card
				}
				const fresh = customCard({ ...drafts.get(card).read(), id: null })
				report(fresh.querySelector('.mcp-status'), false, t('mcp', 'Not saved: this client was deleted in the meantime. Your changes are kept here as a new client; saving adds it again.'))
				return fresh
			})
			for (const card of unsaved.filter((card) => cards.includes(card) || extra.includes(card))) {
				report(card.querySelector('.mcp-status'), false, t('mcp', 'Not saved: the settings changed after this page was loaded. Your changes are kept here; check them and save again.'))
			}
			return [...cards, ...extra]
		}

		/**
		 * Saves one change to the list of self-hosted clients.
		 *
		 * @param {Function} change (clients) => every client to keep, a new one without id; applied to the clients the
		 *     server confirmed last, when the save's turn comes
		 * @return {Promise<object|undefined>} the client the server added, if any
		 */
		async function saveCustom(change) {
			let before = new Set()
			await put((clients) => {
				before = new Set(clients.map((client) => client.id))
				return { customClients: change(clients).map(({ id, name, enabled, redirectUris }) => ({ id, name, enabled, redirectUris })) }
			})
			return customClients.find((client) => !before.has(client.id))
		}

		/**
		 * One self-hosted client: name, Enabled, client_id with Copy (once saved), redirect URIs, Save and Delete.
		 * Saving or deleting replaces only this card, so unsaved edits in the other cards stay.
		 *
		 * @param {object} client a stored client, or {id: null, ...} for a new one
		 * @return {HTMLElement} the card
		 */
		function customCard(client) {
			const n = ++cardCount
			const saved = client.id !== null
			const name = el('input', { type: 'text', id: 'mcp-custom-name-' + n, value: client.name, maxLength: 64, autocomplete: 'off', spellcheck: false })
			const enabled = el('input', { type: 'checkbox', className: 'checkbox', id: 'mcp-custom-enabled-' + n, checked: client.enabled })
			const uris = el('textarea', {
				id: 'mcp-custom-uris-' + n, className: 'mcp-custom-redirects', rows: 3, spellcheck: false, autocomplete: 'off',
				placeholder: 'https://harness.example.com/oauth/callback', value: client.redirectUris.join('\n'),
			})
			const status = el('span', { className: 'mcp-status' })
			status.setAttribute('aria-live', 'polite')
			const save = el('button', { type: 'button', textContent: t('mcp', 'Save') })
			const remove = el('button', { type: 'button', textContent: t('mcp', 'Delete') })
			const rows = [
				el('p', {}, [el('label', { htmlFor: name.id, textContent: t('mcp', 'Name') }), ' ', name]),
				el('p', {}, [enabled, el('label', { htmlFor: enabled.id, textContent: t('mcp', 'Enabled') })]),
			]
			if (saved) {
				const code = el('code', { textContent: client.clientId })
				const copy = el('button', { type: 'button', className: 'mcp-copy', textContent: t('mcp', 'Copy') })
				copy.addEventListener('click', () => {
					navigator.clipboard.writeText(client.clientId).then(
						() => { copy.textContent = t('mcp', 'Copied') },
						() => notifyError(t('mcp', 'Could not copy the client_id.')),
					)
				})
				rows.push(el('p', {}, ['client_id: ', code, ' ', copy]))
			}
			rows.push(
				el('p', {}, [el('label', { htmlFor: uris.id, textContent: t('mcp', 'Accepted redirect URIs') })]),
				uris,
				el('p', { className: 'settings-hint mcp-hint', textContent: t('mcp', 'One URI per line, exactly as the client sends it, port included: https (http only for 127.0.0.1, localhost or [::1]), no wildcard or fragment, up to 2000 bytes.') }),
				el('p', {}, [save, ' ', remove, ' ', status]),
			)
			const card = el('div', { className: 'mcp-custom-client' }, rows)

			/** @return {object} the client as edited in the card */
			function read() {
				return {
					id: client.id,
					name: name.value.trim(),
					enabled: enabled.checked,
					redirectUris: uris.value.split('\n').map((uri) => uri.trim()).filter((uri) => uri !== ''),
				}
			}

			/** @return {boolean} whether the card holds what the server does not have: any new card, or a changed name or URI list */
			function edited() {
				const entry = read()
				return !saved || entry.name !== client.name || entry.redirectUris.join('\n') !== client.redirectUris.join('\n')
			}
			drafts.set(card, { client, read, edited })

			save.addEventListener('click', async () => {
				const entry = read()
				save.disabled = true
				try {
					const added = await saveCustom((clients) => saved
						? clients.map((other) => other.id === client.id ? entry : other)
						: [...clients, entry])
					const next = customCard(saved ? customClients.find((other) => other.id === client.id) : added)
					card.replaceWith(next)
					next.querySelector('.mcp-status').textContent = t('mcp', 'Saved')
					flash(next.querySelector('.mcp-status'), true)
				} catch (e) {
					if (!(await recover(e))) {
						report(status, false, t('mcp', 'Not saved: each client needs a name of up to 64 characters that no other client has, and 1 to 20 redirect URIs (absolute https URIs, without user name, wildcard or fragment).'))
					}
				} finally {
					save.disabled = false
				}
			})

			// A saved client's Enabled setting applies at once, like the native client's; a new one's is saved with it.
			enabled.addEventListener('change', async () => {
				if (!saved) {
					return
				}
				const value = enabled.checked
				enabled.disabled = true
				try {
					await saveCustom((clients) => clients.map((other) => other.id === client.id ? { ...other, enabled: value } : other))
					report(status, true)
				} catch (e) {
					enabled.checked = !value
					if (!(await recover(e))) {
						report(status, false)
						notifyError(t('mcp', 'Could not change the self-hosted client.'))
					}
				} finally {
					enabled.disabled = false
				}
			})

			remove.addEventListener('click', async () => {
				if (!saved) {
					card.remove()
					return
				}
				if (!window.confirm(t('mcp', 'Delete the client “{name}”? Its connections are signed out at once.', { name: client.name }))) {
					return
				}
				remove.disabled = true
				try {
					await saveCustom((clients) => clients.filter((other) => other.id !== client.id))
					card.remove()
				} catch (e) {
					if (!(await recover(e))) {
						report(status, false)
						notifyError(t('mcp', 'Could not delete the self-hosted client.'))
					}
				} finally {
					remove.disabled = false
				}
			})
			return card
		}

		try {
			show(await api('GET', '/api/oauth-clients'))
		} catch (e) {
			notifyError(t('mcp', 'Could not load the OAuth client settings.'))
			return
		}
		customList.replaceChildren(...customClients.map(customCard))
		customAdd.addEventListener('click', () => {
			const card = customCard({ id: null, name: '', enabled: true, redirectUris: [] })
			customList.append(card)
			card.querySelector('input[type="text"]').focus()
		})

		hostsSave.addEventListener('click', async () => {
			const hosts = hostsInput.value.split(',').map((host) => host.trim().toLowerCase()).filter((host) => host !== '')
			hostsSave.disabled = true
			try {
				show(await put(() => ({ hosts })))
				report(hostsStatus, true)
			} catch (e) {
				if (!(await recover(e))) {
					report(hostsStatus, false, t('mcp', 'Invalid host list: use lowercase host names only, without https://, port, path or *, and keep at least one.'))
				}
			} finally {
				hostsSave.disabled = false
			}
		})

		nativeBox.addEventListener('change', async () => {
			const value = nativeBox.checked
			nativeBox.disabled = true
			try {
				show(await put(() => ({ nativeClientEnabled: value })))
				report(nativeStatus, true)
			} catch (e) {
				nativeBox.checked = !value
				if (!(await recover(e))) {
					report(nativeStatus, false)
					notifyError(t('mcp', 'Could not change the native client.'))
				}
			} finally {
				nativeBox.disabled = false
			}
		})
	}

	/**
	 * Checkout upload limit of the status block, in whole MiB, through GET and PUT /apps/mcp/api/checkout-limit.
	 * Next to it: the effective limit and PHP's post_max_size ceiling.
	 */
	async function initCheckoutLimit() {
		const input = document.getElementById('mcp-checkout-limit')
		const save = document.getElementById('mcp-checkout-limit-save')
		const status = document.getElementById('mcp-checkout-limit-status')
		const info = document.getElementById('mcp-checkout-limit-info')
		const mib = (bytes) => Math.round(bytes / 1048576 * 10) / 10

		/** @param {object} data limit returned by the API, in bytes */
		function show(data) {
			// Round up and never show 0: a stored value below 1 MiB would fail the field's own min of 1 on the next save.
			input.value = String(Math.max(1, Math.ceil(data.configuredBytes / 1048576)))
			const effective = t('mcp', 'Effective limit: {size} MiB.', { size: mib(data.effectiveBytes) })
			const php = data.phpBytes > 0
				? t('mcp', 'PHP allows up to {size} MiB (post_max_size).', { size: mib(data.phpBytes) })
				: t('mcp', 'PHP sets no request size limit.')
			info.textContent = effective + ' ' + php
		}

		try {
			show(await api('GET', '/api/checkout-limit'))
		} catch (e) {
			info.textContent = t('mcp', 'Could not load the checkout upload limit.')
			return
		}

		save.addEventListener('click', async () => {
			const value = Number(input.value)
			if (!Number.isInteger(value) || value < 1) {
				status.textContent = t('mcp', 'Enter a whole number of MiB, at least 1.')
				flash(status, false)
				return
			}
			save.disabled = true
			try {
				show(await api('PUT', '/api/checkout-limit', { mib: value }))
				status.textContent = t('mcp', 'Saved')
				flash(status, true)
			} catch (e) {
				status.textContent = t('mcp', 'Not saved')
				flash(status, false)
				notifyError(t('mcp', 'Could not save the checkout upload limit.'))
			} finally {
				save.disabled = false
			}
		})
	}

	/**
	 * Server log section: the switch that hides the text of log messages in the log tools, through GET and PUT
	 * /apps/mcp/api/logs-privacy.
	 */
	async function initLogsPrivacy() {
		const box = document.getElementById('mcp-logs-hide-file-names')
		const status = document.getElementById('mcp-logs-hide-file-names-status')
		if (!box) {
			return
		}
		// The "Learn more" button opens the explanation that ships in the template; without JS it stays open.
		const more = document.getElementById('mcp-logs-privacy-more')
		const detail = document.getElementById('mcp-logs-privacy-detail')
		if (more && detail) {
			detail.hidden = true
			more.hidden = false
			more.addEventListener('click', () => {
				detail.hidden = !detail.hidden
				more.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true')
			})
		}
		try {
			box.checked = (await api('GET', '/api/logs-privacy')).hideFileNames
		} catch (e) {
			box.disabled = true
			notifyError(t('mcp', 'Could not load the log privacy setting.'))
			return
		}
		box.addEventListener('change', async () => {
			const value = box.checked
			box.disabled = true
			try {
				box.checked = (await api('PUT', '/api/logs-privacy', { hideFileNames: value })).hideFileNames
				status.textContent = t('mcp', 'Saved')
				flash(status, true)
			} catch (e) {
				box.checked = !value
				status.textContent = t('mcp', 'Not saved')
				flash(status, false)
				notifyError(t('mcp', 'Could not change the log privacy setting.'))
			} finally {
				box.disabled = false
			}
		})
	}

	/**
	 * Server log section: the groups whose members may read the log besides the administrators, through
	 * GET and PUT /apps/mcp/api/logs-access. The picker locks while a save runs and each save carries
	 * the list it changed (`previous`), so the server answers 409 when another window or administrator
	 * saved first and a removed group is never put back. A listed group that was deleted shows as an
	 * option marked "(group removed)", to be removed.
	 */
	async function initLogsAccess() {
		const container = document.getElementById('mcp-logs-groups')
		const status = document.getElementById('mcp-logs-status')
		const summary = document.getElementById('mcp-logs-summary')
		if (!container || !window.McpAdminPicker) {
			return
		}
		let version = 0
		// The block's own revision, from its own GET or save; never the matrix's, never an error body's.
		let logsRevision = null
		let picker = null
		followRevision(() => logsRevision, (revision) => { logsRevision = revision })

		/** @param {object} data state returned by the API */
		function show(data) {
			version = data.version
			logsRevision = data.revision
			const options = data.allGroups.map((group) => ({ id: group.id, label: group.displayName }))
			for (const id of data.missing) {
				options.push({ id: id, label: t('mcp', '{group} (group removed)', { group: id }, undefined, { escape: false }) })
			}
			if (!picker) {
				picker = window.McpAdminPicker.create({
					container,
					status,
					options,
					selectedIds: data.groups,
					searchLabel: t('mcp', 'Search groups'),
					listLabel: t('mcp', 'Server log groups'),
					emptyOptionsText: t('mcp', 'No groups found; only administrators can read the log.'),
					noMatchText: t('mcp', 'No match.'),
					save: async (nextIds, previousIds) => {
						const sent = logsRevision
						// The server stores the groups as a set and commits only a set other than `previous` (LogsAccess).
						const committed = new Set(nextIds).size !== new Set(previousIds).size || nextIds.some((id) => !previousIds.includes(id))
						const data = await api('PUT', '/api/logs-access', { groups: nextIds, previous: previousIds, version: version, expectedRevision: sent })
						if (committed && oneStep(sent, data.revision)) {
							announce(sent, data.revision)
						}
						return data
					},
					onSaved: async (data) => {
						show(data)
						// The matrix shows the log column per user from the same gate, so it is reloaded.
						load()
					},
					onError: async (e) => {
						if (e.message === '409' || e.message === '428') {
							notifyError(t('mcp', 'The groups were changed in another window or by another administrator; the current list was loaded.'))
							load()
						} else {
							notifyError(t('mcp', 'Could not save the groups that may read the server log.'))
						}
						await reload()
					},
				})
			} else {
				picker.setOptions(options, data.groups)
			}
			paintSummary(data.groups, data.allGroups.map((group) => ({ id: group.id, label: group.displayName })))
		}

		/**
		 * The effective readers, as one line: groups are a gate on top of the per-user grant of the matrix.
		 *
		 * @param {string[]} groups the saved group ids
		 * @param {Array<{id: string, label: string}>} options the known groups, for their display names
		 */
		function paintSummary(groups, options) {
			if (!summary) {
				return
			}
			const readers = options.filter((option) => groups.includes(option.id))
			const names = readers.map((option) => option.label).join(', ')
			try {
				summary.textContent = readers.length === 0
					? t('mcp', 'Who may read the log: administrators only. Each of them also needs the “Server log” permission in the “Access and permissions” tab.')
					: n('mcp',
						'Who may read the log: administrators and the members of %n group ({names}). Each of them also needs the “Server log” permission in the “Access and permissions” tab.',
						'Who may read the log: administrators and the members of %n groups ({names}). Each of them also needs the “Server log” permission in the “Access and permissions” tab.',
						readers.length, { names }, { escape: false })
			} catch {
				// Optional explanatory text must never prevent managing the actual groups.
				summary.textContent = ''
			}
		}

		/** Loads the list again, after a conflict or a failure. */
		async function reload() {
			try {
				show(await api('GET', '/api/logs-access'))
			} catch (e) {
				if (picker) {
					picker.showFallback(t('mcp', 'Could not load the groups.'))
				} else {
					container.setAttribute('aria-busy', 'false')
					container.replaceChildren(el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load the groups.') }))
				}
			}
		}

		await reload()
	}

	/**
	 * What src/admin/admin-overrides.js needs from the matrix: the page on screen, the shared request, lock and undo notice,
	 * and a re-render, so it can paint rows that were drawn before it loaded. It never touches the matrix state itself.
	 */
	window.McpAdminMatrix = {
		data: () => state.data,
		api,
		announce,
		reload: reloadLocked,
		policyError,
		notifyError,
		isBusy: () => bulkBusy || matrixLocked,
		setBusy: setBulkBusy,
		labels: () => ({ modules: moduleLabels(), operations: operationLabels() }),
		/**
		 * @param {string} message text of the notice
		 * @param {{receiptId: string|null, revision: string}} saved the saved command
		 * @return {Function|null} the undo that was offered, to give back to focusUndo()
		 */
		offerUndo(message, saved) {
			const undo = saved.receiptId === null ? null : () => undoBulk(saved.receiptId, saved.revision)
			showNotice(message, undo)
			return undo
		},
		/** @param {Function|null} undo what offerUndo() returned: the focus moves to it while it is still the offered undo */
		focusUndo(undo) {
			if (undo !== null && pendingUndo === undo) {
				undoButton.focus()
			}
		},
		refresh: () => state.data !== null && render(),
		bulk,
		levelLabels,
	}

	load()
	initTagsSection()
	initOauthClients()
	initCheckoutLimit()
	initLogsAccess()
	initLogsPrivacy()
})()
;
/* admin/admin-level-popover.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * The popover of a cell of the levels view: choose a module's level (None, Read, Edit, Full), give the module back to
 * inheritance, or set each operation to Inherit, Allow or Deny; for "Can connect", Yes, No or Inherit.
 *
 * Every choice is one POST /apps/mcp/api/grants/commands, all or nothing, from the revision of the table on screen, and the
 * notice under the table offers the server receipt as the undo, like the bulk changes. A level is presentation only: the
 * command carries one cell per operation whose value changes. When the popover opens it asks the read-only preview
 * (POST /apps/mcp/api/grants/commands/preview) what the person would inherit without their individual values.
 *
 * An operation the chosen level takes away is always sent as an explicit deny, never as "inherit": the revision does not
 * freeze group membership (adding a member to a group does not have to move it), so a group that grants the operation by
 * the time the command lands would keep the access the administrator meant to remove. Only an operation the level allows
 * may go back to inheritance, and only when the preview says the inheritance allows it too; if the inheritance changes
 * afterwards, that can only take access away, which is the safe side. Without that answer it is sent as allow. The
 * "Inherit from groups and defaults" button is the one explicit choice of inheritance, and sends "inherit" as it says.
 *
 * Taking "Can connect" away signs the person out of every MCP client, so it is asked first, in words, and nothing is sent
 * without that confirmation. An unknown "after" counts as taken away.
 *
 * Plain browser JavaScript, no build step. src/admin/admin-grants.js opens it from a level cell through window.McpLevelPopover and
 * exposes window.McpAdminMatrix; this file never touches the matrix state itself.
 */
(function () {
	'use strict'

	const matrix = window.McpAdminMatrix
	const levels = window.McpAdminLevels
	const popover = document.getElementById('mcp-level-popover')
	if (!matrix || !levels || !popover) {
		return
	}

	/** Individual sources of the API; anything else is inherited. */
	const INDIVIDUAL = ['individual_allow', 'individual_deny', 'invalid']

	/** The open popover: {uid, module, trigger, inherited, loading, unavailable, saving}, or null. */
	let current = null

	function el(tag, props, children) {
		const node = document.createElement(tag)
		Object.assign(node, props || {})
		for (const child of children || []) {
			node.append(child)
		}
		return node
	}

	/** @return {object|undefined} the user of the table on screen */
	function userOf(uid) {
		return matrix.data()?.users.find((user) => user.uid === uid)
	}

	/** @return {string[]} the operations of a module, or ["eligible"] for "Can connect" (module null) */
	function operationsOf(module) {
		return module === null ? ['eligible'] : matrix.data().catalog[module]
	}

	/** @return {boolean|null} the effective value of one operation */
	function valueOf(user, module, operation) {
		return module === null ? user.eligible : user.grants[module][operation]
	}

	/** @return {{source: string, groupIds: string[]}} the origin of one operation */
	function originOfCell(user, module, operation) {
		const origin = module === null ? user.origins?.eligible : user.origins?.grants?.[module]?.[operation]
		return origin || { source: 'default', groupIds: [] }
	}

	/**
	 * What each operation would be without the person's individual values: the preview's "after" for an individual cell,
	 * the value on screen for any other. null when the preview has not answered (or could not).
	 *
	 * @param {object} user row of the table
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {Array<object>|null} cells the preview's cells, null without an answer
	 * @return {Object<string, {allowed: boolean|null, source: string, groupIds: string[]}>|null}
	 */
	function inheritedValues(user, module, cells) {
		if (cells === null) {
			return null
		}
		const out = {}
		for (const operation of operationsOf(module)) {
			const origin = originOfCell(user, module, operation)
			if (!INDIVIDUAL.includes(origin.source)) {
				out[operation] = { allowed: valueOf(user, module, operation), source: origin.source, groupIds: origin.groupIds || [] }
				continue
			}
			const answer = cells.find((cell) => cell.uid === user.uid && (cell.module ?? null) === module && cell.operation === operation)
			const after = answer?.after
			out[operation] = after ? { allowed: after.allowed ?? null, source: after.source, groupIds: after.groupIds || [] } : { allowed: null, source: 'unknown', groupIds: [] }
		}
		return out
	}

	/**
	 * The commands that give a set of target values: one per operation whose value changes. Taking an operation away is
	 * always an explicit deny. Giving one is "inherit" only when it is an individual value and the preview says the person
	 * inherits it allowed, otherwise an explicit allow: a later change of the inheritance can then only narrow the access.
	 *
	 * @param {object} user row of the table
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {Object<string, boolean>} target value wanted per operation
	 * @param {object|null} inherited what inheritedValues() returned
	 * @return {Array<{uid: string, module: string|null, operation: string, state: string}>}
	 */
	function planTarget(user, module, target, inherited) {
		const commands = []
		for (const operation of operationsOf(module)) {
			const want = target[operation]
			if (want === undefined || valueOf(user, module, operation) === want) {
				continue
			}
			if (!want) {
				commands.push({ uid: user.uid, module, operation, state: 'deny' })
				continue
			}
			const back = inherited?.[operation]?.allowed === true && INDIVIDUAL.includes(originOfCell(user, module, operation).source)
			commands.push({ uid: user.uid, module, operation, state: back ? 'inherit' : 'allow' })
		}
		return commands
	}

	/** @return {Array<object>} the commands that give a module one level */
	function planLevel(user, module, level, inherited) {
		const operations = operationsOf(module)
		const allowed = levels.operationsFor(module, operations, level)
		return planTarget(user, module, Object.fromEntries(operations.map((operation) => [operation, allowed.includes(operation)])), inherited)
	}

	/** @return {Array<object>} the commands that give every individual value of a module back to inheritance */
	function planInherit(user, module) {
		return operationsOf(module)
			.filter((operation) => INDIVIDUAL.includes(originOfCell(user, module, operation).source))
			.map((operation) => ({ uid: user.uid, module, operation, state: 'inherit' }))
	}

	/** @return {Array<object>} the command that sets one operation to inherit, allow or deny, or none when it already is */
	function planOperation(user, module, operation, state) {
		const source = originOfCell(user, module, operation).source
		const now = source === 'individual_allow' ? 'allow' : source === 'individual_deny' || source === 'invalid' ? 'deny' : 'inherit'
		return now === state ? [] : [{ uid: user.uid, module, operation, state }]
	}

	/**
	 * Whether the commands take "Can connect" away from someone who has it now; an inherit whose result is unknown counts.
	 *
	 * @param {object} user row of the table
	 * @param {Array<object>} commands the commands to send
	 * @param {object|null} inherited what inheritedValues() returned for "Can connect"
	 * @return {boolean}
	 */
	function losesEligibility(user, commands, inherited) {
		if (user.eligible !== true) {
			return false
		}
		return commands.some((command) => command.module === null && (command.state === 'deny'
			|| (command.state === 'inherit' && inherited?.eligible?.allowed !== true)))
	}

	/** @return {string} module name, or "Can connect" */
	function permissionName(module) {
		return module === null ? t('mcp', 'Can connect') : matrix.labels().modules[module] || module
	}

	/** @return {string} name of a level of src/admin/admin-levels.js, or Yes / No for "Can connect" */
	function levelName(module, level) {
		if (module === null) {
			return level === 'read' ? t('mcp', 'Yes') : t('mcp', 'No')
		}
		return { ...window.McpAdminPollsGrants?.levelLabels(), forms_write: t('mcp', 'Read and write forms'), forms_write_results: t('mcp', 'Read and write forms and read responses'), none: t('mcp', 'None'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom'), unknown: t('mcp', 'Unknown') }[level] || level
	}

	/** @return {string} the level these values are, for any module, "Can connect" included */
	function levelOfValues(module, values) {
		if (module === null) {
			return values.eligible === null || values.eligible === undefined ? 'unknown' : values.eligible ? 'read' : 'none'
		}
		return levels.levelOf(module, operationsOf(module), values)
	}

	/** @return {string} where an inherited value comes from */
	function sourceName(origin) {
		if (origin.source === 'group') return t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') }, undefined, { escape: false })
		if (origin.source === 'unknown') return t('mcp', 'Unknown')
		return t('mcp', 'Default')
	}

	/** @return {string} the "Inherited" line of the popover */
	function inheritedLine() {
		if (current.loading) return t('mcp', 'Checking what this person inherits…')
		if (current.unavailable || current.inherited === null) return t('mcp', 'What this person inherits could not be read.')
		const values = Object.fromEntries(Object.entries(current.inherited).map(([operation, value]) => [operation, value.allowed]))
		const level = levelOfValues(current.module, values)
		const sources = Object.values(current.inherited)
		const group = sources.find((value) => value.source === 'group')
		const origin = sources.some((value) => value.source === 'unknown') ? { source: 'unknown', groupIds: [] } : group || { source: 'default', groupIds: [] }
		return t('mcp', 'Inherits: {level} ({source})', { level: levelName(current.module, level), source: sourceName(origin) }, undefined, { escape: false })
	}

	/** @return {string} description of a level: the operations it allows */
	function levelDescription(module, level) {
		if (module === null) {
			return level === 'read' ? t('mcp', 'The person may connect an MCP client.') : t('mcp', 'The person may not connect, and is signed out.')
		}
		const names = matrix.labels().operations
		const allowed = levels.operationsFor(module, operationsOf(module), level)
		return allowed.length === 0 ? t('mcp', 'The tools of this app stay hidden.') : allowed.map((operation) => names[operation] || operation).join(', ')
	}

	/** A pressed button carries a check mark besides aria-pressed, so the choice never depends on colour. */
	function stateButton(text, pressed, onclick) {
		const children = []
		if (pressed) {
			const mark = el('span', { className: 'mcp-pressed-mark', textContent: '✓ ' })
			mark.setAttribute('aria-hidden', 'true')
			children.push(mark)
		}
		children.push(text)
		const button = el('button', { type: 'button', className: 'mcp-tri', disabled: current.saving, onclick }, children)
		button.setAttribute('aria-pressed', String(pressed))
		return button
	}

	/** @return {HTMLElement|null} the control of the popover drawn with this focus key */
	function findFocusKey(node, key) {
		for (const child of node.children || []) {
			if (typeof child === 'string') continue
			if (child.dataset?.focusKey === key) return child
			const found = findFocusKey(child, key)
			if (found) return found
		}
		return null
	}

	/** Draws the popover again, keeping the focus on the same control. */
	function render() {
		// Drawing again replaces every control: the one that had the focus gets it back, so the answer of the preview
		// arriving while the administrator moves through the popover never drops the focus on the page.
		const active = document.activeElement
		const focusKey = active && popover.contains(active) ? active.dataset?.focusKey ?? null : null
		draw()
		const target = focusKey === null ? null : findFocusKey(popover, focusKey)
		if (target && !target.disabled) {
			target.focus()
		} else if (focusKey !== null) {
			(popover.querySelector('input:checked') || popover.querySelector('input'))?.focus()
		}
	}

	/** Draws the popover for the user and module open, from the table on screen. */
	function draw() {
		const user = userOf(current.uid)
		if (!user) {
			close(false)
			return
		}
		const module = current.module
		const operations = operationsOf(module)
		const values = Object.fromEntries(operations.map((operation) => [operation, valueOf(user, module, operation)]))
		const level = levelOfValues(module, values)
		const choices = module === null ? ['read', 'none'] : levels.levels(module, operations)
		const title = el('h3', { id: 'mcp-level-popover-title', textContent: module === null
			? t('mcp', '“Can connect” for {user}', { user: user.displayName }, undefined, { escape: false })
			: t('mcp', '{module} of {user}', { module: permissionName(module), user: user.displayName }, undefined, { escape: false }) })
		const closer = el('button', { type: 'button', className: 'mcp-popover-close', textContent: '✕', onclick: () => close(true) })
		closer.dataset.focusKey = 'close'
		closer.setAttribute('aria-label', t('mcp', 'Close'))
		const inherit = el('p', { className: 'mcp-popover-inherit', textContent: inheritedLine() })
		inherit.setAttribute('aria-live', 'polite')

		const group = el('div', { className: 'mcp-popover-levels' })
		group.setAttribute('role', 'radiogroup')
		group.setAttribute('aria-labelledby', 'mcp-level-popover-title')
		for (const choice of choices) {
			const id = 'mcp-level-choice-' + choice
			const radio = el('input', { type: 'radio', name: 'mcp-level-choice', id, value: choice, checked: choice === level, disabled: current.saving })
			radio.dataset.focusKey = 'level:' + choice
			radio.addEventListener('change', () => save(planLevel(user, module, choice, current.inherited),
				t('mcp', '{permission} of {user}: {level}.', { permission: permissionName(module), user: user.displayName, level: levelName(module, choice) }, undefined, { escape: false })))
			group.append(el('label', { htmlFor: id, className: 'mcp-popover-choice' }, [radio, el('span', {}, [
				el('strong', { textContent: levelName(module, choice) }),
				el('span', { className: 'mcp-popover-hint', textContent: levelDescription(module, choice) }),
			])]))
		}
		const body = [title, closer, inherit, group]
		if (level === 'custom') {
			body.push(el('p', { className: 'mcp-popover-hint', textContent: t('mcp', 'Custom: the allowed operations are no level. See them under Operations.') }))
		}
		const individual = planInherit(user, module)
		const inheritAll = el('button', {
			type: 'button',
			className: 'mcp-popover-inherit-all',
			disabled: current.saving || individual.length === 0,
			textContent: t('mcp', 'Inherit from groups and defaults'),
			onclick: () => save(individual, t('mcp', '{permission} of {user} inherits again.', { permission: permissionName(module), user: user.displayName }, undefined, { escape: false })),
		})
		inheritAll.dataset.focusKey = 'inherit'
		body.push(inheritAll)
		if (module !== null) {
			body.push(operationsBlock(user, module, operations))
		}
		body.push(el('p', { className: 'mcp-popover-hint', textContent: t('mcp', 'Saved when chosen. You can undo for 10 seconds.') }))
		popover.replaceChildren(...body)
	}

	/** The advanced block: every operation of the module with Inherit, Allow and Deny. */
	function operationsBlock(user, module, operations) {
		const names = matrix.labels().operations
		const rows = operations.map((operation) => {
			const source = originOfCell(user, module, operation).source
			const name = names[operation] || operation
			const make = (state, text, pressed) => {
				const button = stateButton(text, pressed, () => save(planOperation(user, module, operation, state),
					t('mcp', '{permission} of {user}: {operation} set to {state}.', { permission: permissionName(module), user: user.displayName, operation: name, state: text }, undefined, { escape: false })))
				button.dataset.focusKey = 'operation:' + operation + ':' + state
				return button
			}
			const inherited = current.inherited?.[operation]
			const hint = inherited === undefined || inherited.allowed === null
				? ''
				: inherited.allowed ? t('mcp', 'inherits allowed') : t('mcp', 'inherits denied')
			const group = el('span', { className: 'mcp-tri-group' }, [
				make('inherit', t('mcp', 'Inherit'), !INDIVIDUAL.includes(source)),
				make('allow', t('mcp', 'Allow'), source === 'individual_allow'),
				make('deny', t('mcp', 'Deny'), source === 'individual_deny' || source === 'invalid'),
			])
			group.setAttribute('role', 'group')
			group.setAttribute('aria-label', name)
			return el('div', { className: 'mcp-popover-operation' }, [el('span', { className: 'mcp-popover-operation-name', textContent: name }),
				el('span', { className: 'mcp-popover-hint', textContent: hint }), group])
		})
		const summary = el('summary', { textContent: t('mcp', 'Operations') })
		summary.dataset.focusKey = 'operations'
		const details = el('details', { className: 'mcp-popover-operations', open: current.operationsOpen === true }, [summary, ...rows])
		details.addEventListener('toggle', () => {
			if (current) current.operationsOpen = details.open
		})
		return details
	}

	/**
	 * Sends the commands as one, confirming first when "Can connect" is taken away, and offers the receipt as the undo. The
	 * matrix is reloaded afterwards with every write blocked until it is on screen.
	 *
	 * @param {Array<object>} commands what planLevel(), planInherit() or planOperation() returned
	 * @param {string} message the notice that names what was saved
	 * @return {Promise<void>}
	 */
	async function save(commands, message) {
		if (current === null || current.saving || matrix.isBusy()) {
			return
		}
		const open = current
		const user = userOf(open.uid)
		if (commands.length === 0) {
			close(true)
			matrix.offerUndo(t('mcp', 'Nothing changed.'), { receiptId: null, revision: matrix.data().revision })
			return
		}
		if (losesEligibility(user, commands, open.module === null ? open.inherited : null)
			&& !window.confirm(t('mcp', 'Turn off “Can connect” for {user}?', { user: user.displayName }, undefined, { escape: false }) + '\n'
				+ t('mcp', 'This signs the person out of every MCP client, and undoing it does not sign them back in.'))) {
			render()
			return
		}
		if (window.McpAdminMailGrants && !window.McpAdminMailGrants.confirmWrites(commands.filter((command) => command.state === 'allow'))) {
			render()
			return
		}
		open.saving = true
		render()
		matrix.setBusy(true)
		let reloaded = false
		try {
			try {
				const sent = matrix.data().revision
				const saved = await matrix.api('POST', '/api/grants/commands', { expectedRevision: sent, commands })
				matrix.announce(sent, saved.revision)
				close(false)
				matrix.offerUndo(message, saved)
			} catch (e) {
				// Nothing was saved: the command is all or nothing.
				close(false)
				reloaded = await matrix.policyError(e)
				if (!reloaded) {
					matrix.notifyError(t('mcp', 'Could not save the permission for {user}.', { user: user.displayName }, undefined, { escape: false }))
				}
			}
			if (!reloaded) {
				await matrix.reload()
			}
		} finally {
			matrix.setBusy(false)
		}
		focusCell(open.uid, open.module)
	}

	/** Gives the focus back to the cell the popover was opened from, in the table drawn after a save. */
	function focusCell(uid, module) {
		const table = document.getElementById('mcp-matrix')
		const row = [...(table?.tBodies?.[0]?.rows || [])].find((candidate) => candidate.dataset.uid === uid)
		const key = module === null ? 'eligible' : module
		const cell = [...(row?.cells || [])].find((candidate) => candidate.dataset.levelKey === key)
		cell?.querySelector('button')?.focus()
	}

	/**
	 * Asks what the person inherits, read only. An answer read at another revision than the table on screen is not used,
	 * so a level is never sent as "inherit" on the strength of a policy that is not the one shown.
	 */
	async function loadInherited(open) {
		const user = userOf(open.uid)
		const individual = planInherit(user, open.module)
		try {
			let cells = []
			if (individual.length > 0) {
				const data = await matrix.api('POST', '/api/grants/commands/preview', { commands: individual })
				if (typeof data.revision !== 'string' || data.revision !== matrix.data()?.revision) {
					throw new Error('stale')
				}
				cells = Array.isArray(data.cells) ? data.cells : []
			}
			if (current !== open) return
			open.inherited = inheritedValues(user, open.module, cells)
		} catch (e) {
			if (current !== open) return
			open.inherited = null
			open.unavailable = true
		}
		open.loading = false
		if (!open.saving) render()
	}

	/** Places the popover under the cell it belongs to, inside the matrix block, and brings all of it into view. */
	function place(trigger) {
		const block = popover.offsetParent || popover.parentElement
		const box = block.getBoundingClientRect()
		const rect = trigger.getBoundingClientRect()
		const width = popover.offsetWidth || 360
		popover.style.top = (rect.bottom - box.top + 4) + 'px'
		popover.style.left = Math.max(0, Math.min(rect.left - box.left, box.width - width)) + 'px'
		// Its height is capped (js/admin.css) and it scrolls inside: the page only moves as far as showing all of it takes.
		popover.scrollIntoView?.({ block: 'nearest', inline: 'nearest' })
	}

	/**
	 * Opens the popover of one cell.
	 *
	 * @param {HTMLElement} trigger the level button of the cell
	 * @param {string} uid user of the row
	 * @param {string|null} module module id, null for "Can connect"
	 */
	function open(trigger, uid, module) {
		if (current !== null) {
			const same = current.uid === uid && current.module === module
			close(false)
			if (same) {
				trigger.focus()
				return
			}
		}
		current = { uid, module, trigger, inherited: null, loading: true, unavailable: false, saving: false, operationsOpen: false }
		trigger.setAttribute('aria-expanded', 'true')
		render()
		popover.hidden = false
		place(trigger)
		;(popover.querySelector('input:checked') || popover.querySelector('input'))?.focus()
		loadInherited(current)
	}

	/** @param {boolean} restoreFocus give the focus back to the cell that opened the popover */
	function close(restoreFocus) {
		if (current === null) {
			return
		}
		const trigger = current.trigger
		current = null
		popover.hidden = true
		popover.replaceChildren()
		trigger?.setAttribute('aria-expanded', 'false')
		if (restoreFocus && trigger?.isConnected) {
			trigger.focus()
		}
		window.dispatchEvent?.(new Event('mcp-level-popover-closed'))
	}

	// Opening "Operations" makes it taller: it comes back into view whole (toggle does not bubble, so it is caught here).
	popover.addEventListener('toggle', () => popover.scrollIntoView?.({ block: 'nearest', inline: 'nearest' }), true)
	popover.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.preventDefault()
			close(true)
		}
	})
	document.addEventListener('pointerdown', (event) => {
		if (current !== null && !current.saving && !popover.contains(event.target) && event.target !== current.trigger && !current.trigger.contains?.(event.target)) {
			close(false)
		}
	})
	// A table drawn again (a reload, another block's write) replaces the cell: the popover would point at a stale one.
	window.addEventListener('mcp-matrix-rendered', () => {
		if (current !== null && !current.saving) {
			close(false)
		}
	})

	window.McpLevelPopover = { open, close, planLevel, planInherit, planOperation, planTarget, inheritedValues, losesEligibility }
})()
;
/* admin/admin-exceptions.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Exceptions of the permission matrix: of the individual values on the page, which differ from what the person would
 * inherit (a real exception) and which only repeat it (redundant).
 *
 * Every value 1.0.8 saved is an individual value in 1.1.0, so after an upgrade nearly every cell is individual and a mark
 * on each of them says nothing. The table only knows a cell's current source, never what the groups and defaults would
 * give without it, so once the page is drawn this asks the read-only preview (POST /apps/mcp/api/grants/commands/preview,
 * the one the reset uses) for every individual cell of the page and compares "before" with "after". Only a real exception
 * keeps the person icon; a redundant value is drawn like what it repeats, and a notice offers to review them.
 *
 * What the page may promise: until the answer comes no cell is marked as an exception, and when it cannot be read (an
 * error, or an answer read at another revision than the table) every individual value is marked, never fewer. An invalid
 * stored value is always an exception. Removing a redundant value changes nothing now, but it changes what happens when
 * the person leaves a group, so it is only ever done through the reset preview, never by itself.
 *
 * Plain browser JavaScript, no build step. src/admin/admin-grants.js asks kindOf() while it draws a level cell, and the access
 * inspector asks classifyPerson() for the person it shows; this file never writes anything.
 */
(function () {
	'use strict'

	const matrix = window.McpAdminMatrix
	if (!matrix) {
		return
	}

	/** Most cells and users one preview accepts (PolicyWriter::MAX_CELLS and MAX_UIDS). */
	const MAX_CELLS = 2500
	const MAX_USERS = 50
	const INDIVIDUAL = ['individual_allow', 'individual_deny', 'invalid']

	/**
	 * The classification of the page on screen: the read of the table it is about (data, the object matrix.data() returned,
	 * and key, its revision and users), status (pending, done or failed) and, per cell, {kind: exception|redundant, after}.
	 * A new read of the table is a new classification even at the same revision and with the same users: a group can lose
	 * a member without moving the revision, and what was redundant becomes an exception.
	 */
	let result = { data: null, key: null, status: 'pending', cells: new Map() }
	/** A redraw waits while the level popover is open, so the answer never closes what the administrator is using. */
	let refreshWaiting = false

	const notice = document.getElementById('mcp-redundant-notice')
	const noticeText = document.getElementById('mcp-redundant-notice-text')
	const reviewButton = document.getElementById('mcp-redundant-review')

	/** @return {string} key of one cell of one user */
	function cellKey(uid, module, operation) {
		return uid + '\0' + (module ?? '') + '\0' + operation
	}

	/** @return {string} key of the page on screen: a new revision or another set of users is another classification */
	function pageKey(data) {
		return data.revision + '\0' + data.users.map((user) => user.uid).join('\0')
	}

	/** @return {boolean} whether the classification is about this read of the table */
	function current(data) {
		return result.data === data && result.key === pageKey(data)
	}

	/**
	 * @param {object} user row of the table
	 * @return {Array<{uid: string, module: string|null, operation: string, before: boolean|null, source: string}>} its
	 *     individual cells, "Can connect" included
	 */
	function individualCells(user) {
		const cells = []
		if (INDIVIDUAL.includes(user.origins?.eligible?.source)) {
			cells.push({ uid: user.uid, module: null, operation: 'eligible', before: user.eligible, source: user.origins.eligible.source })
		}
		for (const [module, operations] of Object.entries(user.origins?.grants || {})) {
			for (const [operation, origin] of Object.entries(operations)) {
				if (INDIVIDUAL.includes(origin?.source)) {
					cells.push({ uid: user.uid, module, operation, before: user.grants?.[module]?.[operation] ?? null, source: origin.source })
				}
			}
		}
		return cells
	}

	/** Splits the cells into requests the preview accepts: at most MAX_USERS users and MAX_CELLS cells each. */
	function chunks(byUser) {
		const out = []
		let current = []
		let users = 0
		for (const cells of byUser) {
			if (cells.length === 0) continue
			if (current.length > 0 && (users + 1 > MAX_USERS || current.length + cells.length > MAX_CELLS)) {
				out.push(current)
				current = []
				users = 0
			}
			current = current.concat(cells)
			users++
		}
		if (current.length > 0) out.push(current)
		return out
	}

	/**
	 * Whether an individual value is a real exception, given what the preview says the person would have without it.
	 *
	 * @param {{before: boolean|null, source: string}} cell an individual cell of the table
	 * @param {object|undefined} answer the preview's cell
	 * @return {string} exception or redundant
	 */
	function kindOfAnswer(cell, answer) {
		const after = answer?.after?.allowed
		if (cell.source === 'invalid' || cell.before === null || after === null || after === undefined) {
			return 'exception'
		}
		return after === cell.before ? 'redundant' : 'exception'
	}

	/**
	 * Asks the preview what each individual cell would be without its value, and classifies it.
	 *
	 * @param {Array<Array<object>>} byUser the individual cells, one list per user (see individualCells())
	 * @param {string} revision the revision the cells were read at
	 * @return {Promise<Map<string, {kind: string, after: object|null}>>} per cellKey(); it fails when the preview fails or
	 *     answers at another revision, since what it says may then not be about these values
	 */
	async function ask(byUser, revision) {
		const answers = new Map()
		for (const chunk of chunks(byUser)) {
			const reply = await matrix.api('POST', '/api/grants/commands/preview', {
				commands: chunk.map((cell) => ({ uid: cell.uid, module: cell.module, operation: cell.operation, state: 'inherit' })),
			})
			if (typeof reply.revision !== 'string' || reply.revision !== revision) {
				throw new Error('stale')
			}
			for (const answer of Array.isArray(reply.cells) ? reply.cells : []) {
				answers.set(cellKey(answer.uid, answer.module ?? null, answer.operation), answer)
			}
		}
		const kinds = new Map()
		for (const cell of byUser.flat()) {
			const answer = answers.get(cellKey(cell.uid, cell.module, cell.operation))
			kinds.set(cellKey(cell.uid, cell.module, cell.operation), {
				kind: kindOfAnswer(cell, answer),
				after: answer?.after ? { source: answer.after.source, groupIds: answer.after.groupIds || [] } : null,
			})
		}
		return kinds
	}

	/**
	 * The classification of the individual values of one person, for the access inspector: the same preview and the same
	 * rule as the matrix, for any person, at the revision the inspector read.
	 *
	 * @param {string} uid the person
	 * @param {Array<{module: string, operation: string, allowed: boolean|null, source: string}>} cells their individual
	 *     values, as the inspector reads them
	 * @param {string} revision the revision of the inspector's answer
	 * @return {Promise<Map<string, {kind: string, after: object|null}>>} per "module/operation"; it fails as ask() does
	 */
	async function classifyPerson(uid, cells, revision) {
		const individual = cells.filter((cell) => INDIVIDUAL.includes(cell.source))
			.map((cell) => ({ uid, module: cell.module, operation: cell.operation, before: cell.allowed ?? null, source: cell.source }))
		const kinds = await ask([individual], revision)
		return new Map(individual.map((cell) => [cell.module + '/' + cell.operation, kinds.get(cellKey(uid, cell.module, cell.operation))]))
	}

	/** Reads the classification of the page on screen, once per revision and set of users. */
	async function classify() {
		const data = matrix.data()
		if (!data) {
			return
		}
		if (current(data)) {
			renderNotice()
			return
		}
		const key = pageKey(data)
		const byUser = data.users.map(individualCells)
		const all = byUser.flat()
		result = { data, key, status: all.length === 0 ? 'done' : 'pending', cells: new Map() }
		renderNotice()
		if (all.length === 0) {
			return
		}
		const open = result
		try {
			const kinds = await ask(byUser, data.revision)
			if (result !== open) return
			open.cells = kinds
			open.status = 'done'
		} catch (e) {
			if (result !== open) return
			open.status = 'failed'
		}
		renderNotice()
		refresh()
	}

	/**
	 * Draws the table again with the classification. It waits while the level popover, a row's actions menu or a drawer
	 * (the resource scope, the inspector) is open, so
	 * the answer never closes what the administrator is using, and otherwise gives the focus back to the same control of
	 * the same cell of the same row: a level button, a box, the "…" button or the selection box.
	 */
	function refresh() {
		const popover = document.getElementById('mcp-level-popover')
		const menu = document.getElementById('mcp-row-menu')
		const drawer = ['mcp-scope', 'mcp-inspector'].some((id) => document.getElementById(id)?.classList?.contains('mcp-drawer-open'))
		if ((popover && !popover.hidden) || (menu && !menu.hidden) || drawer) {
			refreshWaiting = true
			return
		}
		refreshWaiting = false
		const active = document.activeElement
		const cell = active?.closest?.('td, th')
		const uid = cell?.parentElement?.dataset?.uid
		const index = cell?.cellIndex
		const kind = active?.classList?.[0] || null
		const tag = active?.tagName?.toLowerCase?.() || null
		matrix.refresh()
		if (uid === undefined || index === undefined || tag === null) {
			return
		}
		const table = document.getElementById('mcp-matrix')
		const row = [...(table?.tBodies?.[0]?.rows || [])].find((candidate) => candidate.dataset.uid === uid)
		const again = row?.cells?.[index]
		const target = (kind !== null && again?.querySelector(tag + '.' + kind)) || again?.querySelector(tag)
		target?.focus()
	}

	/** @return {Array<{uid: string, module: string|null, operation: string}>} the redundant cells of the page */
	function redundantCells() {
		if (result.status !== 'done') {
			return []
		}
		return [...result.cells.entries()].filter(([, value]) => value.kind === 'redundant').map(([key]) => {
			const [uid, module, operation] = key.split('\0')
			return { uid, module: module === '' ? null : module, operation }
		})
	}

	/** Shows how many individual values on the page only repeat what the person inherits, with a way to review them. */
	function renderNotice() {
		if (!notice || !noticeText) {
			return
		}
		const count = redundantCells().length
		notice.hidden = count === 0
		noticeText.textContent = count === 0 ? '' : n('mcp',
			'%n individual value on this page only repeats what is inherited, so a group change would not reach that person.',
			'%n individual values on this page only repeat what is inherited, so group changes would not reach those people.', count)
	}

	reviewButton?.addEventListener('click', () => {
		const cells = redundantCells()
		if (cells.length > 0) {
			window.McpAdminOverrides?.reviewCells(cells, reviewButton)
		}
	})

	/**
	 * How src/admin/admin-grants.js marks an individual value: exception (the person icon), redundant (drawn like what it repeats,
	 * with that origin), or pending (no mark yet).
	 *
	 * @param {string} uid user
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {string} operation operation, "eligible" for "Can connect"
	 * @return {{kind: string, after: object|null}}
	 */
	function kindOf(uid, module, operation) {
		const data = matrix.data()
		if (!data || !current(data) || result.status === 'pending') {
			return { kind: 'pending', after: null }
		}
		if (result.status === 'failed') {
			return { kind: 'exception', after: null }
		}
		return result.cells.get(cellKey(uid, module, operation)) || { kind: 'exception', after: null }
	}

	window.addEventListener('mcp-matrix-rendered', classify)
	// A save of one cell moves the revision and updates its row without drawing the table again: the classification is
	// read again once that write has finished updating the page.
	window.addEventListener('mcp-revision-moved', () => setTimeout(classify, 0))
	// After the closing, not during it: a menu item runs its action after the menu closes, and that action (a drawer opened
	// from the "…" button) must still find the button on the page.
	for (const closed of ['mcp-level-popover-closed', 'mcp-row-menu-closed', 'mcp-drawer-closed']) {
		window.addEventListener(closed, () => setTimeout(() => {
			if (refreshWaiting) refresh()
		}, 0))
	}

	window.McpAdminExceptions = { kindOf, redundantCells, kindOfAnswer, chunks, classifyPerson, status: () => result.status }
})()
;
/* admin/admin-row-actions.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * The actions of one row of the permission matrix, in a "…" menu at the end of the row, so the row does not repeat a
 * button on every line: inspect the user's access, restrict their resources, give every individual value back to
 * inheritance (the reset preview of src/admin/admin-overrides.js, nothing is removed without it) and copy the user ID.
 *
 * A menu button with role="menu" items: Enter, Space or the arrow keys open it, the arrow keys move between the items,
 * Escape and Tab close it, and the focus goes back to the button. Plain browser JavaScript, no build step; src/admin/admin-grants.js
 * asks button() for every row it draws, and this file never touches the matrix state.
 */
(function () {
	'use strict'

	const matrix = window.McpAdminMatrix
	const menu = document.getElementById('mcp-row-menu')
	if (!matrix || !menu) {
		return
	}

	/** The open menu: {uid, trigger}, or null. */
	let current = null

	function el(tag, props, children) {
		const node = document.createElement(tag)
		Object.assign(node, props || {})
		for (const child of children || []) {
			node.append(child)
		}
		return node
	}

	/** @return {object|undefined} the user of the table on screen */
	function userOf(uid) {
		return matrix.data()?.users.find((user) => user.uid === uid)
	}

	/** @return {Array<{label: string, run: Function, disabled: boolean}>} the actions of a user, in menu order */
	function actions(user, trigger) {
		const overrides = window.McpAdminOverrides
		return [
			{
				label: t('mcp', 'Inspect access'),
				disabled: !window.McpInspectorUi,
				// The inspector opens as a drawer that takes the focus and gives it back to this button.
				run: () => window.McpInspectorUi.open(user.uid, trigger),
			},
			{
				label: t('mcp', 'Restrict resources'),
				disabled: !window.McpScopeUi,
				// The resource scope opens as a drawer that takes the focus and gives it back to this button.
				run: () => window.McpScopeUi.open(user.uid, undefined, trigger),
			},
			{
				label: t('mcp', 'Give everything back to inheritance…'),
				disabled: !overrides || !overrides.hasOverrides(user),
				run: () => overrides.resetUser(user, trigger),
			},
			{
				label: t('mcp', 'Copy user ID'),
				disabled: !navigator.clipboard,
				run: async () => {
					try {
						await navigator.clipboard.writeText(user.uid)
						matrix.offerUndo(t('mcp', 'User ID of {user} copied: {uid}', { user: user.displayName, uid: user.uid }, undefined, { escape: false }), { receiptId: null, revision: matrix.data().revision })
					} catch (e) {
						matrix.notifyError(t('mcp', 'Could not copy the user ID.'))
					}
				},
			},
		]
	}

	/** @return {HTMLElement[]} the items of the open menu that can be chosen */
	function items() {
		return [...menu.children].filter((item) => !item.disabled)
	}

	/**
	 * Opens the menu of one row under its button.
	 *
	 * @param {HTMLButtonElement} trigger the "…" button of the row
	 * @param {string} uid user of the row
	 * @param {number} [at] index of the item that takes the focus, -1 for the last
	 */
	function open(trigger, uid, at = 0) {
		if (current !== null) {
			const same = current.trigger === trigger
			close(false)
			if (same) return
		}
		const user = userOf(uid)
		if (!user) return
		current = { uid, trigger }
		menu.replaceChildren(...actions(user, trigger).map((action) => {
			const item = el('button', { type: 'button', className: 'mcp-row-menu-item', textContent: action.label, disabled: action.disabled })
			item.setAttribute('role', 'menuitem')
			item.addEventListener('click', () => {
				close(false)
				action.run()
			})
			return item
		}))
		menu.setAttribute('aria-label', t('mcp', 'Actions for {user}', { user: user.displayName }, undefined, { escape: false }))
		menu.hidden = false
		trigger.setAttribute('aria-expanded', 'true')
		place(trigger)
		const list = items()
		;(at < 0 ? list[list.length - 1] : list[at])?.focus()
	}

	/** Places the menu under the button, aligned to its end, inside the matrix block. */
	function place(trigger) {
		const block = menu.offsetParent || menu.parentElement
		const box = block.getBoundingClientRect()
		const rect = trigger.getBoundingClientRect()
		const width = menu.offsetWidth || 260
		menu.style.top = (rect.bottom - box.top + 4) + 'px'
		menu.style.left = Math.max(0, Math.min(rect.right - box.left - width, box.width - width)) + 'px'
	}

	/** @param {boolean} restoreFocus give the focus back to the "…" button */
	function close(restoreFocus) {
		if (current === null) return
		const trigger = current.trigger
		current = null
		menu.hidden = true
		menu.replaceChildren()
		trigger.setAttribute('aria-expanded', 'false')
		if (restoreFocus && trigger.isConnected) trigger.focus()
		// src/admin/admin-exceptions.js waits for this to draw the table again.
		window.dispatchEvent?.(new Event('mcp-row-menu-closed'))
	}

	/**
	 * The "…" button of one row.
	 *
	 * @param {object} user row from the API
	 * @return {HTMLButtonElement}
	 */
	function button(user) {
		const trigger = el('button', { type: 'button', className: 'mcp-row-actions-button', textContent: '…' })
		trigger.setAttribute('aria-label', t('mcp', 'Actions for {user}', { user: user.displayName }, undefined, { escape: false }))
		trigger.setAttribute('aria-haspopup', 'menu')
		trigger.setAttribute('aria-expanded', 'false')
		trigger.addEventListener('click', () => open(trigger, user.uid))
		trigger.addEventListener('keydown', (event) => {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault()
				open(trigger, user.uid, event.key === 'ArrowUp' ? -1 : 0)
			}
		})
		return trigger
	}

	menu.addEventListener('keydown', (event) => {
		const list = items()
		const index = list.indexOf(document.activeElement)
		if (event.key === 'Escape') {
			event.preventDefault()
			close(true)
		} else if (event.key === 'Tab') {
			close(true)
		} else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
			event.preventDefault()
			const step = event.key === 'ArrowDown' ? 1 : -1
			list[(index + step + list.length) % list.length]?.focus()
		} else if (event.key === 'Home' || event.key === 'End') {
			event.preventDefault()
			list[event.key === 'Home' ? 0 : list.length - 1]?.focus()
		}
	})
	document.addEventListener('pointerdown', (event) => {
		if (current !== null && !menu.contains(event.target) && event.target !== current.trigger) {
			close(false)
		}
	})
	// A table drawn again replaces the button the menu belongs to.
	window.addEventListener('mcp-matrix-rendered', () => close(false))

	window.McpRowActions = { button, open, close }
})()
;
/* admin/admin-overrides.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Individual overrides of the permission matrix: each cell is allowed, denied or inherited from the groups and
 * defaults, and an override goes back to inheritance for one cell, for one user or for the selected users of the
 * page, with a preview of exactly what is removed and the server receipt as the undo.
 *
 * Plain browser JavaScript, no build step. src/admin/admin-grants.js calls decorateRow() and decorateCell() for every row it
 * draws and exposes window.McpAdminMatrix; this file never reads or writes the matrix state itself. A reset is the
 * command {state: "inherit"} of POST /apps/mcp/api/grants/commands: one transaction, which writes every cell or none.
 *
 * What the page may promise: the table only knows each cell's current source, never what the groups would give once an
 * override is gone, so the preview asks the server (POST /apps/mcp/api/grants/commands/preview, read only) for the value
 * and source of every cell before and after, and shows both; an answer that is unknown is shown as unknown, never as a
 * value. Only a known "Can connect" lost after the reset says the connections are revoked, and a known one kept through a
 * group says nobody is signed out. When the preview cannot be read the page falls back to "may be signed out", never
 * "will be". A 503 of the command means nothing was written: the preview, the selection and the table stay as they are
 * and the administrator can try again. A reset is never reported before the server confirmed it.
 */
(function () {
	'use strict'

	const matrix = window.McpAdminMatrix
	const table = document.getElementById('mcp-matrix')
	// The toolbar goes before the table's wrapper, or before the table itself when the wrapper is missing. With no
	// attached anchor it would be created outside the page and every message and the preview would be lost silently,
	// so nothing is installed at all: the matrix keeps working without the reset.
	const anchor = table?.closest('.mcp-matrix-wrap') || table
	if (!matrix || !table || typeof anchor.before !== 'function' || anchor.isConnected === false) {
		return
	}

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
		return matrix.data()?.users || []
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
		const labels = matrix.labels()
		return (labels.modules[module] || module) + ': ' + (labels.operations[operation] || operation)
	}

	/**
	 * @param {object} user row from the API
	 * @return {Array<{module: string|null, operation: string, kind: string}>} the cells where the user has an individual override
	 */
	function overrideCells(user) {
		const cells = []
		const eligible = OVERRIDES[user.origins?.eligible?.source]
		if (eligible) {
			cells.push({ module: null, operation: 'eligible', kind: eligible })
		}
		for (const [module, operations] of Object.entries(user.origins?.grants || {})) {
			for (const [operation, origin] of Object.entries(operations)) {
				if (OVERRIDES[origin?.source]) {
					cells.push({ module, operation, kind: OVERRIDES[origin.source] })
				}
			}
		}
		return cells
	}

	/**
	 * Whether the table offers no checkbox for this cell of this user: a disabled account, a user who may not connect (the
	 * permission columns show one notice instead), a module whose column is hidden or an app that is unavailable to them.
	 * An override there is still removed by a reset of the row, so the preview says so.
	 *
	 * @param {object} user row from the API
	 * @param {{module: string|null}} cell an override of the user
	 * @return {boolean}
	 */
	function unseenCell(user, cell) {
		if (!user.enabled) {
			return true
		}
		if (cell.module === null) {
			return false
		}
		return user.eligible === false || !(cell.module in (matrix.data()?.catalog || {})) || user.appsEnabled?.[cell.module] === false
	}

	/**
	 * What a reset removes, computed from the table on screen.
	 *
	 * @param {object[]} users users to reset
	 * @param {Array<{module: string|null, operation: string}>|Function|null} only restrict to these cells, or to the cells a
	 *     function (user, cell) keeps; null for every override
	 * @return {object} {targets, users, cells, allow, deny, invalid, eligibleAllow, eligibleDeny, unseen, without, tooMany}:
	 *     eligibleDeny counts a Deny and an invalid value of "Can connect", both of which deny now and may stop denying;
	 *     unseen counts the overrides in a cell the table does not offer to the user (see unseenCell())
	 */
	function plan(users, only) {
		const result = { targets: [], users: 0, cells: 0, allow: 0, deny: 0, invalid: 0, eligibleAllow: 0, eligibleDeny: 0, unseen: 0, without: 0, tooMany: false }
		for (const user of users) {
			const cells = overrideCells(user).filter((cell) => only === null
				|| (typeof only === 'function' ? only(user, cell) : only.some((wanted) => wanted.module === cell.module && wanted.operation === cell.operation)))
			if (cells.length === 0) {
				result.without++
				continue
			}
			result.targets.push({ user, cells })
			result.users++
			for (const cell of cells) {
				result.cells++
				result[cell.kind]++
				if (cell.module === null && cell.kind === 'allow') {
					result.eligibleAllow++
				} else if (cell.module === null) {
					// An invalid stored value denies like a Deny: removing it hands the decision back to the groups too.
					result.eligibleDeny++
				}
				if (unseenCell(user, cell)) {
					result.unseen++
				}
			}
		}
		result.tooMany = result.cells > MAX_CELLS || result.users > MAX_USERS
		return result
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
		statusLine.textContent = ''
		alertLine.textContent = ''
		if (message === '') {
			return
		}
		const region = error ? alertLine : statusLine
		sayTimer = setTimeout(() => {
			region.textContent = message
		}, SAY_DELAY)
	}

	// --- Toolbar: the selection bar, the reset of the selected users, live regions and the preview -------------

	const toolbar = el('div', { className: 'mcp-reset-toolbar' })
	toolbar.setAttribute('role', 'group')
	toolbar.setAttribute('aria-label', t('mcp', 'Individual overrides'))
	const selectAll = el('input', { type: 'checkbox', id: 'mcp-select-all' })
	selectAll.setAttribute('aria-label', t('mcp', 'Select every user on this page'))
	const resetSelected = el('button', { type: 'button', className: 'mcp-reset-selected', onclick: () => start(selectedUsers(), null, resetSelected) })
	/*
	 * The selection bar, shown while someone is selected: set a level of a module (or "Can connect") for the selected
	 * people through the matrix's bulk change, which confirms what changes, who is left out and why and who already has
	 * it, then offers the undo; give their individual values back to inheritance through the reset preview; or clear the
	 * selection. It replaces the "All" menus of the column headers.
	 */
	const selectionCount = el('strong', { className: 'mcp-selection-count' })
	const moduleChoice = el('select', { className: 'mcp-selection-module' })
	moduleChoice.setAttribute('aria-label', t('mcp', 'Permission to set'))
	const levelChoice = el('select', { className: 'mcp-selection-level' })
	levelChoice.setAttribute('aria-label', t('mcp', 'Level to set'))
	const applyChoice = el('button', { type: 'button', className: 'mcp-selection-apply mcp-bulk', textContent: t('mcp', 'Apply'), onclick: () => applySelection() })
	const clearSelection = el('button', { type: 'button', className: 'mcp-selection-clear', textContent: t('mcp', 'Clear selection'), onclick: () => clearAll() })
	const bar = el('div', { className: 'mcp-selection-bar', hidden: true }, [
		selectionCount, el('span', { className: 'mcp-selection-set' }, [t('mcp', 'Set'), ' ', moduleChoice, ' ', levelChoice, ' ', applyChoice]),
		resetSelected, clearSelection,
	])
	bar.setAttribute('role', 'group')
	bar.setAttribute('aria-label', t('mcp', 'Selected people'))
	moduleChoice.addEventListener('change', () => fillLevels())
	const statusLine = el('p', { className: 'mcp-reset-status', id: 'mcp-reset-status' })
	statusLine.setAttribute('role', 'status')
	statusLine.setAttribute('aria-live', 'polite')
	const alertLine = el('p', { className: 'mcp-reset-alert', id: 'mcp-reset-alert' })
	alertLine.setAttribute('role', 'alert')
	alertLine.setAttribute('aria-live', 'assertive')
	const panel = el('div', { className: 'mcp-reset-preview', hidden: true })
	panel.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && review !== null && !saving) {
			close(true)
		}
	})
	// The select-all box heads the selection column once the matrix draws its header (decorateHeader()).
	toolbar.append(el('span', { className: 'mcp-reset-select' }, [selectAll]), bar, statusLine, alertLine, panel)
	// Focusable only by script: where the focus goes when nothing more specific is left to take it.
	toolbar.tabIndex = -1
	anchor.before(toolbar)

	/** @return {object[]} the selected users that are on the page now */
	function selectedUsers() {
		return pageUsers().filter((user) => selected.has(user.uid))
	}

	/** Keeps the toolbar's counters, the select-all box and the button state in line with the selection and the lock. */
	function updateToolbar() {
		const users = pageUsers()
		const chosen = selectedUsers()
		selectAll.checked = users.length > 0 && chosen.length === users.length
		selectAll.indeterminate = chosen.length > 0 && chosen.length < users.length
		selectAll.disabled = users.length === 0
		resetSelected.textContent = t('mcp', 'Give back to inheritance… ({count})', { count: chosen.length }, undefined, { escape: false })
		resetSelected.title = t('mcp', 'Remove the individual values of the selected people, after a preview of what changes')
		resetSelected.disabled = chosen.length === 0 || saving
		bar.hidden = chosen.length === 0
		selectionCount.textContent = n('mcp', '%n selected', '%n selected', chosen.length)
		if (chosen.length > 0 && moduleChoice.children.length === 0) {
			fillModules()
		}
	}

	/** The choices of the selection bar: "Can connect" first, then every module of the catalog. */
	function fillModules() {
		const catalog = matrix.data()?.catalog || {}
		const labels = matrix.labels().modules
		moduleChoice.replaceChildren(el('option', { value: '', textContent: t('mcp', 'Can connect') }),
			...Object.keys(catalog).map((module) => el('option', { value: module, textContent: labels[module] || module })))
		moduleChoice.value = ''
		fillLevels()
	}

	/** The levels of the chosen module, or Yes and No for "Can connect". */
	function fillLevels() {
		const module = moduleChoice.value || null
		const names = matrix.levelLabels?.() || {}
		const choices = module === null
			? [['read', t('mcp', 'Yes')], ['none', t('mcp', 'No')]]
			: window.McpAdminLevels.levels(module, matrix.data().catalog[module]).map((level) => [level, names[level] || level])
		levelChoice.replaceChildren(...choices.map(([value, text]) => el('option', { value, textContent: text })))
	}

	/** Sets the chosen level for the selected people, through the matrix's bulk change. */
	function applySelection() {
		if (saving || matrix.isBusy()) {
			say(t('mcp', 'Wait until the change in progress is saved.'), true)
			return
		}
		const uids = selectedUsers().map((user) => user.uid)
		if (uids.length > 0 && levelChoice.value) {
			matrix.bulk(moduleChoice.value || null, levelChoice.value, uids)
		}
	}

	/** Clears the selection of the page. */
	function clearAll() {
		selected.clear()
		for (const box of table.querySelectorAll('input.mcp-row-select')) {
			box.checked = false
		}
		updateToolbar()
		selectAll.focus()
	}

	/**
	 * Puts the select-all box in the header of the selection column, which src/admin/admin-grants.js draws again on every render.
	 *
	 * @param {HTMLTableCellElement} header the header cell of the selection column
	 */
	function decorateHeader(header) {
		header.append(selectAll)
		updateToolbar()
	}

	selectAll.addEventListener('change', () => {
		for (const user of pageUsers()) {
			selectAll.checked ? selected.add(user.uid) : selected.delete(user.uid)
		}
		for (const box of table.querySelectorAll('input.mcp-row-select')) {
			box.checked = selectAll.checked
		}
		updateToolbar()
	})

	// The table was redrawn: the selection keeps only users that are still on screen, and a preview that was built from
	// the old table is closed, because what it lists may no longer be what is saved.
	window.addEventListener('mcp-matrix-rendered', () => {
		const present = new Set(pageUsers().map((user) => user.uid))
		for (const uid of [...selected]) {
			if (!present.has(uid)) {
				selected.delete(uid)
			}
		}
		if (review !== null && !saving) {
			close(false)
			say(t('mcp', 'The table changed, so the reset preview was closed. Review it again.'))
		}
		updateToolbar()
	})

	// --- Rows and cells -----------------------------------------------------------------------------------------

	/**
	 * Adds the selection box to a row. The reset of the whole row is in the row's actions menu (src/admin/admin-row-actions.js),
	 * through resetUser(), so the row carries no button repeated on every line.
	 *
	 * @param {HTMLTableRowElement} row row drawn by admin-grants.js; its first child is the selection cell
	 * @param {object} user row from the API
	 */
	function decorateRow(row, user) {
		const slot = row.children[0]
		const box = el('input', { type: 'checkbox', className: 'mcp-row-select', checked: selected.has(user.uid) })
		box.setAttribute('aria-label', t('mcp', 'Select {user}', { user: user.displayName }, undefined, { escape: false }))
		box.addEventListener('change', () => {
			box.checked ? selected.add(user.uid) : selected.delete(user.uid)
			updateToolbar()
		})
		slot.append(box)
		updateToolbar()
	}

	/** @return {boolean} whether the user has any individual value a reset would remove */
	function hasOverrides(user) {
		return overrideCells(user).length > 0
	}

	/**
	 * Opens the reset preview for every individual value of one user, as the row's actions menu asks.
	 *
	 * @param {object} user row from the API
	 * @param {HTMLElement} trigger control that asked, which gets the focus back when the preview is cancelled
	 */
	function resetUser(user, trigger) {
		start([user], null, trigger)
	}

	/**
	 * Names the state of a cell (individual allow, individual deny or inherited) for the tooltip and the screen reader, and
	 * outlines it only when it is a real exception (src/admin/admin-exceptions.js): an individual value that repeats what the
	 * person inherits is named but not outlined, and before that is known nothing is outlined. Without the classification
	 * every individual value is outlined, never fewer. A single value goes back to inheritance from the level popover, a
	 * whole row from its actions menu.
	 *
	 * @param {HTMLTableCellElement} cell the permission cell
	 * @param {HTMLInputElement|null} box its checkbox, null for an unavailable cell, which has none
	 * @param {object} user row from the API
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {string} operation operation id, "eligible" for "Can connect"
	 */
	function decorateCell(cell, box, user, module, operation) {
		const origin = module === null ? user.origins?.eligible : user.origins?.grants?.[module]?.[operation]
		const kind = OVERRIDES[origin?.source]
		const names = {
			allow: t('mcp', 'Individual override: allowed'),
			deny: t('mcp', 'Individual override: denied'),
			invalid: t('mcp', 'Invalid stored override (denied)'),
		}
		if (box === null && !kind) {
			// An unavailable cell without an override of its own says nothing more than it already does.
			return
		}
		const exceptions = window.McpAdminExceptions
		const mark = !kind ? null : kind === 'invalid' || !exceptions ? 'exception' : exceptions.kindOf(user.uid, module, operation).kind
		const stateName = !kind ? t('mcp', 'Inherited')
			: mark === 'redundant' ? names[kind] + ' — ' + t('mcp', 'the same as inherited')
				: mark === 'exception' && kind !== 'invalid' ? names[kind] + ' — ' + t('mcp', 'Exception: differs from what the person inherits')
					: names[kind]
		cell.classList.add(kind ? 'mcp-override' : 'mcp-inherited')
		if (kind) {
			cell.classList.add('mcp-override-' + kind)
			if (mark === 'exception') {
				cell.classList.add('mcp-override-exception')
			}
		}
		cell.title += ' — ' + stateName
		if (box !== null) {
			box.setAttribute('aria-label', box.getAttribute('aria-label') + ' — ' + stateName)
		} else {
			// An unavailable cell has no checkbox: its saved override is named for screen readers next to its reason.
			cell.append(el('span', { className: 'mcp-sr-only', textContent: stateName }))
		}
	}

	// --- Preview and reset ------------------------------------------------------------------------------------

	/**
	 * Opens the preview of a reset, or says why there is nothing to reset.
	 *
	 * @param {object[]} users users to reset
	 * @param {Array<{module: string|null, operation: string}>|null} only restrict to these cells
	 * @param {HTMLElement} trigger control that asked, which gets the focus back when the preview is cancelled
	 */
	function start(users, only, trigger) {
		if (saving || matrix.isBusy()) {
			say(t('mcp', 'Wait until the change in progress is saved.'), true)
			return
		}
		const result = plan(users, only)
		if (result.cells === 0) {
			say(t('mcp', 'Nothing to reset: the selected users have no individual overrides.'))
			return
		}
		if (result.tooMany) {
			say(t('mcp', 'Too many overrides to reset at once. Select fewer users.'), true)
			return
		}
		say('')
		review = { plan: result, trigger, retry: false, after: null, loading: true, unavailable: false, revision: null }
		renderReview()
		panel.hidden = false
		panel.querySelector('h3').focus()
		loadAfter(review)
	}

	/**
	 * @param {object} result a plan
	 * @return {Array<{uid: string, module: string|null, operation: string, state: string}>} its inherit commands
	 */
	function commandsOf(result) {
		return result.targets.flatMap((target) => target.cells.map((cell) => ({
			uid: target.user.uid,
			module: cell.module,
			operation: cell.operation,
			state: 'inherit',
		})))
	}

	/**
	 * Asks the server what each cell has before and after the reset and redraws the preview with it. Read only. A preview
	 * that cannot be read leaves every "after" unknown and the warning conservative; it never blocks the reset itself,
	 * whose command refuses on its own what it cannot determine.
	 *
	 * @param {object} current the preview that asked; an answer for a preview that was closed or replaced is dropped
	 */
	async function loadAfter(current) {
		try {
			const data = await matrix.api('POST', '/api/grants/commands/preview', { commands: commandsOf(current.plan) })
			if (review !== current) {
				return
			}
			current.after = new Map((Array.isArray(data.cells) ? data.cells : []).map((cell) => [afterKey(cell.uid, cell.module, cell.operation), cell]))
			// The command is tied to what the preview showed: it carries the revision the preview was read at, so a change
			// after it is a 409 and never something else applied.
			current.revision = typeof data.revision === 'string' ? data.revision : null
		} catch (e) {
			if (review !== current) {
				return
			}
			current.unavailable = true
		}
		current.loading = false
		renderReview()
		say(current.unavailable
			? t('mcp', 'The result after the reset could not be read, so it is shown as unknown.')
			: t('mcp', 'The result after the reset is shown.'), current.unavailable)
	}

	/**
	 * @param {object} current the open preview
	 * @return {boolean} whether the preview was read at another revision than the table on screen, so what the table lists
	 *     and what the preview shows may not be the same overrides
	 */
	function outdated(current) {
		return current.revision !== null && current.revision !== matrix.data()?.revision
	}

	/** @return {string} key of one cell of one user */
	function afterKey(uid, module, operation) {
		return uid + '\0' + (module ?? '') + '\0' + operation
	}

	/** @param {boolean} restoreFocus give the focus back to the control that opened the preview */
	function close(restoreFocus) {
		const trigger = review?.trigger
		review = null
		panel.hidden = true
		panel.replaceChildren()
		if (restoreFocus) {
			focusSomething(trigger)
		}
	}

	/**
	 * Puts the focus on the control that asked for the reset, or on the first control of the toolbar that can take it, and
	 * as the last resort on the toolbar itself, so the focus never falls back to the page when a preview goes away.
	 *
	 * @param {HTMLElement|undefined} trigger control that opened the preview, which a reload may have replaced
	 */
	function focusSomething(trigger) {
		const usable = (node) => node && node.isConnected !== false && !node.disabled
		const target = [trigger, resetSelected, selectAll].find(usable) || toolbar
		target.focus()
	}

	/**
	 * @param {{allowed: boolean|null, source: string, groupIds: string[]}|undefined} outcome the cell after the reset
	 * @return {string} what it will be, or "Unknown": a missing or null answer is never shown as a value
	 */
	function afterText(outcome) {
		if (!outcome || outcome.allowed === null || outcome.allowed === undefined) {
			return t('mcp', 'Unknown')
		}
		if (outcome.allowed) {
			return outcome.source === 'group'
				? t('mcp', 'Granted by groups: {groups}', { groups: outcome.groupIds.join(', ') }, undefined, { escape: false })
				: t('mcp', 'Allowed by default')
		}
		return t('mcp', 'Denied by default')
	}

	/**
	 * @param {{user: object, cells: object[]}} target one user of the plan
	 * @return {string} "Ana: Can connect: Individual override: allowed → Granted by groups: staff; Notes: edit: …"
	 */
	function describeTarget(target) {
		const names = {
			allow: t('mcp', 'Individual override: allowed'),
			deny: t('mcp', 'Individual override: denied'),
			invalid: t('mcp', 'Invalid stored override (denied)'),
		}
		const parts = target.cells.map((cell) => {
			const outcome = review.after?.get(afterKey(target.user.uid, cell.module, cell.operation))?.after
			return t('mcp', '{permission}: {before} → {after}', { permission: permissionName(cell.module, cell.operation), before: names[cell.kind], after: afterText(outcome) }, undefined, { escape: false })
		})
		return t('mcp', '{user}: {permissions}', { user: target.user.displayName, permissions: parts.join('; ') }, undefined, { escape: false })
	}

	/**
	 * What the server says about the results of a reset, once it was read.
	 *
	 * @param {object} current the open preview
	 * @return {{changed: number, same: number, unknown: number, lost: number, kept: number, lostUnknown: number}}
	 */
	function outcomes(current) {
		const out = { changed: 0, same: 0, unknown: 0, lost: 0, kept: 0, lostUnknown: 0 }
		for (const target of current.plan.targets) {
			for (const cell of target.cells) {
				const answer = current.after.get(afterKey(target.user.uid, cell.module, cell.operation))
				const after = answer?.after?.allowed ?? null
				const before = answer?.before?.allowed ?? null
				if (after === null || before === null) {
					out.unknown++
				} else if (after === before) {
					out.same++
				} else {
					out.changed++
				}
				if (cell.module === null && cell.kind === 'allow') {
					// An individual Allow of "Can connect": what the user is left with decides whether the connections stay.
					after === null ? out.lostUnknown++ : after ? out.kept++ : out.lost++
				}
			}
		}
		return out
	}

	/** Draws the preview: the exact cells, what each has before and after, what may change and the buttons. */
	function renderReview() {
		const result = review.plan
		// The preview is drawn again when the server's answer arrives: the focus stays on what had it.
		const focused = ['h3', 'button.mcp-reset-confirm', 'button.mcp-reset-cancel', 'button.mcp-reset-reload'].find((selector) => {
			const node = panel.querySelector(selector)
			return node !== null && document.activeElement === node
		})
		const heading = el('h3', {
			tabIndex: -1,
			textContent: n('mcp', 'Back to group inheritance for %n user', 'Back to group inheritance for %n users', result.users, undefined, { escape: false }),
		})
		const lines = [
			el('p', { textContent: t('mcp', 'Overrides to remove: {allow} allowed, {deny} denied, {invalid} invalid.', { allow: result.allow, deny: result.deny, invalid: result.invalid }, undefined, { escape: false }) }),
		]
		const stale = outdated(review)
		if (stale) {
			lines.push(el('p', { className: 'mcp-reset-warning', textContent: t('mcp', 'The table is older than the preview: the permissions changed after it was loaded. Reload the table to review the reset again.') }))
		}
		const known = review.after !== null
		const counts = known ? outcomes(review) : null
		if (review.loading) {
			lines.push(el('p', { textContent: t('mcp', 'Checking what these users inherit…') }))
		} else if (review.unavailable) {
			lines.push(el('p', { textContent: t('mcp', 'The result after the reset could not be read, so it is shown as unknown.') }))
		}
		lines.push(el('p', { textContent: known
			? t('mcp', 'Results that change: {changed}. Unchanged: {same}. Unknown: {unknown}.', counts, undefined, { escape: false })
			: t('mcp', 'Each removed override is replaced by what the groups and defaults give, so some permissions may change.') }))
		if (known) {
			if (counts.lost > 0) {
				lines.push(el('p', { className: 'mcp-reset-warning', textContent: n('mcp',
					'%n user loses “Can connect” and the access it gives. Their MCP connections are revoked, and undoing this does not sign them back in.',
					'%n users lose “Can connect” and the access it gives. Their MCP connections are revoked, and undoing this does not sign them back in.',
					counts.lost, undefined, { escape: false }) }))
			}
			if (counts.kept > 0) {
				lines.push(el('p', { textContent: n('mcp', '%n user keeps “Can connect” through a group, so nobody is signed out for it.', '%n users keep “Can connect” through a group, so nobody is signed out for it.', counts.kept, undefined, { escape: false }) }))
			}
			if (counts.lostUnknown > 0) {
				lines.push(el('p', { className: 'mcp-reset-warning', textContent: n('mcp',
					'The “Can connect” of %n user after the reset is unknown. The reset is refused until the groups can be read.',
					'The “Can connect” of %n users after the reset is unknown. The reset is refused until the groups can be read.',
					counts.lostUnknown, undefined, { escape: false }) }))
			}
		} else if (result.eligibleAllow > 0) {
			// The server's answer is missing: never "will", a group or another rule may keep the user eligible.
			lines.push(el('p', { className: 'mcp-reset-warning', textContent: n('mcp',
				'%n user loses an individual Allow for “Can connect”. Unless a group or another rule keeps them eligible, they may be signed out of their MCP clients, and undoing this does not sign them back in.',
				'%n users lose an individual Allow for “Can connect”. Unless a group or another rule keeps them eligible, they may be signed out of their MCP clients, and undoing this does not sign them back in.',
				result.eligibleAllow, undefined, { escape: false }) }))
		}
		if (result.eligibleDeny > 0) {
			lines.push(el('p', { textContent: t('mcp', 'Removing an individual Deny for “Can connect” lets the groups decide again, which may give access.') }))
		}
		if (result.unseen > 0) {
			lines.push(el('p', { textContent: n('mcp',
				'%n of these values is in a permission the table does not show for that user. It is removed too.',
				'%n of these values are in permissions the table does not show for those users. They are removed too.',
				result.unseen, undefined, { escape: false }) }))
		}
		if (result.without > 0) {
			lines.push(el('p', { textContent: n('mcp', '%n selected user has no individual overrides and is left out.', '%n selected users have no individual overrides and are left out.', result.without, undefined, { escape: false }) }))
		}
		const list = el('ul', { className: 'mcp-reset-targets', tabIndex: 0 }, result.targets.map((target) => el('li', { textContent: describeTarget(target) })))
		// The list scrolls when many users are selected, so it is a named, focusable region the keyboard can reach.
		list.setAttribute('aria-label', t('mcp', 'Individual overrides'))
		const confirm = el('button', {
			type: 'button',
			className: 'primary mcp-reset-confirm',
			textContent: review.retry ? t('mcp', 'Try again') : t('mcp', 'Remove overrides'),
			disabled: review.loading || stale,
			onclick: execute,
		})
		const cancel = el('button', { type: 'button', className: 'mcp-reset-cancel', textContent: t('mcp', 'Cancel'), onclick: () => close(true) })
		const actions = [confirm, ' ', cancel]
		if (stale) {
			actions.push(' ', el('button', { type: 'button', className: 'mcp-reset-reload', textContent: t('mcp', 'Reload the table'), onclick: () => matrix.reload() }))
		}
		panel.replaceChildren(heading, ...lines, list, el('div', { className: 'mcp-reset-actions' }, actions))
		if (focused) {
			// A button that was just disabled cannot take the focus: the reload button, which now matters most, does.
			const target = panel.querySelector(focused)
			;(target.disabled ? panel.querySelector('button.mcp-reset-reload') || target : target).focus()
		}
	}

	/** Sends the command of the open preview. Nothing is repeated on its own: a retry is the administrator's click. */
	async function execute() {
		if (review === null || saving || matrix.isBusy()) {
			return
		}
		if (outdated(review)) {
			// A matrix save moved the table's revision after the preview was read: say so instead of acting on it.
			renderReview()
			return
		}
		const current = review
		const commands = commandsOf(current.plan)
		saving = true
		matrix.setBusy(true)
		for (const button of panel.querySelectorAll('button')) {
			button.disabled = true
		}
		updateToolbar()
		say('')
		let undo = null
		let failed = false
		try {
			try {
				const sent = current.revision ?? matrix.data().revision
				const saved = await matrix.api('POST', '/api/grants/commands', { expectedRevision: sent, commands })
				matrix.announce(sent, saved.revision)
				for (const target of current.plan.targets) {
					selected.delete(target.user.uid)
				}
				close(false)
				// The table still shows the old values until it reloads: the result is told only now, from the server's answer.
				undo = matrix.offerUndo(saved.changed === 0
					? t('mcp', 'Nothing changed: those overrides were already gone.')
					: n('mcp', '%n individual override removed.', '%n individual overrides removed.', saved.changed, undefined, { escape: false }), saved)
				await matrix.reload()
			} catch (e) {
				if (e.message === '503' && !(e.data && e.data.uncertain)) {
					// Nothing was written: the preview, the selection and the table stay, and the same command can be sent again.
					review.retry = true
					renderReview()
					say(t('mcp', 'The change could not be made because what these users inherit could not be read. Nothing was changed, and your selection is kept. Try again.'), true)
				} else if (!(await matrix.policyError(e))) {
					failed = true
					close(false)
					say(t('mcp', 'Could not return the users to group inheritance. The table was reloaded with the saved values.'), true)
					await matrix.reload()
				} else {
					failed = true
					close(false)
				}
			}
		} finally {
			saving = false
			matrix.setBusy(false)
			updateToolbar()
		}
		if (review !== null && review.retry) {
			panel.querySelector('button').focus()
		}
		if (failed) {
			// The preview is gone and the table was redrawn: the focus goes to a control of the toolbar, never to the page.
			focusSomething(current.trigger)
		}
		matrix.focusUndo(undo)
	}

	/**
	 * Opens the reset preview for exactly these cells of these users, as src/admin/admin-exceptions.js does for the values that only
	 * repeat what the people inherit. The reset itself is the same command and the same preview as any other.
	 *
	 * @param {Array<{uid: string, module: string|null, operation: string}>} cells cells of the users on the page
	 * @param {HTMLElement} trigger control that asked
	 */
	function reviewCells(cells, trigger) {
		const keys = new Set(cells.map((cell) => afterKey(cell.uid, cell.module, cell.operation)))
		const uids = new Set(cells.map((cell) => cell.uid))
		start(pageUsers().filter((user) => uids.has(user.uid)), (user, cell) => keys.has(afterKey(user.uid, cell.module, cell.operation)), trigger)
	}

	window.McpAdminOverrides = { decorateRow, decorateHeader, decorateCell, plan, reviewCells, resetUser, hasOverrides }
	// The matrix may have been drawn before this file ran: draw it again so its rows carry the controls.
	matrix.refresh()
})()
;
/* admin/admin-groups.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Group policy editor. Plain JavaScript, no build. Drafts survive refused saves; writes are never replayed. */
(function () {
	'use strict'
	/** Diagnosis and explicit single-row cleanup, shipped with its consuming editor. */
	class UnknownGroupGrants {
		/** @returns {object|null} Textual warning for a directory row. */
		static warning(group) {
			return group.unknownGrants?.length ? { symbol: '?', text: t('mcp', 'Unknown grants: denied'), warning: true } : null
		}
		/** @returns {object[]} Fresh diagnosis replaces the open group's directory entry after readback. */
		static refreshRow(rows, group) {
			return rows.map((row) => row.groupId === group.groupId ? { ...row, ...group, unknownGrants: group.unknownGrants || [] } : row)
		}
		/** @returns {HTMLElement[]} Accessible diagnostics with one explicit cleanup action per opaque row. */
		static render(group, busy, clean) {
			if (!group.unknownGrants?.length) return []
			const section = el('section', { className: 'mcp-note' })
			section.setAttribute('aria-label', t('mcp', 'Unknown group grants'))
			section.append(el('h4', { textContent: t('mcp', 'Unknown group grants') }),
				el('p', { textContent: t('mcp', 'These saved grants are unknown to this version and grant no access. Other permissions continue to apply. Cleanup is optional and is recorded.') }))
			for (const row of group.unknownGrants) {
				section.append(el('p', {}, [el('span', { textContent: row.module + '.' + row.operation + ' — ' + t('mcp', 'Stored value: Allow; effective: denied') }),
					el('button', { type: 'button', disabled: busy, textContent: t('mcp', 'Clear unknown grant'), onclick: () => clean(row) })]))
			}
			return [section]
		}
		/** Confirms the exact row and snapshot, never replays writes, and reloads after every attempt. */
		static async clean(group, row, { api, setBusy, refresh, say, saved }) {
			if (!window.confirm(t('mcp', 'Clear 1 unknown grant from group {group}? Key: {key}. Stored value: Allow. This cleanup will be recorded.',
				{ group: group.groupId, key: row.module + '.' + row.operation }, undefined, { escape: false }))) return
			setBusy(true)
			try {
				const result = await api('DELETE', '/api/group-grants/unknown', { gid: group.groupId, ids: [row.id], grantSnapshot: group.grantSnapshot, expectedRevision: group.revision, confirm: true })
				if (!result.auditId || result.removed !== 1 || !result.report) throw new Error('unconfirmed')
				say(result.auditPending ? t('mcp', 'Unknown grant cleared and recorded. Audit log delivery is pending.') : t('mcp', 'Unknown grant cleared and recorded.'))
				saved()
			} catch (error) {
				say(error.message === '409' || error.message === '428'
					? t('mcp', 'The saved grants changed. Review the refreshed group before clearing again.')
					: t('mcp', 'The cleanup could not be confirmed. Check the refreshed group before trying again.'), true)
			} finally {
				setBusy(false)
				await refresh(group.groupId)
			}
		}
	}
	const root = document.getElementById('mcp-group-editor')
	if (!root) return
	const search = document.getElementById('mcp-group-search')
	const list = document.getElementById('mcp-group-list')
	const detail = document.getElementById('mcp-group-detail')
	const status = document.getElementById('mcp-group-status')
	const alert = document.getElementById('mcp-group-alert')
	const prev = document.getElementById('mcp-group-prev')
	const next = document.getElementById('mcp-group-next')
	const counter = document.getElementById('mcp-group-count')
	const columns = document.getElementById('mcp-groups')
	const picker = document.getElementById('mcp-group-picker')
	const filters = { all: document.getElementById('mcp-group-filter-all'), granted: document.getElementById('mcp-group-filter-granted'), blocked: document.getElementById('mcp-group-filter-blocked') }
	let page = 1
	/** Filter of the list, sent to the server with the search and the page: all, granted or blocked. */
	let filter = 'all'
	/** Rows of the page on screen, kept so the open group can be marked without reading the list again. */
	let rows = []
	let listSeq = 0
	let detailSeq = 0
	let current = null
	/** Name of the open group as the list shows it; the detail API returns only the ID. */
	let shownName = null
	let draft = {}
	let preview = null
	/** Whether the detail shows each module's operations instead of its level (the "Operations" switch). */
	let advanced = false
	let busy = false
	let timer
	let sayTimer
	/** Milliseconds between clearing a live region and writing into it. */
	const SAY_DELAY = 60
	/** Groups per page of the directory API. */
	const PAGE_SIZE = 50
	/** Options of t() for text written with textContent, which escapes by itself. */
	const RAW = { escape: false }

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		Object.assign(node, props)
		node.append(...children)
		return node
	}
	/**
	 * Writes into the fixed live regions. Both are cleared at once and the message is written shortly after, in
	 * another task, so the same error twice in a row is announced again, and a message that was replaced or cleared
	 * in the meantime never appears.
	 */
	function say(message, error = false) {
		clearTimeout(sayTimer)
		status.textContent = ''
		alert.textContent = ''
		if (message === '') return
		const region = error ? alert : status
		sayTimer = setTimeout(() => { region.textContent = message }, SAY_DELAY)
	}
	async function api(method, path, body) {
		const response = await fetch(OC.generateUrl('/apps/mcp' + path), {
			method,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		// A proxy or expired session can return HTML with status 200. Only a readable JSON response confirms a save.
		const data = await response.json().catch((error) => {
			if (response.ok) throw Object.assign(new Error('unconfirmed'), { cause: error })
			return {}
		})
		if (!response.ok) throw Object.assign(new Error(String(response.status)), { data })
		return data
	}
	const copyGrants = (grants) => Object.fromEntries(Object.entries(grants).map(([module, ops]) => [module, [...ops]]))
	const unknown = () => t('mcp', 'Unknown count')
	const count = (value) => value === null || value === undefined ? unknown() : String(value)
	const untrusted = () => t('mcp', 'This group comes from a source that cannot guarantee group identity (for example LDAP). Grant MCP access to its members individually.')
	function moduleName(module) {
		return { files: t('mcp', 'Files'), notes: t('mcp', 'Notes'), deck: t('mcp', 'Deck'), calendar: t('mcp', 'Calendar'), contacts: t('mcp', 'Contacts'), tasks: t('mcp', 'Tasks'), talk: t('mcp', 'Talk'), people: t('mcp', 'People'), logs: t('mcp', 'Server log'), mail: t('mcp', 'Mail'), tables: t('mcp', 'Tables'), forms: t('mcp', 'Forms'), polls: t('mcp', 'Polls') }[module] || module
	}
	function operationName(operation) {
		return { ...window.McpAdminPollsGrants?.operationLabels(), results: t('mcp', 'results'), read: t('mcp', 'read'), edit: t('mcp', 'edit'), create: t('mcp', 'create'), move: t('mcp', 'move'), delete: t('mcp', 'delete'), transfer: t('mcp', 'transfer'), reply: t('mcp', 'reply'), attach: t('mcp', 'attach'), quote: t('mcp', 'quote'), share: t('mcp', 'share'), link: t('mcp', 'link'), restore: t('mcp', 'restore'), messages: t('mcp', 'messages'), attachments: t('mcp', 'attachments'), flags: t('mcp', 'flags'), draft: t('mcp', 'draft'), send: t('mcp', 'send') }[operation] || operation
	}
	function grantKeys(grants) {
		return Object.entries(grants).flatMap(([module, operations]) => operations.map((operation) => module + ':' + operation)).sort()
	}
	function dirty() {
		return current && JSON.stringify(grantKeys(draft)) !== JSON.stringify(grantKeys(current.grants))
	}
	function discardDraft() {
		return !dirty() || window.confirm(t('mcp', 'Discard the unsaved group changes?'))
	}
	function diagnosis(group) {
		if (group.invalidId) return t('mcp', 'The directory returned an invalid group ID. This group cannot be edited.')
		if (group.trusted === null) return t('mcp', 'The group source is unavailable. Retry before editing.')
		if (group.exists === false) return t('mcp', 'This group no longer exists. Its saved policy does not grant access; you can clear it.')
		if (!group.trusted) return untrusted()
		if (group.state === 'needs_confirm') return t('mcp', 'This policy is awaiting confirmation and does not grant access. Confirming or saving will let it grant access again.')
		if (group.state === 'pending_delete') return t('mcp', 'Group deletion is pending. This policy does not grant access. Check the group before confirming it.')
		return ''
	}
	/** Material Design Icons "lock-outline", in the text colour, so it follows the theme; decoration, hidden from assistive technology. */
	const LOCK_ICON = '<svg class="mcp-group-lock" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12,17C10.89,17 10,16.1 10,15C10,13.89 10.89,13 12,13A2,2 0 0,1 14,15A2,2 0 0,1 12,17M18,20V10H6V20H18M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V10C4,8.89 4.89,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z"/></svg>'
	/**
	 * The state of a group in one short phrase, for its row in the list; '' when the group can grant normally. The
	 * phrase always carries the state in words, next to a symbol, so it never depends on colour.
	 *
	 * @return {{symbol: string, lock?: boolean, text: string, warning: boolean}|null} a lock is drawn as an icon, not as a symbol
	 */
	function rowState(group) {
		if (group.invalidId) return { symbol: '⊘', text: t('mcp', 'Cannot be edited: invalid group ID'), warning: false }
		if (group.trusted === null) return { symbol: '?', text: t('mcp', 'Source unavailable'), warning: true }
		if (group.exists === false) return { symbol: '⊘', text: t('mcp', 'Deleted group'), warning: false }
		if (!group.trusted) return { symbol: '', lock: true, text: t('mcp', 'Cannot grant access'), warning: false }
		if (group.state === 'needs_confirm') return { symbol: '⚠', text: t('mcp', 'Awaiting confirmation'), warning: true }
		if (group.state === 'pending_delete') return { symbol: '⚠', text: t('mcp', 'Deletion pending'), warning: true }
		return UnknownGroupGrants.warning(group)
	}
	/** @return {string} what a group grants, in words, for the accessible name and the tooltip of its row */
	function grantSummary(group) {
		const granted = Object.entries(group.grants || {}).filter(([, operations]) => operations.length > 0).map(([module]) => module)
		if (granted.length === 0) return t('mcp', 'Grants nothing')
		return t('mcp', 'Grants: {permissions}', { permissions: granted.map((module) => module === '_system' ? t('mcp', 'Can connect') : moduleName(module)).join(', ') }, undefined, RAW)
	}
	/** @return {string} what a group grants in one or two words, for the end of its row: "—", "Can connect" or "N modules" */
	function grantShort(group) {
		const granted = Object.entries(group.grants || {}).filter(([, operations]) => operations.length > 0).map(([module]) => module)
		const apps = granted.filter((module) => module !== '_system').length
		if (apps > 0) return n('mcp', '%n module', '%n modules', apps)
		return granted.length > 0 ? t('mcp', 'Can connect') : '—'
	}
	/**
	 * One line per group: the name, cut with an ellipsis; the ID, quietly, only when it differs from the name; and at the
	 * end what it grants in short or the symbol of its state. The full name, the ID and the state or grants in words are
	 * the row's accessible name and tooltip, so nothing is told by the symbol or a colour alone.
	 *
	 * @return {HTMLButtonElement} the row's button; drawList() puts each in its list item
	 */
	function groupRow(group) {
		const differs = group.groupId !== null && group.groupId !== group.displayName
		const children = [el('span', { className: 'mcp-group-name', textContent: group.displayName })]
		if (differs) children.push(el('span', { className: 'mcp-group-id', textContent: group.groupId }))
		const state = rowState(group)
		let end
		if (state) {
			end = el('span', { className: 'mcp-group-end mcp-group-state' + (state.warning ? ' mcp-group-state-warning' : ''), textContent: state.symbol })
			if (state.lock) end.innerHTML = LOCK_ICON
		} else {
			end = el('span', { className: 'mcp-group-end', textContent: grantShort(group) })
		}
		end.setAttribute('aria-hidden', 'true')
		children.push(end)
		const label = [group.displayName, differs ? t('mcp', 'ID: {id}', { id: group.groupId }, undefined, RAW) : '', state ? state.text : grantSummary(group)].filter(Boolean).join('. ')
		const selected = current !== null && group.groupId !== null && current.groupId === group.groupId
		const button = el('button', { type: 'button', className: 'mcp-group-item', title: label, disabled: group.invalidId === true, tabIndex: -1, onclick: () => open(group.groupId, false, false, group.displayName) }, children)
		button.setAttribute('aria-label', label)
		button.setAttribute('aria-current', String(selected))
		return button
	}
	/** The row buttons drawn by drawList(), in order. */
	let buttons = []
	/** The rows that can take the focus, in order. */
	const items = () => buttons.filter((button) => !button.disabled)
	/** One stop in the tab order for the whole list (the open group, else the first); the arrows move inside it. */
	function rove(target) {
		for (const button of items()) button.tabIndex = button === target ? 0 : -1
	}
	/** Scrolls the list, and only the list, so that a row is in view. */
	function keepVisible(button) {
		if (typeof button.offsetTop !== 'number' || typeof list.clientHeight !== 'number') return
		const top = button.offsetTop - list.offsetTop
		if (top < list.scrollTop) list.scrollTop = top
		else if (top + button.offsetHeight > list.scrollTop + list.clientHeight) list.scrollTop = top + button.offsetHeight - list.clientHeight
	}
	function drawList() {
		buttons = rows.map(groupRow)
		list.replaceChildren(...buttons.map((button) => el('li', {}, [button])))
		if (!rows.length) list.append(el('li', { className: 'mcp-hint', textContent: t('mcp', 'No groups found.') }))
		const all = items()
		const open = all.find((button) => button.getAttribute('aria-current') === 'true')
		rove(open || all[0])
		if (open) keepVisible(open)
	}
	list.addEventListener('keydown', (event) => {
		const all = items()
		const at = all.indexOf(document.activeElement)
		if (at < 0) return
		const to = { ArrowDown: at + 1, ArrowUp: at - 1, Home: 0, End: all.length - 1 }[event.key]
		if (to === undefined) return
		event.preventDefault()
		const target = all[Math.max(0, Math.min(all.length - 1, to))]
		rove(target)
		target.focus()
	})
	/** The filter buttons say how many groups each shows, when the server knows it without extra work. */
	function drawFilters(counts) {
		const names = { all: t('mcp', 'All'), granted: t('mcp', 'With grants'), blocked: t('mcp', 'Blocked groups') }
		const counted = { all: (count) => t('mcp', 'All ({count})', { count }, undefined, RAW), granted: (count) => t('mcp', 'With grants ({count})', { count }, undefined, RAW), blocked: (count) => t('mcp', 'Blocked groups ({count})', { count }, undefined, RAW) }
		for (const [key, button] of Object.entries(filters)) {
			if (!button) continue
			const known = counts?.[key]
			button.textContent = typeof known === 'number' ? counted[key](known) : names[key]
			button.setAttribute('aria-pressed', String(key === filter))
		}
	}
	for (const [key, button] of Object.entries(filters)) {
		button?.addEventListener('click', () => {
			if (filter === key) return
			filter = key
			page = 1
			drawFilters(null)
			loadList()
		})
	}
	/** On a narrow screen the list folds into the picker above the editor; on a wide one both columns stay. */
	function setListOpen(isOpen) {
		if (!columns || !picker) return
		columns.setAttribute('data-list', isOpen ? 'open' : 'closed')
		picker.setAttribute('aria-expanded', String(isOpen))
		picker.textContent = current && !isOpen ? t('mcp', 'Group: {group}', { group: shownName ?? current.groupId }, undefined, RAW) : t('mcp', 'Choose a group')
	}
	picker?.addEventListener('click', () => {
		const isOpen = picker.getAttribute('aria-expanded') !== 'true'
		setListOpen(isOpen)
		if (isOpen) search.focus()
	})
	async function loadList() {
		const seq = ++listSeq
		list.setAttribute('aria-busy', 'true')
		prev.disabled = next.disabled = true
		try {
			const data = await api('GET', '/api/group-directory?' + new URLSearchParams({ search: search.value.trim(), page: String(page), filter }))
			if (seq !== listSeq) return
			rows = data.groups
			list.scrollTop = 0
			drawList()
			drawFilters(data.counts)
			const total = typeof data.total === 'number' ? data.total : rows.length
			const first = (page - 1) * PAGE_SIZE + 1
			counter.textContent = rows.length === 0 ? ''
				: page === 1 && !data.hasMore ? n('mcp', '%n group', '%n groups', total)
					: t('mcp', 'Groups {first}–{last} of {total}', { first, last: first + rows.length - 1, total }, undefined, RAW)
			prev.disabled = page <= 1
			next.disabled = !data.hasMore
			say('')
		} catch (e) {
			if (seq !== listSeq) return
			rows = []
			counter.textContent = ''
			list.replaceChildren(el('li', {}, [t('mcp', 'Could not load groups.'), retry(loadList)]))
			say(t('mcp', 'Could not load groups.'), true)
		} finally {
			if (seq === listSeq) list.setAttribute('aria-busy', 'false')
		}
	}
	function retry(action) {
		return el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: action })
	}
	async function open(gid, preserve = false, keepMessage = false, displayName = null) {
		if (busy || (!preserve && !discardDraft())) return
		if (displayName !== null) shownName = displayName
		const seq = ++detailSeq
		// Keep the draft's base until a read succeeds, including after a failed refresh.
		preview = null
		detail.replaceChildren(el('p', { textContent: t('mcp', 'Loading…') }))
		try {
			const data = await api('GET', '/api/group-directory/detail?' + new URLSearchParams({ gid }))
			if (seq !== detailSeq) return
			current = data
			rows = UnknownGroupGrants.refreshRow(rows, current)
			if (!preserve) draft = copyGrants(data.grants)
			if (!keepMessage) say('')
			drawList()
			setListOpen(false)
			render('heading')
		} catch (e) {
			if (seq !== detailSeq) return
			detail.replaceChildren(retry(() => open(gid, preserve)))
			say(t('mcp', 'Could not read the group policy. Your draft is kept. Retry.'), true)
		}
	}
	/** Operations of a module in a grants object, whether it came as an object or as PHP's empty array. */
	const operationsIn = (grants, module) => (grants && !Array.isArray(grants) && grants[module]) || []
	/** The catalog's modules, without the "Can connect" key. */
	const modules = () => Object.entries(current.catalog).filter(([module]) => module !== '_system')
	/**
	 * The level a set of granted operations makes, with the matrix's tiers (src/admin/admin-levels.js). A group only grants, so an
	 * operation outside the set is "not granted", never denied.
	 */
	function levelIn(grants, module) {
		const operations = current.catalog[module]
		const granted = operationsIn(grants, module)
		return window.McpAdminLevels.levelOf(module, operations, Object.fromEntries(operations.map((operation) => [operation, granted.includes(operation)])))
	}
	/** What a member gets without any grant: GrantPolicy::defaultGranted(), reading except the server log. */
	function defaultLevel(module) {
		const operations = current.catalog[module]
		return window.McpAdminLevels.levelOf(module, operations, Object.fromEntries(operations.map((operation) => [operation, operation === 'read' && module !== 'logs'])))
	}
	/** For a group the lowest level is "Not granted": the members keep what they have from elsewhere. */
	function levelName(level) {
		return { ...window.McpAdminPollsGrants?.levelLabels(), forms_write: t('mcp', 'Read and write forms'), forms_write_results: t('mcp', 'Read and write forms and read responses'), none: t('mcp', 'Not granted'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom') }[level] || level
	}
	/** A level in words, a custom one with its operations, since "Custom → Custom" would say nothing. */
	function levelText(grants, module) {
		const level = levelIn(grants, module)
		if (level !== 'custom') return levelName(level)
		return t('mcp', 'Custom ({operations})', { operations: current.catalog[module].filter((operation) => operationsIn(grants, module).includes(operation)).map(operationName).join(', ') }, undefined, RAW)
	}
	/** One line per changed module, "Files: Read → Edit", and one for "Can connect". */
	function changes(before, after) {
		const lines = []
		const eligible = (grants) => operationsIn(grants, '_system').includes('eligible')
		if (eligible(before) !== eligible(after)) {
			lines.push(t('mcp', '{module}: {before} → {after}', { module: t('mcp', 'Can connect'), before: eligible(before) ? t('mcp', 'Yes') : t('mcp', 'No'), after: eligible(after) ? t('mcp', 'Yes') : t('mcp', 'No') }, undefined, RAW))
		}
		for (const [module] of modules()) {
			const was = [...operationsIn(before, module)].sort().join()
			const will = [...operationsIn(after, module)].sort().join()
			if (was !== will) lines.push(t('mcp', '{module}: {before} → {after}', { module: moduleName(module), before: levelText(before, module), after: levelText(after, module) }, undefined, RAW))
		}
		return lines
	}
	function setOperations(module, operations, focusKey) {
		if (operations.length) draft[module] = [...operations].sort()
		else delete draft[module]
		preview = null
		render(focusKey)
	}
	/**
	 * The matrix, filtered by this group and by "With exceptions", for the members whose individual settings win over it.
	 * The matrix's group filter lists the groups it can filter by; a group it does not list (an external group, or one that
	 * no longer exists) is said so here, and the page does not move. The focus goes to the exceptions filter, a real
	 * control, which also tells a screen reader where it landed.
	 */
	function viewInMatrix(gid, name) {
		const select = document.getElementById('mcp-group')
		const exceptionsOnly = document.getElementById('mcp-only-exceptions')
		if (!select || !Array.from(select.options).some((option) => option.value === gid)) {
			say(t('mcp', 'The matrix cannot show only the members of {group}: its group filter does not list this group.', { group: name }, undefined, RAW))
			return
		}
		select.value = gid
		select.dispatchEvent(new Event('change'))
		exceptionsOnly?.click()
		document.getElementById('mcp-block-matrix')?.scrollIntoView?.({ block: 'start' })
		exceptionsOnly?.focus()
	}
	/** A pressed level carries a check mark besides aria-pressed, as in the matrix popover, so it never depends on colour. */
	function levelButton(module, level, pressed, onclick) {
		const children = []
		if (pressed) {
			const mark = el('span', { className: 'mcp-pressed-mark', textContent: '✓' })
			mark.setAttribute('aria-hidden', 'true')
			children.push(mark)
		}
		children.push(levelName(level))
		const button = el('button', { type: 'button', name: 'mcp-group-level-' + module, value: level, className: 'mcp-group-level' + (level === 'none' ? ' mcp-group-level-none' : ''), onclick }, children)
		button.setAttribute('aria-pressed', String(pressed))
		return button
	}
	/**
	 * Draws the open group and focuses the control drawn under focusKey, so a change redraws without losing the place.
	 *
	 * @param {string|null} focusKey key of the control to focus, or null to leave the focus alone
	 */
	function render(focusKey = null) {
		const controls = new Map()
		const keyed = (key, node) => { controls.set(key, node); return node }
		const editable = !busy && current.trusted === true
		const name = shownName ?? current.groupId
		const heading = keyed('heading', el('h3', { tabIndex: -1, textContent: name }))
		heading.setAttribute('aria-describedby', 'mcp-group-alert mcp-group-diagnosis')
		const stats = current.counts
		const members = stats.members === null || stats.members === undefined
			? t('mcp', 'Unknown number of members')
			: n('mcp', '%n member', '%n members', stats.members)
		const head = el('div', { className: 'mcp-group-head' }, [heading])
		if (name !== current.groupId) head.append(el('span', { className: 'mcp-hint', textContent: t('mcp', 'ID: {id}', { id: current.groupId }, undefined, RAW) }))
		head.append(el('span', { className: 'mcp-hint', textContent: members }))
		const clear = el('button', { type: 'button', className: 'mcp-group-clear', textContent: t('mcp', 'Preview clearing group policy'), disabled: busy || grantKeys(current.grants).length === 0, onclick: () => {
			if (!discardDraft()) return
			draft = {}
			run('preview')
		} })
		head.append(clear)
		const confirm = el('button', { type: 'button', textContent: t('mcp', 'Confirm current policy'), disabled: !editable, onclick: () => {
			if (!discardDraft()) return
			draft = copyGrants(current.grants)
			run('preview')
		} })
		confirm.hidden = !['needs_confirm', 'pending_delete'].includes(current.state)
		// The group's notices live in its detail, under the title, with the action they ask for.
		const reason = diagnosis(current)
		const note = el('div', { className: 'mcp-note mcp-group-note', hidden: reason === '' }, [el('p', { id: 'mcp-group-diagnosis', textContent: reason }), confirm])

		const operationsSwitch = keyed('operations', el('input', { type: 'checkbox', id: 'mcp-group-operations', checked: advanced }))
		operationsSwitch.setAttribute('role', 'switch')
		operationsSwitch.addEventListener('change', () => { advanced = operationsSwitch.checked; render('operations') })
		const toolbar = el('div', { className: 'mcp-group-toolbar' }, [el('label', { htmlFor: 'mcp-group-operations' }, [operationsSwitch, t('mcp', 'Operations')])])

		const fields = el('fieldset', { disabled: !editable })
		fields.setAttribute('aria-describedby', 'mcp-group-diagnosis')
		fields.append(el('legend', { className: 'mcp-sr-only', textContent: t('mcp', 'Group grants') }))
		const eligible = keyed('eligible', el('input', { type: 'checkbox', name: 'mcp-group-eligible', id: 'mcp-group-eligible', checked: operationsIn(draft, '_system').includes('eligible') }))
		eligible.setAttribute('role', 'switch')
		eligible.addEventListener('change', () => setOperations('_system', eligible.checked ? ['eligible'] : [], 'eligible'))
		fields.append(el('div', { className: 'mcp-group-row' }, [el('label', { htmlFor: 'mcp-group-eligible', className: 'mcp-group-module' }, [t('mcp', 'Can connect')]), el('span', {}, [eligible]), el('span')]))
		for (const [module, operations] of modules()) {
			const label = el('span', { className: 'mcp-group-module', id: 'mcp-group-module-' + module, textContent: moduleName(module) })
			const granted = operationsIn(draft, module)
			const level = levelIn(draft, module)
			let control
			if (advanced) {
				control = el('span', { className: 'mcp-group-operations' }, operations.map((operation) => {
					const box = keyed('op:' + module + ':' + operation, el('input', { type: 'checkbox', name: 'mcp-group-op-' + module, value: operation, checked: granted.includes(operation) }))
					box.setAttribute('aria-label', moduleName(module) + ': ' + operationName(operation))
					box.addEventListener('change', () => {
						const values = new Set(granted)
						box.checked ? values.add(operation) : values.delete(operation)
						setOperations(module, [...values], 'op:' + module + ':' + operation)
					})
					return el('label', {}, [box, operationName(operation)])
				}))
			} else {
				control = el('span', { className: 'mcp-group-levels' }, window.McpAdminLevels.levels(module, operations).map((choice) => keyed('level:' + module + ':' + choice,
					levelButton(module, choice, choice === level, () => setOperations(module, window.McpAdminLevels.operationsFor(module, operations, choice), 'level:' + module + ':' + choice)))))
			}
			control.setAttribute('role', 'group')
			control.setAttribute('aria-labelledby', 'mcp-group-module-' + module)
			let hint = ''
			if (level === 'none') hint = t('mcp', 'Default: {level}', { level: defaultLevel(module) === 'none' ? t('mcp', 'None') : levelName(defaultLevel(module)) }, undefined, RAW)
			else if (level === 'custom' && !advanced) hint = t('mcp', 'Custom: change it in Operations')
			fields.append(el('div', { className: 'mcp-group-row' }, [label, control, el('span', { className: 'mcp-hint', textContent: hint })]))
		}

		// One sentence and a link instead of a table of counters: who wins over this group, and where to see them.
		const overrides = stats.overrides
		const exceptions = el('p', { className: 'mcp-group-exceptions' }, [overrides === null || overrides === undefined
			? t('mcp', 'The members with individual settings could not be counted.')
			: overrides === 0 ? t('mcp', 'No member has individual settings that take precedence over this group.')
				: n('mcp', '%n member has individual settings that take precedence over this group.', '%n members have individual settings that take precedence over this group.', overrides)])
		if (overrides !== 0) exceptions.append(el('button', { type: 'button', className: 'mcp-link-button', textContent: t('mcp', 'View in the matrix'), onclick: () => viewInMatrix(current.groupId, name) }))

		const parts = [head, note, ...UnknownGroupGrants.render(current, busy, (row) => UnknownGroupGrants.clean(current, row, {
			api, say, setBusy: (value) => { busy = value; root.setAttribute('aria-busy', String(value)); render() },
			refresh: async (gid) => { await open(gid, true, true) },
			saved: () => window.dispatchEvent(new Event('mcp-group-policy-saved')),
		})), toolbar, fields, exceptions]
		const save = keyed('save', el('button', { type: 'button', className: 'primary', textContent: t('mcp', 'Save group policy'), disabled: busy || !preview, onclick: () => run('save') }))
		const review = keyed('review', el('button', { type: 'button', className: 'primary', textContent: t('mcp', 'Review and save'), disabled: !editable, onclick: () => run('preview') }))
		for (const button of [review, confirm, clear, save]) button.setAttribute('aria-describedby', 'mcp-group-diagnosis')
		if (preview) {
			// The review of the preview the server returned: what changes, who is affected, and the save it allows.
			const region = el('div', { className: 'mcp-group-review' })
			region.setAttribute('role', 'region')
			region.setAttribute('aria-labelledby', 'mcp-group-review-title')
			const lines = changes(preview.current || current.grants, preview.grants || draft)
			region.append(el('h4', { id: 'mcp-group-review-title', textContent: t('mcp', 'Review the changes to {group}', { group: name }, undefined, RAW) }),
				lines.length ? el('ul', {}, lines.map((line) => el('li', { textContent: line }))) : el('p', { textContent: t('mcp', 'No change to the saved policy.') }),
				el('p', { textContent: t('mcp', 'Group grants apply to the live group, including future members. Individual overrides take precedence. Nextcloud permissions still apply.') }),
				el('p', { textContent: t('mcp', 'Preview: {members} current members; {revokes} credential holders will be signed out.', { members: count(preview.members), revokes: count(preview.revokes) }) }))
			if (preview.withdraws) region.append(el('p', { className: 'mcp-group-warning', textContent: t('mcp', 'Losing the last source of eligibility revokes connections. Restoring this policy does not reconnect users.') }))
			const back = el('button', { type: 'button', textContent: t('mcp', 'Back'), disabled: busy, onclick: () => { preview = null; render('review') } })
			region.append(el('div', { className: 'mcp-group-actions' }, [back, save]))
			parts.push(region)
		} else if (dirty()) {
			const lines = changes(current.grants, draft)
			const bar = el('div', { className: 'mcp-group-savebar' }, [
				el('div', { className: 'mcp-group-savebar-text' }, [el('strong', { textContent: n('mcp', '%n unsaved change', '%n unsaved changes', lines.length) }), el('span', { className: 'mcp-hint', textContent: lines.join('; ') })]),
				el('button', { type: 'button', textContent: t('mcp', 'Discard'), disabled: busy, onclick: () => { draft = copyGrants(current.grants); render('heading') } }),
				review,
			])
			parts.push(bar)
		}
		detail.replaceChildren(...parts)
		if (focusKey !== null) (controls.get(focusKey) || heading).focus()
	}
	async function run(action) {
		if (busy || !current || (action === 'save' && !preview)) return
		if (action === 'save' && window.McpAdminMailGrants && !window.McpAdminMailGrants.confirmWrites(Object.entries(draft)
			.flatMap(([module, operations]) => operations.filter((operation) => !(current.grants[module] || []).includes(operation)).map((operation) => ({ module, operation }))))) return
		busy = true
		const gid = current.groupId
		const body = { gid, grants: structuredClone(draft), expectedRevision: action === 'save' ? preview.revision : current.revision }
		if (action === 'save') body.previewId = preview.previewId
		render()
		root.setAttribute('aria-busy', 'true')
		let refresh = false
		try {
			const data = await api(action === 'save' ? 'PUT' : 'POST', '/api/group-grants' + (action === 'save' ? '' : '/preview'), body)
			if (action === 'preview') {
				preview = data
				say(t('mcp', 'Review the impact, then save the group policy.'))
			} else {
				preview = null
				refresh = true
				say(t('mcp', 'Saved'))
				window.dispatchEvent(new Event('mcp-group-policy-saved'))
			}
		} catch (e) {
			preview = null
			if (action === 'save' && e.message === 'unconfirmed') {
				refresh = true
				say(t('mcp', 'The change could not be confirmed. Your draft is kept. Retry after checking the group.'), true)
			} else if (e.message === '409' || e.message === '428') {
				// A fresh read pairs the revision with the stored policy. Never adopt an error revision or replay.
				refresh = true
				say(t('mcp', 'The policy or group members changed. Your draft is kept. Review a new preview before saving again.'), true)
			} else if (e.message === '503') {
				refresh = true
				say(t('mcp', 'The change could not be confirmed. Your draft is kept. Retry after checking the group.'), true)
			} else if (e.message === '422') {
				refresh = true
				say(untrusted(), true)
			} else {
				say(t('mcp', 'Could not save the group policy. Your draft is kept. Request a new preview and retry.'), true)
			}
		} finally {
			busy = false
			root.setAttribute('aria-busy', 'false')
			if (refresh) await open(gid, true, true)
			else render(action === 'preview' && preview ? 'save' : 'heading')
		}
	}
	search.addEventListener('input', () => {
		clearTimeout(timer)
		timer = setTimeout(() => { page = 1; loadList() }, 300)
	})
	prev.addEventListener('click', () => { page = Math.max(1, page - 1); loadList() })
	next.addEventListener('click', () => { page++; loadList() })
	loadList()
})()
;
/* admin/admin-scope.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Resource scope panel of the admin page (issue #4, B7; contracts B.6). Plain JavaScript, no build. One user at a
 * time: per module of the scope (Files, Calendar, Tasks) the administrator chooses "All resources" or "Only
 * selected" and picks folders, calendars or task lists from the user's own Nextcloud resources, through the admin
 * routes of the scope (GET /api/scope/{uid}/{module}, its candidates, PUT with expectedRevision, undo through
 * /api/grants/undo). Nothing here decides access: the server validates every item and the tools apply the scope.
 *
 * A module whose activation barrier is still closed on the server (`active: false`) is shown with its saved state
 * and browsable, but cannot be saved, and says so: the tools of that module do not apply a scope yet. A conflict
 * (409) never repeats the write: the current scope is read again and shown. Each module keeps the revision its own
 * draft was read at, so a draft made before a change elsewhere is refused by the server instead of overwriting it.
 * A write of this page (a module here, or the matrix, OAuth clients or server log of src/admin/admin-grants.js) is announced
 * with the revision it was sent with and the one it made (event "mcp-revision-moved"); a module still at the first
 * takes the second and keeps its draft, since nothing but that write lies between them.
 * Exposes window.McpScopeUi for the matrix, which shows "Restricted to N" (or "Scope unknown") in the module's first
 * cell and opens this panel from it.
 *
 * The panel is a drawer at the side of the page (S9): the Access tab shows one sentence and a button, and the drawer
 * opens from that button, from a "Restricted to N" cell or from the actions menu of a matrix row. Its modules are tabs
 * that say the saved state ("Files: 3", "Calendar: all") and whether a draft is not saved. Escape and the close button
 * close it, asking first when a draft would be lost, and the focus goes back to the control that opened it. The drawer
 * is not modal, so the matrix stays usable beside it: opening another person while a draft is open asks first too, and
 * close() says whether the drawer really closed, so another drawer never opens over a draft that was kept.
 */
(function () {
	'use strict'
	const MODULES = ['files', 'calendar', 'tasks']
	const UNDO_SECONDS = 10
	/** Delay before a message is written, so the same message twice in a row is announced twice (as in the matrix). */
	const SAY_DELAY = 60
	const root = document.getElementById('mcp-scope')
	const form = document.getElementById('mcp-scope-form')
	const userInput = document.getElementById('mcp-scope-user')
	const status = document.getElementById('mcp-scope-status')
	const alertBox = document.getElementById('mcp-scope-alert')
	const container = document.getElementById('mcp-scope-modules')
	const tabs = document.getElementById('mcp-scope-tabs')
	const drawerTitle = document.getElementById('mcp-scope-title')
	const closeButton = document.getElementById('mcp-scope-close')
	const entryButton = document.getElementById('mcp-scope-entry')
	/** The module whose tab is open, and the control the focus goes back to when the drawer closes. */
	let currentTab = 'files'
	let returnFocus = null
	let returnRow = null
	let uid = null
	let seq = 0
	let sayTimer = null
	/** @type {Object<string, object>} state of each module of the open user */
	let modules = {}
	/** Page event of a write this page committed: detail {from, to}; src/admin/admin-grants.js sends and follows it too. */
	const REVISION_MOVED = 'mcp-revision-moved'

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		Object.assign(node, props)
		node.append(...children)
		return node
	}
	function say(message, error = false) {
		clearTimeout(sayTimer)
		status.textContent = ''
		alertBox.textContent = ''
		if (message === '') return
		const region = error ? alertBox : status
		sayTimer = setTimeout(() => { region.textContent = message }, SAY_DELAY)
	}
	async function api(method, path, query, body) {
		const url = OC.generateUrl('/apps/mcp' + path) + (query ? '?' + new URLSearchParams(query) : '')
		const response = await fetch(url, {
			method,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		// A 2xx HTML response cannot confirm a save or undo; retain HTTP status handling for errors.
		const data = await response.json().catch((error) => {
			if (response.ok) throw Object.assign(new Error('unconfirmed'), { cause: error })
			return {}
		})
		if (!response.ok) throw Object.assign(new Error(String(response.status)), { status: response.status, data })
		return data
	}
	function scopePath(module, suffix = '') {
		return '/api/scope/' + encodeURIComponent(uid) + '/' + module + suffix
	}
	function title(module) {
		return { files: t('mcp', 'Files: folders'), calendar: t('mcp', 'Calendar: calendars'), tasks: t('mcp', 'Tasks: task lists') }[module]
	}
	function emptyText(module) {
		return {
			files: t('mcp', 'Nothing selected: the Files tools of this user reach no folder.'),
			calendar: t('mcp', 'Nothing selected: the Calendar tools of this user reach no calendar.'),
			tasks: t('mcp', 'Nothing selected: the Tasks tools of this user reach no task list.'),
		}[module]
	}
	function failure(error) {
		if (error.status === 403) return t('mcp', 'Only administrators can change the resource scope.')
		if (error.status === 400) return t('mcp', 'Check the user ID and try again.')
		if (error.status === 503) return t('mcp', 'The MCP policy cannot be read right now. Nothing was changed. Try again shortly.')
		return t('mcp', 'The request failed. Try again.')
	}
	/** What a saved or selected item is called on screen: its path for a folder, its name otherwise. */
	function label(item) {
		if (item.found === false) return t('mcp', 'Resource no longer found ({id})', { id: item.id }, undefined, { escape: false })
		return item.path || item.name
	}
	function badges(item) {
		const out = []
		if (item.found === false) out.push(el('span', { className: 'mcp-badge', textContent: t('mcp', 'not found: it has no effect') }))
		if (item.excluded) out.push(el('span', { className: 'mcp-badge', textContent: t('mcp', 'excluded by a tag: choosing it has no effect') }))
		if (item.inaccessible) out.push(el('span', { className: 'mcp-badge mcp-badge-error', textContent: t('mcp', 'the user cannot reach it in Nextcloud') }))
		return out
	}

	/**
	 * A message of one module: shown under it and announced through the page's live regions, which exist from the
	 * start (a region created with its text already in it is not reliably announced).
	 */
	function note(state, message, error = false) {
		state.line = message
		if (message !== '') say(title(state.module) + ': ' + message, error)
	}

	/** @return {boolean} whether the draft differs from what is saved */
	function dirty(state) {
		const ids = (items) => items.map((item) => item.id).sort().join(',')
		return state.mode !== state.savedMode || (state.mode === 'restricted' && ids(state.items) !== ids(state.savedItems))
	}

	async function openUser(target, focusModule) {
		const mine = ++seq
		uid = target
		modules = {}
		currentTab = focusModule || MODULES[0]
		tabs?.replaceChildren()
		container.replaceChildren()
		container.setAttribute('aria-busy', 'true')
		say(t('mcp', 'Loading the resource scope…'))
		try {
			const loaded = await Promise.all(MODULES.map((module) => api('GET', scopePath(module))))
			if (mine !== seq) return
			loaded.forEach((data) => {
				modules[data.module] = {
					module: data.module, active: data.active, limit: data.limit, revision: data.revision,
					mode: data.mode, savedMode: data.mode, items: [...data.items], savedItems: [...data.items],
					search: '', parents: [], results: null, page: 1, undo: null, saving: false, stale: false,
				}
			})
			MODULES.forEach((module) => container.append(renderModule(modules[module])))
			renderTabs()
			say(t('mcp', 'Resource scope of {user}.', { user: uid }, undefined, { escape: false }))
			const heading = document.getElementById('mcp-scope-heading-' + (focusModule || MODULES[0]))
			if (focusModule && heading) {
				heading.scrollIntoView?.({ block: 'start' })
				heading.focus?.()
			}
		} catch (error) {
			if (mine !== seq) return
			uid = null
			say(failure(error), true)
		} finally {
			if (mine === seq) container.setAttribute('aria-busy', 'false')
		}
	}

	/** Reads one module again from the server; its draft is replaced by the saved scope and its revision. */
	async function reload(state) {
		const data = await api('GET', scopePath(state.module))
		Object.assign(state, { active: data.active, revision: data.revision, stale: false,
			mode: data.mode, savedMode: data.mode, items: [...data.items], savedItems: [...data.items] })
		rerender(state)
	}

	/**
	 * The policy revision moved (a save, an undo, a conflict): the three modules are read again. A module with an
	 * unsaved draft keeps it. When it already took the revision this page's own write made (`current`), the draft is
	 * still valid and stays as it is. Otherwise it keeps the revision it was read at, and says so: saving it is refused
	 * by the server (409) rather than accepted against a scope it never saw, and discarding it loads the saved scope.
	 *
	 * @param {object} origin the module that saved, undid or was refused
	 * @param {string} [current] revision made by this page's own write, when it committed
	 */
	async function reloadAll(origin, current) {
		for (const module of MODULES) {
			const state = modules[module]
			if (state === origin || !dirty(state)) {
				await reload(state).catch(() => {})
			} else if (current === undefined || state.revision !== current) {
				state.stale = true
				state.line = t('mcp', 'Changed before the policy moved, so saving will be refused. Discard the changes to load the saved scope.')
				rerender(state)
			}
		}
	}

	function rerender(state, focusId) {
		const old = document.getElementById('mcp-scope-module-' + state.module)
		const fresh = renderModule(state)
		if (old) old.replaceWith(fresh)
		renderTabs()
		if (focusId) document.getElementById(focusId)?.focus?.()
	}

	/** @return {string} the tab of a module: its saved state, and whether a draft is not saved */
	function tabLabel(state) {
		const name = { files: t('mcp', 'Files'), calendar: t('mcp', 'Calendar'), tasks: t('mcp', 'Tasks') }[state.module]
		const saved = state.savedMode === 'restricted'
			? t('mcp', '{module}: {count}', { module: name, count: state.savedItems.length }, undefined, { escape: false })
			: t('mcp', '{module}: all', { module: name }, undefined, { escape: false })
		return dirty(state) ? saved + ' (' + t('mcp', 'not saved') + ')' : saved
	}

	/** The module tabs of the drawer (WAI-ARIA tabs: the arrow keys, Home and End move between them). */
	function renderTabs() {
		if (!tabs) return
		const shown = MODULES.filter((module) => modules[module])
		tabs.replaceChildren(...shown.map((module, index) => {
			const selected = module === currentTab
			const tab = el('button', { type: 'button', id: 'mcp-scope-tab-' + module, textContent: tabLabel(modules[module]), tabIndex: selected ? 0 : -1 })
			tab.setAttribute('role', 'tab')
			tab.setAttribute('aria-selected', String(selected))
			tab.setAttribute('aria-controls', 'mcp-scope-module-' + module)
			tab.addEventListener('click', () => selectTab(module, true))
			tab.addEventListener('keydown', (event) => {
				const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: shown.length - 1 }[event.key]
				if (next === undefined) return
				event.preventDefault?.()
				selectTab(shown[(next + shown.length) % shown.length], true)
			})
			return tab
		}))
	}

	/** Shows one module's section and marks its tab. */
	function selectTab(module, focus) {
		currentTab = module
		for (const other of MODULES) {
			const section = document.getElementById('mcp-scope-module-' + other)
			if (section) section.hidden = Boolean(tabs) && other !== module
		}
		renderTabs()
		if (focus) document.getElementById('mcp-scope-tab-' + module)?.focus?.()
	}

	/**
	 * Puts the open drawer right under the Nextcloud header. A drawer is position: fixed, but Nextcloud 35 blurs the content
	 * behind it (backdrop-filter on #content-vue), which makes that box, not the window, the one it is placed in: its top
	 * would then be counted from the box, one header too low. The offset is measured, not assumed, since a theme may turn
	 * the blur off.
	 */
	function placeDrawer() {
		root.style?.removeProperty?.('top')
		const rect = root.getBoundingClientRect?.()
		if (!rect) return
		const header = document.getElementById('header')?.getBoundingClientRect?.().bottom ?? 0
		const shift = rect.top - header
		if (Math.abs(shift) >= 1) root.style.top = (parseFloat(getComputedStyle(root).top) - shift) + 'px'
	}

	/** Opens the drawer; the focus goes back to `trigger` when it closes. */
	function openDrawer(trigger) {
		// Only one drawer is open at a time: the inspector closes when the scope opens.
		window.McpInspectorUi?.close?.()
		returnFocus = trigger || document.activeElement || null
		// The row of a "…" button that opened the drawer: the table may be drawn again meanwhile, and its new button is
		// the one that gets the focus back.
		returnRow = returnFocus?.closest?.('tr')?.dataset?.uid ?? null
		root.classList?.add('mcp-drawer-open')
		root.setAttribute('role', 'dialog')
		placeDrawer()
	}

	/** @return {boolean} whether a module has a draft that is not saved */
	function hasDraft() {
		return Object.values(modules).some(dirty)
	}

	/** Drops the drafts the administrator agreed to lose, so they never come back when the drawer opens again. */
	function forget() {
		seq++
		uid = null
		modules = {}
		tabs?.replaceChildren()
		container.replaceChildren()
	}

	/**
	 * Closes the drawer, asking first when a draft of a module would be lost.
	 *
	 * @return {boolean} whether the drawer is closed now: false when the administrator kept the draft
	 */
	function closeDrawer() {
		if (!root.classList?.contains('mcp-drawer-open')) return true
		if (hasDraft()) {
			if (!window.confirm(t('mcp', 'Close the resource scope? The changes that are not saved are lost.'))) return false
			forget()
		}
		root.classList.remove('mcp-drawer-open')
		root.removeAttribute?.('role')
		root.style?.removeProperty?.('top')
		const target = returnFocus
		returnFocus = null
		focusBack(target)
		window.dispatchEvent?.(new Event('mcp-drawer-closed'))
		return true
	}

	/**
	 * Whether the drafts of the open user may be lost to open another one (or the same one read again): asked, never
	 * assumed.
	 *
	 * @return {boolean}
	 */
	function mayReplace() {
		return !hasDraft() || window.confirm(t('mcp', 'Open another person? The changes that are not saved for {user} are lost.', { user: uid }, undefined, { escape: false }))
	}

	/**
	 * Gives the focus back to the control that opened the drawer, or, when the table was drawn again and that control is
	 * gone, to the same row's "…" button, then to the drawer's own button on the Access tab: never to the page.
	 */
	function focusBack(target) {
		if (target && target.isConnected !== false) {
			target.focus?.()
			return
		}
		const rows = [...(document.getElementById('mcp-matrix')?.tBodies?.[0]?.rows || [])]
		const row = returnRow === null ? null : rows.find((candidate) => candidate.dataset.uid === returnRow)
		;(row?.querySelector('button.mcp-row-actions-button') || entryButton)?.focus?.()
	}

	function renderModule(state) {
		const module = state.module
		const section = el('section', { id: 'mcp-scope-module-' + module, className: 'mcp-scope-module', hidden: Boolean(tabs) && module !== currentTab })
		const heading = el('h3', { id: 'mcp-scope-heading-' + module, textContent: title(module), tabIndex: -1 })
		section.setAttribute('aria-labelledby', heading.id)
		section.append(heading)
		if (state.stale) {
			section.append(el('p', { className: 'mcp-scope-inactive', textContent: t('mcp', 'Changed before the policy moved, so saving will be refused. Discard the changes to load the saved scope.') }))
		}
		if (!state.active) {
			section.append(el('p', { className: 'mcp-scope-inactive', textContent: t('mcp', 'Not available in this version: the tools of this module do not apply a scope yet, so nothing can be saved. You can still browse.') }))
		}
		const fieldset = el('fieldset', {}, [el('legend', { textContent: t('mcp', 'Resources the MCP tools of {user} may reach', { user: uid }, undefined, { escape: false }) })])
		;[['unrestricted', t('mcp', 'All resources')], ['restricted', t('mcp', 'Only selected')]].forEach(([value, text]) => {
			const id = 'mcp-scope-mode-' + module + '-' + value
			const radio = el('input', { type: 'radio', name: 'mcp-scope-mode-' + module, id, value, checked: state.mode === value })
			radio.addEventListener('change', () => {
				if (!radio.checked) return
				state.mode = value
				rerender(state, id)
			})
			fieldset.append(el('span', { className: 'mcp-scope-choice' }, [radio, el('label', { htmlFor: id, textContent: text })]))
		})
		section.append(fieldset)
		if (state.mode === 'restricted') {
			section.append(renderSelection(state), renderPicker(state))
		}
		// Nothing can be sent twice: Save, Discard and Undo wait for the answer of the request in flight.
		const save = el('button', { type: 'button', id: 'mcp-scope-save-' + module, textContent: t('mcp', 'Save'), disabled: state.saving || !state.active || !dirty(state) })
		save.addEventListener('click', () => saveModule(state))
		const discard = el('button', { type: 'button', id: 'mcp-scope-discard-' + module, textContent: t('mcp', 'Discard changes'), disabled: state.saving || (!dirty(state) && !state.stale) })
		discard.addEventListener('click', async () => {
			if (state.stale) {
				// A draft of an older revision: what is saved now comes from the server, not from the old copy.
				await reload(state).catch((error) => note(state, failure(error), true))
				rerender(state, 'mcp-scope-mode-' + module + '-' + state.mode)
				return
			}
			Object.assign(state, { mode: state.savedMode, items: [...state.savedItems] })
			rerender(state, 'mcp-scope-mode-' + module + '-' + state.mode)
		})
		const actions = el('div', { className: 'mcp-scope-actions' }, [save, discard])
		if (state.undo) {
			const offer = state.undo
			const undo = el('button', { type: 'button', id: 'mcp-scope-undo-' + module, textContent: t('mcp', 'Undo'), disabled: state.saving })
			undo.addEventListener('click', () => undoModule(state))
			// The ten seconds pause while the pointer or the focus is on the button (WCAG 2.2.1).
			const hold = (key, value) => () => {
				offer[key] = value
				if (!value) expireUndo(state, offer)
			}
			undo.addEventListener('mouseenter', hold('hover', true))
			undo.addEventListener('mouseleave', hold('hover', false))
			undo.addEventListener('focus', hold('focus', true))
			undo.addEventListener('blur', hold('focus', false))
			actions.append(undo)
		}
		section.append(actions)
		section.append(el('p', { id: 'mcp-scope-line-' + module, className: 'mcp-scope-line', textContent: state.line || '', tabIndex: -1 }))
		return section
	}

	function renderSelection(state) {
		const module = state.module
		const wrap = el('div', { className: 'mcp-scope-selection' })
		wrap.append(el('p', { textContent: state.items.length === 0 ? emptyText(module) : t('mcp', '{count} of at most {limit} selected', { count: state.items.length, limit: state.limit }, undefined, { escape: false }) }))
		if (state.items.length > 0) {
			const list = el('ul', { className: 'mcp-scope-list' })
			list.setAttribute('aria-label', t('mcp', 'Selected resources'))
			state.items.forEach((item, index) => {
				const remove = el('button', { type: 'button', id: 'mcp-scope-remove-' + module + '-' + item.id, textContent: t('mcp', 'Remove') })
				remove.setAttribute('aria-label', t('mcp', 'Remove {name}', { name: label(item) }, undefined, { escape: false }))
				remove.addEventListener('click', () => {
					state.items = state.items.filter((other) => other.id !== item.id)
					// The focus goes to the next item, else the previous one, else the mode choice.
					const next = state.items[index] || state.items[index - 1]
					rerender(state, next ? 'mcp-scope-remove-' + module + '-' + next.id : 'mcp-scope-mode-' + module + '-restricted')
				})
				list.append(el('li', {}, [el('span', { textContent: label(item) }), ...badges(item), remove]))
			})
			wrap.append(list)
		}
		return wrap
	}

	function renderPicker(state) {
		const module = state.module
		const picker = el('div', { className: 'mcp-scope-picker' })
		const searchId = 'mcp-scope-search-' + module
		const search = el('input', { type: 'search', id: searchId, value: state.search, autocomplete: 'off', spellcheck: false })
		const go = el('button', { type: 'button', textContent: t('mcp', 'Search') })
		const run = () => { state.search = search.value; state.page = 1; state.results = null; browse(state, searchId) }
		go.addEventListener('click', run)
		search.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); run() } })
		picker.append(el('label', { htmlFor: searchId, textContent: module === 'files' ? t('mcp', 'Find a folder of the user') : t('mcp', 'Find a calendar or task list of the user') }), search, go)
		if (module === 'files') {
			const here = state.parents[state.parents.length - 1]
			picker.append(el('p', { className: 'mcp-scope-where', textContent: here ? t('mcp', 'In folder {path}', { path: here.path }, undefined, { escape: false }) : t('mcp', 'In the top folder of the user') }))
			if (here) {
				const up = el('button', { type: 'button', id: 'mcp-scope-up-files', textContent: t('mcp', 'Up one folder') })
				up.addEventListener('click', () => { state.parents.pop(); state.page = 1; state.results = null; browse(state, 'mcp-scope-up-files') })
				picker.append(up, addButton(state, here, t('mcp', 'Add this folder')))
			}
		}
		const results = el('ul', { className: 'mcp-scope-results' })
		results.setAttribute('aria-label', t('mcp', 'Resources found'))
		const count = el('p', { className: 'mcp-scope-count' })
		count.setAttribute('aria-live', 'polite')
		if (state.results === null) {
			count.textContent = t('mcp', 'Search, or press Search with an empty field to list everything.')
		} else {
			count.textContent = state.results.total === 0 ? t('mcp', 'Nothing found.') : t('mcp', '{shown} of {total} shown', { shown: state.results.items.length, total: state.results.total }, undefined, { escape: false })
			state.results.items.forEach((item) => {
				const row = el('li', {}, [el('span', { textContent: label(item) }), ...badges(item), addButton(state, item)])
				if (module === 'files') {
					const open = el('button', { type: 'button', textContent: t('mcp', 'Open') })
					open.setAttribute('aria-label', t('mcp', 'Open {name}', { name: label(item) }, undefined, { escape: false }))
					open.addEventListener('click', () => { state.parents.push(item); state.page = 1; state.results = null; state.search = ''; browse(state, searchId) })
					row.append(open)
				}
				results.append(row)
			})
			if (state.results.hasMore) {
				const more = el('button', { type: 'button', id: 'mcp-scope-more-' + module, textContent: t('mcp', 'Show more') })
				more.addEventListener('click', () => { state.page += 1; browse(state, 'mcp-scope-more-' + module, true) })
				picker.append(results, count, more)
				return picker
			}
		}
		picker.append(results, count)
		return picker
	}

	function addButton(state, item, text) {
		const chosen = state.items.some((other) => other.id === item.id)
		const add = el('button', { type: 'button', textContent: text || (chosen ? t('mcp', 'Added') : t('mcp', 'Add')), disabled: chosen })
		add.setAttribute('aria-label', chosen ? t('mcp', '{name} is selected', { name: label(item) }, undefined, { escape: false }) : t('mcp', 'Add {name}', { name: label(item) }, undefined, { escape: false }))
		add.addEventListener('click', () => {
			if (state.items.length >= state.limit) {
				note(state, t('mcp', 'At most {limit} resources can be selected.', { limit: state.limit }, undefined, { escape: false }), true)
				rerender(state)
				return
			}
			state.items = [...state.items, { id: item.id, name: item.name, path: item.path, found: true, excluded: item.excluded }]
			note(state, t('mcp', '{name} added. Save to apply it.', { name: label(item) }, undefined, { escape: false }))
			rerender(state, 'mcp-scope-search-' + state.module)
		})
		return add
	}

	async function browse(state, focusId, append = false) {
		const query = { search: state.search, page: String(state.page) }
		const here = state.parents[state.parents.length - 1]
		if (state.module === 'files' && here) query.parent = here.id
		try {
			const data = await api('GET', scopePath(state.module, '/candidates'), query)
			state.results = append && state.results ? Object.assign(data, { items: [...state.results.items, ...data.items] }) : data
			state.line = ''
		} catch (error) {
			state.results = null
			note(state, failure(error), true)
		}
		rerender(state, focusId)
	}

	async function saveModule(state) {
		if (state.saving) return
		// The revision this module's draft was read at, never one another module moved to since.
		const body = state.mode === 'unrestricted'
			? { expectedRevision: state.revision, mode: 'unrestricted' }
			: { expectedRevision: state.revision, mode: 'restricted', items: state.items.map((item) => item.id) }
		state.saving = true
		note(state, t('mcp', 'Saving…'))
		rerender(state)
		try {
			const result = await api('PUT', scopePath(state.module), null, body)
			state.saving = false
			announce(body.expectedRevision, result.revision)
			await reloadAll(state, result.revision)
			note(state, result.changed ? t('mcp', 'Saved. It applies from the next MCP call of the user; sessions are not ended.') : t('mcp', 'Nothing changed.'))
			if (result.changed && result.receiptId) offerUndo(state, result.receiptId, result.revision)
			rerender(state, 'mcp-scope-line-' + state.module)
			window.dispatchEvent(new Event('mcp-scope-saved'))
		} catch (error) {
			state.saving = false
			await saveFailed(state, error)
		}
	}

	async function saveFailed(state, error) {
		const data = error.data || {}
		if (error.message === 'unconfirmed') {
			note(state, t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.'), true)
			const line = state.line
			await reloadAll(state)
			state.line = line
		} else if (error.status === 409) {
			// Never written again from here: the admin sees what is saved now and decides.
			note(state, t('mcp', 'The policy changed after this scope was loaded, so nothing was saved. The saved scope is shown again.'), true)
			const line = state.line
			await reloadAll(state)
			state.line = line
		} else if (error.status === 422 && data.error === 'ScopeInactive') {
			state.active = false
			note(state, t('mcp', 'Not saved: the tools of this module do not apply a resource scope in this version.'), true)
		} else if (error.status === 422 && data.error === 'Inaccessible') {
			const missing = new Set(data.items || [])
			state.items = state.items.map((item) => missing.has(item.id) ? Object.assign({}, item, { inaccessible: true }) : item)
			note(state, t('mcp', 'Not saved: the user cannot reach the marked resources in Nextcloud. Remove them and save again.'), true)
		} else if (error.status === 422 && data.error === 'TooManyResources') {
			note(state, t('mcp', 'Not saved: at most {limit} resources can be selected.', { limit: data.limit || state.limit }, undefined, { escape: false }), true)
		} else if (error.status === 503 && data.uncertain) {
			note(state, t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.'), true)
			const line = state.line
			await reloadAll(state)
			state.line = line
		} else {
			note(state, failure(error), true)
		}
		rerender(state, 'mcp-scope-line-' + state.module)
	}

	function offerUndo(state, receiptId, savedRevision) {
		const offer = { receiptId, savedRevision, hover: false, focus: false, expired: false }
		state.undo = offer
		setTimeout(() => {
			offer.expired = true
			expireUndo(state, offer)
		}, UNDO_SECONDS * 1000)
	}

	/** Removes an expired undo once neither the pointer nor the focus holds it; the focus never falls to the page. */
	function expireUndo(state, offer) {
		if (state.undo !== offer || !offer.expired || offer.hover || offer.focus) return
		const focused = document.activeElement && document.activeElement.id === 'mcp-scope-undo-' + state.module
		state.undo = null
		rerender(state, focused ? 'mcp-scope-line-' + state.module : undefined)
	}

	async function undoModule(state) {
		if (state.saving) return
		const { receiptId, savedRevision } = state.undo
		state.undo = null
		state.saving = true
		rerender(state)
		let current
		try {
			current = (await api('POST', '/api/grants/undo', null, { receiptId, expectedRevision: savedRevision })).revision
			announce(savedRevision, current)
			note(state, t('mcp', 'Change undone.'))
			window.dispatchEvent(new Event('mcp-scope-saved'))
		} catch (error) {
			note(state, error.message === 'unconfirmed'
				? t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.')
				: error.status === 409
					? t('mcp', 'The policy changed after this change, so it can no longer be undone.')
					: error.status === 410
						? t('mcp', 'This change can no longer be undone: the time to undo it has passed.')
						: failure(error), true)
		}
		state.saving = false
		const line = state.line
		await reloadAll(state, current)
		state.line = line
		rerender(state, 'mcp-scope-line-' + state.module)
	}

	/**
	 * Tells the other blocks of the page that a write of this panel moved the revision from `from` to `to`.
	 *
	 * @param {string} from revision the write was sent with
	 * @param {string} to revision the server answered with
	 */
	function announce(from, to) {
		if (typeof from === 'string' && typeof to === 'string' && from !== to) {
			window.dispatchEvent(new CustomEvent(REVISION_MOVED, { detail: { from, to } }))
		}
	}

	/**
	 * The matrix cell mark of a module that is restricted, or whose scope cannot be read: visible text, no colour or
	 * icon alone, and a way into the panel. An unrestricted module gets no mark.
	 *
	 * @param {string} target uid of the row
	 * @param {string} module scope module
	 * @param {{mode: string, count: ?number}} scope the cell's scope from the matrix
	 * @return {HTMLButtonElement|null}
	 */
	function indicator(target, module, scope) {
		const unknown = scope.mode === 'unavailable'
		if (!unknown && scope.mode !== 'restricted') return null
		const mark = el('span', { className: 'mcp-scope-mark', textContent: unknown ? '?' : '◩' })
		mark.setAttribute('aria-hidden', 'true')
		const button = el('button', { type: 'button', className: 'mcp-scope-indicator' }, [mark, ' ' + (unknown ? t('mcp', 'Scope unknown') : t('mcp', 'Restricted to {count}', { count: scope.count }, undefined, { escape: false }))])
		button.title = unknown
			? t('mcp', 'The scope cannot be read now, so the tools of this module refuse until it can. Open the resource scope.')
			: t('mcp', 'The MCP tools of this user reach only the selected resources of this module. Open the resource scope.')
		button.addEventListener('click', () => open(target, module, button))
		return button
	}

	/**
	 * Opens the drawer on one user, at one module when given.
	 *
	 * @param {string} target user ID
	 * @param {string} [module] module whose tab opens
	 * @param {HTMLElement} [trigger] control the focus goes back to when the drawer closes
	 */
	function open(target, module, trigger) {
		if (!root) return false
		// The same person in the open drawer: only the tab changes, and the draft stays.
		if (root.classList?.contains('mcp-drawer-open') && target === uid && Object.keys(modules).length > 0) {
			if (module) selectTab(module, true)
			return true
		}
		// The drawer is not modal: another person can be opened from the matrix while a draft is open.
		if (!mayReplace()) {
			trigger?.focus?.()
			return false
		}
		openDrawer(trigger)
		userInput.value = target
		drawerTitle?.focus?.()
		openUser(target, module)
		return true
	}

	window.McpScopeUi = { indicator, open, close: closeDrawer }
	// A module exactly at the revision a write of this page committed from takes the one it made; its draft stays.
	window.addEventListener(REVISION_MOVED, (event) => {
		for (const state of Object.values(modules)) {
			if (state.revision === event.detail.from) state.revision = event.detail.to
		}
	})
	// A matrix drawn before this script ran is drawn again, now with the scope marks.
	window.McpAdminMatrix?.refresh?.()
	if (!root) return
	entryButton?.addEventListener('click', () => {
		openDrawer(entryButton)
		userInput.focus?.()
	})
	closeButton?.addEventListener('click', () => closeDrawer())
	root.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && root.classList?.contains('mcp-drawer-open')) {
			event.preventDefault?.()
			closeDrawer()
		}
	})
	form.addEventListener('submit', (event) => {
		event.preventDefault()
		const target = userInput.value.trim()
		if (target === '') {
			say(t('mcp', 'Enter the user ID.'), true)
			return
		}
		if (!mayReplace()) return
		openUser(target, null)
	})
})()
;
/* admin/admin-inspector.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Effective access inspector (ticket 13; issue #4 A5, B6). Plain JavaScript, no build. It only reads: the effective
 * access of one user per operation, and why one path or one calendar is reached or not. Every text comes from the
 * codes the server answers, the same codes the tools are decided by; nothing here decides access.
 *
 * It opens as a drawer at the side of the page (S10), from its button on the Access tab or from the actions menu of a
 * matrix row, and answers first whether the person can use the tools at all: when one general check blocks every
 * operation (the account, the service, "Can connect" or the personal connection), one sentence says which and how to
 * fix it, next to the four general checks. Then one line per module gives the level the person has, where it comes
 * from, the resource scope and what blocks it; the table of every operation stays one click away. An individual value
 * that only repeats what the person inherits is said as the matrix says it ("also set for this person, with the same
 * value"), through the same read-only preview (src/admin/admin-exceptions.js); until that is known it is "set for this person".
 */
(function () {
	'use strict'
	const root = document.getElementById('mcp-inspector')
	if (!root) return
	const form = document.getElementById('mcp-inspector-form')
	const user = document.getElementById('mcp-inspector-user')
	const status = document.getElementById('mcp-inspector-status')
	const alert = document.getElementById('mcp-inspector-alert')
	const access = document.getElementById('mcp-inspector-access')
	const resources = document.getElementById('mcp-inspector-resources')
	const pathForm = document.getElementById('mcp-inspector-path-form')
	const pathInput = document.getElementById('mcp-inspector-path')
	const pathResult = document.getElementById('mcp-inspector-path-result')
	const calendarForm = document.getElementById('mcp-inspector-calendar-form')
	const calendarModule = document.getElementById('mcp-inspector-calendar-module')
	const calendarSelect = document.getElementById('mcp-inspector-calendar')
	const calendarOperation = document.getElementById('mcp-inspector-calendar-operation')
	const calendarResult = document.getElementById('mcp-inspector-calendar-result')
	let inspected = null
	let catalog = {}
	let seq = 0
	/**
	 * What each individual value of the person on screen is (src/admin/admin-exceptions.js, the same preview and rule as the
	 * matrix): per "module/operation", {kind: exception|redundant, after}. Null until known, and when it cannot be read:
	 * every individual value is then said as set for this person, never as repeating what they inherit.
	 */
	let kinds = null
	/** The answer on screen, drawn again once the kinds are known. */
	let shown = null

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		Object.assign(node, props)
		node.append(...children)
		return node
	}
	function say(message, error = false) {
		status.textContent = ''
		alert.textContent = ''
		;(error ? alert : status).textContent = message
	}
	async function get(path, query) {
		const response = await fetch(OC.generateUrl('/apps/mcp' + path) + '?' + new URLSearchParams(query), {
			headers: { Accept: 'application/json', requesttoken: OC.requestToken },
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) throw Object.assign(new Error(String(response.status)), { status: response.status, data })
		return data
	}
	function failure(error) {
		if (error.status === 403) return t('mcp', 'Only administrators can inspect access.')
		if (error.status === 400) return t('mcp', 'Check the user ID and the value entered, then try again.')
		if (error.status === 503) return t('mcp', 'The MCP policy cannot be read right now, so nothing can be confirmed. Try again shortly.')
		return t('mcp', 'The inspection failed. Try again.')
	}
	function moduleName(module) {
		return { files: t('mcp', 'Files'), notes: t('mcp', 'Notes'), deck: t('mcp', 'Deck'), calendar: t('mcp', 'Calendar'), contacts: t('mcp', 'Contacts'), tasks: t('mcp', 'Tasks'), talk: t('mcp', 'Talk'), people: t('mcp', 'People'), logs: t('mcp', 'Server log'), mail: t('mcp', 'Mail'), tables: t('mcp', 'Tables'), forms: t('mcp', 'Forms'), polls: t('mcp', 'Polls') }[module] || module
	}
	function operationName(operation) {
		return { ...window.McpAdminPollsGrants?.operationLabels(), results: t('mcp', 'results'), read: t('mcp', 'read'), edit: t('mcp', 'edit'), create: t('mcp', 'create'), move: t('mcp', 'move'), delete: t('mcp', 'delete'), transfer: t('mcp', 'transfer'), reply: t('mcp', 'reply'), attach: t('mcp', 'attach'), quote: t('mcp', 'quote'), share: t('mcp', 'share'), link: t('mcp', 'link'), restore: t('mcp', 'restore'), messages: t('mcp', 'messages'), attachments: t('mcp', 'attachments'), flags: t('mcp', 'flags'), draft: t('mcp', 'draft'), send: t('mcp', 'send') }[operation] || operation
	}
	/** The first check that blocks, by the code of the effective access (EffectiveResult::blockingGate). */
	function gateText(gate) {
		return {
			account: t('mcp', 'The account does not exist or is disabled.'),
			service: t('mcp', 'The MCP service is switched off.'),
			eligibility: t('mcp', 'The user may not connect to MCP.'),
			eligibility_unknown: t('mcp', 'Unknown: whether the user may connect depends on a group whose policy cannot be confirmed now.'),
			connection: t('mcp', 'The user has not switched on their MCP connection.'),
			app: t('mcp', 'The app of this module is not available to the user.'),
			logs_available: t('mcp', 'The server log cannot be read on this instance.'),
			logs_role: t('mcp', 'The user does not have the role needed to read the server log.'),
			grant: t('mcp', 'Not granted.'),
			grant_unknown: t('mcp', 'Unknown: the grant depends on a group whose policy cannot be confirmed now.'),
			resource_scope: t('mcp', 'Outside the resource scope of this user.'),
			resource_scope_unknown: t('mcp', 'Unknown: the resource scope of this user cannot be read.'),
		}[gate] || gate
	}
	/**
	 * Where one operation's value comes from, in words. An individual value that only repeats what the person inherits
	 * says what it repeats, as the matrix does.
	 */
	function grantText(row) {
		const kind = kinds?.get(row.module + '/' + row.operation)
		if (kind?.kind === 'redundant' && kind.after) {
			return sourceText(kind.after) + ' — ' + t('mcp', 'also set for this person, with the same value')
		}
		return sourceText(row.grant)
	}
	function sourceText(grant) {
		const groups = (grant.groupIds || []).join(', ')
		return {
			individual_allow: t('mcp', 'Individual: allowed'),
			individual_deny: t('mcp', 'Individual: denied'),
			group: t('mcp', 'Group: {groups}', { groups }),
			default: t('mcp', 'Default'),
			invalid: t('mcp', 'Invalid stored value (denied)'),
			unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups }),
		}[grant.source] || grant.source
	}
	function resultText(allowed) {
		return allowed === true ? t('mcp', 'Allowed') : allowed === false ? t('mcp', 'Blocked') : t('mcp', 'Unknown')
	}
	/** What a path or calendar answer means, from its codes (AccessInspector, CalendarAccessExplainer). */
	function explanation(answer) {
		const checks = { policy: t('mcp', 'the MCP policy'), native_acl: t('mcp', 'the Nextcloud permissions'), exclusion: t('mcp', 'the exclusion tags'), resource_scope_unknown: t('mcp', 'the resource scope'), grant_unknown: t('mcp', 'a group grant'), eligibility_unknown: t('mcp', 'a group eligibility') }
		const exclusions = {
			tag_direct: t('mcp', 'Not reached: it carries an exclusion tag.'),
			tag_folder: t('mcp', 'Not reached: it is inside the excluded folder {folder}.', { folder: answer.folder || '' }),
			tag_share_origin: t('mcp', 'Not reached: the shared item is excluded in the files of its owner.'),
			backup_of_excluded: t('mcp', 'Not reached: it is an MCP backup of an excluded file.'),
			invalid_configuration: t('mcp', 'Not reached: the saved exclusion tags cannot be read, so every file is refused.'),
		}
		switch (answer.reason) {
		case 'allowed': return t('mcp', 'Reached: every check passed.')
		case 'blocked': return t('mcp', 'Not reached: {reason}', { reason: gateText(answer.gate) })
		case 'out_of_scope': return t('mcp', 'Not reached: outside the resource scope of this user.')
		case 'not_found': return t('mcp', 'Not reached: the path does not exist in the files of this user.')
		case 'no_native_access': return t('mcp', 'Not reached: the user has no access to it in Nextcloud.')
		case 'read_only': return t('mcp', 'Not reached for this operation: the user can only read it in Nextcloud.')
		case 'excluded': return exclusions[answer.exclusion] || t('mcp', 'Not reached: it is excluded from MCP access.')
		case 'not_evaluated': return t('mcp', 'Not confirmed: {check} could not be checked, so access is not known.', { check: checks[answer.gate] || answer.gate })
		default: return answer.reason
		}
	}

	/** The general checks, in the order the tools apply them; one of them blocking blocks every operation. */
	const GENERAL = ['account', 'service', 'eligibility', 'connection']

	/**
	 * The general check that blocks every operation, or null when none does: every operation must be blocked by it.
	 *
	 * @param {Array<object>} operations the operations of the answer
	 * @return {string|null} a gate of GENERAL, or eligibility_unknown
	 */
	function generalBlock(operations) {
		const gates = new Set(operations.map((row) => row.blockingGate))
		if (operations.length === 0 || gates.size !== 1) return null
		const [gate] = gates
		return GENERAL.includes(gate) || gate === 'eligibility_unknown' ? gate : null
	}

	/** What to do about a general check that blocks, when the administrator or the person can do something. */
	function remedy(gate) {
		return {
			service: t('mcp', 'Turn the service on in the Overview tab.'),
			eligibility: t('mcp', 'Turn on “Can connect” in the matrix or through a group.'),
			connection: t('mcp', 'They turn it on in their personal settings, under MCP for Cloud.'),
		}[gate] || ''
	}

	/**
	 * What the operations say once no general check blocks: the tools can be used when one operation is allowed, it is
	 * not confirmed when none is and one cannot be read, and nothing can be used when every one is blocked.
	 *
	 * @param {Array<object>} operations the operations of the answer, not empty
	 * @return {string} usable, unknown, none_granted (every one blocked by its grant) or none
	 */
	function outcome(operations) {
		if (operations.some((row) => row.allowed === true)) return 'usable'
		if (operations.some((row) => row.allowed !== false)) return 'unknown'
		return operations.every((row) => row.blockingGate === 'grant') ? 'none_granted' : 'none'
	}

	/** The verdict and the four general checks: passed, the one that blocks, or not checked after it. */
	function renderVerdict(operations) {
		// An answer without operations confirms nothing, not even the general checks.
		if (operations.length === 0) {
			return [el('p', { className: 'mcp-inspector-verdict', textContent: t('mcp', 'No operation was answered, so nothing can be said about {user}.', { user: inspected }, undefined, { escape: false }) })]
		}
		const block = generalBlock(operations)
		const failed = block === 'eligibility_unknown' ? 'eligibility' : block
		const index = failed === null ? GENERAL.length : GENERAL.indexOf(failed)
		const names = {
			account: t('mcp', 'Account active'),
			service: t('mcp', 'MCP service on'),
			eligibility: t('mcp', 'Can connect'),
			connection: t('mcp', 'Personal connection on'),
		}
		const checks = el('ul', { className: 'mcp-inspector-checks' }, GENERAL.map((gate, i) => {
			const state = i < index ? 'passed' : i > index ? 'skipped' : block === 'eligibility_unknown' ? 'unknown' : 'failed'
			const mark = { passed: '✓', failed: '✕', unknown: '?', skipped: '—' }[state]
			const word = { passed: t('mcp', 'passed'), failed: t('mcp', 'blocks'), unknown: t('mcp', 'not confirmed'), skipped: t('mcp', 'not checked') }[state]
			const symbol = el('span', { className: 'mcp-inspector-mark', textContent: mark })
			symbol.setAttribute('aria-hidden', 'true')
			return el('li', { className: 'mcp-inspector-check-' + state }, [symbol, ' ', names[gate] + ': ' + word])
		}))
		if (block === null) {
			const result = outcome(operations)
			if (result === 'usable') {
				return [el('p', { className: 'mcp-inspector-verdict mcp-inspector-allowed', textContent: t('mcp', '{user} can use the MCP tools allowed below: the general checks passed.', { user: inspected }, undefined, { escape: false }) }), checks]
			}
			const text = {
				unknown: t('mcp', 'Not confirmed: no operation of {user} is allowed for sure now, and some cannot be confirmed.', { user: inspected }, undefined, { escape: false }),
				none_granted: t('mcp', '{user} cannot use any MCP tool: nothing is granted.', { user: inspected }, undefined, { escape: false }),
				none: t('mcp', '{user} cannot use any MCP tool: every operation is blocked, as each module below says.', { user: inspected }, undefined, { escape: false }),
			}[result]
			const said = el('div', { className: 'mcp-note ' + (result === 'unknown' ? 'mcp-note-warning' : 'mcp-note-error') }, [el('p', { textContent: text })])
			said.setAttribute('role', 'status')
			return [said, checks]
		}
		const note = el('div', { className: 'mcp-note ' + (block === 'eligibility_unknown' ? 'mcp-note-warning' : 'mcp-note-error') }, [
			el('p', {}, [el('strong', { textContent: t('mcp', '{user} cannot use any MCP tool.', { user: inspected }, undefined, { escape: false }) }), ' ' + gateText(block) + (remedy(block) ? ' ' + remedy(block) : '')]),
		])
		note.setAttribute('role', 'status')
		return [note, checks]
	}

	/**
	 * One line per module: the level the grants give, where it comes from, the resource scope and what blocks it. While a
	 * general check blocks everything, the lines say what will apply once it is fixed, without repeating it on each.
	 */
	function renderModules(operations) {
		const general = generalBlock(operations) !== null
		const levels = window.McpAdminLevels
		const byModule = new Map()
		for (const row of operations) {
			if (!byModule.has(row.module)) byModule.set(row.module, [])
			byModule.get(row.module).push(row)
		}
		const levelName = { ...window.McpAdminPollsGrants?.levelLabels(), forms_write: t('mcp', 'Read and write forms'), forms_write_results: t('mcp', 'Read and write forms and read responses'), none: t('mcp', 'None'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom'), unknown: t('mcp', 'Unknown') }
		const items = [...byModule.entries()].map(([module, rows]) => {
			const operationList = rows.map((row) => row.operation)
			const values = Object.fromEntries(rows.map((row) => [row.operation, row.grant ? row.grant.allowed : row.allowed]))
			const level = levels ? levels.levelOf(module, operationList, values) : null
			const gates = new Set(rows.map((row) => row.blockingGate).filter((gate) => gate !== null && gate !== 'grant'))
			const blocked = !general && rows.every((row) => row.allowed === false) && gates.size === 1 ? [...gates][0] : null
			const origin = levels ? levels.originOf(operationList, Object.fromEntries(rows.map((row) => [row.operation, row.grant]))) : null
			const source = origin === null ? '' : origin.source === 'individual' ? individualText(rows) : {
				invalid: t('mcp', 'Invalid stored value (denied)'),
				group: t('mcp', 'Group: {groups}', { groups: origin.groupIds.join(', ') }),
				unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') }),
				default: t('mcp', 'Default'),
			}[origin.source]
			const scope = rows[0].scope && rows[0].scope.mode === 'restricted' ? t('mcp', 'Restricted to {count}', { count: rows[0].scope.count }) : ''
			const state = blocked !== null
				? el('span', { className: 'mcp-inspector-blocked', textContent: t('mcp', 'Blocked') })
				: el('span', { className: level === 'unknown' ? 'mcp-inspector-unknown' : 'mcp-inspector-level', textContent: level === null ? '' : levelName[level] || level })
			const detail = [blocked !== null ? gateText(blocked) : source, scope].filter((text) => text !== '').join(' — ')
			return el('li', { className: 'mcp-inspector-module' }, [el('span', { className: 'mcp-inspector-module-name', textContent: moduleName(module) }), state, el('span', { className: 'mcp-hint', textContent: detail })])
		})
		const list = el('ul', { className: 'mcp-inspector-modules' }, items)
		return general ? el('div', {}, [el('p', { className: 'mcp-hint', textContent: t('mcp', 'What each module grants, once the general check is fixed:') }), list]) : list
	}

	/**
	 * Where a module's individual values come from, as the matrix says it: an exception when one of them differs from what
	 * the person inherits, what they repeat when every one only repeats it, and "set for this person" until that is known.
	 */
	function individualText(rows) {
		const individual = rows.filter((row) => row.grant?.source === 'individual_allow' || row.grant?.source === 'individual_deny')
		const known = individual.map((row) => kinds?.get(row.module + '/' + row.operation))
		if (kinds === null || known.some((kind) => !kind)) return t('mcp', 'Set for this person')
		if (known.some((kind) => kind.kind === 'exception')) return t('mcp', 'Exception: differs from what the person inherits')
		const repeated = Object.fromEntries(rows.map((row) => {
			const index = individual.indexOf(row)
			return [row.operation, index < 0 ? row.grant : known[index].after || { source: 'default', groupIds: [] }]
		}))
		const origin = window.McpAdminLevels.originOf(rows.map((row) => row.operation), repeated)
		const text = {
			group: t('mcp', 'Group: {groups}', { groups: origin.groupIds.join(', ') }),
			unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') }),
		}[origin.source] || t('mcp', 'Default')
		return text + ' — ' + t('mcp', 'also set for this person, with the same value')
	}

	/**
	 * Asks what the individual values of the answer on screen repeat, then draws it again; an answer for another person
	 * or another inspection is dropped.
	 */
	async function classifyShown(data, mine) {
		const exceptions = window.McpAdminExceptions
		if (!exceptions?.classifyPerson || typeof data.revision !== 'string') return
		const cells = data.operations.map((row) => ({ module: row.module, operation: row.operation, allowed: row.grant?.allowed ?? null, source: row.grant?.source }))
		if (!cells.some((cell) => cell.source === 'individual_allow' || cell.source === 'individual_deny')) return
		try {
			const answer = await exceptions.classifyPerson(inspected, cells, data.revision)
			if (mine !== seq || shown !== data) return
			kinds = answer
		} catch (e) {
			return
		}
		// Drawn again in place: the table of every operation stays open, and a focused summary keeps the focus.
		const details = access.querySelector?.('details.mcp-inspector-all')
		const wasOpen = Boolean(details?.open)
		const hadFocus = document.activeElement !== undefined && document.activeElement === details?.querySelector?.('summary')
		renderAccess(data)
		const again = access.querySelector?.('details.mcp-inspector-all')
		if (again && wasOpen) again.open = true
		if (hadFocus) again?.querySelector?.('summary')?.focus?.()
	}

	function renderAccess(data) {
		shown = data
		catalog = {}
		const rows = data.operations.map((row) => {
			;(catalog[row.module] = catalog[row.module] || []).push(row.operation)
			const scope = row.scope && row.scope.mode === 'restricted' ? ' · ' + t('mcp', 'Restricted to {count}', { count: row.scope.count }) : ''
			return el('tr', {}, [
				el('th', { scope: 'row', textContent: moduleName(row.module) + ': ' + operationName(row.operation) }),
				el('td', { textContent: resultText(row.allowed), className: 'mcp-inspector-' + (row.allowed === true ? 'allowed' : row.allowed === false ? 'blocked' : 'unknown') }),
				el('td', { textContent: row.blockingGate === null ? '—' : gateText(row.blockingGate) }),
				el('td', { textContent: grantText(row) + scope }),
			])
		})
		const head = el('tr', {}, [t('mcp', 'Operation'), t('mcp', 'Result'), t('mcp', 'First check that blocks'), t('mcp', 'Grant')].map((text) => el('th', { scope: 'col', textContent: text })))
		const table = el('table', { className: 'mcp-inspector-table' }, [el('caption', { textContent: t('mcp', 'Effective access of {user}', { user: inspected }) }), el('thead', {}, [head]), el('tbody', {}, rows)])
		access.replaceChildren(...renderVerdict(data.operations), renderModules(data.operations),
			el('details', { className: 'mcp-inspector-all' }, [el('summary', { textContent: t('mcp', 'Every operation') }), table]))
	}

	async function inspect() {
		const uid = user.value.trim()
		if (uid === '') {
			say(t('mcp', 'Enter the user ID to inspect.'), true)
			return
		}
		const mine = ++seq
		access.setAttribute('aria-busy', 'true')
		say(t('mcp', 'Inspecting…'))
		try {
			const data = await get('/api/inspector/access', { uid })
			if (mine !== seq) return
			inspected = uid
			kinds = null
			renderAccess(data)
			classifyShown(data, mine)
			resources.hidden = false
			pathResult.textContent = ''
			calendarResult.textContent = ''
			await loadCalendars()
			say(t('mcp', 'Access of {user} as the tools see it now.', { user: uid }))
		} catch (error) {
			if (mine !== seq) return
			inspected = null
			kinds = null
			shown = null
			access.replaceChildren()
			resources.hidden = true
			say(failure(error), true)
		} finally {
			if (mine === seq) access.setAttribute('aria-busy', 'false')
		}
	}

	/** A request to the user or module that is no longer on screen is answered too late: its answer is dropped. */
	const asked = { calendars: 0, path: 0, calendar: 0 }
	function stale(kind, mine, user) {
		return mine !== asked[kind] || user !== inspected
	}

	async function loadCalendars() {
		const module = calendarModule.value
		const mine = ++asked.calendars
		const user = inspected
		calendarOperation.replaceChildren(...(catalog[module] || ['read']).map((operation) => el('option', { value: operation, textContent: operationName(operation) })))
		calendarSelect.replaceChildren()
		try {
			const data = await get('/api/scope/' + encodeURIComponent(user) + '/' + module + '/candidates', {})
			if (stale('calendars', mine, user) || module !== calendarModule.value) return
			calendarSelect.replaceChildren(...data.items.map((item) => el('option', { value: item.id, textContent: item.name })))
			if (data.items.length === 0) calendarResult.textContent = t('mcp', 'This user has no calendar or task list of this kind in Nextcloud.')
		} catch (error) {
			if (stale('calendars', mine, user)) return
			say(failure(error), true)
		}
	}

	async function explain(kind, target, path, query, module) {
		const mine = ++asked[kind]
		const user = inspected
		target.textContent = t('mcp', 'Checking…')
		try {
			const answer = await get(path, Object.assign({ uid: user }, query))
			if (stale(kind, mine, user)) return
			target.textContent = explanation(answer)
			if (answer.reason === 'out_of_scope' && window.McpScopeUi) {
				const change = el('button', { type: 'button', textContent: t('mcp', 'Open the resource scope') })
				change.addEventListener('click', () => window.McpScopeUi.open(user, module))
				target.append(' ', change)
			}
		} catch (error) {
			if (stale(kind, mine, user)) return
			// An error is announced like every other error of the inspector, in its alert region.
			target.textContent = ''
			say(failure(error), true)
		}
	}

	form.addEventListener('submit', (event) => { event.preventDefault(); inspect() })
	const drawerTitle = document.getElementById('mcp-inspector-title')
	const closeButton = document.getElementById('mcp-inspector-close')
	const entryButton = document.getElementById('mcp-inspector-entry')
	let returnFocus = null
	let returnRow = null

	/**
	 * Puts the open drawer right under the Nextcloud header. A drawer is position: fixed, but Nextcloud 35 blurs the content
	 * behind it (backdrop-filter on #content-vue), which makes that box, not the window, the one it is placed in: its top
	 * would then be counted from the box, one header too low. The offset is measured, not assumed, since a theme may turn
	 * the blur off.
	 */
	function placeDrawer() {
		root.style?.removeProperty?.('top')
		const rect = root.getBoundingClientRect?.()
		if (!rect) return
		const header = document.getElementById('header')?.getBoundingClientRect?.().bottom ?? 0
		const shift = rect.top - header
		if (Math.abs(shift) >= 1) root.style.top = (parseFloat(getComputedStyle(root).top) - shift) + 'px'
	}

	/**
	 * Opens the drawer; the focus goes back to `trigger` when it closes. Only one drawer is open at a time: when the
	 * administrator keeps a draft of the resource scope, its drawer stays and this one does not open.
	 *
	 * @return {boolean} whether the drawer opened
	 */
	function openDrawer(trigger) {
		if (window.McpScopeUi?.close?.() === false) {
			trigger?.focus?.()
			return false
		}
		returnFocus = trigger || document.activeElement || null
		// The row of a "…" button that opened the drawer: the table may be drawn again meanwhile, and its new button is
		// the one that gets the focus back.
		returnRow = returnFocus?.closest?.('tr')?.dataset?.uid ?? null
		root.classList?.add('mcp-drawer-open')
		root.setAttribute('role', 'dialog')
		placeDrawer()
		return true
	}

	/**
	 * Closes the drawer and gives the focus back. The inspector only reads, so nothing can be lost.
	 *
	 * @return {boolean} always true: the drawer is closed now
	 */
	function closeDrawer() {
		if (!root.classList?.contains('mcp-drawer-open')) return true
		root.classList.remove('mcp-drawer-open')
		root.removeAttribute?.('role')
		root.style?.removeProperty?.('top')
		const target = returnFocus
		returnFocus = null
		focusBack(target)
		window.dispatchEvent?.(new Event('mcp-drawer-closed'))
		return true
	}

	/**
	 * Gives the focus back to the control that opened the drawer, or, when the table was drawn again and that control is
	 * gone, to the same row's "…" button, then to the drawer's own button on the Access tab: never to the page.
	 */
	function focusBack(target) {
		if (target && target.isConnected !== false) {
			target.focus?.()
			return
		}
		const rows = [...(document.getElementById('mcp-matrix')?.tBodies?.[0]?.rows || [])]
		const row = returnRow === null ? null : rows.find((candidate) => candidate.dataset.uid === returnRow)
		;(row?.querySelector('button.mcp-row-actions-button') || entryButton)?.focus?.()
	}

	entryButton?.addEventListener('click', () => {
		if (openDrawer(entryButton)) user.focus?.()
	})
	closeButton?.addEventListener('click', () => closeDrawer())
	root.addEventListener?.('keydown', (event) => {
		if (event.key === 'Escape' && root.classList?.contains('mcp-drawer-open')) {
			event.preventDefault?.()
			closeDrawer()
		}
	})

	// The actions menu of a matrix row inspects that user (src/admin/admin-row-actions.js), in the drawer.
	window.McpInspectorUi = {
		open(target, trigger) {
			if (!openDrawer(trigger)) return false
			user.value = target
			drawerTitle?.focus?.()
			inspect()
			return true
		},
		close: closeDrawer,
	}
	pathForm.addEventListener('submit', (event) => {
		event.preventDefault()
		const path = pathInput.value.trim()
		if (inspected === null || path === '') return
		explain('path', pathResult, '/api/inspector/path', { path: path.startsWith('/') ? path : '/' + path }, 'files')
	})
	calendarModule.addEventListener('change', () => { if (inspected !== null) loadCalendars() })
	calendarForm.addEventListener('submit', (event) => {
		event.preventDefault()
		if (inspected === null || calendarSelect.value === '') return
		explain('calendar', calendarResult, '/api/inspector/calendar', { module: calendarModule.value, calendarId: calendarSelect.value, operation: calendarOperation.value }, calendarModule.value)
	})
})()
;
/* shared/connections.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Active OAuth connections of the MCP app, shared by the admin page (every user, GET/DELETE /apps/mcp/api/connections)
 * and the personal page (only the signed-in user, GET/DELETE /apps/mcp/api/my/connections). The root element's
 * data-scope ("admin" or "personal") picks the API and the columns.
 * The personal page's read-only resource scope is the second block in this file. On the admin page
 * those elements are absent and that block returns without doing anything.
 * Plain browser JavaScript, no build step; strings go through t('mcp', …) for translation.
 */
(function () {
	'use strict'

	const root = document.getElementById('mcp-connections')
	if (!root) {
		return
	}

	const admin = root.dataset.scope === 'admin'
	const base = admin ? '/api/connections' : '/api/my/connections'
	const state = { search: '', page: 1, data: null }
	const table = document.getElementById('mcp-connections-table')
	const searchInput = document.getElementById('mcp-connections-search')
	const prevButton = document.getElementById('mcp-connections-prev')
	const nextButton = document.getElementById('mcp-connections-next')
	const pageInfo = document.getElementById('mcp-connections-page-info')
	const status = document.getElementById('mcp-connections-status')
	const connectionsCount = document.getElementById('mcp-count-connections')
	// t() escapes variables as HTML by default; the confirm dialog and the status region take plain text, where
	// that would show "D&#039;Avila". Never pass this for a result that is inserted as HTML.
	const PLAIN_TEXT = { escape: false }

	/**
	 * @param {string} method HTTP method
	 * @param {string} path path below /apps/mcp
	 * @return {Promise<object>} decoded JSON response
	 */
	async function api(method, path) {
		const response = await fetch(OC.generateUrl('/apps/mcp' + path), {
			method,
			headers: { Accept: 'application/json', requesttoken: OC.requestToken },
		})
		if (!response.ok) {
			throw new Error(String(response.status))
		}
		return response.json()
	}

	/** @param {string} message error shown to the user */
	function notifyError(message) {
		// The old notification global is gone since Nextcloud 34 and OCP.Toast is deprecated, so without it the message goes to the
		// list's own live region instead of being lost.
		if (window.OCP && OCP.Toast) {
			OCP.Toast.error(message)
		} else {
			status.textContent = message
		}
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

	/**
	 * @param {number} seconds Unix time
	 * @return {string} date and time in the user's locale
	 */
	function when(seconds) {
		const locale = (document.documentElement.dataset.locale || '').replace('_', '-') || undefined
		try {
			return new Date(seconds * 1000).toLocaleString(locale, { dateStyle: 'medium', timeStyle: 'short' })
		} catch (e) {
			return new Date(seconds * 1000).toLocaleString()
		}
	}

	/**
	 * @param {object} client client of a connection from the API
	 * @return {string} what people call it: the host, a fixed label for the native client, or the name the administrator
	 *     gave a self-hosted client
	 */
	function clientName(client) {
		if (client.kind === 'native') {
			return t('mcp', 'Local program (native client)')
		}
		if (client.kind === 'custom') {
			return client.name || t('mcp', 'Self-hosted client')
		}
		return client.host
	}

	/**
	 * @param {object} client client of a connection from the API
	 * @return {HTMLElement} its name, with the full client_id as tooltip
	 */
	function clientCell(client) {
		return el('td', { title: client.id, textContent: clientName(client) })
	}

	/** @param {{counts?: {connections?: number}}} result DELETE response; refreshes the Status card when it carries the new total */
	function updateCounts(result) {
		if (connectionsCount && result && result.counts && Number.isInteger(result.counts.connections)) {
			connectionsCount.textContent = String(result.counts.connections)
		}
	}

	/** @param {string} text message for the screen reader status region */
	function say(text) {
		if (status) {
			status.textContent = text
		}
	}

	/** Reads the current page and re-renders the table; a failure is shown in the table with a retry. */
	async function load() {
		table.setAttribute('aria-busy', 'true')
		const query = new URLSearchParams({ page: String(state.page) })
		if (admin) {
			query.set('search', state.search)
		}
		try {
			state.data = await api('GET', base + '?' + query.toString())
			if (state.data.connections.length === 0 && state.page > 1) {
				// The last row of the last page was revoked: show the previous page instead of an empty one.
				state.page -= 1
				await load()
				return
			}
			render()
		} catch (e) {
			const retry = el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: load })
			table.tBodies[0].replaceChildren(el('tr', {}, [el('td', { colSpan: admin ? 5 : 4, className: 'mcp-empty mcp-load-error' },
				[t('mcp', 'Could not load the connections.'), ' ', retry])]))
		} finally {
			table.setAttribute('aria-busy', 'false')
		}
	}

	/** Renders the rows and the pager from state.data. */
	function render() {
		const data = state.data
		const rows = data.connections.map((connection) => {
			const cells = []
			if (admin) {
				cells.push(el('th', { scope: 'row' }, [
					el('span', { className: 'mcp-name', textContent: connection.displayName }),
					el('span', { className: 'mcp-uid', textContent: connection.uid }),
				]))
			}
			cells.push(clientCell(connection.client))
			cells.push(el('td', { textContent: when(connection.createdAt) }))
			cells.push(el('td', { textContent: when(connection.expiresAt) }))
			// Every row has the same visible button text, so the accessible name says which connection it revokes.
			const client = clientName(connection.client)
			const actions = el('td', { className: 'mcp-actions' }, [el('button', {
				type: 'button',
				textContent: t('mcp', 'Revoke'),
				onclick: () => revoke(connection),
			})])
			actions.firstChild.setAttribute('aria-label', admin
				? t('mcp', 'Revoke {client} for {user}', { client, user: connection.displayName }, undefined, PLAIN_TEXT)
				: t('mcp', 'Revoke {client}', { client }, undefined, PLAIN_TEXT))
			if (admin) {
				const all = el('button', {
					type: 'button',
					textContent: t('mcp', 'Revoke all of this user'),
					onclick: () => revokeUser(connection),
				})
				all.setAttribute('aria-label', t('mcp', 'Revoke all connections of {user}', { user: connection.displayName }, undefined, PLAIN_TEXT))
				actions.append(' ', all)
			}
			cells.push(actions)
			return el('tr', {}, cells)
		})
		if (rows.length === 0) {
			const text = admin
				? (state.search === '' ? t('mcp', 'No active connections.') : t('mcp', 'No connection matches the search.'))
				: t('mcp', 'You have no active connections.')
			rows.push(el('tr', {}, [el('td', { colSpan: admin ? 5 : 4, className: 'mcp-empty', textContent: text })]))
		}
		table.tBodies[0].replaceChildren(...rows)
		prevButton.disabled = data.page <= 1
		nextButton.disabled = !data.hasMore
		pageInfo.textContent = t('mcp', 'Page {page}', { page: data.page })
	}

	/** @param {object} connection row from the API */
	async function revoke(connection) {
		const name = clientName(connection.client)
		const question = admin
			? t('mcp', 'Revoke the connection of {user} through {client}? The client is signed out at once.', { user: connection.displayName, client: name }, undefined, PLAIN_TEXT)
			: t('mcp', 'Revoke the connection through {client}? The client is signed out at once.', { client: name }, undefined, PLAIN_TEXT)
		if (!window.confirm(question)) {
			return
		}
		try {
			updateCounts(await api('DELETE', base + '/' + encodeURIComponent(String(connection.id))))
			say(t('mcp', 'Connection revoked.'))
		} catch (e) {
			notifyError(t('mcp', 'Could not revoke the connection.'))
		}
		await load()
	}

	/** @param {object} connection any row of the user whose connections are revoked */
	async function revokeUser(connection) {
		if (!window.confirm(t('mcp', 'Revoke every connection of {user}? Their clients are signed out at once; the user can sign in again.', { user: connection.displayName }, undefined, PLAIN_TEXT))) {
			return
		}
		try {
			updateCounts(await api('DELETE', '/api/connections/users/' + encodeURIComponent(connection.uid)))
			say(t('mcp', 'Connections of {user} revoked.', { user: connection.displayName }, undefined, PLAIN_TEXT))
		} catch (e) {
			notifyError(t('mcp', 'Could not revoke the connections of {user}.', { user: connection.displayName }))
		}
		state.page = 1
		await load()
	}

	if (searchInput) {
		let timer = null
		searchInput.addEventListener('input', () => {
			clearTimeout(timer)
			timer = setTimeout(() => {
				state.search = searchInput.value.trim()
				state.page = 1
				load()
			}, 300)
		})
	}
	prevButton.addEventListener('click', () => {
		state.page = Math.max(1, state.page - 1)
		load()
	})
	nextButton.addEventListener('click', () => {
		state.page += 1
		load()
	})

	load()
})();

/**
 * The user's own resource scope, read only (issue #4, B7; contracts B.6): for Files, Calendar and Tasks, whether the
 * MCP tools reach every resource or only the ones the administrator selected. GET /api/my/scope answers for the
 * signed-in user only. A scope that cannot be read is said so, never shown as unrestricted.
 */
(function () {
	'use strict'
	const status = document.getElementById('mcp-my-scope-status')
	const list = document.getElementById('mcp-my-scope-list')
	if (!status || !list) return

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		Object.assign(node, props)
		node.append(...children)
		return node
	}
	function line(module, scope) {
		const names = scope.items.map((item) => item.found === false ? t('mcp', 'a resource that no longer exists') : (item.path || item.name))
		if (scope.mode !== 'restricted') {
			return { files: t('mcp', 'Files: every folder'), calendar: t('mcp', 'Calendar: every calendar'), tasks: t('mcp', 'Tasks: every task list') }[module]
		}
		if (names.length === 0) {
			return { files: t('mcp', 'Files: no folder'), calendar: t('mcp', 'Calendar: no calendar'), tasks: t('mcp', 'Tasks: no task list') }[module]
		}
		return { files: t('mcp', 'Files: only {names}', { names: names.join(', ') }, undefined, { escape: false }), calendar: t('mcp', 'Calendar: only {names}', { names: names.join(', ') }, undefined, { escape: false }), tasks: t('mcp', 'Tasks: only {names}', { names: names.join(', ') }, undefined, { escape: false }) }[module]
	}

	async function load() {
		status.textContent = t('mcp', 'Loading…')
		try {
			const response = await fetch(OC.generateUrl('/apps/mcp/api/my/scope'), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } })
			const data = await response.json().catch(() => ({}))
			if (!response.ok) throw new Error(String(response.status))
			list.replaceChildren(...['files', 'calendar', 'tasks'].map((module) => el('li', { textContent: line(module, data.modules[module]) })))
			status.textContent = ''
		} catch (error) {
			list.replaceChildren()
			status.textContent = t('mcp', 'Your resource scope cannot be read right now. Try again later.')
		}
	}
	load()
})()
;
/* admin/admin-tabs.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Admin page tab navigation for the MCP app (WAI-ARIA tabs pattern with automatic activation).
 *
 * Generic: every tab and panel is read from the markup ([role="tab"] buttons inside the tablist,
 * each naming its panel through aria-controls and its address through data-tab-hash), so a new area
 * in a later version only adds one button and one panel to templates/admin.php. The active tab
 * lives in the URL hash, so a reload, the history back/forward buttons and a copied link reopen the
 * same tab; an unknown hash opens the first tab. Without JavaScript the panels carry no `hidden`
 * attribute and stay visible, and the tab bar stays display:none — the page degrades to the old
 * single-page layout. Plain browser JavaScript, no build step.
 */
(function () {
	'use strict'

	const root = document.getElementById('mcp-admin')
	const tablist = root ? root.querySelector('[role="tablist"]') : null
	if (!root || !tablist) {
		return
	}

	const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'))
	/** @type {Map<string, HTMLElement>} data-tab-hash value to its tab */
	const byHash = new Map()
	// Query strings belong to each tab; navigation preserves them without knowing their meaning.
	const tabQueries = new Map()

	for (const tab of tabs) {
		const hash = tab.getAttribute('data-tab-hash')
		if (hash && tab.id && document.getElementById(tab.getAttribute('aria-controls'))) {
			byHash.set(hash, tab)
		}
	}
	if (byHash.size === 0) {
		return
	}
	const validTabs = tabs.filter((tab) => byHash.get(tab.getAttribute('data-tab-hash')) === tab)
	const firstTab = validTabs[0]

	/**
	 * Resolves the current URL hash to a tab. A tab hash wins; a hash that is the id of an element
	 * inside a panel (for example #mcp-connections) opens the panel that contains it and scrolls to
	 * the element; anything else, or no hash, opens the first tab.
	 *
	 * @return {{tab: HTMLElement, element: HTMLElement|null}}
	 */
	function resolveFromHash() {
		const fragment = window.location.hash.replace(/^#/, '')
		const hash = fragment.split('?')[0]
		const direct = byHash.get(hash)
		if (direct) {
			tabQueries.set(hash, fragment.slice(hash.length))
			return { tab: direct, element: null }
		}
		const element = hash === '' ? null : document.getElementById(hash)
		const panel = element ? element.closest('[role="tabpanel"]') : null
		if (panel) {
			const tab = validTabs.find((candidate) => candidate.getAttribute('aria-controls') === panel.id) ?? null
			if (tab) {
				return { tab, element }
			}
		}
		return { tab: firstTab, element: null }
	}

	/**
	 * Activates a tab: roving tabindex, aria-selected and the panel visibility follow it.
	 *
	 * @param {HTMLElement} tab the tab to activate
	 * @param {boolean} moveFocus whether the focus moves to the tab (keyboard and click)
	 * @param {boolean} trackHash whether the URL hash is written (a new history entry)
	 */
	function activate(tab, moveFocus, trackHash) {
		for (const candidate of validTabs) {
			const selected = candidate === tab
			candidate.setAttribute('aria-selected', selected ? 'true' : 'false')
			candidate.tabIndex = selected ? 0 : -1
			const panel = document.getElementById(candidate.getAttribute('aria-controls'))
			if (panel) {
				panel.hidden = !selected
			}
		}
		if (moveFocus) {
			tab.focus()
		}
		if (trackHash) {
			resolveFromHash()
			const hash = tab.getAttribute('data-tab-hash') + (tabQueries.get(tab.getAttribute('data-tab-hash')) || '')
			if (window.location.hash !== '#' + hash) {
				// One history entry per switch, so back/forward revisit the tab.
				window.location.hash = hash
			}
		}
	}

	/** @param {HTMLElement} tab the focused tab @param {number} delta steps to move, wrapping around */
	function moveAndActivate(tab, delta) {
		const index = validTabs.indexOf(tab)
		const next = validTabs[(index + delta + validTabs.length) % validTabs.length]
		activate(next, true, true)
	}

	tablist.addEventListener('click', (event) => {
		const tab = event.target.closest('[role="tab"]')
		if (tab && validTabs.includes(tab)) {
			activate(tab, false, true)
		}
	})

	tablist.addEventListener('keydown', (event) => {
		const tab = event.target.closest('[role="tab"]')
		if (!tab || !validTabs.includes(tab)) {
			return
		}
		switch (event.key) {
		case 'ArrowLeft':
			event.preventDefault()
			moveAndActivate(tab, -1)
			break
		case 'ArrowRight':
			event.preventDefault()
			moveAndActivate(tab, 1)
			break
		case 'Home':
			event.preventDefault()
			activate(validTabs[0], true, true)
			break
		case 'End':
			event.preventDefault()
			activate(validTabs[validTabs.length - 1], true, true)
			break
		}
	})

	window.addEventListener('hashchange', () => {
		// Back/forward or a pasted hash: activate without writing the address again.
		const resolved = resolveFromHash()
		activate(resolved.tab, false, false)
		resolved.element?.scrollIntoView()
	})

	root.classList.add('mcp-tabs-on')
	const initial = resolveFromHash()
	activate(initial.tab, false, false)
	initial.element?.scrollIntoView()
})()
;
/* admin/admin-file-write-policy.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * File policy form, including independent protection alerts. No build step or inline handlers.
 * @param {Window|typeof globalThis} scope Browser globals, or node exports for tests.
 * @returns {void}
 */
(function (scope) {
	'use strict'

	/**
	 * Trim only the boundary bytes removed by PHP trim(), excluding form feed and Unicode spaces.
	 * @param {string} text Raw text or a separated token.
	 * @returns {string} Text with PHP's default ASCII boundary whitespace removed.
	 */
	function phpTrim(text) {
		return text.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, '')
	}

	/**
	 * Normalize the textarea with FileWritePolicy's ASCII grammar, PHP trim and non-UTF PCRE separators.
	 * @param {string} text Raw textarea input.
	 * @returns {string[]} Sorted, unique lowercase extensions.
	 * @throws {Error} For unsupported tokens, separators or more than 128 distinct extensions.
	 */
	function extensions(text) {
		if (/[^\x00-\x7f]/.test(text)) throw new Error('extensions')
		const trimmed = phpTrim(text)
		const tokens = trimmed === '' ? [] : trimmed.split(/[ \t\n\r\v\f]*(?:,|\r\n|[\n\r\v\f])[ \t\n\r\v\f]*/)
		if (tokens.at(-1) === '') tokens.pop()
		const canonical = tokens.map(token => phpTrim(token).toLowerCase().replace(/^\./, ''))
		if (canonical.some(token => !/^[a-z0-9][a-z0-9_-]{0,31}$/.test(token))) throw new Error('extensions')
		const result = [...new Set(canonical)].sort()
		if (result.length > 128) throw new Error('extensions')
		return result
	}

	/**
	 * Reject malformed responses rather than interpreting a failed load as off.
	 * @param {unknown} value Parsed JSON response.
	 * @returns {boolean} Whether it is a canonical policy.
	 */
	function validPolicy(value) {
		return !!value && value.version === 1 && ['off', 'allowlist'].includes(value.mode)
			&& typeof value.allowExtensionless === 'boolean' && Array.isArray(value.extensions)
			&& value.extensions.every(token => typeof token === 'string' && /^[a-z0-9][a-z0-9_-]{0,31}$/.test(token))
			&& value.extensions.length <= 128
			&& JSON.stringify([...new Set(value.extensions)].sort()) === JSON.stringify(value.extensions)
	}

	/**
	 * Bind one form using injectable browser primitives for request and DOM tests.
	 * @param {Document} document Admin page document.
	 * @param {typeof fetch} request Same-origin HTTP transport.
	 * @param {(app: string, text: string) => string} t Nextcloud translation function.
	 * @param {string} url Policy endpoint.
	 * @param {() => string} requestToken Read the current session CSRF token for each request.
	 * @param {typeof MutationObserver|undefined} Observer Shared protection-marker observer.
	 * @returns {{ready: Promise<void>, submit: (event: Event) => Promise<void>, aggregate: () => void}|null} Form hooks.
	 */
	function mount(document, request, t, url, requestToken, Observer) {
		/**
		 * @param {string} id Element identifier.
		 * @returns {HTMLElement|null} Matching form element.
		 */
		const get = id => document.getElementById(id)
		const form = get('mcp-file-policy-form')
		if (!form) return null
		const mode = get('mcp-file-policy-mode')
		const list = get('mcp-file-policy-extensions')
		const extensionless = get('mcp-file-policy-extensionless')
		const save = get('mcp-file-policy-save')
		const status = get('mcp-file-policy-status')
		let busy = true
		let issue = false

		/** @returns {void} Keep the shared marker visible while either protection configuration has an issue. */
		function aggregate() {
			const tags = get('mcp-tags-invalid-overview')
			const alert = get('mcp-tab-protection-alert')
			const hidden = !(issue || (tags && !tags.hidden))
			if (alert && alert.hidden !== hidden) alert.hidden = hidden
		}
		/**
		 * @param {boolean} value Whether loading or verification failed.
		 * @param {string} [message] Already translated, safe explanation.
		 * @returns {void}
		 */
		function markIssue(value, message) {
			issue = value
			get('mcp-file-policy-invalid').hidden = !value
			if (message) get('mcp-file-policy-invalid').textContent = message
			get('mcp-file-policy-overview').hidden = !value
			aggregate()
		}
		/** @returns {void} Synchronize busy state, editable controls and the empty-list warning. */
		function render() {
			form.setAttribute('aria-busy', String(busy))
			mode.disabled = busy
			list.disabled = busy || mode.value !== 'allowlist'
			extensionless.disabled = busy || mode.value !== 'allowlist'
			save.disabled = busy
			get('mcp-file-policy-empty').hidden = mode.value !== 'allowlist' || phpTrim(list.value) !== ''
		}
		/**
		 * @param {string} field Known policy field identifier.
		 * @param {string} message Translated error, or empty to clear it.
		 * @returns {void}
		 */
		function fieldError(field, message) {
			const control = { mode, extensions: list, allowExtensionless: extensionless }[field]
			const error = get('mcp-file-policy-' + field + '-error')
			if (!control || !error) return
			error.hidden = !message
			error.textContent = message || ''
			control.setAttribute('aria-invalid', String(!!message))
			if (message) control.focus()
		}
		const messages = {
			mode: t('mcp', 'Choose no restriction or only listed extensions.'),
			extensions: t('mcp', 'Enter valid extensions, separated by commas or new lines, without paths or wildcards.'),
			allowExtensionless: t('mcp', 'Choose whether files without an extension are allowed.'),
		}
		/**
		 * @param {string} method GET or PUT.
		 * @param {{mode: string, extensions: string[], allowExtensionless: boolean}} [body] Complete policy input.
		 * @returns {Promise<{version: number, mode: string, extensions: string[], allowExtensionless: boolean}>} Verified response.
		 * @throws {object|Error} On HTTP, JSON or policy validation failure.
		 */
		async function exchange(method, body) {
			const response = await request(url, {
				method,
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					requesttoken: requestToken(),
				},
				...(body ? { body: JSON.stringify(body) } : {}),
			})
			const data = await response.json()
			if (!response.ok || !validPolicy(data)) throw { status: response.status, data }
			return data
		}
		/**
		 * @param {{mode: string, extensions: string[], allowExtensionless: boolean}} policy Canonical server response.
		 * @returns {void}
		 */
		function fill(policy) {
			mode.value = policy.mode
			list.value = policy.extensions.join('\n')
			extensionless.checked = policy.allowExtensionless
		}
		/**
		 * @param {Event} event Form submission.
		 * @returns {Promise<void>} Settles after validation or the verified save; never clears input on failure.
		 */
		async function submit(event) {
			event.preventDefault()
			if (busy) return
			Object.keys(messages).forEach(field => fieldError(field, ''))
			status.textContent = ''
			if (!['off', 'allowlist'].includes(mode.value)) {
				fieldError('mode', messages.mode)
				return
			}
			const active = mode.value === 'allowlist'
			let parsed
			try { parsed = active ? extensions(list.value) : [] } catch (_) {
				fieldError('extensions', messages.extensions)
				return
			}
			busy = true
			render()
			status.textContent = t('mcp', 'Saving file type policy…')
			try {
				fill(await exchange('PUT', {
					mode: mode.value,
					extensions: parsed,
					allowExtensionless: active && extensionless.checked,
				}))
				markIssue(false)
				status.textContent = t('mcp', 'File type policy saved.')
			} catch (error) {
				if (error.status === 400 && messages[error.data?.field]) {
					fieldError(error.data.field, messages[error.data.field])
					status.textContent = ''
				} else {
					const message = t('mcp', 'The policy could not be saved and verified. Your input is preserved. Reload before relying on the saved state.')
					markIssue(true, message)
					status.textContent = message
				}
			} finally {
				busy = false
				render()
				for (const [field, control] of Object.entries({ mode, extensions: list, allowExtensionless: extensionless })) {
					if (!get('mcp-file-policy-' + field + '-error').hidden) { control.focus(); break }
				}
			}
		}
		form.addEventListener('submit', submit)
		mode.addEventListener('change', render)
		list.addEventListener('input', render)
		// The tag editor still owns its warning. Observe both its source and the shared marker so
		// late tag responses cannot clear a simultaneous policy warning, nor the reverse.
		if (Observer) {
			const observer = new Observer(aggregate)
			for (const id of ['mcp-tags-invalid-overview', 'mcp-tab-protection-alert']) {
				if (get(id)) observer.observe(get(id), { attributes: true, attributeFilter: ['hidden'] })
			}
		}
		render()
		const ready = exchange('GET').then(policy => {
			fill(policy)
			markIssue(false)
			status.textContent = ''
		}, () => {
			markIssue(true, t('mcp', 'The file type policy could not be loaded. Applicable MCP writes remain refused if the saved configuration is invalid. Choose a complete policy and save it to recover; no restriction is a deliberate choice.'))
			status.textContent = ''
		}).finally(() => { busy = false; render() })
		return { ready, submit, aggregate }
	}

	if (typeof module !== 'undefined' && module.exports) module.exports = { extensions, validPolicy, mount }
	if (scope.document) mount(scope.document, scope.fetch.bind(scope), scope.t,
		scope.OC.generateUrl('/apps/mcp/api/file-write-policy'), () => scope.OC.requestToken, scope.MutationObserver)
})(typeof window !== 'undefined' ? window : globalThis);

/**
 * Revisioned administrator backup editor. It runs after the file-type policy, in this same file, so the page does not
 * load it apart. Transport and display values never become HTML.
 */
(function (scope) {
	'use strict'

	/**
	 * @param {Document} document Admin document.
	 * @param {typeof fetch} request Same-origin HTTP transport.
	 * @param {Function} t Nextcloud translator.
	 * @param {string} url Existing app settings API namespace.
	 * @param {Function} token Request-token provider.
	 * @returns {{ready: Promise<void>, reload: Function}|undefined} editor lifecycle
	 */
	function mount(document, request, t, url, token) {
		const get = id => document.getElementById('mcp-backup-' + id)
		const form = get('form')
		const events = scope.addEventListener ? scope : document
		if (!form) return
		const folder = get('folder'), copies = get('copies'), save = get('save'), reload = get('reload')
		let state = null, busy = false, stale = false
		/** @returns {void} Refresh controls without changing the administrator's draft. */
		function render() {
			form.setAttribute('aria-busy', String(busy))
			folder.disabled = busy || !state
			copies.disabled = busy || !state || (!state.versionsAvailable && copies.checked)
			save.disabled = busy || !state || stale
			reload.disabled = busy
		}
		/**
		 * @param {string} method HTTP verb.
		 * @param {Object|undefined} body Draft.
		 * @returns {Promise<Object>} Canonical backend response.
		 */
		async function exchange(method, body) {
			const response = await request(url, { method, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', requesttoken: token() }, ...(body ? { body: JSON.stringify(body) } : {}) })
			const data = await response.json()
			if (!response.ok) throw new Error(String(response.status))
			if (!data || typeof data.folder !== 'string' || typeof data.copies !== 'boolean' || typeof data.effectiveCopies !== 'boolean' || typeof data.versionsAvailable !== 'boolean' || !/^(0|[1-9][0-9]*)$/.test(data.revision)) throw new Error('invalid')
			return data
		}
		/**
		 * @param {Object} data Verified response.
		 * @returns {void} Fill saved settings and describe the current account's effective state.
		 */
		function fill(data) {
			state = data
			folder.value = data.folder
			copies.checked = data.copies
			stale = false
			get('effective').textContent = !data.versionsForActor
				? t('mcp', 'Copies are required for your account. Existing Files operations are unavailable without the Versions app.')
				: data.effectiveCopies ? t('mcp', 'MCP pre-write copies are currently required for your account.') : t('mcp', 'MCP pre-write copies are currently suspended for your account. Native versioning follows its own policy.')
		}
		/** @returns {Promise<void>} Reload saved state explicitly; a failed load never offers defaults to save. */
		async function load() {
			busy = true; render()
			try { fill(await exchange('GET')); get('status').textContent = '' }
			catch (_) { state = null; get('status').textContent = t('mcp', 'Backup settings could not be loaded. Reload before saving. No changes were made.') }
			finally { busy = false; render() }
		}
		form.addEventListener('submit', async event => {
			event.preventDefault()
			if (busy || !state || stale) return
			busy = true; render()
			const from = state.revision
			try {
				const data = await exchange('PUT', { folder: folder.value, copies: copies.checked, expectedRevision: from })
				fill(data)
				get('status').textContent = t('mcp', 'Backup settings saved.')
				if (scope.CustomEvent) events.dispatchEvent(new scope.CustomEvent('mcp-revision-moved', { detail: { from, to: data.revision } }))
			} catch (error) {
				stale = error.message !== '400'
				get('status').textContent = error.message === '400'
					? t('mcp', 'Use one valid Nextcloud folder name. Suspending copies requires the Versions app to be enabled.')
					: t('mcp', 'Backup settings could not be saved or changed elsewhere. Reload to verify the saved state before trying again.')
			} finally { busy = false; render() }
		})
		copies.addEventListener('change', render)
		reload.addEventListener('click', load)
		events.addEventListener('mcp-revision-moved', event => {
			if (state && !busy && event.detail?.from === state.revision && typeof event.detail.to === 'string') state.revision = event.detail.to
		})
		return { ready: load(), reload: load }
	}
	if (typeof module !== 'undefined' && module.exports) module.exports.mountBackupSettings = mount
	if (scope.document) mount(scope.document, scope.fetch.bind(scope), scope.t, scope.OC.generateUrl('/apps/mcp/api/backup-settings'), () => scope.OC.requestToken)
})(typeof window !== 'undefined' ? window : globalThis)
