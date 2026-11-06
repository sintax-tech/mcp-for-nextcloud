/* admin/registration.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** The admin namespace, shared by the independently registered page units.
 * @param {Window|typeof globalThis} root page global on which the single app namespace is initialized
 * @return {void}
 */
(function (root) {
	'use strict'

	root.OCA = root.OCA || {}
	root.OCA.Mcp = root.OCA.Mcp || {}
	root.OCA.Mcp.Admin = root.OCA.Mcp.Admin || { modules: {}, sessions: {} }
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/picker/session.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the picker session unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	let pickerCount = 0

	admin.modules.picker = admin.modules.picker || {}
	/** Mount picker elements with a page-unique ID and expose live selection and control state.
	 * @param {object} context picker specification, mounted controls and live selection bindings
	 * @return {void}
	 */
	admin.modules.picker.session = function (context) {
		/** Create a DOM element, apply its properties and append its child nodes or text.
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

		/** Write save feedback into the picker's optional status element.
		 * @param {string} text status text for the save feedback element
		 * @return {void}
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
;
/* admin/picker/list.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the picker list unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.picker = admin.modules.picker || {}
	/** Attach list visibility, filtering, keyboard movement and option rendering.
	 * @param {object} context picker specification, mounted controls and live selection bindings
	 * @return {void}
	 */
	admin.modules.picker.list = function (context) {
		/** The list items the filter currently shows.
		 * @return {HTMLElement[]} the list items the filter currently shows */
		function visibleItems() {
			return Array.from(context.list.querySelectorAll('[role="option"]')).filter((item) => !item.hidden)
		}

		/** Show the option list when options exist and update the combobox expanded state.
		 * @return {void}
		 */
		function openList() {
			if (context.options.length === 0) {
				return
			}
			context.list.hidden = false
			context.input.setAttribute('aria-expanded', 'true')
		}

		/** Hide the option list and clear its active descendant and keyboard selection.
		 * @return {void}
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

		/** Move the active option through visible items with wraparound and scroll it into view.
		 * @param {number} delta keyboard steps within the visible options, wrapping around
		 * @return {void}
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
		 * @return {void}
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
		 * @return {void}
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
;
/* admin/picker/rendering.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the picker rendering unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.picker = admin.modules.picker || {}
	/** Attach selected chips, fallback display and save lock presentation.
	 * @param {object} context picker specification, mounted controls and live selection bindings
	 * @return {void}
	 */
	admin.modules.picker.rendering = function (context) {
		/** Rebuilds the chips of the selected options.
		 * @return {void}
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
		 * @return {void}
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

		/** Lock picker controls during persistence and update its accessible busy state.
		 * @param {boolean} locked locks every control while a save runs
		 * @return {void}
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
;
/* admin/picker/saving.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the picker saving unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.picker = admin.modules.picker || {}
	/** Attach confirmed selection persistence, rollback and option toggling.
	 * @param {object} context picker specification, mounted controls and live selection bindings
	 * @return {void}
	 */
	admin.modules.picker.saving = function (context) {
		/**
		 * Persists a new selection: confirms first when the caller asks, locks the control, keeps the
		 * previous state on failure and reports through the status element and the onError hook.
		 * @param {Set<string>} next the intended selection
		 * @param {boolean} force permits an explicit save while the options show a fallback
		 * @return {Promise<void>}
		 */
		async function commit(next, force = false) {
			if (context.busy || (context.fallbackText !== null && !force)) {
				return
			}
			if (context.spec.confirmSave && !(await context.spec.confirmSave(Array.from(next)))) {
				return
			}
			// Rebuilding chips and disabling the input can send keyboard focus to the body.
			const restoreFocus = document.activeElement === context.input || context.chips.contains(document.activeElement)
			const before = context.selected
			context.selected = new Set(next)
			context.paint()
			context.spec.onChange?.(Array.from(context.selected))
			context.setBusy(true)
			context.setStatus(t('mcp', 'Saving…'))
			try {
				const result = await context.spec.save(Array.from(context.selected), Array.from(before))
				context.setStatus(t('mcp', 'Saved'))
				if (context.spec.clearSavedAfterMs) {
					const savedText = t('mcp', 'Saved')
					setTimeout(() => {
						if (context.spec.status && context.spec.status.textContent === savedText) {
							context.spec.status.textContent = ''
						}
					}, context.spec.clearSavedAfterMs)
				}
				await context.spec.onSaved?.(result)
			} catch (error) {
				// Rollback first, so an onError hook that resynchronizes wins over the old state.
				context.selected = before
				context.paint()
				context.spec.onChange?.(Array.from(context.selected))
				context.setStatus(context.spec.errorStatus ?? t('mcp', 'Not saved'))
				await context.spec.onError?.(error)
			} finally {
				context.setBusy(false)
				if (restoreFocus && !context.box.hidden
					&& (document.activeElement === document.body || document.activeElement === context.input)) {
					context.input.focus()
				}
			}
		}

		/** Add or remove an option from the picker selection and start its save.
		 * @param {string} id option to add to or drop from the selection
		 * @return {void}
		 */
		function toggle(id) {
			const next = new Set(context.selected)
			if (next.has(id)) {
				next.delete(id)
			} else {
				next.add(id)
			}
			context.commit(next)
		}

		Object.assign(context, { commit, toggle })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/picker/start.js */
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

	/** Mount an independent multi-select picker and return its selection and persistence controls.
	 * @template T
	 * @param {object} spec
	 * @param {HTMLElement} spec.container mount point; its children are replaced by the control
	 * @param {Array<{id: string, label: string, badge?: {text: string, title?: string, className?: string}}>} spec.options
	 *        every option; a selected id without an option here is dropped
	 * @param {string[]} spec.selectedIds currently selected option ids
	 * @param {string} spec.searchLabel accessible label and placeholder of the search field
	 * @param {string} spec.listLabel accessible label of the listbox
	 * @param {string} spec.emptyOptionsText shown when there are no options at all
	 * @param {string} spec.noMatchText shown when the filter matches no option
	 * @param {(nextIds: string[], previousIds: string[]) => Promise<T>} spec.save persists the selection
	 *        and throws on failure; any value it resolves with reaches onSaved
	 * @param {(nextIds: string[]) => boolean|Promise<boolean>} [spec.confirmSave] a false answer cancels
	 *        the change without an error (for example the empty-selection confirmation)
	 * @param {(result: T) => void|Promise<void>} [spec.onSaved] runs after a successful save
	 * @param {(error: Error) => void|Promise<void>} [spec.onError] runs after a failed save; it may call
	 *        setOptions to resynchronize the control (for example after a conflict)
	 * @param {(selectedIds: string[]) => void} [spec.onChange] runs whenever the shown selection changes
	 * @param {HTMLElement} [spec.status] element that receives the saving/saved/error feedback
	 * @param {number} [spec.clearSavedAfterMs] clears the "Saved" status after this many ms
	 * @param {string} [spec.errorStatus] status text after a failed save (default: the "Not saved" text)
	 * @return {{setOptions: function(object[], string[]): void, commit: function(object=): Promise<void>,
	 *     showFallback: function(string): void, getSelected: function(): string[]}} mounted picker controls
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
			 * @param {object[]} nextOptions same shape as spec.options
			 * @param {string[]} nextSelectedIds IDs retained when present in nextOptions
			 * @return {void}
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
			/** Saves again; force bypasses the fallback message, keeping confirmation and locking.
			 * @param {{force?: boolean}} [options] explicit save options
			 * @return {Promise<void>} resolves after the attempt or cancellation
			 */
			commit({ force = false } = {}) {
				return context.commit(new Set(context.selected), force)
			},
			/** Shows a plain message instead of the control, for a load failure or an empty option list.
			 * @param {string} text fallback explanation
			 * @return {void}
			 */
			showFallback(text) {
				context.fallbackText = text
				context.paint()
			},
			/** Read the picker's currently selected option IDs.
			 * @return {string[]} selected IDs in selection order
			 */
			getSelected() {
				return Array.from(context.selected)
			},
		}
	}

	admin.picker = { create }
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/access/levels/polls.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Polls: four named, independent presets instead of cumulative tiers, so creating polls never rides on
 * a protected read. src/admin/admin-levels.js asks window.OCA.Mcp.Admin.pollsGrants for the Polls tiers and levels.
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
		return { results: t('mcp', 'results'), links: t('mcp', 'Read existing links (credentials)'), configure: t('mcp', 'configure'), options: t('mcp', 'options'), 'options.remove': t('mcp', 'remove or restore options'), invite: t('mcp', 'invite') }
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

	root.OCA.Mcp.Admin.pollsGrants = { levelLabels, operationLabels, operationHint, tiers, operationsFor }
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/files.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Files: Read, then Edit adds editing, creating, moving and restoring, then Full adds sharing, links and deleting.
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
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
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
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
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
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
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
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
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
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
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
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
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
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
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
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
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.logs = [['read']]
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/mail.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * What the Mail operations let the AI do, for the column tooltips of src/admin/matrix/labels.js: `read` lists accounts and
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
	 * none of its levels is called Full. src/admin/matrix/labels.js translates the names.
	 */
	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.mail = [['read'], ['messages'], ['attachments'], ['flags'], ['move'], ['draft'], ['send']]
	catalog.tierNames.mail = ['folders', 'messages', 'attachments', 'flags', 'move', 'draft', 'send']

	root.OCA.Mcp.Admin.mailGrants = { WRITE_OPERATIONS, operationHint, writeWarning, confirmWrites }
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/tables.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Tables: Read, then Full adds creating and editing together.
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
	catalog.tiers.tables = [['read'], ['create', 'edit']]
	// Structure and sharing remain operation-only until the presets are explicitly widened.
	catalog.untiered = Object.assign(catalog.untiered || {}, { tables: ['schema', 'share'] })
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/access/levels/forms.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Access levels of Forms: Read, then writing forms, then writing forms and reading responses; src/admin/matrix/labels.js translates the names.
 * Registered in window.OCA.Mcp.Admin.levelCatalog, which src/admin/admin-levels.js reads; see that file for how tiers make levels.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }
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
 * by operation. The tiers follow GrantCatalog::CATALOG; an operation the tiers do not name (unless explicitly untiered) falls into
 * the last tier, so it is never hidden and never granted by a lower level.
 *
 * The level functions are pure, with no DOM and no strings: src/admin/matrix/level-cells.js draws the cells and src/admin/matrix/labels.js names the levels.
 * The tiers of each module live in its own file, src/admin/access/levels/<app>.js, which registers them in
 * window.OCA.Mcp.Admin.levelCatalog before this file runs (src/admin.manifest lists them first). Polls has named presets
 * instead of tiers (src/admin/access/levels/polls.js, window.OCA.Mcp.Admin.pollsGrants) and Mail adds its write warnings
 * (src/admin/access/levels/mail.js, window.OCA.Mcp.Admin.mailGrants); the other admin scripts read both.
 */
(function (root) {
	'use strict'

	const catalog = root.OCA.Mcp.Admin.levelCatalog || { tiers: {}, tierNames: {} }

	/** Cumulative tiers per module, from src/admin/access/levels/<app>.js: [Read, Edit, Full]. Modules with one operation have only Read. */
	const TIERS = catalog.tiers

	/** Names of the tiers by how many a module has. */
	const TIER_NAMES = { 1: ['read'], 2: ['read', 'full'], 3: ['read', 'edit', 'full'] }

	/**
	 * Names of the tiers of the modules whose tiers are not Read, Edit and Full, in tier order (Forms and Mail, from
	 * their files). src/admin/matrix/labels.js translates them.
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
	 * the last tier, except operations explicitly kept outside presets by the module.
	 *
	 * @param {string} module module id
	 * @param {string[]} operations the module's operations in the catalog
	 * @return {Array<{level: string, operations: string[]}>} one entry per tier, cumulative in this order
	 */
	function tiers(module, operations) {
		if (module === 'polls') return root.OCA.Mcp.Admin.pollsGrants?.tiers(operations) || []
		const known = TIERS[module] || [operations.filter((operation) => operation === 'read'), operations.filter((operation) => operation !== 'read')]
		const listed = known.map((tier) => tier.filter((operation) => operations.includes(operation)))
		const untiered = catalog.untiered?.[module] || []
		const rest = operations.filter((operation) => !listed.some((tier) => tier.includes(operation)) && !untiered.includes(operation))
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
		if (module === 'polls') return root.OCA.Mcp.Admin.pollsGrants?.operationsFor(operations, level) || []
		const all = tiers(module, operations)
		const upTo = all.findIndex((tier) => tier.level === level)
		if (level !== 'none' && upTo < 0) {
			throw new Error('Unknown level ' + level + ' for ' + module)
		}
		const allowed = new Set(all.slice(0, upTo + 1).flatMap((tier) => tier.operations))
		return operations.filter((operation) => allowed.has(operation))
	}

	/**
	 * A preset changes only operations represented by its tiers; None explicitly clears the whole catalog.
	 * @param {string} module catalog module id
	 * @param {string[]} operations catalog operations
	 * @param {string} level existing preset id
	 * @return {Object<string, boolean>} omitted operations keep their stored values
	 */
	function targetFor(module, operations, level) {
		const allowed = operationsFor(module, operations, level)
		const tiered = new Set(tiers(module, operations).flatMap(tier => tier.operations))
		return Object.fromEntries(operations.filter(operation => level === 'none' || tiered.has(operation))
			.map(operation => [operation, allowed.includes(operation)]))
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

	root.OCA.Mcp.Admin.levels = { TIERS, MODULE_TIER_NAMES, tierNames, tiers, levels, operationsFor, targetFor, levelOf, originOf }
})(typeof window !== 'undefined' ? window : globalThis)
;
/* admin/matrix/session.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Initial matrix controls and saved page state.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Expose the matrix controls, paging state and catalog through live context bindings.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.session = function (context) {
		const root = context.root
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

		const notice = document.getElementById('mcp-bulk-notice')
		const noticeText = document.getElementById('mcp-bulk-notice-text')
		const undoButton = document.getElementById('mcp-bulk-undo')

		Object.defineProperties(context, {
			root: { configurable: true, get: () => root },
			FIXED_COLUMNS: { configurable: true, get: () => FIXED_COLUMNS },
			state: { configurable: true, get: () => state },
			table: { configurable: true, get: () => table },
			searchInput: { configurable: true, get: () => searchInput },
			groupSelect: { configurable: true, get: () => groupSelect },
			filterSelect: { configurable: true, get: () => filterSelect },
			appSelect: { configurable: true, get: () => appSelect },
			appFilter: { configurable: true, get: () => appFilter },
			viewButtons: { configurable: true, get: () => viewButtons },
			legends: { configurable: true, get: () => legends },
			countInfo: { configurable: true, get: () => countInfo },
			prevButtons: { configurable: true, get: () => prevButtons },
			nextButtons: { configurable: true, get: () => nextButtons },
			pageInfos: { configurable: true, get: () => pageInfos },
			onlyButtons: { configurable: true, get: () => onlyButtons },
			serviceBox: { configurable: true, get: () => serviceBox },
			serviceStatus: { configurable: true, get: () => serviceStatus },
			notice: { configurable: true, get: () => notice },
			noticeText: { configurable: true, get: () => noticeText },
			undoButton: { configurable: true, get: () => undoButton },
		})
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/labels.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix labels unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach the translated module, operation and level label catalogs to the matrix context.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.labels = function (context) {
		/** Module labels.
		 * @return {Object<string, string>} module labels */
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

		/** Operation labels.
		 * @return {Object<string, string>} operation labels */
		function operationLabels() {
			return {
				...window.OCA.Mcp.Admin.pollsGrants?.operationLabels(),
				read: t('mcp', 'read'),
				schema: t('mcp', 'schema'), results: t('mcp', 'results'),
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
			if (module === 'forms' && operation === 'edit') {
				return t('mcp', 'Change existing forms, including adding, reordering and deleting questions and options and changing a question type')
			}
			const mailHint = window.OCA.Mcp.Admin.pollsGrants?.operationHint(module, operation) || (window.OCA.Mcp.Admin.mailGrants ? window.OCA.Mcp.Admin.mailGrants.operationHint(module, operation) : null)
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
				share: module === 'files' ? t('mcp', 'Share own files with people and groups') : '',
				link: t('mcp', 'Create public links to own files'),
			}
			return hints[operation] || ''
		}

		/** Names of the access levels of src/admin/admin-levels.js.
		 * @return {Object<string, string>} names of the access levels of src/admin/admin-levels.js */
		function levelLabels() {
			return {
				...window.OCA.Mcp.Admin.pollsGrants?.levelLabels(),
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

		Object.assign(context, { levelLabels, moduleLabels, operationHint, operationLabels })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/requests.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix requests unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach JSON requests, policy error recovery and the live matrix write lock.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.requests = function (context) {
		let matrixLocked = false

		/** Fetch a JSON API response and reject unsuccessful requests with their response details.
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

		/** Reloads the matrix with every matrix write blocked until the new page is on screen.
		 * @return {Promise<void>}
		 */
		async function reloadLocked() {
			matrixLocked = true
			try {
				await context.load()
			} finally {
				matrixLocked = false
			}
		}

		/**
		 * Tells the admin what a failed save means when the policy moved or the storage failed, and reloads
		 * the matrix in those cases so nothing is written twice.
		 * @param {Error} e error thrown by api()
		 * @return {Promise<boolean>} whether the error was handled here (the matrix is then reloaded)
		 */
		async function policyError(e) {
			if (e.message === '409' || e.message === '428') {
				context.notifyError(t('mcp', 'The permissions were changed in another window or by another administrator. The table was reloaded with the saved values; nothing was overwritten.'))
			} else if (e.message === '503' && e.data && e.data.uncertain) {
				context.notifyError(t('mcp', 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.'))
			} else {
				return false
			}
			await context.reloadLocked()
			return true
		}

		/** Show an error through the available Nextcloud toast or the browser alert fallback.
		 * @param {string} message error shown to the admin
		 * @return {void}
		 */
		function notifyError(message) {
			// The old notification global is gone since Nextcloud 34 and OCP.Toast is deprecated, so without it the browser's own dialog
			// still shows the message instead of losing it.
			if (window.OCP && OCP.Toast) {
				OCP.Toast.error(message)
			} else {
				window.alert(message)
			}
		}

		Object.defineProperties(context, {
			matrixLocked: { configurable: true, get: () => matrixLocked, set: (value) => { matrixLocked = value } },
		})

		Object.assign(context, { api, notifyError, policyError, reloadLocked })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/revisions.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix revisions unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach revision announcements and subscriptions used to keep settings writes synchronized.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.revisions = function (context) {
		const REVISION_MOVED = 'mcp-revision-moved'

		/**
		 * Tells the other blocks of this page that a write of this page moved the policy revision from `from` to `to`.
		 * Only the answer of a save qualifies: the server committed it from `from`, so `to` holds that write and nothing else.
		 * @param {string} from revision the write was sent with
		 * @param {string} to revision the server answered with
		 * @return {void}
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
		 * @param {string} text
		 * @return {string}
		 */
		function phpTrim(text) {
			return text.replace(/^[ \t\n\r\x00\x0B]+|[ \t\n\r\x00\x0B]+$/g, '')
		}

		/**
		 * Keeps a block's own revision in step with the writes of this page: when the block is exactly at the revision a
		 * write committed from, it takes the one that write made, and its unsaved changes stay valid.
		 * @param {function(): (string|null)} get read the block's live revision
		 * @param {function(string): void} set replace the block's live revision
		 * @return {void}
		 */
		function followRevision(get, set) {
			window.addEventListener(REVISION_MOVED, (event) => {
				if (get() === event.detail.from) {
					set(event.detail.to)
				}
			})
		}

		Object.defineProperties(context, {
			REVISION_MOVED: { configurable: true, get: () => REVISION_MOVED },
		})

		Object.assign(context, { announce, followRevision, oneStep, phpTrim })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/elements.js */
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
;
/* admin/matrix/loading.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix loading unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach matrix fetching, error rendering and group and app filter population.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.loading = function (context) {
		let loadSeq = 0

		/**
		 * Reads the current page from the server and re-renders the table; a failure is shown in the table with a retry.
		 * The page and its revision always come from the same response, and only the newest request is rendered.
		 * @return {Promise<void>}
		 */
		async function load() {
			const seq = ++loadSeq
			context.table.setAttribute('aria-busy', 'true')
			context.table.classList.add('mcp-loading')
			const query = new URLSearchParams({ search: context.state.search, group: context.state.group, filter: context.state.filter, page: String(context.state.page) })
			try {
				const data = await context.api('GET', '/api/grants?' + query.toString())
				if (seq !== loadSeq) {
					return
				}
				context.state.data = data
				context.render()
			} catch (e) {
				if (seq !== loadSeq) {
					return
				}
				context.renderError()
				context.notifyError(t('mcp', 'Could not load the users.'))
			} finally {
				if (seq === loadSeq) {
					context.table.setAttribute('aria-busy', 'false')
					context.table.classList.remove('mcp-loading')
				}
			}
		}

		/** Replaces the body with an error row and a retry button, keeping the last header.
		 * @return {void}
		 */
		function renderError() {
			const retry = context.el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: context.load })
			const cell = context.el('td', { colSpan: context.state.data ? context.FIXED_COLUMNS + context.visiblePermissionColumns() : 1, className: 'mcp-empty mcp-load-error' },
				[t('mcp', 'Could not load the users.'), ' ', retry])
			context.table.tBodies[0].replaceChildren(context.el('tr', {}, [cell]))
			context.applyAppFilter()
		}

		/** Fills the group filter once, keeping the current selection.
		 * @return {void}
		 */
		function renderGroups() {
			if (context.state.groupsLoaded) {
				return
			}
			for (const group of context.state.data.groups) {
				context.groupSelect.append(context.el('option', { value: group.id, textContent: group.displayName }))
			}
			context.state.groupsLoaded = true
		}

		/** Populate app choices from the actual catalog, preserving a valid selection.
		 * @return {void}
		 */
		function renderApps() {
			const labels = context.moduleLabels()
			const modules = Object.keys(context.state.data.catalog)
			if (!modules.includes(context.state.app)) {
				const stale = context.state.app !== ''
				context.state.app = ''
				if (stale) {
					// An address naming an unknown app is corrected in place, without a new history entry.
					context.writeAppToHash(true)
				}
			}
			context.appSelect.replaceChildren(context.el('option', { value: '', textContent: t('mcp', 'All apps') }),
				...modules.map((module) => context.el('option', { value: module, textContent: labels[module] || module })))
			context.appSelect.value = context.state.app
			context.appSelect.disabled = false
		}

		Object.defineProperties(context, {
			loadSeq: { configurable: true, get: () => loadSeq, set: (value) => { loadSeq = value } },
		})

		Object.assign(context, { load, renderApps, renderError, renderGroups })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/view.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix view unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach view switching, column filtering and URL history synchronization.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.view = function (context) {
		/** Filter only presentation; user queries and permission payloads stay unchanged.
		 * @return {void}
		 */
		function applyAppFilter() {
			const app = context.shownApp()
			for (const cell of context.table.querySelectorAll('[data-module]')) {
				cell.hidden = app !== '' && cell.dataset.module !== app
				if (cell.hidden) {
					for (const menu of cell.querySelectorAll('details[open]')) {
						menu.open = false
					}
				}
			}
			const permissions = context.visiblePermissionColumns()
			for (const cell of context.table.querySelectorAll('td.mcp-empty')) {
				cell.colSpan = context.FIXED_COLUMNS + permissions
			}
			for (const cell of context.table.querySelectorAll('td.mcp-row-unavailable')) {
				cell.colSpan = permissions
			}
			context.stickSecondHeaderRow()
		}

		/** Read the Access tab's app parameter on reload and history navigation.
		 * @return {void}
		 */
		function readAppFromHash() {
			const [tab, query = ''] = window.location.hash.slice(1).split('?')
			if (tab !== 'access') {
				return
			}
			const params = new URLSearchParams(query)
			const view = params.get('view') === 'operations' ? 'operations' : 'levels'
			context.state.app = params.get('app') || ''
			if (!context.state.data) {
				context.state.view = view
				return
			}
			if (view !== context.state.view) {
				context.state.view = view
				context.render()
				return
			}
			context.renderApps()
			context.applyAppFilter()
		}

		/** Whether the matrix shows one level per module.
		 * @return {boolean} whether the matrix shows one level per module */
		function isLevelsView() {
			return context.state.view === 'levels' && Boolean(window.OCA.Mcp.Admin.levels)
		}

		/** The app whose columns are shown alone, '' for all; the levels view always shows every module.
		 * @return {string} the app whose columns are shown alone, '' for all; the levels view always shows every module */
		function shownApp() {
			return context.isLevelsView() ? '' : context.state.app
		}

		/**
		 * Switches between the levels and the operations view, keeping the page, the filters and the data on screen.
		 * @param {string} view "levels" or "operations"
		 * @param {string} [app] module to show alone in the operations view
		 * @return {void}
		 */
		function setView(view, app) {
			context.state.view = view
			// The levels view shows every module, so the app filter of the operations view leaves the address with it.
			if (view === 'levels') {
				context.state.app = ''
			} else if (app !== undefined) {
				context.state.app = app
			}
			if (context.state.data) {
				context.render()
			}
			context.writeAppToHash(false)
		}

		/**
		 * Writes the chosen app into the address (#access?app=<module>, no parameter for all apps).
		 * @param {boolean} replace correct the current entry instead of adding a history entry
		 * @return {void}
		 */
		function writeAppToHash(replace) {
			const [tab, query = ''] = window.location.hash.slice(1).split('?')
			if (tab !== 'access') {
				return
			}
			const params = new URLSearchParams(query)
			if (context.state.app === '') {
				params.delete('app')
			} else {
				params.set('app', context.state.app)
			}
			if (context.state.view === 'operations') {
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

		/** Bind app and view controls and hash navigation, then apply the current address state.
		 * @return {void}
		 */
		function attachView() {
			context.appSelect.addEventListener('change', () => {
				context.state.app = context.appSelect.value
				context.applyAppFilter()
				context.writeAppToHash(false)
			})
			for (const [view, button] of Object.entries(context.viewButtons)) {
				button?.addEventListener('click', () => {
					if (context.state.view !== view) {
						context.setView(view)
					}
				})
			}
			window.addEventListener('hashchange', context.readAppFromHash)
			context.readAppFromHash()

		}

		Object.assign(context, { applyAppFilter, attachView, isLevelsView, readAppFromHash, setView, shownApp, writeAppToHash })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/rendering.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix rendering unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach table rendering and sticky header measurement to the matrix context.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.rendering = function (context) {
		let observedHeaderRow = null
		let headerObserver = null

		/** Makes the second header row stick right below the first one, whose height depends on the density.
		 * @return {void}
		 */
		function stickSecondHeaderRow() {
			const [top, sub] = context.table.tHead.rows
			if (!top || !sub) {
				return
			}
			const offset = top.getBoundingClientRect().height
			for (const cell of sub.cells) {
				cell.style.top = offset + 'px'
			}
		}

		/**
		 * Re-measures the first header row whenever it resizes. The Access panel starts hidden, so the
		 * render there measures zero; showing the tab (or changing density or font size) resizes the row
		 * and the observer re-sticks the second row below it. A browser without ResizeObserver keeps the
		 * render-time measure, which is correct whenever the panel is visible.
		 * @return {void}
		 */
		function observeHeaderRow() {
			if (!window.ResizeObserver) {
				return
			}
			const top = context.table.tHead?.rows?.[0]
			if (!top || top === observedHeaderRow) {
				return
			}
			if (!headerObserver) {
				headerObserver = new window.ResizeObserver(context.stickSecondHeaderRow)
			}
			headerObserver.disconnect()
			observedHeaderRow = top
			headerObserver.observe(top)
		}

		/** Renders header and rows from state.data.
		 * @return {void}
		 */
		function render() {
			const data = context.state.data
			// After a conflict the saved switch may differ from what this page last showed.
			if (!context.serviceBox.disabled) {
				context.serviceBox.checked = data.serviceEnabled
			}
			context.renderGroups()
			context.renderApps()
			const modules = context.moduleLabels()
			const operations = context.operationLabels()
			const levelsView = context.isLevelsView()
			for (const [view, button] of Object.entries(context.viewButtons)) {
				button?.setAttribute('aria-pressed', String(view === (levelsView ? 'levels' : 'operations')))
			}
			for (const [view, legend] of Object.entries(context.legends)) {
				if (legend) legend.hidden = view !== (levelsView ? 'levels' : 'operations')
			}
			if (context.appFilter) {
				context.appFilter.hidden = levelsView
			}
			context.table.classList.toggle('mcp-levels', levelsView)

			const top = context.el('tr')
			const sub = context.el('tr')
			const rowSpan = levelsView ? 1 : 2
			const selectHeader = context.el('th', { rowSpan, className: 'mcp-select-col', scope: 'col' }, [context.el('span', { className: 'mcp-sr-only', textContent: t('mcp', 'Select') })])
			// The "select every user on this page" box of src/admin/overrides/start.js heads the selection column.
			window.OCA.Mcp.Admin.overrides?.decorateHeader?.(selectHeader)
			top.append(selectHeader)
			top.append(context.el('th', { rowSpan, className: 'mcp-user-col', scope: 'col', textContent: t('mcp', 'User') }))
			const canConnect = t('mcp', 'Can connect')
			top.append(context.el('th', { rowSpan, scope: 'col', title: t('mcp', 'The administrator allows this user to connect an MCP client'), textContent: canConnect }))
			Object.entries(data.catalog).forEach(([module, ops], index) => {
				const band = 'mcp-band-' + (index % 2)
				const name = modules[module] || module
				const header = context.el('th', { colSpan: levelsView ? 1 : ops.length, scope: levelsView ? 'col' : 'colgroup', className: 'mcp-module ' + band, textContent: name })
				header.dataset.module = module
				top.append(header)
				if (levelsView) {
					return
				}
				ops.forEach((operation, i) => {
					const cell = context.el('th', {
						scope: 'col',
						className: band + (i === 0 ? ' mcp-module' : ''),
						title: context.operationHint(module, operation),
						textContent: operations[operation] || operation,
					})
					cell.dataset.module = module
					sub.append(cell)
				})
			})
			top.append(context.el('th', { rowSpan, scope: 'col', className: 'mcp-actions-col' }, [context.el('span', { className: 'mcp-sr-only', textContent: t('mcp', 'Actions') })]))
			context.table.tHead.replaceChildren(...(levelsView ? [top] : [top, sub]))
			if (context.bulkBusy) {
				context.setBulkBusy(true)
			}
			context.stickSecondHeaderRow()
			context.observeHeaderRow()

			const rows = data.users.map(context.renderRow)
			if (rows.length === 0) {
				const columns = context.FIXED_COLUMNS + (levelsView ? Object.keys(data.catalog).length : Object.values(data.catalog).reduce((sum, ops) => sum + ops.length, 0))
				rows.push(context.el('tr', {}, [context.el('td', { colSpan: columns, className: 'mcp-empty', textContent: t('mcp', 'No users found.') })]))
			}
			context.table.tBodies[0].replaceChildren(...rows)
			context.applyAppFilter()
			context.applyExceptionsFilter()

			const pageText = data.total === null
				? t('mcp', 'Page {page}', { page: data.page })
				: t('mcp', 'Page {page} of {pages}', { page: data.page, pages: Math.max(1, Math.ceil(data.total / data.pageSize)) })
			for (const button of context.prevButtons) {
				button.disabled = data.page <= 1
			}
			for (const button of context.nextButtons) {
				button.disabled = !data.hasMore
			}
			for (const info of context.pageInfos) {
				info.textContent = pageText
			}
			const from = (data.page - 1) * data.pageSize + 1
			const to = from + data.users.length - 1
			context.countInfo.textContent = data.users.length === 0
				? ''
				: (data.total === null
					? t('mcp', 'Users {from}–{to}', { from, to })
					: t('mcp', 'Users {from}–{to} of {total}', { from, to, total: data.total }))
			window.dispatchEvent(new Event('mcp-matrix-rendered'))
		}

		Object.defineProperties(context, {
			observedHeaderRow: { configurable: true, get: () => observedHeaderRow, set: (value) => { observedHeaderRow = value } },
			headerObserver: { configurable: true, get: () => headerObserver, set: (value) => { headerObserver = value } },
		})

		Object.assign(context, { observeHeaderRow, render, stickSecondHeaderRow })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/rows.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix rows unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach user rows, action menus and the individual exception filter.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.rows = function (context) {
		const OWN_SOURCES = ['individual_allow', 'individual_deny', 'invalid']

		/** Build the user identity, connection, permission and action cells for one matrix row.
		 * @param {object} user row from the API
		 * @return {HTMLTableRowElement}
		 */
		function renderRow(user) {
			const row = context.buildRow(user)
			window.OCA.Mcp.Admin.overrides?.decorateRow(row, user)
			return row
		}

		/** Build the identity, connection, permission and action cells for a matrix user.
		 * @param {object} user row from the API
		 * @return {HTMLTableRowElement}
		 */
		function buildRow(user) {
			const row = context.el('tr', { className: user.enabled ? '' : 'mcp-disabled-user' })
			row.dataset.uid = user.uid
			// The selection box of src/admin/overrides/start.js goes here, in its own column instead of next to the name.
			row.append(context.el('td', { className: 'mcp-select-col' }))
			row.append(context.el('th', { scope: 'row', className: 'mcp-user-col' }, [context.el('span', { className: 'mcp-name', textContent: user.displayName }), context.userLine(user)]))
			row.append(context.isLevelsView() ? context.eligibleCell(user) : context.permissionCell(user, null, user.eligible, t('mcp', 'Can connect'), { eligible: null }))
			const rowReason = context.rowUnavailableReason(user)
			if (rowReason !== null) {
				// One notice instead of a mark in every column; the saved permissions come back with "Can connect".
				const notice = context.el('td', { className: 'mcp-cell mcp-row-unavailable', colSpan: context.visiblePermissionColumns(), textContent: rowReason })
				notice.title = user.enabled
					? t('mcp', 'The saved permissions are kept and shown again when “Can connect” is on.')
					: t('mcp', 'The saved permissions are kept and shown again when the account is enabled and “Can connect” is on.')
				row.append(notice)
				row.append(context.actionsCell(user))
				return row
			}
			const modules = context.moduleLabels()
			const operations = context.operationLabels()
			if (context.isLevelsView()) {
				Object.entries(context.state.data.catalog).forEach(([module, ops], index) => row.append(context.levelCell(user, module, ops, index)))
				row.append(context.actionsCell(user))
				return row
			}
			Object.entries(context.state.data.catalog).forEach(([module, ops], index) => {
				ops.forEach((operation, i) => {
					// The same name as the column header and the reset button, never the raw id ("files:read").
					const label = (modules[module] || module) + ': ' + (operations[operation] || operation)
					const cell = context.permissionCell(user, module, user.grants[module][operation], label, { module, operation, granted: null })
					cell.dataset.module = module
					cell.hidden = context.state.app !== '' && context.state.app !== module
					cell.classList.add('mcp-band-' + (index % 2))
					if (i === 0) {
						cell.classList.add('mcp-module')
						// "Restricted to N" or "Scope unknown" of a resource scope, drawn and opened by src/admin/scope/start.js.
						const mark = user.scope?.[module] && window.OCA.Mcp.Admin.scope ? window.OCA.Mcp.Admin.scope.indicator(user.uid, module, user.scope[module]) : null
						if (mark) cell.append(mark)
					}
					row.append(cell)
				})
			})
			row.append(context.actionsCell(user))
			return row
		}

		/**
		 * The second line under a name: "Account disabled", "Connected" (with its mark, in the success colour as text) or, for
		 * everyone else, the user ID. Being connected is a fact worth seeing; not being connected is the normal case and says
		 * nothing more than the ID.
		 * @param {object} user row from the API
		 * @return {HTMLElement}
		 */
		function userLine(user) {
			if (!user.enabled) {
				return context.el('span', { className: 'mcp-uid mcp-user-disabled', textContent: t('mcp', 'Account disabled') })
			}
			if (user.eligible && user.connected) {
				const mark = context.el('span', { className: 'mcp-connected-mark', textContent: '●' })
				mark.setAttribute('aria-hidden', 'true')
				const line = context.el('span', { className: 'mcp-uid mcp-user-connected' }, [mark, ' ', t('mcp', 'Connected')])
				line.title = user.uid
				return line
			}
			return context.el('span', { className: 'mcp-uid', textContent: user.uid })
		}

		/** Build a user row's actions menu cell when the row actions surface is available.
		 * @return {HTMLTableCellElement} the cell with the row's actions menu (src/admin/admin-row-actions.js)
		 * @param {object} user matrix user row with grants, origins and availability
		 */
		function actionsCell(user) {
			const cell = context.el('td', { className: 'mcp-actions-col' })
			const button = window.OCA.Mcp.Admin.rowActions?.button(user)
			if (button) cell.append(button)
			return cell
		}

		/**
		 * Whether a user has a real exception on the page (src/admin/admin-exceptions.js): an individual value that differs from what
		 * they would inherit. null while that is not known.
		 * @param {object} user row from the API
		 * @return {boolean|null}
		 */
		function hasException(user) {
			const exceptions = window.OCA.Mcp.Admin.exceptions
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
		 * @return {void}
		 */
		function applyExceptionsFilter() {
			if (!context.onlyButtons.exceptions || !context.state.data) return
			const known = context.state.data.users.map((user) => [user.uid, context.hasException(user)])
			const pending = known.some(([, value]) => value === null)
			const count = known.filter(([, value]) => value === true).length
			context.onlyButtons.exceptions.textContent = pending
				? t('mcp', 'With exceptions')
				: t('mcp', 'With exceptions ({count})', { count }, undefined, { escape: false })
			context.onlyButtons.exceptions.title = t('mcp', 'People on this page with an individual value that differs from what they inherit')
			for (const [only, button] of Object.entries(context.onlyButtons)) {
				button?.setAttribute('aria-pressed', String((only === 'exceptions') === context.state.exceptionsOnly))
			}
			const show = new Set(known.filter(([, value]) => !context.state.exceptionsOnly || value !== false).map(([uid]) => uid))
			let shown = 0
			for (const row of context.table.tBodies[0].rows) {
				if (row.dataset.uid === undefined) continue
				row.hidden = !show.has(row.dataset.uid)
				if (!row.hidden) shown++
			}
			context.table.tBodies[0].querySelector('tr.mcp-only-empty')?.remove()
			if (context.state.exceptionsOnly && shown === 0 && context.state.data.users.length > 0) {
				context.table.tBodies[0].append(context.el('tr', { className: 'mcp-only-empty' }, [context.el('td', {
					colSpan: context.FIXED_COLUMNS + context.visiblePermissionColumns(),
					className: 'mcp-empty',
					textContent: t('mcp', 'No one on this page has an exception.'),
				})]))
			}
		}

		/**
		 * Why none of a user's permissions can be changed: a disabled account or "Can connect" off. The
		 * row then shows this one notice across the permission columns instead of a mark in each of them.
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

		/** Permission columns shown with the current app filter.
		 * @return {number} permission columns shown with the current app filter */
		function visiblePermissionColumns() {
			if (context.isLevelsView()) {
				return Object.keys(context.state.data?.catalog || {}).length
			}
			return Object.entries(context.state.data?.catalog || {})
				.filter(([module]) => context.state.app === '' || module === context.state.app)
				.reduce((sum, [, operations]) => sum + operations.length, 0)
		}

		/** Bind the existing All/With exceptions controls at their original startup phase.
		 * @return {void}
		 */
		function attachExceptions() {
			for (const [only, button] of Object.entries(context.onlyButtons)) {
				button?.addEventListener('click', () => {
					context.state.exceptionsOnly = only === 'exceptions'
					context.applyExceptionsFilter()
				})
			}
		}

		Object.defineProperties(context, {
			OWN_SOURCES: { configurable: true, get: () => OWN_SOURCES },
		})

		Object.assign(context, { actionsCell, applyExceptionsFilter, attachExceptions, buildRow, hasException, renderRow, rowUnavailableReason, userLine, visiblePermissionColumns })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/operation-cells.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix operation cells unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach operation checkboxes and accessible cell state presentation.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.operationCells = function (context) {
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
		 * Why a user's permission cannot be changed here, or null when it can. The first reason wins:
		 * a disabled account, then "Can connect" off, then the app (or the log role) unavailable to the user.
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
		 * Paints one state on a cell: its class, tooltip and the name read by screen readers.
		 * @param {HTMLTableCellElement} cell the cell
		 * @param {string} key key of PERMISSION_STATES
		 * @param {string} [detail] extra sentence (the reason of an unavailable cell)
		 * @return {void}
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
		 * @param {object} user row from the API
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {boolean} checked saved value
		 * @param {string} label accessible name of the permission
		 * @param {object} body request body template; the null field receives the new value
		 * @return {HTMLTableCellElement}
		 */
		function permissionCell(user, module, checked, label, body) {
			const cell = context.el('td', { className: 'mcp-cell' })
			const reason = checked === null ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : context.unavailableReason(user, module)
			if (reason !== null) {
				const saved = checked === null ? t('mcp', 'Unknown') : checked ? t('mcp', 'Saved value: allowed.') : t('mcp', 'Saved value: denied.')
				context.paintState(cell, 'unavailable', reason + ' ' + saved)
				const mark = context.el('span', { className: 'mcp-state-mark', textContent: checked === null ? '—' : '⊘' })
				mark.setAttribute('aria-hidden', 'true')
				cell.append(mark, context.el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + label + ': ' + cell.title }))
				if (checked === null) cell.append(context.el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: context.reloadLocked }))
				// A saved individual override stays visible and removable here, even though the cell cannot be edited.
				window.OCA.Mcp.Admin.overrides?.decorateCell(cell, null, user, module, module === null ? 'eligible' : body.operation)
				return cell
			}
			const box = context.el('input', { type: 'checkbox', checked })
			box.setAttribute('aria-label', user.displayName + ' — ' + label)
			context.paintState(cell, checked ? 'allowed' : 'denied')
			const origin = module === null ? user.origins?.eligible : user.origins?.grants?.[module]?.[body.operation]
			if (origin?.source === 'group') {
				const source = t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') })
				cell.title += ' — ' + source
				box.setAttribute('aria-label', user.displayName + ' — ' + label + ' — ' + source)
			}
			box.addEventListener('change', async () => {
				const value = box.checked
				if (context.matrixLocked) {
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
				if (value && module !== null && window.OCA.Mcp.Admin.mailGrants && !window.OCA.Mcp.Admin.mailGrants.confirmWrites([{ module, operation: body.operation }])) {
					box.checked = false
					return
				}
				const payload = Object.fromEntries(Object.entries(body).map(([key, v]) => [key, v === null ? value : v]))
				const hadFocus = document.activeElement === box
				// The focus comes back to this checkbox only when the admin did not move it during the save.
				const focusStayed = () => hadFocus && (document.activeElement === null || document.activeElement === document.body || document.activeElement === box)
				box.disabled = true
				try {
					const sent = context.state.data.revision
					const updated = await context.api('PUT', '/api/grants/' + encodeURIComponent(user.uid), { ...payload, expectedRevision: sent })
					// Committed from the revision on screen, so the page plus this row is exactly the new revision.
					context.state.data.revision = updated.revision
					context.announce(sent, updated.revision)
					const index = context.state.data.users.findIndex((u) => u.uid === user.uid)
					context.state.data.users[index] = updated
					const restore = focusStayed()
					const fresh = context.renderRow(updated)
					cell.parentElement.replaceWith(fresh)
					const freshCell = fresh.children[cell.cellIndex]
					context.flash(freshCell, true)
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
					context.flash(cell, false)
					if (!(await context.policyError(e))) {
						context.notifyError(t('mcp', 'Could not save the permission for {user}.', { user: user.displayName }))
					}
				}
			})
			cell.append(box)
			// The override state and the per-cell reset come from src/admin/overrides/start.js, when it is loaded.
			window.OCA.Mcp.Admin.overrides?.decorateCell(cell, box, user, module, module === null ? 'eligible' : body.operation)
			return cell
		}

		Object.defineProperties(context, {
			PERMISSION_STATES: { configurable: true, get: () => PERMISSION_STATES },
		})

		Object.assign(context, { paintState, permissionCell, unavailableReason })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/level-cells.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix level cells unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach level cells, inheritance markers and popover triggers.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.levelCells = function (context) {
		const INDIVIDUAL_SOURCES = ['individual_allow', 'individual_deny', 'invalid']

		/**
		 * Where a module's values come from, as a sentence for the tooltip and screen readers.
		 * @param {{source: string, groupIds: string[]}} origin what OCA.Mcp.Admin.levels.originOf() returned
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
		 * @param {object} user row from the API
		 * @param {string} module module id
		 * @param {string[]} operations the module's operations in the catalog
		 * @param {number} index position of the module, for the alternating band
		 * @return {HTMLTableCellElement}
		 */
		function levelCell(user, module, operations, index) {
			const levels = window.OCA.Mcp.Admin.levels
			const cell = context.el('td', { className: 'mcp-cell mcp-level-cell mcp-module mcp-band-' + (index % 2) })
			cell.dataset.module = module
			cell.dataset.levelKey = module
			const name = context.moduleLabels()[module] || module
			const level = levels.levelOf(module, operations, user.grants[module])
			const reason = context.unavailableReason(user, module)
			if (reason !== null || level === 'unknown') {
				const unknown = reason === null
				context.paintState(cell, 'unavailable', unknown ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : reason)
				const mark = context.el('span', { className: 'mcp-state-mark', textContent: unknown ? '—' : '⊘' })
				mark.setAttribute('aria-hidden', 'true')
				const word = context.el('span', { className: 'mcp-level-word', textContent: unknown ? t('mcp', 'Unknown') : t('mcp', 'Unavailable') })
				word.setAttribute('aria-hidden', 'true')
				cell.append(mark, ' ', word, context.el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + name + ': ' + cell.title }))
				if (unknown) cell.append(context.el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: context.reloadLocked }))
			} else {
				const { origin, mark: kind } = context.originMark(user, module, operations)
				const labels = context.operationLabels()
				const allowed = operations.filter((operation) => user.grants[module][operation] === true)
				const levelName = context.levelLabels()[level] || level
				const summary = allowed.length === 0
					? t('mcp', 'Allows nothing')
					: t('mcp', 'Allows: {operations}', { operations: allowed.map((operation) => labels[operation] || operation).join(', ') }, undefined, { escape: false })
				context.paintState(cell, allowed.length === 0 ? 'denied' : 'allowed')
				const source = context.markText(origin, kind)
				const individual = kind === 'exception'
				const icon = context.markIcon(origin, kind)
				const parts = []
				if (icon !== null) {
					const glyph = context.el('span', { className: 'mcp-origin-icon ' + icon })
					glyph.setAttribute('aria-hidden', 'true')
					parts.push(glyph)
				}
				parts.push(context.el('span', { className: 'mcp-level-word', textContent: levelName }))
				const button = context.levelButton(user, module, 'mcp-level mcp-level-' + level + (individual ? ' mcp-level-individual' : ''), parts,
					levelName + '. ' + summary + ' — ' + source,
					t('mcp', '{user}, {module}: {level}. {source}.', { user: user.displayName, module: name, level: levelName, source }, undefined, { escape: false }))
				cell.title = button.title
				cell.dataset.level = level
				cell.dataset.origin = origin.source
				cell.dataset.mark = kind
				cell.append(button)
			}
			// "Restricted to N" or "Scope unknown" of a resource scope, drawn and opened by src/admin/scope/start.js.
			const mark = user.scope?.[module] && window.OCA.Mcp.Admin.scope ? window.OCA.Mcp.Admin.scope.indicator(user.uid, module, user.scope[module]) : null
			if (mark) cell.append(mark)
			return cell
		}

		/**
		 * Where a cell's values come from and how to mark it. Only a real exception, an individual value that differs from what
		 * the person would inherit (src/admin/admin-exceptions.js), gets the person icon; individual values that only repeat the
		 * inheritance are drawn like what they repeat, and while that is not known yet they get no mark. Without the
		 * classification every individual value is an exception, never fewer.
		 * @param {object} user row from the API
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {string[]} operations the module's operations, or ["eligible"]
		 * @return {{origin: {source: string, groupIds: string[]}, mark: string}} mark is exception, redundant, pending, or the
		 *     source (group, unknown, default) of a cell with no individual value
		 */
		function originMark(user, module, operations) {
			const levels = window.OCA.Mcp.Admin.levels
			const origins = module === null ? { eligible: user.origins?.eligible } : user.origins?.grants?.[module]
			const origin = levels.originOf(operations, origins)
			if (origin.source !== 'individual' && origin.source !== 'invalid') {
				return { origin, mark: origin.source }
			}
			const exceptions = window.OCA.Mcp.Admin.exceptions
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

		/** Choose the person or group marker class for a classified inheritance source.
		 * @return {string|null} the icon class of a mark: a person for an exception, a group for a group, none otherwise
		 * @param {{source: string, groupIds?: string[]}} origin grant source and contributing groups
		 * @param {string} mark exception, redundant, pending or inherited source classification
		 */
		function markIcon(origin, mark) {
			if (mark === 'exception') return 'icon-user'
			if (mark === 'pending') return null
			return origin.source === 'group' || origin.source === 'unknown' ? 'icon-group' : null
		}

		/** Describe a classified inheritance source for the tooltip and screen reader.
		 * @return {string} where a cell's values come from, in words: a screen reader hears "Exception" where the eye sees the
		 *     person icon, and the inspector says the same
		 * @param {{source: string, groupIds?: string[]}} origin grant source and contributing groups
		 * @param {string} mark exception, redundant, pending or inherited source classification
		 */
		function markText(origin, mark) {
			if (mark === 'pending') return t('mcp', 'Set for this person')
			if (mark === 'exception' && origin.source === 'individual') return t('mcp', 'Exception: differs from what the person inherits')
			if (mark === 'redundant') return context.originText(origin) + ' — ' + t('mcp', 'also set for this person, with the same value')
			return context.originText(origin)
		}

		/**
		 * The control of a level cell: it opens the popover of src/admin/admin-level-popover.js, where the level is chosen.
		 * @param {object} user row from the API
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {string} className classes of the button
		 * @param {Array<Node>} parts icon and word
		 * @param {string} title tooltip
		 * @param {string} label accessible name, which says the state in words
		 * @return {HTMLButtonElement}
		 */
		function levelButton(user, module, className, parts, title, label) {
			const button = context.el('button', { type: 'button', className, title }, parts)
			button.onclick = () => window.OCA.Mcp.Admin.levelPopover?.open(button, user.uid, module)
			button.setAttribute('aria-label', label)
			button.setAttribute('aria-haspopup', 'dialog')
			button.setAttribute('aria-expanded', 'false')
			return button
		}

		Object.defineProperties(context, {
			INDIVIDUAL_SOURCES: { configurable: true, get: () => INDIVIDUAL_SOURCES },
		})

		Object.assign(context, { levelButton, levelCell, markIcon, markText, originMark, originText })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/eligibility.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix eligibility unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach connection eligibility rendering and its individual grant changes.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.eligibility = function (context) {
		/**
		 * "Can connect" in the levels view: Yes or No in words, its origin as an icon like the module cells, and the popover to
		 * change it. A disabled account has no control, as in the operations view.
		 * @param {object} user row from the API
		 * @return {HTMLTableCellElement}
		 */
		function eligibleCell(user) {
			const cell = context.el('td', { className: 'mcp-cell mcp-level-cell' })
			cell.dataset.levelKey = 'eligible'
			const canConnect = t('mcp', 'Can connect')
			const reason = user.eligible === null ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : context.unavailableReason(user, null)
			if (reason !== null) {
				const unknown = user.eligible === null
				context.paintState(cell, 'unavailable', reason)
				const mark = context.el('span', { className: 'mcp-state-mark', textContent: unknown ? '—' : '⊘' })
				mark.setAttribute('aria-hidden', 'true')
				const word = context.el('span', { className: 'mcp-level-word', textContent: unknown ? t('mcp', 'Unknown') : t('mcp', 'Unavailable') })
				word.setAttribute('aria-hidden', 'true')
				cell.append(mark, ' ', word, context.el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + canConnect + ': ' + cell.title }))
				if (unknown) cell.append(context.el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: context.reloadLocked }))
				return cell
			}
			const { origin, mark: kind } = context.originMark(user, null, ['eligible'])
			const individual = kind === 'exception'
			const icon = context.markIcon(origin, kind)
			const parts = []
			if (icon !== null) {
				const glyph = context.el('span', { className: 'mcp-origin-icon ' + icon })
				glyph.setAttribute('aria-hidden', 'true')
				parts.push(glyph)
			}
			const word = user.eligible ? t('mcp', 'Yes') : t('mcp', 'No')
			parts.push(context.el('span', { className: 'mcp-level-word', textContent: word }))
			context.paintState(cell, user.eligible ? 'allowed' : 'denied')
			const source = context.markText(origin, kind)
			const button = context.levelButton(user, null, 'mcp-level mcp-level-' + (user.eligible ? 'read' : 'none') + (individual ? ' mcp-level-individual' : ''), parts,
				canConnect + ': ' + word + ' — ' + source,
				t('mcp', '{user}, {module}: {level}. {source}.', { user: user.displayName, module: canConnect, level: word, source }, undefined, { escape: false }))
			cell.title = button.title
			cell.dataset.level = user.eligible ? 'read' : 'none'
			cell.dataset.origin = origin.source
			cell.dataset.mark = kind
			cell.append(button)
			return cell
		}

		Object.assign(context, { eligibleCell })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/notices.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix notices unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach the live bulk lock, undo notice state and notice interaction handlers.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.notices = function (context) {
		/** Seconds the undo of a bulk change stays offered; it waits while the pointer or focus is on it. */
		const UNDO_SECONDS = 10
		const notice = context.notice
		const noticeText = context.noticeText
		const undoButton = context.undoButton
		let noticeTimer = null
		let pendingUndo = null
		/** True while a bulk change or its undo saves and the matrix reloads: only one runs at a time. */
		let bulkBusy = false

		/**
		 * Locks the "All" menus and the undo while a bulk change or an undo is saving, so an older
		 * operation can never finish on top of a newer one's notice, undo or focus.
		 * @param {boolean} on whether an operation is running
		 * @return {void}
		 */
		function setBulkBusy(on) {
			bulkBusy = on
			const reason = on ? t('mcp', 'Wait until the change in progress is saved.') : ''
			for (const button of context.root.querySelectorAll('.mcp-bulk, #mcp-bulk-undo')) {
				button.disabled = on
				button.setAttribute('aria-disabled', on ? 'true' : 'false')
				button.title = reason
			}
			// The undo window only runs while its button can be used: it stops during the lock and
			// starts again, in full, once the lock is released.
			if (on) {
				clearTimeout(noticeTimer)
			} else if (notice.classList.contains('mcp-notice-on')) {
				context.armNotice()
			}
		}

		/** Clears the notice and forgets the undo it offered.
		 * @return {void}
		 */
		function hideNotice() {
			clearTimeout(noticeTimer)
			pendingUndo = null
			notice.classList.remove('mcp-notice-on')
			noticeText.textContent = ''
			undoButton.hidden = true
		}

		/** Starts the countdown, unless the admin is on the notice or a bulk change is still running.
		 * @return {void}
		 */
		function armNotice() {
			clearTimeout(noticeTimer)
			if (!bulkBusy && !notice.matches(':hover') && !notice.contains(document.activeElement)) {
				noticeTimer = setTimeout(context.hideNotice, UNDO_SECONDS * 1000)
			}
		}

		/**
		 * Shows a message under the matrix, with an Undo button when an undo is given.
		 * @param {string} message text of the notice
		 * @param {(function(): Promise<void>)|null} undo restores the previous values
		 * @return {void}
		 */
		function showNotice(message, undo) {
			// A newer notice takes the single undo slot; say so instead of dropping the older undo silently.
			const replaced = pendingUndo !== null && pendingUndo !== undo
			pendingUndo = undo
			noticeText.textContent = replaced ? message + ' ' + t('mcp', 'The previous undo is no longer available.') : message
			undoButton.hidden = undo === null
			notice.classList.add('mcp-notice-on')
			context.armNotice()
		}

		/** Bind undo, notice dismissal and pointer and focus holds on the undo expiry timer.
		 * @return {void}
		 */
		function attachNotices() {
			notice.addEventListener('mouseenter', () => clearTimeout(noticeTimer))
			notice.addEventListener('focusin', () => clearTimeout(noticeTimer))
			notice.addEventListener('mouseleave', () => notice.classList.contains('mcp-notice-on') && context.armNotice())
			notice.addEventListener('focusout', (event) => {
				if (notice.classList.contains('mcp-notice-on') && !notice.contains(event.relatedTarget)) {
					context.armNotice()
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

		}

		Object.defineProperties(context, {
			UNDO_SECONDS: { configurable: true, get: () => UNDO_SECONDS },
			notice: { configurable: true, get: () => notice },
			noticeText: { configurable: true, get: () => noticeText },
			undoButton: { configurable: true, get: () => undoButton },
			noticeTimer: { configurable: true, get: () => noticeTimer, set: (value) => { noticeTimer = value } },
			pendingUndo: { configurable: true, get: () => pendingUndo, set: (value) => { pendingUndo = value } },
			bulkBusy: { configurable: true, get: () => bulkBusy, set: (value) => { bulkBusy = value } },
		})

		Object.assign(context, { armNotice, attachNotices, hideNotice, setBulkBusy, showNotice })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/commands.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix commands unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach bulk grant commands, confirmation and receipt-based undo.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.commands = function (context) {
		/**
		 * What a bulk change would do: per operation, the users whose saved value differs from the target and that value; users
		 * with an unavailable or unknown cell are left out, users already set are counted.
		 * @param {string|null} module module id, null for eligibility
		 * @param {string[]} operationList operation ids, or ["eligible"]
		 * @param {boolean|Object<string, boolean>} granted the new value of every operation, or one per operation
		 * @param {string[]|null} [uids] the users to change, the whole page when null
		 * @return {{changes: Array<{operation: string, granted: boolean, previous: Map<string, boolean>}>, users: number, unavailable: number, unchanged: number}}
		 */
		function bulkPlan(module, operationList, granted, uids = null) {
			const target = (operation) => typeof granted === 'boolean' ? granted : granted[operation]
			const changes = operationList.filter(operation => target(operation) !== undefined)
				.map((operation) => ({ operation, granted: target(operation), previous: new Map() }))
			let users = 0
			let unavailable = 0
			let unchanged = 0
			for (const user of context.state.data.users) {
				if (uids !== null && !uids.includes(user.uid)) {
					continue
				}
				if (context.unavailableReason(user, module) !== null || changes.some(({ operation }) =>
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
			const sent = context.state.data.revision
			const saved = await context.api('POST', '/api/grants/commands', { expectedRevision: sent, commands })
			context.announce(sent, saved.revision)
			return saved
		}

		/**
		 * The selection bar's "Set" (src/admin/overrides/start.js): gives the selected users one level of a module, or turns "Can
		 * connect" on or off. It confirms exactly what changes, how many are left out and why, and how many already have it,
		 * sends nothing when nobody changes, saves one command and offers the undo. Every cell is an explicit allow or deny,
		 * never an inheritance, as the level popover does for a removal (F1).
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {string} level a level of src/admin/admin-levels.js; for "Can connect", "read" is on and "none" is off
		 * @param {string[]} uids the selected users of the page
		 * @return {Promise<void>}
		 */
		async function bulk(module, level, uids) {
			// The plan is computed from the table on screen, so never from one that is being replaced.
			if (context.bulkBusy || context.matrixLocked) {
				return
			}
			const operationList = module === null ? ['eligible'] : context.state.data.catalog[module]
			const target = module === null ? { eligible: level === 'read' } : window.OCA.Mcp.Admin.levels.targetFor(module, operationList, level)
			const plan = context.bulkPlan(module, operationList, target, uids)
			const levelName = module === null ? (level === 'read' ? t('mcp', 'Yes') : t('mcp', 'No')) : context.levelLabels()[level] || level
			const vars = { permission: module === null ? t('mcp', 'Can connect') : context.moduleLabels()[module] || module, level: levelName }
			const lines = []
			if (plan.unavailable > 0) {
				lines.push(n('mcp', '%n selected person is left out: this permission is unavailable or unknown for them.', '%n selected people are left out: this permission is unavailable or unknown for them.', plan.unavailable, vars, { escape: false }))
			}
			if (plan.unchanged > 0) {
				lines.push(n('mcp', '%n selected person already has it.', '%n selected people already have it.', plan.unchanged, vars, { escape: false }))
			}
			if (plan.users === 0) {
				context.showNotice([t('mcp', 'Nothing to change for the selected people: “{permission}” is already {level}.', vars, undefined, { escape: false }), ...lines].join(' '), null)
				return
			}
			const question = n('mcp', 'Set “{permission}” to {level} for %n selected person?', 'Set “{permission}” to {level} for %n selected people?', plan.users, vars, { escape: false })
			if (module === null && level !== 'read') {
				lines.push(t('mcp', 'They are signed out of their MCP clients, and undoing this does not sign them back in.'))
			}
			const mailWarning = module !== null && window.OCA.Mcp.Admin.mailGrants ? window.OCA.Mcp.Admin.mailGrants.writeWarning(plan.changes.filter((change) => change.granted).map((change) => ({ module, operation: change.operation }))) : null
			if (mailWarning) lines.push(mailWarning)
			if (!window.confirm([question, ...lines].join('\n'))) {
				return
			}
			const requests = plan.changes.map((change) => ({ operation: change.operation, uids: [...change.previous.keys()], granted: change.granted }))
			let undo = null
			let reloaded = false
			context.setBulkBusy(true)
			try {
				try {
					const saved = await context.sendCommand(module, requests)
					undo = saved.receiptId === null ? null : () => context.undoBulk(saved.receiptId, saved.revision)
					context.showNotice(n('mcp', '“{permission}” set to {level} for %n person.', '“{permission}” set to {level} for %n people.', plan.users, vars, { escape: false }), undo)
				} catch (e) {
					// The command is all or nothing, but an unreadable response cannot confirm its outcome.
					reloaded = await context.policyError(e)
					if (!reloaded) {
						context.notifyError(t('mcp', 'Could not update the selected people.'))
					}
				}
				if (!reloaded) {
					await context.reloadLocked()
				}
			} finally {
				context.setBulkBusy(false)
			}
			if (undo !== null && context.pendingUndo === undo) {
				// The focus goes to the undo, scrolling the notice under the table into view when it is below the fold.
				context.undoButton.focus()
			}
		}

		/**
		 * Undoes a bulk change through its receipt: the server puts back each cell's exact saved value,
		 * absence included, and refuses when anything changed since that change.
		 * @param {string} receiptId receipt of the saved command
		 * @param {string} savedRevision revision that command committed
		 * @return {Promise<void>}
		 */
		async function undoBulk(receiptId, savedRevision) {
			context.setBulkBusy(true)
			try {
				try {
					const undone = await context.api('POST', '/api/grants/undo', { receiptId, expectedRevision: savedRevision })
					context.announce(savedRevision, undone.revision)
					context.showNotice(t('mcp', 'Change undone.'), null)
				} catch (e) {
					if (e.message === '503' && !(e.data && e.data.uncertain)) {
						// Nothing was written (what the users inherit could not be read): the table stays as it is, and the same
						// undo is offered again instead of a reload that would hide that it is still possible.
						const retry = t('mcp', 'The change could not be undone because what these users inherit could not be read. Nothing was changed. Try again.')
						context.notifyError(retry)
						context.showNotice(retry, () => context.undoBulk(receiptId, savedRevision))
						return
					}
					const failure = e.message === '503' && e.data && e.data.uncertain
						? t('mcp', 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.')
						: e.message === '409'
							? t('mcp', 'The permissions changed after this change, so it can no longer be undone. The table was reloaded with the saved values.')
							: e.message === '410'
								? t('mcp', 'This change can no longer be undone: the time to undo it has passed.')
								: t('mcp', 'Could not undo the change. The table was reloaded with the saved values.')
					context.notifyError(failure)
					context.showNotice(failure, null)
				}
				await context.reloadLocked()
			} finally {
				context.setBulkBusy(false)
			}
		}

		Object.assign(context, { bulk, bulkPlan, sendCommand, undoBulk })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/interactions.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix interactions unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach focus visibility and the service, reload and filter listener installers.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.interactions = function (context) {
		let searchTimer = null

		/**
		 * Keeps the control that takes the focus clear of the sticky columns of its row (the selection box and the name at the
		 * start, the "…" menu at the end, js/admin.css), and inside the block. The browser scrolls a focused control into the
		 * matrix block, but not out from under those columns, and it leaves one that shows in part where it is, so a cell
		 * reached by Tab could be hidden (WCAG 2.4.11). Measured on screen, so it holds at any width and in either direction.
		 * @param {FocusEvent} event focusin on the scrolling block of the matrix
		 * @return {void}
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

		/** Bind service switching to policy-aware persistence and a locked matrix reload.
		 * @return {void}
		 */
		function attachService() {
			context.serviceBox.addEventListener('change', async () => {
				const value = context.serviceBox.checked
				if (context.matrixLocked || context.state.data === null) {
					context.serviceBox.checked = !value
					return
				}
				context.serviceBox.disabled = true
				try {
					const sent = context.state.data.revision
					const result = await context.api('PUT', '/api/service', { enabled: value, expectedRevision: sent })
					// Committed from the revision on screen: the switch is the only thing that changed.
					context.state.data.revision = result.revision
					context.announce(sent, result.revision)
					context.state.data.serviceEnabled = result.enabled
					context.serviceBox.checked = result.enabled
					context.serviceStatus.textContent = t('mcp', 'Saved')
					context.flash(context.serviceStatus, true)
				} catch (e) {
					context.serviceBox.checked = !value
					context.serviceStatus.textContent = t('mcp', 'Not saved')
					context.flash(context.serviceStatus, false)
					// Enabled again first, so the reloaded page can paint the saved switch.
					context.serviceBox.disabled = false
					if (!(await context.policyError(e))) {
						context.notifyError(t('mcp', 'Could not change the MCP service.'))
					}
				} finally {
					context.serviceBox.disabled = false
				}
			})

		}

		/** Bind successful group and scope writes to matrix reloads.
		 * @return {void}
		 */
		function attachReloads() {
			window.addEventListener('mcp-group-policy-saved', context.reloadLocked)
			window.addEventListener('mcp-scope-saved', context.reloadLocked)

		}

		/** Bind user search, group and eligibility filters, paging and explicit matrix reload.
		 * @return {void}
		 */
		function attachInteractions() {
			context.table.parentElement?.addEventListener('focusin', context.revealFocus)

			context.searchInput.addEventListener('input', () => {
				clearTimeout(searchTimer)
				searchTimer = setTimeout(() => {
					context.state.search = context.searchInput.value.trim()
					context.state.page = 1
					context.load()
				}, 300)
			})
			context.groupSelect.addEventListener('change', () => {
				context.state.group = context.groupSelect.value
				context.state.page = 1
				context.load()
			})
			// The matrix opens on the users who can connect; "Everyone" stays one choice away.
			context.filterSelect.value = context.state.filter
			context.filterSelect.addEventListener('change', () => {
				context.state.filter = context.filterSelect.value
				context.state.page = 1
				context.load()
			})
			for (const button of context.prevButtons) {
				button.addEventListener('click', () => {
					context.state.page = Math.max(1, context.state.page - 1)
					context.load()
				})
			}
			for (const button of context.nextButtons) {
				button.addEventListener('click', () => {
					context.state.page += 1
					context.load()
				})
			}

			for (const button of context.root.querySelectorAll('.mcp-copy')) {
				button.addEventListener('click', () => {
					const text = document.getElementById(button.dataset.copy).textContent
					navigator.clipboard.writeText(text).then(
						() => { button.textContent = t('mcp', 'Copied') },
						() => context.notifyError(t('mcp', 'Could not copy the endpoint.')),
					)
				})
			}
		}

		Object.defineProperties(context, {
			searchTimer: { configurable: true, get: () => searchTimer, set: (value) => { searchTimer = value } },
		})

		Object.assign(context, { attachInteractions, attachReloads, attachService, revealFocus })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/protection/exclusion-tags.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the protection exclusion tags unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.protection = admin.modules.protection || {}
	/** Attach exclusion tag loading, picker validation and persistence to the matrix context.
	 * @param {object} context matrix request and revision dependencies for exclusion tag settings
	 * @return {void}
	 */
	admin.modules.protection.exclusionTags = function (context) {
		/** Load exclusion tags, mount their picker and register invalid-policy repair controls.
		 * @return {Promise<void>}
		 */
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
			if (!container || !window.OCA.Mcp.Admin.picker) {
				return
			}

			/** Mark invalid exclusion configuration and expose its repair controls.
			 * @param {boolean} isInvalid shows or hides every sign of the unreadable setting
			 * @return {void}
			 */
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

			/** Show the warning when selected exclusion tags can be removed by ordinary editors.
			 * @param {string[]} selectedIds warns when a collaborative tag, removable by editors, is selected
			 * @return {void}
			 */
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
			 * @param {string[]} tagIds the ids to save
			 * @return {Promise<void>}
			 */
			async function saveTags(tagIds) {
				try {
					await context.api('PUT', '/api/admin/tags', { tagIds })
				} catch (e) {
					// 503: the system tags could not be checked and the server kept the previous setting.
					context.notifyError(e.message === '503'
						? t('mcp', 'Could not check the system tags, so the exclusion tags were not changed. Try again later.')
						: t('mcp', 'Could not update exclusion tags.'))
					throw e
				}
				setInvalidState(false)
			}

			try {
				const res = await context.api('GET', '/api/admin/tags')
				allTags = res.tags || []

				const picker = window.OCA.Mcp.Admin.picker.create({
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
				container.replaceChildren(context.el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load system tags.') }))
			}
		}

		Object.assign(context, { initTagsSection })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/checkout/upload-limit.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the checkout upload limit unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.checkout = admin.modules.checkout || {}
	/** Attach upload limit loading, display and persistence to the matrix context.
	 * @param {object} context matrix request and revision dependencies for the upload limit
	 * @return {void}
	 */
	admin.modules.checkout.uploadLimit = function (context) {
		/**
		 * Checkout upload limit of the status block, in whole MiB, through GET and PUT /apps/mcp/api/checkout-limit.
		 * Next to it: the effective limit and PHP's post_max_size ceiling.
		 * @return {Promise<void>}
		 */
		async function initCheckoutLimit() {
			const input = document.getElementById('mcp-checkout-limit')
			const save = document.getElementById('mcp-checkout-limit-save')
			const status = document.getElementById('mcp-checkout-limit-status')
			const info = document.getElementById('mcp-checkout-limit-info')
			const mib = (bytes) => Math.round(bytes / 1048576 * 10) / 10

			/** Display the configured, effective and PHP upload limits in MiB, rounding the editable value up.
			 * @param {object} data limit returned by the API, in bytes
			 * @return {void}
			 */
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
				show(await context.api('GET', '/api/checkout-limit'))
			} catch (e) {
				info.textContent = t('mcp', 'Could not load the checkout upload limit.')
				return
			}

			save.addEventListener('click', async () => {
				const value = Number(input.value)
				if (!Number.isInteger(value) || value < 1) {
					status.textContent = t('mcp', 'Enter a whole number of MiB, at least 1.')
					context.flash(status, false)
					return
				}
				save.disabled = true
				try {
					show(await context.api('PUT', '/api/checkout-limit', { mib: value }))
					status.textContent = t('mcp', 'Saved')
					context.flash(status, true)
				} catch (e) {
					status.textContent = t('mcp', 'Not saved')
					context.flash(status, false)
					context.notifyError(t('mcp', 'Could not save the checkout upload limit.'))
				} finally {
					save.disabled = false
				}
			})
		}

		Object.assign(context, { initCheckoutLimit })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/logs/privacy.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the logs privacy unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.logs = admin.modules.logs || {}
	/** Attach log privacy loading and persistence to the matrix context.
	 * @param {object} context matrix request and revision dependencies for log settings
	 * @return {void}
	 */
	admin.modules.logs.privacy = function (context) {
		/**
		 * Server log section: the switch that hides the text of log messages in the log tools, through GET and PUT
		 * /apps/mcp/api/logs-privacy.
		 * @return {Promise<void>}
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
				box.checked = (await context.api('GET', '/api/logs-privacy')).hideFileNames
			} catch (e) {
				box.disabled = true
				context.notifyError(t('mcp', 'Could not load the log privacy setting.'))
				return
			}
			box.addEventListener('change', async () => {
				const value = box.checked
				box.disabled = true
				try {
					box.checked = (await context.api('PUT', '/api/logs-privacy', { hideFileNames: value })).hideFileNames
					status.textContent = t('mcp', 'Saved')
					context.flash(status, true)
				} catch (e) {
					box.checked = !value
					status.textContent = t('mcp', 'Not saved')
					context.flash(status, false)
					context.notifyError(t('mcp', 'Could not change the log privacy setting.'))
				} finally {
					box.disabled = false
				}
			})
		}

		Object.assign(context, { initLogsPrivacy })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/logs/readers.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the logs readers unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.logs = admin.modules.logs || {}
	/** Attach log reader group loading, selection and persistence to the matrix context.
	 * @param {object} context matrix request and revision dependencies for log settings
	 * @return {void}
	 */
	admin.modules.logs.readers = function (context) {
		/**
		 * Server log section: the groups whose members may read the log besides the administrators, through
		 * GET and PUT /apps/mcp/api/logs-access. The picker locks while a save runs and each save carries
		 * the list it changed (`previous`), so the server answers 409 when another window or administrator
		 * saved first and a removed group is never put back. A listed group that was deleted shows as an
		 * option marked "(group removed)", to be removed.
		 * @return {Promise<void>}
		 */
		async function initLogsAccess() {
			const container = document.getElementById('mcp-logs-groups')
			const status = document.getElementById('mcp-logs-status')
			const summary = document.getElementById('mcp-logs-summary')
			if (!container || !window.OCA.Mcp.Admin.picker) {
				return
			}
			let version = 0
			// The block's own revision, from its own GET or save; never the matrix's, never an error body's.
			let logsRevision = null
			let picker = null
			context.followRevision(() => logsRevision, (revision) => { logsRevision = revision })

			/** Apply the loaded settings to their controls and retain the server-confirmed state.
			 * @param {object} data state returned by the API
			 * @return {void}
			 */
			function show(data) {
				version = data.version
				logsRevision = data.revision
				const options = data.allGroups.map((group) => ({ id: group.id, label: group.displayName }))
				for (const id of data.missing) {
					options.push({ id: id, label: t('mcp', '{group} (group removed)', { group: id }, undefined, { escape: false }) })
				}
				if (!picker) {
					picker = window.OCA.Mcp.Admin.picker.create({
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
							const data = await context.api('PUT', '/api/logs-access', { groups: nextIds, previous: previousIds, version: version, expectedRevision: sent })
							if (committed && context.oneStep(sent, data.revision)) {
								context.announce(sent, data.revision)
							}
							return data
						},
						onSaved: async (data) => {
							show(data)
							// The matrix shows the log column per user from the same gate, so it is reloaded.
							context.load()
						},
						onError: async (e) => {
							if (e.message === '409' || e.message === '428') {
								context.notifyError(t('mcp', 'The groups were changed in another window or by another administrator; the current list was loaded.'))
								context.load()
							} else {
								context.notifyError(t('mcp', 'Could not save the groups that may read the server log.'))
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
			 * @param {string[]} groups the saved group ids
			 * @param {Array<{id: string, label: string}>} options the known groups, for their display names
			 * @return {void}
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

			/** Loads the list again, after a conflict or a failure.
			 * @return {Promise<void>}
			 */
			async function reload() {
				try {
					show(await context.api('GET', '/api/logs-access'))
				} catch (e) {
					if (picker) {
						picker.showFallback(t('mcp', 'Could not load the groups.'))
					} else {
						container.setAttribute('aria-busy', 'false')
						container.replaceChildren(context.el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load the groups.') }))
					}
				}
			}

			await reload()
		}

		Object.assign(context, { initLogsAccess })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/oauth-clients/session.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** One OAuth editor session and its live queue, revisions and card drafts.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.oauthClients = admin.modules.oauthClients || {}
	/** Expose OAuth controls and live confirmed settings, revision, queue and epoch bindings.
	 * @param {object} context OAuth controls, confirmed settings and live queue and revision bindings
	 * @return {void}
	 */
	admin.modules.oauthClients.session = function (context) {
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

		Object.defineProperties(context, {
			hostsInput: { configurable: true, get: () => hostsInput },
			hostsSave: { configurable: true, get: () => hostsSave },
			hostsStatus: { configurable: true, get: () => hostsStatus },
			nativeBox: { configurable: true, get: () => nativeBox },
			nativeStatus: { configurable: true, get: () => nativeStatus },
			details: { configurable: true, get: () => details },
			clientId: { configurable: true, get: () => clientId },
			redirects: { configurable: true, get: () => redirects },
			customList: { configurable: true, get: () => customList },
			customAdd: { configurable: true, get: () => customAdd },
			customClients: { configurable: true, get: () => customClients, set: (value) => { customClients = value } },
			cardCount: { configurable: true, get: () => cardCount, set: (value) => { cardCount = value } },
			oauthRevision: { configurable: true, get: () => oauthRevision, set: (value) => { oauthRevision = value } },
			queue: { configurable: true, get: () => queue, set: (value) => { queue = value } },
			epoch: { configurable: true, get: () => epoch, set: (value) => { epoch = value } },
			drafts: { configurable: true, get: () => drafts },
			confirmed: { configurable: true, get: () => confirmed, set: (value) => { confirmed = value } },
		})
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/oauth-clients/saving.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the oauth clients saving unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.oauthClients = admin.modules.oauthClients || {}
	/** Attach serialized OAuth writes, change detection and custom client persistence.
	 * @param {object} context OAuth controls, confirmed settings and live queue and revision bindings
	 * @return {void}
	 */
	admin.modules.oauthClients.saving = function (context) {
		/**
		 * Runs one task of the block after the previous one has finished.
		 * @template T
		 * @param {function(): (T|PromiseLike<T>)} task work to run after the previous task settles
		 * @return {Promise<T>} the result of this queued task
		 */
		function enqueue(task) {
			const run = context.queue.then(task)
			context.queue = run.catch(() => {})
			return run
		}

		/**
		 * Sends one change when its turn comes: the body is built then by `build`, from the clients the server confirmed
		 * last, with the revision of that same answer, which is then replaced by the revision and clients the server
		 * answers with. The policy revision moved, so the matrix is reloaded to keep its own saves valid. An intent
		 * queued before a reload is dropped (error "stale"); a refused save drops the intents queued behind it.
		 * @param {function(object[]): object} build derive changed settings from the confirmed clients
		 * @return {Promise<object>} the settings the server saved
		 */
		function put(build) {
			const queuedAt = context.epoch
			return context.enqueue(async () => {
				if (queuedAt !== context.epoch) {
					throw new Error('stale')
				}
				try {
					const sent = context.oauthRevision
					const change = build(context.customClients)
					const committed = context.changes(change, context.confirmed)
					const data = await context.api('PUT', '/api/oauth-clients', { ...change, expectedRevision: sent })
					context.oauthRevision = data.revision
					context.customClients = data.customClients
					context.confirmed = data
					if (committed && context.oneStep(sent, data.revision)) {
						context.announce(sent, data.revision)
					}
					context.load()
					return data
				} catch (e) {
					if (e.message === '409' || e.message === '428' || (e.message === '503' && e.data && e.data.uncertain)) {
						context.epoch++
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
		 * @param {object} change the settings sent (hosts, nativeClientEnabled or customClients)
		 * @param {object} stored the server's last answer
		 * @return {boolean}
		 */
		function changes(change, stored) {
			const control = (text) => /[\x00-\x1f\x7f]/.test(text)
			const uris = (list) => [...new Set(list.map(context.phpTrim))]
			if (change.nativeClientEnabled !== undefined && change.nativeClientEnabled !== stored.nativeClientEnabled) {
				return true
			}
			if (change.hosts !== undefined) {
				// strtolower() of PHP 8 changes A-Z only.
				const hosts = [...new Set(change.hosts.map((host) => context.phpTrim(host).replace(/[A-Z]/g, (letter) => letter.toLowerCase())))]
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
				if (change.customClients.some((client) => control(context.phpTrim(client.name)) || uris(client.redirectUris).some(control))) {
					return false
				}
				const key = (clients) => JSON.stringify(clients.map((client) => [client.id ?? null, context.phpTrim(client.name), client.enabled, uris(client.redirectUris)]))
				if (key(change.customClients) !== key(stored.customClients)) {
					return true
				}
			}
			return false
		}

		/**
		 * Saves one change to the list of self-hosted clients.
		 * @param {function(object[]): object[]} change every client to keep, a new one without id; applied to the clients the
		 *     server confirmed last, when the save's turn comes
		 * @return {Promise<object|undefined>} the client the server added, if any
		 */
		async function saveCustom(change) {
			let before = new Set()
			await context.put((clients) => {
				before = new Set(clients.map((client) => client.id))
				return { customClients: change(clients).map(({ id, name, enabled, redirectUris }) => ({ id, name, enabled, redirectUris })) }
			})
			return context.customClients.find((client) => !before.has(client.id))
		}

		Object.assign(context, { changes, enqueue, put, saveCustom })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/oauth-clients/recovery.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the oauth clients recovery unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.oauthClients = admin.modules.oauthClients || {}
	/** Attach conflict recovery that refreshes OAuth state without replaying queued writes.
	 * @param {object} context OAuth controls, confirmed settings and live queue and revision bindings
	 * @return {void}
	 */
	admin.modules.oauthClients.recovery = function (context) {
		/**
		 * When a save failed because the page was out of date (409, or 428 without a revision) or the server could not
		 * confirm it (503 uncertain), shows what is stored now, in the queue's order, and tells the admin nothing was
		 * overwritten. The cards with unsaved typed changes are kept (see keepUnsaved()). An intent dropped behind such a
		 * save is handled by that reload.
		 * @param {Error} e error thrown by put()
		 * @return {Promise<boolean>} whether the error was handled here (the settings are then reloaded)
		 */
		async function recover(e) {
			if (e.message === 'stale') {
				return true
			}
			if (e.message === '409' || e.message === '428') {
				context.notifyError(t('mcp', 'The OAuth client settings were changed in another window or by another administrator. The current settings were loaded; nothing was overwritten.'))
			} else if (e.message === '503' && e.data && e.data.uncertain) {
				context.notifyError(t('mcp', 'The server could not confirm whether the change was saved. The current settings were loaded.'))
			} else {
				return false
			}
			try {
				const unsaved = [...context.customList.children].filter((card) => context.drafts.get(card)?.edited())
				context.show(await context.enqueue(() => context.api('GET', '/api/oauth-clients')))
				context.customList.replaceChildren(...context.keepUnsaved(unsaved))
			} catch (reload) {
				context.notifyError(t('mcp', 'Could not load the OAuth client settings.'))
			}
			return true
		}

		/**
		 * The cards of the clients the server has now, with the cards whose typed changes it does not have put back: a
		 * saved client keeps its edited card in its place, a new card comes after the saved ones, and the edits of a
		 * client deleted elsewhere come back as a new card. Each card put back says it was not saved.
		 * @param {HTMLElement[]} unsaved cards with typed changes, read before the reload
		 * @return {HTMLElement[]} the cards to show
		 */
		function keepUnsaved(unsaved) {
			const kept = new Map(unsaved.filter((card) => context.drafts.get(card).client.id !== null).map((card) => [context.drafts.get(card).client.id, card]))
			const cards = context.customClients.map((client) => kept.get(client.id) || context.customCard(client))
			const extra = unsaved.filter((card) => !cards.includes(card)).map((card) => {
				if (context.drafts.get(card).client.id === null) {
					return card
				}
				const fresh = context.customCard({ ...context.drafts.get(card).read(), id: null })
				context.report(fresh.querySelector('.mcp-status'), false, t('mcp', 'Not saved: this client was deleted in the meantime. Your changes are kept here as a new client; saving adds it again.'))
				return fresh
			})
			for (const card of unsaved.filter((card) => cards.includes(card) || extra.includes(card))) {
				context.report(card.querySelector('.mcp-status'), false, t('mcp', 'Not saved: the settings changed after this page was loaded. Your changes are kept here; check them and save again.'))
			}
			return [...cards, ...extra]
		}

		Object.assign(context, { keepUnsaved, recover })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/oauth-clients/cards.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the oauth clients cards unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.oauthClients = admin.modules.oauthClients || {}
	/** Attach custom client card construction and its edit and deletion controls.
	 * @param {object} context OAuth controls, confirmed settings and live queue and revision bindings
	 * @return {void}
	 */
	admin.modules.oauthClients.cards = function (context) {
		/**
		 * One self-hosted client: name, Enabled, client_id with Copy (once saved), redirect URIs, Save and Delete.
		 * Saving or deleting replaces only this card, so unsaved edits in the other cards stay.
		 * @param {object} client a stored client, or {id: null, ...} for a new one
		 * @return {HTMLElement} the card
		 */
		function customCard(client) {
			const n = ++context.cardCount
			const saved = client.id !== null
			const name = context.el('input', { type: 'text', id: 'mcp-custom-name-' + n, value: client.name, maxLength: 64, autocomplete: 'off', spellcheck: false })
			const enabled = context.el('input', { type: 'checkbox', className: 'checkbox', id: 'mcp-custom-enabled-' + n, checked: client.enabled })
			const uris = context.el('textarea', {
				id: 'mcp-custom-uris-' + n, className: 'mcp-custom-redirects', rows: 3, spellcheck: false, autocomplete: 'off',
				placeholder: 'https://harness.example.com/oauth/callback', value: client.redirectUris.join('\n'),
			})
			const status = context.el('span', { className: 'mcp-status' })
			status.setAttribute('aria-live', 'polite')
			const save = context.el('button', { type: 'button', textContent: t('mcp', 'Save') })
			const remove = context.el('button', { type: 'button', textContent: t('mcp', 'Delete') })
			const rows = [
				context.el('p', {}, [context.el('label', { htmlFor: name.id, textContent: t('mcp', 'Name') }), ' ', name]),
				context.el('p', {}, [enabled, context.el('label', { htmlFor: enabled.id, textContent: t('mcp', 'Enabled') })]),
			]
			if (saved) {
				const code = context.el('code', { textContent: client.clientId })
				const copy = context.el('button', { type: 'button', className: 'mcp-copy', textContent: t('mcp', 'Copy') })
				copy.addEventListener('click', () => {
					navigator.clipboard.writeText(client.clientId).then(
						() => { copy.textContent = t('mcp', 'Copied') },
						() => context.notifyError(t('mcp', 'Could not copy the client_id.')),
					)
				})
				rows.push(context.el('p', {}, ['client_id: ', code, ' ', copy]))
			}
			rows.push(
				context.el('p', {}, [context.el('label', { htmlFor: uris.id, textContent: t('mcp', 'Accepted redirect URIs') })]),
				uris,
				context.el('p', { className: 'settings-hint mcp-hint', textContent: t('mcp', 'One URI per line, exactly as the client sends it, port included: https (http only for 127.0.0.1, localhost or [::1]), no wildcard or fragment, up to 2000 bytes.') }),
				context.el('p', {}, [save, ' ', remove, ' ', status]),
			)
			const card = context.el('div', { className: 'mcp-custom-client' }, rows)

			/** The client as edited in the card.
			 * @return {object} the client as edited in the card */
			function read() {
				return {
					id: client.id,
					name: name.value.trim(),
					enabled: enabled.checked,
					redirectUris: uris.value.split('\n').map((uri) => uri.trim()).filter((uri) => uri !== ''),
				}
			}

			/** Whether the card holds what the server does not have: any new card, or a changed name or uri list.
			 * @return {boolean} whether the card holds what the server does not have: any new card, or a changed name or URI list */
			function edited() {
				const entry = read()
				return !saved || entry.name !== client.name || entry.redirectUris.join('\n') !== client.redirectUris.join('\n')
			}
			context.drafts.set(card, { client, read, edited })

			save.addEventListener('click', async () => {
				const entry = read()
				save.disabled = true
				try {
					const added = await context.saveCustom((clients) => saved
						? clients.map((other) => other.id === client.id ? entry : other)
						: [...clients, entry])
					const next = context.customCard(saved ? context.customClients.find((other) => other.id === client.id) : added)
					card.replaceWith(next)
					next.querySelector('.mcp-status').textContent = t('mcp', 'Saved')
					context.flash(next.querySelector('.mcp-status'), true)
				} catch (e) {
					if (!(await context.recover(e))) {
						context.report(status, false, t('mcp', 'Not saved: each client needs a name of up to 64 characters that no other client has, and 1 to 20 redirect URIs (absolute https URIs, without user name, wildcard or fragment).'))
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
					await context.saveCustom((clients) => clients.map((other) => other.id === client.id ? { ...other, enabled: value } : other))
					context.report(status, true)
				} catch (e) {
					enabled.checked = !value
					if (!(await context.recover(e))) {
						context.report(status, false)
						context.notifyError(t('mcp', 'Could not change the self-hosted client.'))
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
					await context.saveCustom((clients) => clients.filter((other) => other.id !== client.id))
					card.remove()
				} catch (e) {
					if (!(await context.recover(e))) {
						context.report(status, false)
						context.notifyError(t('mcp', 'Could not delete the self-hosted client.'))
					}
				} finally {
					remove.disabled = false
				}
			})
			return card
		}

		Object.assign(context, { customCard })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/oauth-clients/editor.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose and start the OAuth clients editor at its original matrix startup phase.
 * @param {object} admin shared OCA.Mcp.Admin registry and public surfaces
 * @return {void}
 */
(function (admin) {
	'use strict'
	admin.modules.oauthClients = admin.modules.oauthClients || {}
	/** Attach OAuth startup to the matrix without loading settings until startup calls it.
	 * @param {object} matrix matrix request, revision and presentation dependencies
	 * @return {void}
	 */
	admin.modules.oauthClients.editor = function (matrix) {
		/** Initial settings load and original listener registration.
		 * @return {Promise<void>} initial settings load and original listener registration */
		async function initOauthClients() {
			const context = { api: matrix.api, flash: matrix.flash, el: matrix.el,
				notifyError: matrix.notifyError, oneStep: matrix.oneStep, phpTrim: matrix.phpTrim,
				announce: matrix.announce, load: matrix.load }
			admin.sessions.oauthClients = context
			admin.modules.oauthClients.session(context)
			admin.modules.oauthClients.saving(context)
			admin.modules.oauthClients.recovery(context)
			admin.modules.oauthClients.cards(context)
			matrix.followRevision(() => context.oauthRevision, (revision) => { context.oauthRevision = revision })

			/** Retain confirmed OAuth settings and update hosts, native client details and custom clients.
			 * @param {object} data settings returned by the API
			 * @return {void}
			 */
			function show(data) {
				context.oauthRevision = data.revision
				context.confirmed = data
				context.hostsInput.value = data.hosts.join(', ')
				context.nativeBox.checked = data.nativeClientEnabled
				context.details.hidden = !data.nativeClientEnabled
				context.clientId.textContent = data.nativeClientId
				context.redirects.replaceChildren(...data.nativeRedirectUris.map((uri) => context.el('li', {}, [context.el('code', { textContent: uri })])))
				context.customClients = data.customClients
			}

			/** Write and animate the saved or failed outcome beside an OAuth control.
			 * @param {HTMLElement} status status span next to the control
			 * @param {boolean} ok whether the save worked
			 * @param {string} [message] text shown on failure
			 * @return {void}
			 */
			function report(status, ok, message) {
				status.textContent = ok ? t('mcp', 'Saved') : (message || t('mcp', 'Not saved'))
				context.flash(status, ok)
			}

			context.show = show
			context.report = report

			try {
				show(await context.api('GET', '/api/oauth-clients'))
			} catch (e) {
				context.notifyError(t('mcp', 'Could not load the OAuth client settings.'))
				return
			}
			context.customList.replaceChildren(...context.customClients.map(context.customCard))
			context.customAdd.addEventListener('click', () => {
				const card = context.customCard({ id: null, name: '', enabled: true, redirectUris: [] })
				context.customList.append(card)
				card.querySelector('input[type="text"]').focus()
			})

			context.hostsSave.addEventListener('click', async () => {
				const hosts = context.hostsInput.value.split(',').map((host) => host.trim().toLowerCase()).filter((host) => host !== '')
				context.hostsSave.disabled = true
				try {
					show(await context.put(() => ({ hosts })))
					report(context.hostsStatus, true)
				} catch (e) {
					if (!(await context.recover(e))) {
						report(context.hostsStatus, false, t('mcp', 'Invalid host list: use lowercase host names only, without https://, port, path or *, and keep at least one.'))
					}
				} finally {
					context.hostsSave.disabled = false
				}
			})

			context.nativeBox.addEventListener('change', async () => {
				const value = context.nativeBox.checked
				context.nativeBox.disabled = true
				try {
					show(await context.put(() => ({ nativeClientEnabled: value })))
					report(context.nativeStatus, true)
				} catch (e) {
					context.nativeBox.checked = !value
					if (!(await context.recover(e))) {
						report(context.nativeStatus, false)
						context.notifyError(t('mcp', 'Could not change the native client.'))
					}
				} finally {
					context.nativeBox.disabled = false
				}
			})
		}
		matrix.initOauthClients = initOauthClients
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/matrix/start.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the matrix and start its original effects in their original order.
 * @param {object} admin shared OCA.Mcp.Admin registry and public surfaces
 * @return {void}
 */
(function (admin) {
	'use strict'
	const root = document.getElementById('mcp-admin')
	if (!root) return
	const context = { root }
	admin.sessions.matrix = context
	admin.modules.matrix.session(context)
	admin.modules.matrix.labels(context)
	admin.modules.matrix.requests(context)
	admin.modules.matrix.revisions(context)
	admin.modules.matrix.elements(context)
	admin.modules.matrix.loading(context)
	admin.modules.matrix.view(context)
	admin.modules.matrix.rendering(context)
	admin.modules.matrix.rows(context)
	admin.modules.matrix.operationCells(context)
	admin.modules.matrix.levelCells(context)
	admin.modules.matrix.eligibility(context)
	admin.modules.matrix.notices(context)
	admin.modules.matrix.commands(context)
	admin.modules.matrix.interactions(context)
	admin.modules.protection.exclusionTags(context)
	admin.modules.checkout.uploadLimit(context)
	admin.modules.logs.privacy(context)
	admin.modules.logs.readers(context)
	admin.modules.oauthClients.editor(context)
	context.attachView()
	context.attachExceptions()
	context.attachNotices()
	context.attachService()
	context.attachReloads()
	context.attachInteractions()
	/**
	 * What src/admin/overrides/start.js needs from the matrix: the page on screen, the shared request, lock and undo notice,
	 * and a re-render, so it can paint rows that were drawn before it loaded. It never touches the matrix state itself.
	 */
	window.OCA.Mcp.Admin.matrix = {
		data: () => context.state.data,
		api: context.api,
		announce: context.announce,
		reload: context.reloadLocked,
		policyError: context.policyError,
		notifyError: context.notifyError,
		isBusy: () => context.bulkBusy || context.matrixLocked,
		setBusy: context.setBulkBusy,
		labels: () => ({ modules: context.moduleLabels(), operations: context.operationLabels() }),
		/** Show a command result and offer receipt-based undo when the save changed the policy.
		 * @param {string} message text of the notice
		 * @param {{receiptId: string|null, revision: string}} saved the saved command
		 * @return {(function(): Promise<void>)|null} the undo that was offered, to give back to focusUndo()
		 */
		offerUndo(message, saved) {
			const undo = saved.receiptId === null ? null : () => context.undoBulk(saved.receiptId, saved.revision)
			context.showNotice(message, undo)
			return undo
		},
		/** Focus the undo control only while it still represents the supplied offer.
		 * @param {(function(): Promise<void>)|null} undo callback returned by offerUndo
		 * @return {void}
		 */
		focusUndo(undo) {
			if (undo !== null && context.pendingUndo === undo) {
				context.undoButton.focus()
			}
		},
		refresh: () => context.state.data !== null && context.render(),
		bulk: context.bulk,
		levelLabels: context.levelLabels,
	}
	context.load()
	context.initTagsSection()
	context.initOauthClients()
	context.initCheckoutLimit()
	context.initLogsAccess()
	context.initLogsPrivacy()
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/level-popover/session.js */
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
;
/* admin/level-popover/planning.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the level popover planning unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.levelPopover = admin.modules.levelPopover || {}
	/** Attach level, inheritance and individual operation command planning.
	 * @param {object} context matrix and level catalogs, popover element and live active cell bindings
	 * @return {void}
	 */
	admin.modules.levelPopover.planning = function (context) {
		/**
		 * What each operation would be without the person's individual values: the preview's "after" for an individual cell,
		 * the value on screen for any other. null when the preview has not answered (or could not).
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
			for (const operation of context.operationsOf(module)) {
				const origin = context.originOfCell(user, module, operation)
				if (!context.INDIVIDUAL.includes(origin.source)) {
					out[operation] = { allowed: context.valueOf(user, module, operation), source: origin.source, groupIds: origin.groupIds || [] }
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
		 * @param {object} user row of the table
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {Object<string, boolean>} target value wanted per operation
		 * @param {object|null} inherited what inheritedValues() returned
		 * @return {Array<{uid: string, module: string|null, operation: string, state: string}>}
		 */
		function planTarget(user, module, target, inherited) {
			const commands = []
			for (const operation of context.operationsOf(module)) {
				const want = target[operation]
				if (want === undefined || context.valueOf(user, module, operation) === want) {
					continue
				}
				if (!want) {
					commands.push({ uid: user.uid, module, operation, state: 'deny' })
					continue
				}
				const back = inherited?.[operation]?.allowed === true && context.INDIVIDUAL.includes(context.originOfCell(user, module, operation).source)
				commands.push({ uid: user.uid, module, operation, state: back ? 'inherit' : 'allow' })
			}
			return commands
		}

		/** Plan only the individual changes needed to set the requested level against inheritance.
		 * @return {Array<object>} the commands that give a module one level
		 * @param {object} user matrix user row with grants, origins and availability
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {string} level permission level ID
		 * @param {object|null} inherited inherited values read for the active cell, null while unknown
		 */
		function planLevel(user, module, level, inherited) {
			const operations = context.operationsOf(module)
			return context.planTarget(user, module, context.levels.targetFor(module, operations, level), inherited)
		}

		/** Plan removal of the cell's individual values so its inheritance applies.
		 * @return {Array<object>} the commands that give every individual value of a module back to inheritance
		 * @param {object} user matrix user row with grants, origins and availability
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 */
		function planInherit(user, module) {
			return context.operationsOf(module)
				.filter((operation) => context.INDIVIDUAL.includes(context.originOfCell(user, module, operation).source))
				.map((operation) => ({ uid: user.uid, module, operation, state: 'inherit' }))
		}

		/** Plan the change to one individual operation value or its inheritance.
		 * @return {Array<object>} the command that sets one operation to inherit, allow or deny, or none when it already is
		 * @param {object} user matrix user row with grants, origins and availability
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {string} operation catalog operation ID
		 * @param {"allow"|"deny"|"inherit"} state desired individual operation setting
		 */
		function planOperation(user, module, operation, state) {
			const source = context.originOfCell(user, module, operation).source
			const now = source === 'individual_allow' ? 'allow' : source === 'individual_deny' || source === 'invalid' ? 'deny' : 'inherit'
			return now === state ? [] : [{ uid: user.uid, module, operation, state }]
		}

		/**
		 * Whether the commands take "Can connect" away from someone who has it now; an inherit whose result is unknown counts.
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

		Object.assign(context, { inheritedValues, planTarget, planLevel, planInherit, planOperation, losesEligibility })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/level-popover/wording.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the level popover wording unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.levelPopover = admin.modules.levelPopover || {}
	/** Attach permission names, level summaries and inheritance source descriptions.
	 * @param {object} context matrix and level catalogs, popover element and live active cell bindings
	 * @return {void}
	 */
	admin.modules.levelPopover.wording = function (context) {
		/** Name the module permission or the connection eligibility permission.
		 * @return {string} module name, or "Can connect"
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 */
		function permissionName(module) {
			return module === null ? t('mcp', 'Can connect') : context.matrix.labels().modules[module] || module
		}

		/** Translate a level name, using Yes and No for connection eligibility.
		 * @return {string} name of a level of src/admin/admin-levels.js, or Yes / No for "Can connect"
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {string} level permission level ID
		 */
		function levelName(module, level) {
			if (module === null) {
				return level === 'read' ? t('mcp', 'Yes') : t('mcp', 'No')
			}
			return { ...window.OCA.Mcp.Admin.pollsGrants?.levelLabels(), forms_write: t('mcp', 'Read and write forms'), forms_write_results: t('mcp', 'Read and write forms and read responses'), none: t('mcp', 'None'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom'), unknown: t('mcp', 'Unknown') }[level] || level
		}

		/** Calculate a level from the module's catalog and effective operation values.
		 * @return {string} the level these values are, for any module, "Can connect" included
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {Object<string, boolean|null>} values effective operation values indexed by operation ID
		 */
		function levelOfValues(module, values) {
			if (module === null) {
				return values.eligible === null || values.eligible === undefined ? 'unknown' : values.eligible ? 'read' : 'none'
			}
			return context.levels.levelOf(module, context.operationsOf(module), values)
		}

		/** Describe the inherited grant source and any contributing groups.
		 * @return {string} where an inherited value comes from
		 * @param {{source: string, groupIds?: string[]}} origin grant source and contributing groups
		 */
		function sourceName(origin) {
			if (origin.source === 'group') return t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') }, undefined, { escape: false })
			if (origin.source === 'unknown') return t('mcp', 'Unknown')
			return t('mcp', 'Default')
		}

		/** The "inherited" line of the popover.
		 * @return {string} the "Inherited" line of the popover */
		function inheritedLine() {
			if (context.current.loading) return t('mcp', 'Checking what this person inherits…')
			if (context.current.unavailable || context.current.inherited === null) return t('mcp', 'What this person inherits could not be read.')
			const values = Object.fromEntries(Object.entries(context.current.inherited).map(([operation, value]) => [operation, value.allowed]))
			const level = context.levelOfValues(context.current.module, values)
			const sources = Object.values(context.current.inherited)
			const group = sources.find((value) => value.source === 'group')
			const origin = sources.some((value) => value.source === 'unknown') ? { source: 'unknown', groupIds: [] } : group || { source: 'default', groupIds: [] }
			return t('mcp', 'Inherits: {level} ({source})', { level: context.levelName(context.current.module, level), source: context.sourceName(origin) }, undefined, { escape: false })
		}

		/** Describe the operations included in a selectable permission level.
		 * @return {string} description of a level: the operations it allows
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {string} level permission level ID
		 */
		function levelDescription(module, level) {
			if (module === null) {
				return level === 'read' ? t('mcp', 'The person may connect an MCP client.') : t('mcp', 'The person may not connect, and is signed out.')
			}
			const names = context.matrix.labels().operations
			const allowed = context.levels.operationsFor(module, context.operationsOf(module), level)
			return allowed.length === 0 ? t('mcp', 'The tools of this app stay hidden.') : allowed.map((operation) => names[operation] || operation).join(', ')
		}

		Object.assign(context, { permissionName, levelName, levelOfValues, sourceName, inheritedLine, levelDescription })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/level-popover/rendering.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the level popover rendering unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.levelPopover = admin.modules.levelPopover || {}
	/** Attach popover drawing, operation controls and focus-preserving redraws.
	 * @param {object} context matrix and level catalogs, popover element and live active cell bindings
	 * @return {void}
	 */
	admin.modules.levelPopover.rendering = function (context) {
		/** A pressed button carries a check mark besides aria-pressed, so the choice never depends on colour.
		 * @param {string} text visible choice label
		 * @param {boolean} pressed whether this choice is currently selected
		 * @param {function(MouseEvent): Promise<void>} onclick handler invoked when the control is clicked
		 * @return {HTMLButtonElement}
		 */
		function stateButton(text, pressed, onclick) {
			const children = []
			if (pressed) {
				const mark = context.el('span', { className: 'mcp-pressed-mark', textContent: '✓ ' })
				mark.setAttribute('aria-hidden', 'true')
				children.push(mark)
			}
			children.push(text)
			const button = context.el('button', { type: 'button', className: 'mcp-tri', disabled: context.current.saving, onclick }, children)
			button.setAttribute('aria-pressed', String(pressed))
			return button
		}

		/** Find a descendant carrying the logical focus key saved before redraw.
		 * @return {HTMLElement|null} the control of the popover drawn with this focus key
		 * @param {HTMLElement} node root whose descendants are searched
		 * @param {string} key logical focus key to find
		 */
		function findFocusKey(node, key) {
			for (const child of node.children || []) {
				if (typeof child === 'string') continue
				if (child.dataset?.focusKey === key) return child
				const found = context.findFocusKey(child, key)
				if (found) return found
			}
			return null
		}

		/** Draws the popover again, keeping the focus on the same control.
		 * @return {void}
		 */
		function render() {
			// Drawing again replaces every control: the one that had the focus gets it back, so the answer of the preview
			// arriving while the administrator moves through the popover never drops the focus on the page.
			const active = document.activeElement
			const focusKey = active && context.popover.contains(active) ? active.dataset?.focusKey ?? null : null
			context.draw()
			const target = focusKey === null ? null : context.findFocusKey(context.popover, focusKey)
			if (target && !target.disabled) {
				target.focus()
			} else if (focusKey !== null) {
				(context.popover.querySelector('input:checked') || context.popover.querySelector('input'))?.focus()
			}
		}

		/** Draws the popover for the user and module open, from the table on screen.
		 * @return {void}
		 */
		function draw() {
			const user = context.userOf(context.current.uid)
			if (!user) {
				context.close(false)
				return
			}
			const module = context.current.module
			const operations = context.operationsOf(module)
			const values = Object.fromEntries(operations.map((operation) => [operation, context.valueOf(user, module, operation)]))
			const level = context.levelOfValues(module, values)
			const choices = module === null ? ['read', 'none'] : context.levels.levels(module, operations)
			const title = context.el('h3', { id: 'mcp-level-popover-title', textContent: module === null
				? t('mcp', '“Can connect” for {user}', { user: user.displayName }, undefined, { escape: false })
				: t('mcp', '{module} of {user}', { module: context.permissionName(module), user: user.displayName }, undefined, { escape: false }) })
			const closer = context.el('button', { type: 'button', className: 'mcp-popover-close', textContent: '✕', onclick: () => context.close(true) })
			closer.dataset.focusKey = 'close'
			closer.setAttribute('aria-label', t('mcp', 'Close'))
			const inherit = context.el('p', { className: 'mcp-popover-inherit', textContent: context.inheritedLine() })
			inherit.setAttribute('aria-live', 'polite')

			const group = context.el('div', { className: 'mcp-popover-levels' })
			group.setAttribute('role', 'radiogroup')
			group.setAttribute('aria-labelledby', 'mcp-level-popover-title')
			for (const choice of choices) {
				const id = 'mcp-level-choice-' + choice
				const radio = context.el('input', { type: 'radio', name: 'mcp-level-choice', id, value: choice, checked: choice === level, disabled: context.current.saving })
				radio.dataset.focusKey = 'level:' + choice
				radio.addEventListener('change', () => context.save(context.planLevel(user, module, choice, context.current.inherited),
					t('mcp', '{permission} of {user}: {level}.', { permission: context.permissionName(module), user: user.displayName, level: context.levelName(module, choice) }, undefined, { escape: false })))
				group.append(context.el('label', { htmlFor: id, className: 'mcp-popover-choice' }, [radio, context.el('span', {}, [
					context.el('strong', { textContent: context.levelName(module, choice) }),
					context.el('span', { className: 'mcp-popover-hint', textContent: context.levelDescription(module, choice) }),
				])]))
			}
			const body = [title, closer, inherit, group]
			if (level === 'custom') {
				body.push(context.el('p', { className: 'mcp-popover-hint', textContent: t('mcp', 'Custom: the allowed operations are no level. See them under Operations.') }))
			}
			const individual = context.planInherit(user, module)
			const inheritAll = context.el('button', {
				type: 'button',
				className: 'mcp-popover-inherit-all',
				disabled: context.current.saving || individual.length === 0,
				textContent: t('mcp', 'Inherit from groups and defaults'),
				onclick: () => context.save(individual, t('mcp', '{permission} of {user} inherits again.', { permission: context.permissionName(module), user: user.displayName }, undefined, { escape: false })),
			})
			inheritAll.dataset.focusKey = 'inherit'
			body.push(inheritAll)
			if (module !== null) {
				body.push(context.operationsBlock(user, module, operations))
			}
			body.push(context.el('p', { className: 'mcp-popover-hint', textContent: t('mcp', 'Saved when chosen. You can undo for 10 seconds.') }))
			context.popover.replaceChildren(...body)
		}

		/** The advanced block: every operation of the module with Inherit, Allow and Deny.
		 * @param {object} user matrix user row with grants, origins and availability
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {string[]} operations module operations exposed as individual controls
		 * @return {HTMLElement}
		 */
		function operationsBlock(user, module, operations) {
			const names = context.matrix.labels().operations
			const rows = operations.map((operation) => {
				const source = context.originOfCell(user, module, operation).source
				const name = names[operation] || operation
				const make = (state, text, pressed) => {
					const button = context.stateButton(text, pressed, () => context.save(context.planOperation(user, module, operation, state),
						t('mcp', '{permission} of {user}: {operation} set to {state}.', { permission: context.permissionName(module), user: user.displayName, operation: name, state: text }, undefined, { escape: false })))
					button.dataset.focusKey = 'operation:' + operation + ':' + state
					return button
				}
				const inherited = context.current.inherited?.[operation]
				const hint = inherited === undefined || inherited.allowed === null
					? ''
					: inherited.allowed ? t('mcp', 'inherits allowed') : t('mcp', 'inherits denied')
				const group = context.el('span', { className: 'mcp-tri-group' }, [
					make('inherit', t('mcp', 'Inherit'), !context.INDIVIDUAL.includes(source)),
					make('allow', t('mcp', 'Allow'), source === 'individual_allow'),
					make('deny', t('mcp', 'Deny'), source === 'individual_deny' || source === 'invalid'),
				])
				group.setAttribute('role', 'group')
				group.setAttribute('aria-label', name)
				return context.el('div', { className: 'mcp-popover-operation' }, [context.el('span', { className: 'mcp-popover-operation-name', textContent: name }),
					context.el('span', { className: 'mcp-popover-hint', textContent: hint }), group])
			})
			const summary = context.el('summary', { textContent: t('mcp', 'Operations') })
			summary.dataset.focusKey = 'operations'
			const details = context.el('details', { className: 'mcp-popover-operations', open: context.current.operationsOpen === true }, [summary, ...rows])
			details.addEventListener('toggle', () => {
				if (context.current) context.current.operationsOpen = details.open
			})
			return details
		}

		Object.assign(context, { stateButton, findFocusKey, render, draw, operationsBlock })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/level-popover/saving.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the level popover saving unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.levelPopover = admin.modules.levelPopover || {}
	/** Attach command persistence, inherited value loading and matrix focus restoration.
	 * @param {object} context matrix and level catalogs, popover element and live active cell bindings
	 * @return {void}
	 */
	admin.modules.levelPopover.saving = function (context) {
		/**
		 * Sends the commands as one, confirming first when "Can connect" is taken away, and offers the receipt as the undo. The
		 * matrix is reloaded afterwards with every write blocked until it is on screen.
		 * @param {Array<object>} commands what planLevel(), planInherit() or planOperation() returned
		 * @param {string} message the notice that names what was saved
		 * @return {Promise<void>}
		 */
		async function save(commands, message) {
			if (context.current === null || context.current.saving || context.matrix.isBusy()) {
				return
			}
			const open = context.current
			const user = context.userOf(open.uid)
			if (commands.length === 0) {
				context.close(true)
				context.matrix.offerUndo(t('mcp', 'Nothing changed.'), { receiptId: null, revision: context.matrix.data().revision })
				return
			}
			if (context.losesEligibility(user, commands, open.module === null ? open.inherited : null)
				&& !window.confirm(t('mcp', 'Turn off “Can connect” for {user}?', { user: user.displayName }, undefined, { escape: false }) + '\n'
					+ t('mcp', 'This signs the person out of every MCP client, and undoing it does not sign them back in.'))) {
				context.render()
				return
			}
			if (window.OCA.Mcp.Admin.mailGrants && !window.OCA.Mcp.Admin.mailGrants.confirmWrites(commands.filter((command) => command.state === 'allow'))) {
				context.render()
				return
			}
			open.saving = true
			context.render()
			context.matrix.setBusy(true)
			let reloaded = false
			try {
				try {
					const sent = context.matrix.data().revision
					const saved = await context.matrix.api('POST', '/api/grants/commands', { expectedRevision: sent, commands })
					context.matrix.announce(sent, saved.revision)
					context.close(false)
					context.matrix.offerUndo(message, saved)
				} catch (e) {
					// Nothing was saved: the command is all or nothing.
					context.close(false)
					reloaded = await context.matrix.policyError(e)
					if (!reloaded) {
						context.matrix.notifyError(t('mcp', 'Could not save the permission for {user}.', { user: user.displayName }, undefined, { escape: false }))
					}
				}
				if (!reloaded) {
					await context.matrix.reload()
				}
			} finally {
				context.matrix.setBusy(false)
			}
			context.focusCell(open.uid, open.module)
		}

		/** Gives the focus back to the cell the popover was opened from, in the table drawn after a save.
		 * @param {string} uid Nextcloud user ID
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @return {void}
		 */
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
		 * @param {object} open active popover snapshot whose identity guards the pending read
		 * @return {Promise<void>}
		 */
		async function loadInherited(open) {
			const user = context.userOf(open.uid)
			const individual = context.planInherit(user, open.module)
			try {
				let cells = []
				if (individual.length > 0) {
					const data = await context.matrix.api('POST', '/api/grants/commands/preview', { commands: individual })
					if (typeof data.revision !== 'string' || data.revision !== context.matrix.data()?.revision) {
						throw new Error('stale')
					}
					cells = Array.isArray(data.cells) ? data.cells : []
				}
				if (context.current !== open) return
				open.inherited = context.inheritedValues(user, open.module, cells)
			} catch (e) {
				if (context.current !== open) return
				open.inherited = null
				open.unavailable = true
			}
			open.loading = false
			if (!open.saving) context.render()
		}

		Object.assign(context, { save, focusCell, loadInherited })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/level-popover/entry.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the level popover entry unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.levelPopover = admin.modules.levelPopover || {}
	/** Attach popover placement, opening and closing while retaining the active cell.
	 * @param {object} context matrix and level catalogs, popover element and live active cell bindings
	 * @return {void}
	 */
	admin.modules.levelPopover.entry = function (context) {
		/** Places the popover under the cell it belongs to, inside the matrix block, and brings all of it into view.
		 * @param {HTMLElement|null|undefined} trigger control that opened the drawer or popover and may receive focus again
		 * @return {void}
		 */
		function place(trigger) {
			const block = context.popover.offsetParent || context.popover.parentElement
			const box = block.getBoundingClientRect()
			const rect = trigger.getBoundingClientRect()
			const width = context.popover.offsetWidth || 360
			context.popover.style.top = (rect.bottom - box.top + 4) + 'px'
			context.popover.style.left = Math.max(0, Math.min(rect.left - box.left, box.width - width)) + 'px'
			// Its height is capped (js/admin.css) and it scrolls inside: the page only moves as far as showing all of it takes.
			context.popover.scrollIntoView?.({ block: 'nearest', inline: 'nearest' })
		}

		/**
		 * Opens the popover of one cell.
		 * @param {HTMLElement} trigger the level button of the cell
		 * @param {string} uid user of the row
		 * @param {string|null} module module id, null for "Can connect"
		 * @return {void}
		 */
		function open(trigger, uid, module) {
			if (context.current !== null) {
				const same = context.current.uid === uid && context.current.module === module
				context.close(false)
				if (same) {
					trigger.focus()
					return
				}
			}
			context.current = { uid, module, trigger, inherited: null, loading: true, unavailable: false, saving: false, operationsOpen: false }
			trigger.setAttribute('aria-expanded', 'true')
			context.render()
			context.popover.hidden = false
			context.place(trigger)
			;(context.popover.querySelector('input:checked') || context.popover.querySelector('input'))?.focus()
			context.loadInherited(context.current)
		}

		/** Close the current preview or popover and restore focus when requested.
		 * @param {boolean} restoreFocus give the focus back to the cell that opened the popover
		 * @return {void}
		 */
		function close(restoreFocus) {
			if (context.current === null) {
				return
			}
			const trigger = context.current.trigger
			context.current = null
			context.popover.hidden = true
			context.popover.replaceChildren()
			trigger?.setAttribute('aria-expanded', 'false')
			if (restoreFocus && trigger?.isConnected) {
				trigger.focus()
			}
			window.dispatchEvent?.(new Event('mcp-level-popover-closed'))
		}

		Object.assign(context, { place, open, close })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/level-popover/start.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the level popover once.
 * @param {object} admin app registry
 * @return {void} starts the original popover
 */
(function (admin) {
	'use strict'
	const matrix = window.OCA.Mcp.Admin.matrix
	const levels = window.OCA.Mcp.Admin.levels
	const popover = document.getElementById('mcp-level-popover')
	if (!matrix || !levels || !popover) {
		return
	}

	const context = { matrix, levels, popover }
	admin.sessions.levelPopover = context
	admin.modules.levelPopover.session(context)
	admin.modules.levelPopover.planning(context)
	admin.modules.levelPopover.wording(context)
	admin.modules.levelPopover.rendering(context)
	admin.modules.levelPopover.saving(context)
	admin.modules.levelPopover.entry(context)

	// Opening "Operations" makes it taller: it comes back into view whole (toggle does not bubble, so it is caught here).
	context.popover.addEventListener('toggle', () => context.popover.scrollIntoView?.({ block: 'nearest', inline: 'nearest' }), true)
	context.popover.addEventListener('keydown', (event) => {
		if (event.key === 'Escape') {
			event.preventDefault()
			context.close(true)
		}
	})
	document.addEventListener('pointerdown', (event) => {
		if (context.current !== null && !context.current.saving && !context.popover.contains(event.target) && event.target !== context.current.trigger && !context.current.trigger.contains?.(event.target)) {
			context.close(false)
		}
	})
	// A table drawn again (a reload, another block's write) replaces the cell: the popover would point at a stale one.
	window.addEventListener('mcp-matrix-rendered', () => {
		if (context.current !== null && !context.current.saving) {
			context.close(false)
		}
	})

	window.OCA.Mcp.Admin.levelPopover = { open: context.open, close: context.close, planLevel: context.planLevel, planInherit: context.planInherit, planOperation: context.planOperation, planTarget: context.planTarget, inheritedValues: context.inheritedValues, losesEligibility: context.losesEligibility }

})((globalThis.window || globalThis).OCA.Mcp.Admin)
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
 * Plain browser JavaScript, concatenated by the PHP asset builder. src/admin/matrix/level-cells.js asks kindOf() while it draws a level cell, and the access
 * inspector asks classifyPerson() for the person it shows; this file never writes anything.
 */
(function () {
	'use strict'

	const matrix = window.OCA.Mcp.Admin.matrix
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
			window.OCA.Mcp.Admin.overrides?.reviewCells(cells, reviewButton)
		}
	})

	/**
	 * How src/admin/matrix/level-cells.js marks an individual value: exception (the person icon), redundant (drawn like what it repeats,
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

	window.OCA.Mcp.Admin.exceptions = { kindOf, redundantCells, kindOfAnswer, chunks, classifyPerson, status: () => result.status }
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
 * inheritance (the reset preview of src/admin/overrides/preview.js, nothing is removed without it) and copy the user ID.
 *
 * A menu button with role="menu" items: Enter, Space or the arrow keys open it, the arrow keys move between the items,
 * Escape and Tab close it, and the focus goes back to the button. Plain browser JavaScript, concatenated by the PHP asset builder; src/admin/matrix/rows.js
 * asks button() for every row it draws, and this file never touches the matrix state.
 */
(function () {
	'use strict'

	const matrix = window.OCA.Mcp.Admin.matrix
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
		const overrides = window.OCA.Mcp.Admin.overrides
		return [
			{
				label: t('mcp', 'Inspect access'),
				disabled: !window.OCA.Mcp.Admin.inspector,
				// The inspector opens as a drawer that takes the focus and gives it back to this button.
				run: () => window.OCA.Mcp.Admin.inspector.open(user.uid, trigger),
			},
			{
				label: t('mcp', 'Restrict resources'),
				disabled: !window.OCA.Mcp.Admin.scope,
				// The resource scope opens as a drawer that takes the focus and gives it back to this button.
				run: () => window.OCA.Mcp.Admin.scope.open(user.uid, undefined, trigger),
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

	window.OCA.Mcp.Admin.rowActions = { button, open, close }
})()
;
/* admin/overrides/session.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the overrides session unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.overrides = admin.modules.overrides || {}
	/** Expose reset selection, preview state and live status announcements.
	 * @param {object} context matrix dependencies, selected users and the live reset preview
	 * @return {void}
	 */
	admin.modules.overrides.session = function (context) {
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

		/** The users of the table on screen.
		 * @return {object[]} the users of the table on screen */
		function pageUsers() {
			return context.matrix.data()?.users || []
		}

		/** Name the module permission or the connection eligibility permission.
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {string} operation operation id, "eligible" for "Can connect"
		 * @return {string} the permission as the table names it
		 */
		function permissionName(module, operation) {
			if (module === null) {
				return t('mcp', 'Can connect')
			}
			const labels = context.matrix.labels()
			return (labels.modules[module] || module) + ': ' + (labels.operations[operation] || operation)
		}

		/**
		 * Writes into the live regions. A region is cleared at once and written shortly after, so the same text twice in a
		 * row (a second identical error) is announced again, and a message that was replaced in the meantime never appears.
		 * The regions are fixed: a status one (polite) and an alert one (assertive), whose role never changes.
		 * @param {string} message text, '' to clear both
		 * @param {boolean} [error] an error goes to the alert region
		 * @return {void}
		 */
		function say(message, error = false) {
			clearTimeout(sayTimer)
			context.statusLine.textContent = ''
			context.alertLine.textContent = ''
			if (message === '') {
				return
			}
			const region = error ? context.alertLine : context.statusLine
			sayTimer = setTimeout(() => {
				region.textContent = message
			}, SAY_DELAY)
		}

		Object.defineProperties(context, {
			OVERRIDES: { configurable: true, get: () => OVERRIDES },
			MAX_CELLS: { configurable: true, get: () => MAX_CELLS },
			MAX_USERS: { configurable: true, get: () => MAX_USERS },
			SAY_DELAY: { configurable: true, get: () => SAY_DELAY },
			selected: { configurable: true, get: () => selected },
			review: { configurable: true, get: () => review, set: (value) => { review = value } },
			saving: { configurable: true, get: () => saving, set: (value) => { saving = value } },
			sayTimer: { configurable: true, get: () => sayTimer, set: (value) => { sayTimer = value } },
		})

		Object.assign(context, { el, pageUsers, permissionName, say })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/overrides/planning.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the overrides planning unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.overrides = admin.modules.overrides || {}
	/** Attach override extraction, reset planning and command construction.
	 * @param {object} context matrix dependencies, selected users and the live reset preview
	 * @return {void}
	 */
	admin.modules.overrides.planning = function (context) {
		/** The cells where the user has an individual override.
		 * @param {object} user row from the API
		 * @return {Array<{module: string|null, operation: string, kind: string}>} the cells where the user has an individual override
		 */
		function overrideCells(user) {
			const cells = []
			const eligible = context.OVERRIDES[user.origins?.eligible?.source]
			if (eligible) {
				cells.push({ module: null, operation: 'eligible', kind: eligible })
			}
			for (const [module, operations] of Object.entries(user.origins?.grants || {})) {
				for (const [operation, origin] of Object.entries(operations)) {
					if (context.OVERRIDES[origin?.source]) {
						cells.push({ module, operation, kind: context.OVERRIDES[origin.source] })
					}
				}
			}
			return cells
		}

		/**
		 * Whether the table offers no checkbox for this cell of this user: a disabled account, a user who may not connect (the
		 * permission columns show one notice instead), a module whose column is hidden or an app that is unavailable to them.
		 * An override there is still removed by a reset of the row, so the preview says so.
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
			return user.eligible === false || !(cell.module in (context.matrix.data()?.catalog || {})) || user.appsEnabled?.[cell.module] === false
		}

		/**
		 * What a reset removes, computed from the table on screen.
		 * @param {object[]} users users to reset
		 * @param {Array<{module: string|null, operation: string}>|function(object, object): boolean|null} only restrict to these cells, or to the cells a
		 *     function (user, cell) keeps; null for every override
		 * @return {object} {targets, users, cells, allow, deny, invalid, eligibleAllow, eligibleDeny, unseen, without, tooMany}:
		 *     eligibleDeny counts a Deny and an invalid value of "Can connect", both of which deny now and may stop denying;
		 *     unseen counts the overrides in a cell the table does not offer to the user (see unseenCell())
		 */
		function plan(users, only) {
			const result = { targets: [], users: 0, cells: 0, allow: 0, deny: 0, invalid: 0, eligibleAllow: 0, eligibleDeny: 0, unseen: 0, without: 0, tooMany: false }
			for (const user of users) {
				const cells = context.overrideCells(user).filter((cell) => only === null
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
					if (context.unseenCell(user, cell)) {
						result.unseen++
					}
				}
			}
			result.tooMany = result.cells > context.MAX_CELLS || result.users > context.MAX_USERS
			return result
		}

		/** Its inherit commands.
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

		/** Whether the preview was read at another revision than the table on screen, so what the table lists.
		 * @param {object} current the open preview
		 * @return {boolean} whether the preview was read at another revision than the table on screen, so what the table lists
		 *     and what the preview shows may not be the same overrides
		 */
		function outdated(current) {
			return current.revision !== null && current.revision !== context.matrix.data()?.revision
		}

		/** Build a stable key for a user, module and operation in a reset impact response.
		 * @return {string} key of one cell of one user
		 * @param {string} uid Nextcloud user ID
		 * @param {string|null} module catalog module ID, null for connection eligibility
		 * @param {string} operation catalog operation ID
		 */
		function afterKey(uid, module, operation) {
			return uid + '\0' + (module ?? '') + '\0' + operation
		}

		Object.assign(context, { afterKey, commandsOf, outdated, overrideCells, plan, unseenCell })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/overrides/selection.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the overrides selection unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.overrides = admin.modules.overrides || {}
	/** Create the selection toolbar and attach bulk selection controls and their listeners.
	 * @param {object} context matrix dependencies, selected users and the live reset preview
	 * @return {void}
	 */
	admin.modules.overrides.selection = function (context) {
		// --- Toolbar: the selection bar, the reset of the selected users, live regions and the preview -------------

		const toolbar = context.el('div', { className: 'mcp-reset-toolbar' })
		toolbar.setAttribute('role', 'group')
		toolbar.setAttribute('aria-label', t('mcp', 'Individual overrides'))
		const selectAll = context.el('input', { type: 'checkbox', id: 'mcp-select-all' })
		selectAll.setAttribute('aria-label', t('mcp', 'Select every user on this page'))
		const resetSelected = context.el('button', { type: 'button', className: 'mcp-reset-selected', onclick: () => context.start(context.selectedUsers(), null, resetSelected) })
		/*
		 * The selection bar, shown while someone is selected: set a level of a module (or "Can connect") for the selected
		 * people through the matrix's bulk change, which confirms what changes, who is left out and why and who already has
		 * it, then offers the undo; give their individual values back to inheritance through the reset preview; or clear the
		 * selection. It replaces the "All" menus of the column headers.
		 */
		const selectionCount = context.el('strong', { className: 'mcp-selection-count' })
		const moduleChoice = context.el('select', { className: 'mcp-selection-module' })
		moduleChoice.setAttribute('aria-label', t('mcp', 'Permission to set'))
		const levelChoice = context.el('select', { className: 'mcp-selection-level' })
		levelChoice.setAttribute('aria-label', t('mcp', 'Level to set'))
		const applyChoice = context.el('button', { type: 'button', className: 'mcp-selection-apply mcp-bulk', textContent: t('mcp', 'Apply'), onclick: () => context.applySelection() })
		const clearSelection = context.el('button', { type: 'button', className: 'mcp-selection-clear', textContent: t('mcp', 'Clear selection'), onclick: () => context.clearAll() })
		const bar = context.el('div', { className: 'mcp-selection-bar', hidden: true }, [
			selectionCount, context.el('span', { className: 'mcp-selection-set' }, [t('mcp', 'Set'), ' ', moduleChoice, ' ', levelChoice, ' ', applyChoice]),
			resetSelected, clearSelection,
		])
		bar.setAttribute('role', 'group')
		bar.setAttribute('aria-label', t('mcp', 'Selected people'))
		moduleChoice.addEventListener('change', () => context.fillLevels())
		const statusLine = context.el('p', { className: 'mcp-reset-status', id: 'mcp-reset-status' })
		statusLine.setAttribute('role', 'status')
		statusLine.setAttribute('aria-live', 'polite')
		const alertLine = context.el('p', { className: 'mcp-reset-alert', id: 'mcp-reset-alert' })
		alertLine.setAttribute('role', 'alert')
		alertLine.setAttribute('aria-live', 'assertive')
		const panel = context.el('div', { className: 'mcp-reset-preview', hidden: true })
		panel.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && context.review !== null && !context.saving) {
				context.close(true)
			}
		})
		// The select-all box heads the selection column once the matrix draws its header (decorateHeader()).
		toolbar.append(context.el('span', { className: 'mcp-reset-select' }, [selectAll]), bar, statusLine, alertLine, panel)
		// Focusable only by script: where the focus goes when nothing more specific is left to take it.
		toolbar.tabIndex = -1
		context.anchor.before(toolbar)

		/** The selected users that are on the page now.
		 * @return {object[]} the selected users that are on the page now */
		function selectedUsers() {
			return context.pageUsers().filter((user) => context.selected.has(user.uid))
		}

		/** Keeps the toolbar's counters, the select-all box and the button state in line with the selection and the lock.
		 * @return {void}
		 */
		function updateToolbar() {
			const users = context.pageUsers()
			const chosen = context.selectedUsers()
			selectAll.checked = users.length > 0 && chosen.length === users.length
			selectAll.indeterminate = chosen.length > 0 && chosen.length < users.length
			selectAll.disabled = users.length === 0
			resetSelected.textContent = t('mcp', 'Give back to inheritance… ({count})', { count: chosen.length }, undefined, { escape: false })
			resetSelected.title = t('mcp', 'Remove the individual values of the selected people, after a preview of what changes')
			resetSelected.disabled = chosen.length === 0 || context.saving
			bar.hidden = chosen.length === 0
			selectionCount.textContent = n('mcp', '%n selected', '%n selected', chosen.length)
			if (chosen.length > 0 && moduleChoice.children.length === 0) {
				context.fillModules()
			}
		}

		/** The choices of the selection bar: "Can connect" first, then every module of the catalog.
		 * @return {void}
		 */
		function fillModules() {
			const catalog = context.matrix.data()?.catalog || {}
			const labels = context.matrix.labels().modules
			moduleChoice.replaceChildren(context.el('option', { value: '', textContent: t('mcp', 'Can connect') }),
				...Object.keys(catalog).map((module) => context.el('option', { value: module, textContent: labels[module] || module })))
			moduleChoice.value = ''
			context.fillLevels()
		}

		/** The levels of the chosen module, or Yes and No for "Can connect".
		 * @return {void}
		 */
		function fillLevels() {
			const module = moduleChoice.value || null
			const names = context.matrix.levelLabels?.() || {}
			const choices = module === null
				? [['read', t('mcp', 'Yes')], ['none', t('mcp', 'No')]]
				: window.OCA.Mcp.Admin.levels.levels(module, context.matrix.data().catalog[module]).map((level) => [level, names[level] || level])
			levelChoice.replaceChildren(...choices.map(([value, text]) => context.el('option', { value, textContent: text })))
		}

		/** Sets the chosen level for the selected people, through the matrix's bulk change.
		 * @return {void}
		 */
		function applySelection() {
			if (context.saving || context.matrix.isBusy()) {
				context.say(t('mcp', 'Wait until the change in progress is saved.'), true)
				return
			}
			const uids = context.selectedUsers().map((user) => user.uid)
			if (uids.length > 0 && levelChoice.value) {
				context.matrix.bulk(moduleChoice.value || null, levelChoice.value, uids)
			}
		}

		/** Clears the selection of the page.
		 * @return {void}
		 */
		function clearAll() {
			context.selected.clear()
			for (const box of context.table.querySelectorAll('input.mcp-row-select')) {
				box.checked = false
			}
			context.updateToolbar()
			selectAll.focus()
		}

		/**
		 * Puts the select-all box in the header of the selection column, which src/admin/matrix/start.js draws again on every render.
		 * @param {HTMLTableCellElement} header the header cell of the selection column
		 * @return {void}
		 */
		function decorateHeader(header) {
			header.append(selectAll)
			context.updateToolbar()
		}

		selectAll.addEventListener('change', () => {
			for (const user of context.pageUsers()) {
				selectAll.checked ? context.selected.add(user.uid) : context.selected.delete(user.uid)
			}
			for (const box of context.table.querySelectorAll('input.mcp-row-select')) {
				box.checked = selectAll.checked
			}
			context.updateToolbar()
		})

		// The table was redrawn: the selection keeps only users that are still on screen, and a preview that was built from
		// the old table is closed, because what it lists may no longer be what is saved.
		window.addEventListener('mcp-matrix-rendered', () => {
			const present = new Set(context.pageUsers().map((user) => user.uid))
			for (const uid of [...context.selected]) {
				if (!present.has(uid)) {
					context.selected.delete(uid)
				}
			}
			if (context.review !== null && !context.saving) {
				context.close(false)
				context.say(t('mcp', 'The table changed, so the reset preview was closed. Review it again.'))
			}
			context.updateToolbar()
		})

		Object.defineProperties(context, {
			toolbar: { configurable: true, get: () => toolbar },
			selectAll: { configurable: true, get: () => selectAll },
			resetSelected: { configurable: true, get: () => resetSelected },
			selectionCount: { configurable: true, get: () => selectionCount },
			moduleChoice: { configurable: true, get: () => moduleChoice },
			levelChoice: { configurable: true, get: () => levelChoice },
			applyChoice: { configurable: true, get: () => applyChoice },
			clearSelection: { configurable: true, get: () => clearSelection },
			bar: { configurable: true, get: () => bar },
			statusLine: { configurable: true, get: () => statusLine },
			alertLine: { configurable: true, get: () => alertLine },
			panel: { configurable: true, get: () => panel },
		})

		Object.assign(context, { applySelection, clearAll, decorateHeader, fillLevels, fillModules, selectedUsers, updateToolbar })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/overrides/decoration.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the overrides decoration unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.overrides = admin.modules.overrides || {}
	/** Attach row selection controls and accessible individual override cell markers.
	 * @param {object} context matrix dependencies, selected users and the live reset preview
	 * @return {void}
	 */
	admin.modules.overrides.decoration = function (context) {
		// --- Rows and cells -----------------------------------------------------------------------------------------

		/**
		 * Adds the selection box to a row. The reset of the whole row is in the row's actions menu (src/admin/admin-row-actions.js),
		 * through resetUser(), so the row carries no button repeated on every line.
		 * @param {HTMLTableRowElement} row row drawn by matrix/rows.js; its first child is the selection cell
		 * @param {object} user row from the API
		 * @return {void}
		 */
		function decorateRow(row, user) {
			const slot = row.children[0]
			const box = context.el('input', { type: 'checkbox', className: 'mcp-row-select', checked: context.selected.has(user.uid) })
			box.setAttribute('aria-label', t('mcp', 'Select {user}', { user: user.displayName }, undefined, { escape: false }))
			box.addEventListener('change', () => {
				box.checked ? context.selected.add(user.uid) : context.selected.delete(user.uid)
				context.updateToolbar()
			})
			slot.append(box)
			context.updateToolbar()
		}

		/** Check whether the user has any individual values that a reset would remove.
		 * @return {boolean} whether the user has any individual value a reset would remove
		 * @param {object} user matrix user row with grants, origins and availability
		 */
		function hasOverrides(user) {
			return context.overrideCells(user).length > 0
		}

		/**
		 * Opens the reset preview for every individual value of one user, as the row's actions menu asks.
		 * @param {object} user row from the API
		 * @param {HTMLElement} trigger control that asked, which gets the focus back when the preview is cancelled
		 * @return {void}
		 */
		function resetUser(user, trigger) {
			context.start([user], null, trigger)
		}

		/**
		 * Names the state of a cell (individual allow, individual deny or inherited) for the tooltip and the screen reader, and
		 * outlines it only when it is a real exception (src/admin/admin-exceptions.js): an individual value that repeats what the
		 * person inherits is named but not outlined, and before that is known nothing is outlined. Without the classification
		 * every individual value is outlined, never fewer. A single value goes back to inheritance from the level popover, a
		 * whole row from its actions menu.
		 * @param {HTMLTableCellElement} cell the permission cell
		 * @param {HTMLInputElement|null} box its checkbox, null for an unavailable cell, which has none
		 * @param {object} user row from the API
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {string} operation operation id, "eligible" for "Can connect"
		 * @return {void}
		 */
		function decorateCell(cell, box, user, module, operation) {
			const origin = module === null ? user.origins?.eligible : user.origins?.grants?.[module]?.[operation]
			const kind = context.OVERRIDES[origin?.source]
			const names = {
				allow: t('mcp', 'Individual override: allowed'),
				deny: t('mcp', 'Individual override: denied'),
				invalid: t('mcp', 'Invalid stored override (denied)'),
			}
			if (box === null && !kind) {
				// An unavailable cell without an override of its own says nothing more than it already does.
				return
			}
			const exceptions = window.OCA.Mcp.Admin.exceptions
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
				cell.append(context.el('span', { className: 'mcp-sr-only', textContent: stateName }))
			}
		}

		Object.assign(context, { decorateCell, decorateRow, hasOverrides, resetUser })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/overrides/preview.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the overrides preview unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.overrides = admin.modules.overrides || {}
	/** Attach reset preview opening, read-only impact loading and focus restoration.
	 * @param {object} context matrix dependencies, selected users and the live reset preview
	 * @return {void}
	 */
	admin.modules.overrides.preview = function (context) {
		// --- Preview and reset ------------------------------------------------------------------------------------

		/**
		 * Opens the preview of a reset, or says why there is nothing to reset.
		 * @param {object[]} users users to reset
		 * @param {Array<{module: string|null, operation: string}>|function(object, object): boolean|null} only restrict to these cells
		 * @param {HTMLElement} trigger control that asked, which gets the focus back when the preview is cancelled
		 * @return {void}
		 */
		function start(users, only, trigger) {
			if (context.saving || context.matrix.isBusy()) {
				context.say(t('mcp', 'Wait until the change in progress is saved.'), true)
				return
			}
			const result = context.plan(users, only)
			if (result.cells === 0) {
				context.say(t('mcp', 'Nothing to reset: the selected users have no individual overrides.'))
				return
			}
			if (result.tooMany) {
				context.say(t('mcp', 'Too many overrides to reset at once. Select fewer users.'), true)
				return
			}
			context.say('')
			context.review = { plan: result, trigger, retry: false, after: null, loading: true, unavailable: false, revision: null }
			context.renderReview()
			context.panel.hidden = false
			context.panel.querySelector('h3').focus()
			context.loadAfter(context.review)
		}

		/**
		 * Asks the server what each cell has before and after the reset and redraws the preview with it. Read only. A preview
		 * that cannot be read leaves every "after" unknown and the warning conservative; it never blocks the reset itself,
		 * whose command refuses on its own what it cannot determine.
		 * @param {object} current the preview that asked; an answer for a preview that was closed or replaced is dropped
		 * @return {Promise<void>}
		 */
		async function loadAfter(current) {
			try {
				const data = await context.matrix.api('POST', '/api/grants/commands/preview', { commands: context.commandsOf(current.plan) })
				if (context.review !== current) {
					return
				}
				current.after = new Map((Array.isArray(data.cells) ? data.cells : []).map((cell) => [context.afterKey(cell.uid, cell.module, cell.operation), cell]))
				// The command is tied to what the preview showed: it carries the revision the preview was read at, so a change
				// after it is a 409 and never something else applied.
				current.revision = typeof data.revision === 'string' ? data.revision : null
			} catch (e) {
				if (context.review !== current) {
					return
				}
				current.unavailable = true
			}
			current.loading = false
			context.renderReview()
			context.say(current.unavailable
				? t('mcp', 'The result after the reset could not be read, so it is shown as unknown.')
				: t('mcp', 'The result after the reset is shown.'), current.unavailable)
		}

		/** Close the current preview or popover and restore focus when requested.
		 * @param {boolean} restoreFocus give the focus back to the control that opened the preview
		 * @return {void}
		 */
		function close(restoreFocus) {
			const trigger = context.review?.trigger
			context.review = null
			context.panel.hidden = true
			context.panel.replaceChildren()
			if (restoreFocus) {
				context.focusSomething(trigger)
			}
		}

		/**
		 * Puts the focus on the control that asked for the reset, or on the first control of the toolbar that can take it, and
		 * as the last resort on the toolbar itself, so the focus never falls back to the page when a preview goes away.
		 * @param {HTMLElement|undefined} trigger control that opened the preview, which a reload may have replaced
		 * @return {void}
		 */
		function focusSomething(trigger) {
			const usable = (node) => node && node.isConnected !== false && !node.disabled
			const target = [trigger, context.resetSelected, context.selectAll].find(usable) || context.toolbar
			target.focus()
		}

		/**
		 * Opens the reset preview for exactly these cells of these users, as src/admin/admin-exceptions.js does for the values that only
		 * repeat what the people inherit. The reset itself is the same command and the same preview as any other.
		 * @param {Array<{uid: string, module: string|null, operation: string}>} cells cells of the users on the page
		 * @param {HTMLElement} trigger control that asked
		 * @return {void}
		 */
		function reviewCells(cells, trigger) {
			const keys = new Set(cells.map((cell) => context.afterKey(cell.uid, cell.module, cell.operation)))
			const uids = new Set(cells.map((cell) => cell.uid))
			context.start(context.pageUsers().filter((user) => uids.has(user.uid)), (user, cell) => keys.has(context.afterKey(user.uid, cell.module, cell.operation)), trigger)
		}

		Object.assign(context, { close, focusSomething, loadAfter, reviewCells, start })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/overrides/review.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the overrides review unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.overrides = admin.modules.overrides || {}
	/** Attach reset impact rendering and its confirmation and retry controls.
	 * @param {object} context matrix dependencies, selected users and the live reset preview
	 * @return {void}
	 */
	admin.modules.overrides.review = function (context) {
		/** What it will be, or "unknown": a missing or null answer is never shown as a value.
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

		/** Describe one user's individual values and the previewed inheritance after their reset.
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
				const outcome = context.review.after?.get(context.afterKey(target.user.uid, cell.module, cell.operation))?.after
				return t('mcp', '{permission}: {before} → {after}', { permission: context.permissionName(cell.module, cell.operation), before: names[cell.kind], after: context.afterText(outcome) }, undefined, { escape: false })
			})
			return t('mcp', '{user}: {permissions}', { user: target.user.displayName, permissions: parts.join('; ') }, undefined, { escape: false })
		}

		/**
		 * What the server says about the results of a reset, once it was read.
		 * @param {object} current the open preview
		 * @return {{changed: number, same: number, unknown: number, lost: number, kept: number, lostUnknown: number}}
		 */
		function outcomes(current) {
			const out = { changed: 0, same: 0, unknown: 0, lost: 0, kept: 0, lostUnknown: 0 }
			for (const target of current.plan.targets) {
				for (const cell of target.cells) {
					const answer = current.after.get(context.afterKey(target.user.uid, cell.module, cell.operation))
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

		/** Draws the preview: the exact cells, what each has before and after, what may change and the buttons.
		 * @return {void}
		 */
		function renderReview() {
			const result = context.review.plan
			// The preview is drawn again when the server's answer arrives: the focus stays on what had it.
			const focused = ['h3', 'button.mcp-reset-confirm', 'button.mcp-reset-cancel', 'button.mcp-reset-reload'].find((selector) => {
				const node = context.panel.querySelector(selector)
				return node !== null && document.activeElement === node
			})
			const heading = context.el('h3', {
				tabIndex: -1,
				textContent: n('mcp', 'Back to group inheritance for %n user', 'Back to group inheritance for %n users', result.users, undefined, { escape: false }),
			})
			const lines = [
				context.el('p', { textContent: t('mcp', 'Overrides to remove: {allow} allowed, {deny} denied, {invalid} invalid.', { allow: result.allow, deny: result.deny, invalid: result.invalid }, undefined, { escape: false }) }),
			]
			const stale = context.outdated(context.review)
			if (stale) {
				lines.push(context.el('p', { className: 'mcp-reset-warning', textContent: t('mcp', 'The table is older than the preview: the permissions changed after it was loaded. Reload the table to review the reset again.') }))
			}
			const known = context.review.after !== null
			const counts = known ? context.outcomes(context.review) : null
			if (context.review.loading) {
				lines.push(context.el('p', { textContent: t('mcp', 'Checking what these users inherit…') }))
			} else if (context.review.unavailable) {
				lines.push(context.el('p', { textContent: t('mcp', 'The result after the reset could not be read, so it is shown as unknown.') }))
			}
			lines.push(context.el('p', { textContent: known
				? t('mcp', 'Results that change: {changed}. Unchanged: {same}. Unknown: {unknown}.', counts, undefined, { escape: false })
				: t('mcp', 'Each removed override is replaced by what the groups and defaults give, so some permissions may change.') }))
			if (known) {
				if (counts.lost > 0) {
					lines.push(context.el('p', { className: 'mcp-reset-warning', textContent: n('mcp',
						'%n user loses “Can connect” and the access it gives. Their MCP connections are revoked, and undoing this does not sign them back in.',
						'%n users lose “Can connect” and the access it gives. Their MCP connections are revoked, and undoing this does not sign them back in.',
						counts.lost, undefined, { escape: false }) }))
				}
				if (counts.kept > 0) {
					lines.push(context.el('p', { textContent: n('mcp', '%n user keeps “Can connect” through a group, so nobody is signed out for it.', '%n users keep “Can connect” through a group, so nobody is signed out for it.', counts.kept, undefined, { escape: false }) }))
				}
				if (counts.lostUnknown > 0) {
					lines.push(context.el('p', { className: 'mcp-reset-warning', textContent: n('mcp',
						'The “Can connect” of %n user after the reset is unknown. The reset is refused until the groups can be read.',
						'The “Can connect” of %n users after the reset is unknown. The reset is refused until the groups can be read.',
						counts.lostUnknown, undefined, { escape: false }) }))
				}
			} else if (result.eligibleAllow > 0) {
				// The server's answer is missing: never "will", a group or another rule may keep the user eligible.
				lines.push(context.el('p', { className: 'mcp-reset-warning', textContent: n('mcp',
					'%n user loses an individual Allow for “Can connect”. Unless a group or another rule keeps them eligible, they may be signed out of their MCP clients, and undoing this does not sign them back in.',
					'%n users lose an individual Allow for “Can connect”. Unless a group or another rule keeps them eligible, they may be signed out of their MCP clients, and undoing this does not sign them back in.',
					result.eligibleAllow, undefined, { escape: false }) }))
			}
			if (result.eligibleDeny > 0) {
				lines.push(context.el('p', { textContent: t('mcp', 'Removing an individual Deny for “Can connect” lets the groups decide again, which may give access.') }))
			}
			if (result.unseen > 0) {
				lines.push(context.el('p', { textContent: n('mcp',
					'%n of these values is in a permission the table does not show for that user. It is removed too.',
					'%n of these values are in permissions the table does not show for those users. They are removed too.',
					result.unseen, undefined, { escape: false }) }))
			}
			if (result.without > 0) {
				lines.push(context.el('p', { textContent: n('mcp', '%n selected user has no individual overrides and is left out.', '%n selected users have no individual overrides and are left out.', result.without, undefined, { escape: false }) }))
			}
			const list = context.el('ul', { className: 'mcp-reset-targets', tabIndex: 0 }, result.targets.map((target) => context.el('li', { textContent: context.describeTarget(target) })))
			// The list scrolls when many users are selected, so it is a named, focusable region the keyboard can reach.
			list.setAttribute('aria-label', t('mcp', 'Individual overrides'))
			const confirm = context.el('button', {
				type: 'button',
				className: 'primary mcp-reset-confirm',
				textContent: context.review.retry ? t('mcp', 'Try again') : t('mcp', 'Remove overrides'),
				disabled: context.review.loading || stale,
				onclick: context.execute,
			})
			const cancel = context.el('button', { type: 'button', className: 'mcp-reset-cancel', textContent: t('mcp', 'Cancel'), onclick: () => context.close(true) })
			const actions = [confirm, ' ', cancel]
			if (stale) {
				actions.push(' ', context.el('button', { type: 'button', className: 'mcp-reset-reload', textContent: t('mcp', 'Reload the table'), onclick: () => context.matrix.reload() }))
			}
			context.panel.replaceChildren(heading, ...lines, list, context.el('div', { className: 'mcp-reset-actions' }, actions))
			if (focused) {
				// A button that was just disabled cannot take the focus: the reload button, which now matters most, does.
				const target = context.panel.querySelector(focused)
				;(target.disabled ? context.panel.querySelector('button.mcp-reset-reload') || target : target).focus()
			}
		}

		Object.assign(context, { afterText, describeTarget, outcomes, renderReview })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/overrides/saving.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the overrides saving unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.overrides = admin.modules.overrides || {}
	/** Attach reset execution and conflict handling without automatic write replay.
	 * @param {object} context matrix dependencies, selected users and the live reset preview
	 * @return {void}
	 */
	admin.modules.overrides.saving = function (context) {
		/** Sends the command of the open preview. Nothing is repeated on its own: a retry is the administrator's click.
		 * @return {Promise<void>}
		 */
		async function execute() {
			if (context.review === null || context.saving || context.matrix.isBusy()) {
				return
			}
			if (context.outdated(context.review)) {
				// A matrix save moved the table's revision after the preview was read: say so instead of acting on it.
				context.renderReview()
				return
			}
			const current = context.review
			const commands = context.commandsOf(current.plan)
			context.saving = true
			context.matrix.setBusy(true)
			for (const button of context.panel.querySelectorAll('button')) {
				button.disabled = true
			}
			context.updateToolbar()
			context.say('')
			let undo = null
			let failed = false
			try {
				try {
					const sent = current.revision ?? context.matrix.data().revision
					const saved = await context.matrix.api('POST', '/api/grants/commands', { expectedRevision: sent, commands })
					context.matrix.announce(sent, saved.revision)
					for (const target of current.plan.targets) {
						context.selected.delete(target.user.uid)
					}
					context.close(false)
					// The table still shows the old values until it reloads: the result is told only now, from the server's answer.
					undo = context.matrix.offerUndo(saved.changed === 0
						? t('mcp', 'Nothing changed: those overrides were already gone.')
						: n('mcp', '%n individual override removed.', '%n individual overrides removed.', saved.changed, undefined, { escape: false }), saved)
					await context.matrix.reload()
				} catch (e) {
					if (e.message === '503' && !(e.data && e.data.uncertain)) {
						// Nothing was written: the preview, the selection and the table stay, and the same command can be sent again.
						context.review.retry = true
						context.renderReview()
						context.say(t('mcp', 'The change could not be made because what these users inherit could not be read. Nothing was changed, and your selection is kept. Try again.'), true)
					} else if (!(await context.matrix.policyError(e))) {
						failed = true
						context.close(false)
						context.say(t('mcp', 'Could not return the users to group inheritance. The table was reloaded with the saved values.'), true)
						await context.matrix.reload()
					} else {
						failed = true
						context.close(false)
					}
				}
			} finally {
				context.saving = false
				context.matrix.setBusy(false)
				context.updateToolbar()
			}
			if (context.review !== null && context.review.retry) {
				context.panel.querySelector('button').focus()
			}
			if (failed) {
				// The preview is gone and the table was redrawn: the focus goes to a control of the toolbar, never to the page.
				context.focusSomething(current.trigger)
			}
			context.matrix.focusUndo(undo)
		}

		Object.assign(context, { execute })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/overrides/start.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the overrides editor and start its original effects once.
 * @param {object} admin shared OCA.Mcp.Admin registry and public surfaces
 * @return {void}
 */
(function (admin) {
	'use strict'
	const matrix = window.OCA.Mcp.Admin.matrix
	const table = document.getElementById('mcp-matrix')
	// The toolbar goes before the table's wrapper, or before the table itself when the wrapper is missing. With no
	// attached anchor it would be created outside the page and every message and the preview would be lost silently,
	// so nothing is installed at all: the matrix keeps working without the reset.
	const anchor = table?.closest('.mcp-matrix-wrap') || table
	if (!matrix || !table || typeof anchor.before !== 'function' || anchor.isConnected === false) {
		return
	}

	const context = { matrix, table, anchor }
	admin.sessions.overrides = context
	admin.modules.overrides.session(context)
	admin.modules.overrides.planning(context)
	admin.modules.overrides.decoration(context)
	admin.modules.overrides.preview(context)
	admin.modules.overrides.review(context)
	admin.modules.overrides.saving(context)
	admin.modules.overrides.selection(context)
	window.OCA.Mcp.Admin.overrides = { decorateRow: context.decorateRow, decorateHeader: context.decorateHeader, decorateCell: context.decorateCell, plan: context.plan, reviewCells: context.reviewCells, resetUser: context.resetUser, hasOverrides: context.hasOverrides }
	// The matrix may have been drawn before this file ran: draw it again so its rows carry the controls.
	context.matrix.refresh()
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/groups/unknown-grants.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Explicit diagnosis and cleanup of an unknown group grant.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	/** Expose unknown grant diagnosis, row refresh and explicit audited cleanup.
	 * @param {object} context group directory controls, live policy draft and attached editor operations
	 * @return {void}
	 */
	admin.modules.groups.unknownGrants = function (context) {
		const UnknownGroupGrants = class UnknownGroupGrants {
			/** Describe unknown grants in a group directory row, or return null when none exist.
			 * @returns {object|null} Textual warning for a directory row.
			 * @param {object} group directory group with identity, grants and policy diagnosis
			 */
			static warning(group) {
				return group.unknownGrants?.length ? { symbol: '?', text: t('mcp', 'Unknown grants: denied'), warning: true } : null
			}
			/** Merge freshly read policy diagnosis into the matching directory row.
			 * @returns {object[]} Fresh diagnosis replaces the open group's directory entry after readback.
			 * @param {object[]} rows directory or access rows in display order
			 * @param {object} group directory group with identity, grants and policy diagnosis
			 */
			static refreshRow(rows, group) {
				return rows.map((row) => row.groupId === group.groupId ? { ...row, ...group, unknownGrants: group.unknownGrants || [] } : row)
			}
			/** Build unknown grant diagnostics with an explicit cleanup button for each opaque row.
			 * @returns {HTMLElement[]} Accessible diagnostics with one explicit cleanup action per opaque row.
			 * @param {object} group directory group with identity, grants and policy diagnosis
			 * @param {boolean} busy whether a policy change currently locks the controls
			 * @param {function(object): Promise<void>} clean callback that clears the chosen unknown grant
			 */
			static render(group, busy, clean) {
				if (!group.unknownGrants?.length) return []
				const section = context.el('section', { className: 'mcp-note' })
				section.setAttribute('aria-label', t('mcp', 'Unknown group grants'))
				section.append(context.el('h4', { textContent: t('mcp', 'Unknown group grants') }),
					context.el('p', { textContent: t('mcp', 'These saved grants are unknown to this version and grant no access. Other permissions continue to apply. Cleanup is optional and is recorded.') }))
				for (const row of group.unknownGrants) {
					section.append(context.el('p', {}, [context.el('span', { textContent: row.module + '.' + row.operation + ' — ' + t('mcp', 'Stored value: Allow; effective: denied') }),
						context.el('button', { type: 'button', disabled: busy, textContent: t('mcp', 'Clear unknown grant'), onclick: () => clean(row) })]))
				}
				return [section]
			}
			/** Confirms the exact row and snapshot, never replays writes, and reloads after every attempt.
			 * @param {object} group directory group with identity, grants and policy diagnosis
			 * @param {object} row grant row returned by the API
			 * @param {{api: function(string, string, object): Promise<object>, setBusy: function(boolean): void,
			 *     refresh: function(string): Promise<void>, say: function(string, boolean=): void, saved: function(): void}} options cleanup dependencies
			 * @return {Promise<void>}
			 */
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

		Object.defineProperties(context, {
			UnknownGroupGrants: { configurable: true, get: () => UnknownGroupGrants },
		})
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/groups/session.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the groups session unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	/** Expose group controls, live directory and draft state, JSON requests and status feedback.
	 * @param {object} context group directory controls, live policy draft and attached editor operations
	 * @return {void}
	 */
	admin.modules.groups.session = function (context) {
		const root = context.root
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

		const copyGrants = (grants) => Object.fromEntries(Object.entries(grants).map(([module, ops]) => [module, [...ops]]))
		const unknown = () => t('mcp', 'Unknown count')
		const count = (value) => value === null || value === undefined ? unknown() : String(value)
		const untrusted = () => t('mcp', 'This group comes from a source that cannot guarantee group identity (for example LDAP). Grant MCP access to its members individually.')

		/** Create a DOM element, apply its properties and append its child nodes or text.
		 * @param {string} tag HTML element tag name
		 * @param {object} [props] DOM properties assigned to the element
		 * @param {Array<Node|string>} [children] child nodes and text appended in order
		 * @return {HTMLElement}
		 */
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
		 * @param {string} message feedback text; an empty string clears the message
		 * @param {boolean} [error=false] announce through the alert region instead of the status region
		 * @return {void}
		 */
		function say(message, error = false) {
			clearTimeout(sayTimer)
			status.textContent = ''
			alert.textContent = ''
			if (message === '') return
			const region = error ? alert : status
			sayTimer = setTimeout(() => { region.textContent = message }, SAY_DELAY)
		}

		/** Fetch a JSON API response and reject unsuccessful requests with their response details.
		 * @param {string} method HTTP request method
		 * @param {string} path API path below /apps/mcp
		 * @param {object|undefined} body JSON request body when present
		 * @return {Promise<object>}
		 */
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

		/** Resolve the translated module label, falling back to its catalog ID.
		 * @param {string} module catalog module ID
		 * @return {string}
		 */
		function moduleName(module) {
			return { files: t('mcp', 'Files'), notes: t('mcp', 'Notes'), deck: t('mcp', 'Deck'), calendar: t('mcp', 'Calendar'), contacts: t('mcp', 'Contacts'), tasks: t('mcp', 'Tasks'), talk: t('mcp', 'Talk'), people: t('mcp', 'People'), logs: t('mcp', 'Server log'), mail: t('mcp', 'Mail'), tables: t('mcp', 'Tables'), forms: t('mcp', 'Forms'), polls: t('mcp', 'Polls') }[module] || module
		}

		/** Resolve the translated operation label, falling back to its catalog ID.
		 * @param {string} operation catalog operation ID
		 * @return {string}
		 */
		function operationName(operation) {
			return { ...window.OCA.Mcp.Admin.pollsGrants?.operationLabels(), schema: t('mcp', 'schema'), results: t('mcp', 'results'), read: t('mcp', 'read'), edit: t('mcp', 'edit'), create: t('mcp', 'create'), move: t('mcp', 'move'), delete: t('mcp', 'delete'), transfer: t('mcp', 'transfer'), reply: t('mcp', 'reply'), attach: t('mcp', 'attach'), quote: t('mcp', 'quote'), share: t('mcp', 'share'), link: t('mcp', 'link'), restore: t('mcp', 'restore'), messages: t('mcp', 'messages'), attachments: t('mcp', 'attachments'), flags: t('mcp', 'flags'), draft: t('mcp', 'draft'), send: t('mcp', 'send') }[operation] || operation
		}

		/** Flatten and sort granted module and operation pairs for stable draft comparison.
		 * @param {Object<string, string[]>} grants granted operations indexed by module
		 * @return {string[]}
		 */
		function grantKeys(grants) {
			return Object.entries(grants).flatMap(([module, operations]) => operations.map((operation) => module + ':' + operation)).sort()
		}

		/** Compare the current group draft with its saved grants; return null before a group is open.
		 * @return {boolean|null}
		 */
		function dirty() {
			return current && JSON.stringify(context.grantKeys(draft)) !== JSON.stringify(context.grantKeys(current.grants))
		}

		/** Ask before discarding unsaved group changes and report whether replacement may proceed.
		 * @return {boolean}
		 */
		function discardDraft() {
			return !context.dirty() || window.confirm(t('mcp', 'Discard the unsaved group changes?'))
		}

		/** Describe why a group cannot currently grant access or needs administrator confirmation.
		 * @param {object} group directory group with identity, grants and policy diagnosis
		 * @return {string}
		 */
		function diagnosis(group) {
			if (group.invalidId) return t('mcp', 'The directory returned an invalid group ID. This group cannot be edited.')
			if (group.trusted === null) return t('mcp', 'The group source is unavailable. Retry before editing.')
			if (group.exists === false) return t('mcp', 'This group no longer exists. Its saved policy does not grant access; you can clear it.')
			if (!group.trusted) return untrusted()
			if (group.state === 'needs_confirm') return t('mcp', 'This policy is awaiting confirmation and does not grant access. Confirming or saving will let it grant access again.')
			if (group.state === 'pending_delete') return t('mcp', 'Group deletion is pending. This policy does not grant access. Check the group before confirming it.')
			return ''
		}

		Object.defineProperties(context, {
			root: { configurable: true, get: () => root },
			search: { configurable: true, get: () => search },
			list: { configurable: true, get: () => list },
			detail: { configurable: true, get: () => detail },
			status: { configurable: true, get: () => status },
			alert: { configurable: true, get: () => alert },
			prev: { configurable: true, get: () => prev },
			next: { configurable: true, get: () => next },
			counter: { configurable: true, get: () => counter },
			columns: { configurable: true, get: () => columns },
			picker: { configurable: true, get: () => picker },
			filters: { configurable: true, get: () => filters },
			page: { configurable: true, get: () => page, set: (value) => { page = value } },
			filter: { configurable: true, get: () => filter, set: (value) => { filter = value } },
			rows: { configurable: true, get: () => rows, set: (value) => { rows = value } },
			listSeq: { configurable: true, get: () => listSeq, set: (value) => { listSeq = value } },
			detailSeq: { configurable: true, get: () => detailSeq, set: (value) => { detailSeq = value } },
			current: { configurable: true, get: () => current, set: (value) => { current = value } },
			shownName: { configurable: true, get: () => shownName, set: (value) => { shownName = value } },
			draft: { configurable: true, get: () => draft, set: (value) => { draft = value } },
			preview: { configurable: true, get: () => preview, set: (value) => { preview = value } },
			advanced: { configurable: true, get: () => advanced, set: (value) => { advanced = value } },
			busy: { configurable: true, get: () => busy, set: (value) => { busy = value } },
			timer: { configurable: true, get: () => timer, set: (value) => { timer = value } },
			sayTimer: { configurable: true, get: () => sayTimer, set: (value) => { sayTimer = value } },
			SAY_DELAY: { configurable: true, get: () => SAY_DELAY },
			PAGE_SIZE: { configurable: true, get: () => PAGE_SIZE },
			RAW: { configurable: true, get: () => RAW },
			copyGrants: { configurable: true, get: () => copyGrants },
			unknown: { configurable: true, get: () => unknown },
			count: { configurable: true, get: () => count },
			untrusted: { configurable: true, get: () => untrusted },
		})

		Object.assign(context, { api, diagnosis, dirty, discardDraft, el, grantKeys, moduleName, operationName, say })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/groups/directory.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the groups directory unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	/** Attach paged group browsing, filters, keyboard navigation and policy detail loading.
	 * @param {object} context group directory controls, live policy draft and attached editor operations
	 * @return {void}
	 */
	admin.modules.groups.directory = function (context) {
		/** Material Design Icons "lock-outline", in the text colour, so it follows the theme; decoration, hidden from assistive technology. */
		const LOCK_ICON = '<svg class="mcp-group-lock" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12,17C10.89,17 10,16.1 10,15C10,13.89 10.89,13 12,13A2,2 0 0,1 14,15A2,2 0 0,1 12,17M18,20V10H6V20H18M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V10C4,8.89 4.89,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z"/></svg>'
		/**
		 * The state of a group in one short phrase, for its row in the list; '' when the group can grant normally. The
		 * phrase always carries the state in words, next to a symbol, so it never depends on colour.
		 * @return {{symbol: string, lock?: boolean, text: string, warning: boolean}|null} a lock is drawn as an icon, not as a symbol
		 * @param {object} group directory group with identity, grants and policy diagnosis
		 */
		function rowState(group) {
			if (group.invalidId) return { symbol: '⊘', text: t('mcp', 'Cannot be edited: invalid group ID'), warning: false }
			if (group.trusted === null) return { symbol: '?', text: t('mcp', 'Source unavailable'), warning: true }
			if (group.exists === false) return { symbol: '⊘', text: t('mcp', 'Deleted group'), warning: false }
			if (!group.trusted) return { symbol: '', lock: true, text: t('mcp', 'Cannot grant access'), warning: false }
			if (group.state === 'needs_confirm') return { symbol: '⚠', text: t('mcp', 'Awaiting confirmation'), warning: true }
			if (group.state === 'pending_delete') return { symbol: '⚠', text: t('mcp', 'Deletion pending'), warning: true }
			return context.UnknownGroupGrants.warning(group)
		}
		/** Describe every granted module for the directory row's accessible name and tooltip.
		 * @return {string} what a group grants, in words, for the accessible name and the tooltip of its row
		 * @param {object} group directory group with identity, grants and policy diagnosis
		 */
		function grantSummary(group) {
			const granted = Object.entries(group.grants || {}).filter(([, operations]) => operations.length > 0).map(([module]) => module)
			if (granted.length === 0) return t('mcp', 'Grants nothing')
			return t('mcp', 'Grants: {permissions}', { permissions: granted.map((module) => module === '_system' ? t('mcp', 'Can connect') : context.moduleName(module)).join(', ') }, undefined, context.RAW)
		}
		/** Summarize the granted module count or connection eligibility at the end of a group row.
		 * @return {string} what a group grants in one or two words, for the end of its row: "—", "Can connect" or "N modules"
		 * @param {object} group directory group with identity, grants and policy diagnosis
		 */
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
		 * @return {HTMLButtonElement} the row's button; drawList() puts each in its list item
		 * @param {object} group directory group with identity, grants and policy diagnosis
		 */
		function groupRow(group) {
			const differs = group.groupId !== null && group.groupId !== group.displayName
			const children = [context.el('span', { className: 'mcp-group-name', textContent: group.displayName })]
			if (differs) children.push(context.el('span', { className: 'mcp-group-id', textContent: group.groupId }))
			const state = context.rowState(group)
			let end
			if (state) {
				end = context.el('span', { className: 'mcp-group-end mcp-group-state' + (state.warning ? ' mcp-group-state-warning' : ''), textContent: state.symbol })
				if (state.lock) end.innerHTML = LOCK_ICON
			} else {
				end = context.el('span', { className: 'mcp-group-end', textContent: context.grantShort(group) })
			}
			end.setAttribute('aria-hidden', 'true')
			children.push(end)
			const label = [group.displayName, differs ? t('mcp', 'ID: {id}', { id: group.groupId }, undefined, context.RAW) : '', state ? state.text : context.grantSummary(group)].filter(Boolean).join('. ')
			const selected = context.current !== null && group.groupId !== null && context.current.groupId === group.groupId
			const button = context.el('button', { type: 'button', className: 'mcp-group-item', title: label, disabled: group.invalidId === true, tabIndex: -1, onclick: () => open(group.groupId, false, false, group.displayName) }, children)
			button.setAttribute('aria-label', label)
			button.setAttribute('aria-current', String(selected))
			return button
		}
		/** The row buttons drawn by drawList(), in order. */
		let buttons = []
		/** The rows that can take the focus, in order. */
		const items = () => buttons.filter((button) => !button.disabled)
		/** One stop in the tab order for the whole list (the open group, else the first); the arrows move inside it.
		 * @param {HTMLElement|null|undefined} target control to receive focus
		 * @return {void}
		 */
		function rove(target) {
			for (const button of items()) button.tabIndex = button === target ? 0 : -1
		}
		/** Scrolls the list, and only the list, so that a row is in view.
		 * @param {HTMLButtonElement} button directory row whose visibility is restored
		 * @return {void}
		 */
		function keepVisible(button) {
			if (typeof button.offsetTop !== 'number' || typeof context.list.clientHeight !== 'number') return
			const top = button.offsetTop - context.list.offsetTop
			if (top < context.list.scrollTop) context.list.scrollTop = top
			else if (top + button.offsetHeight > context.list.scrollTop + context.list.clientHeight) context.list.scrollTop = top + button.offsetHeight - context.list.clientHeight
		}
		/** Replace the directory rows and restore the open group's tab stop and visibility.
		 * @return {void}
		 */
		function drawList() {
			buttons = context.rows.map(context.groupRow)
			context.list.replaceChildren(...buttons.map((button) => context.el('li', {}, [button])))
			if (!context.rows.length) context.list.append(context.el('li', { className: 'mcp-hint', textContent: t('mcp', 'No groups found.') }))
			const all = items()
			const open = all.find((button) => button.getAttribute('aria-current') === 'true')
			context.rove(open || all[0])
			if (open) context.keepVisible(open)
		}
		context.list.addEventListener('keydown', (event) => {
			const all = items()
			const at = all.indexOf(document.activeElement)
			if (at < 0) return
			const to = { ArrowDown: at + 1, ArrowUp: at - 1, Home: 0, End: all.length - 1 }[event.key]
			if (to === undefined) return
			event.preventDefault()
			const target = all[Math.max(0, Math.min(all.length - 1, to))]
			context.rove(target)
			target.focus()
		})
		/** The filter buttons say how many groups each shows, when the server knows it without extra work.
		 * @param {Object<string, number>|null|undefined} counts server-provided totals by directory filter
		 * @return {void}
		 */
		function drawFilters(counts) {
			const names = { all: t('mcp', 'All'), granted: t('mcp', 'With grants'), blocked: t('mcp', 'Blocked groups') }
			const counted = { all: (count) => t('mcp', 'All ({count})', { count }, undefined, context.RAW), granted: (count) => t('mcp', 'With grants ({count})', { count }, undefined, context.RAW), blocked: (count) => t('mcp', 'Blocked groups ({count})', { count }, undefined, context.RAW) }
			for (const [key, button] of Object.entries(context.filters)) {
				if (!button) continue
				const known = counts?.[key]
				button.textContent = typeof known === 'number' ? counted[key](known) : names[key]
				button.setAttribute('aria-pressed', String(key === context.filter))
			}
		}
		for (const [key, button] of Object.entries(context.filters)) {
			button?.addEventListener('click', () => {
				if (context.filter === key) return
				context.filter = key
				context.page = 1
				context.drawFilters(null)
				context.loadList()
			})
		}
		/** On a narrow screen the list folds into the picker above the editor; on a wide one both columns stay.
		 * @param {boolean} isOpen whether the directory should be expanded
		 * @return {void}
		 */
		function setListOpen(isOpen) {
			if (!context.columns || !context.picker) return
			context.columns.setAttribute('data-list', isOpen ? 'open' : 'closed')
			context.picker.setAttribute('aria-expanded', String(isOpen))
			context.picker.textContent = context.current && !isOpen ? t('mcp', 'Group: {group}', { group: context.shownName ?? context.current.groupId }, undefined, context.RAW) : t('mcp', 'Choose a group')
		}
		context.picker?.addEventListener('click', () => {
			const isOpen = context.picker.getAttribute('aria-expanded') !== 'true'
			context.setListOpen(isOpen)
			if (isOpen) context.search.focus()
		})
		/** Load the current group search page, discard superseded responses and update paging controls.
		 * @return {Promise<void>}
		 */
		async function loadList() {
			const seq = ++context.listSeq
			context.list.setAttribute('aria-busy', 'true')
			context.prev.disabled = context.next.disabled = true
			try {
				const data = await context.api('GET', '/api/group-directory?' + new URLSearchParams({ search: context.search.value.trim(), page: String(context.page), filter: context.filter }))
				if (seq !== context.listSeq) return
				context.rows = data.groups
				context.list.scrollTop = 0
				context.drawList()
				context.drawFilters(data.counts)
				const total = typeof data.total === 'number' ? data.total : context.rows.length
				const first = (context.page - 1) * context.PAGE_SIZE + 1
				context.counter.textContent = context.rows.length === 0 ? ''
					: context.page === 1 && !data.hasMore ? n('mcp', '%n group', '%n groups', total)
						: t('mcp', 'Groups {first}–{last} of {total}', { first, last: first + context.rows.length - 1, total }, undefined, context.RAW)
				context.prev.disabled = context.page <= 1
				context.next.disabled = !data.hasMore
				context.say('')
			} catch (e) {
				if (seq !== context.listSeq) return
				context.rows = []
				context.counter.textContent = ''
				context.list.replaceChildren(context.el('li', {}, [t('mcp', 'Could not load groups.'), context.retry(context.loadList)]))
				context.say(t('mcp', 'Could not load groups.'), true)
			} finally {
				if (seq === context.listSeq) context.list.setAttribute('aria-busy', 'false')
			}
		}
		/** Create a retry button that invokes the supplied load action.
		 * @param {function(): Promise<void>} action load action invoked by the retry button
		 * @return {HTMLButtonElement}
		 */
		function retry(action) {
			return context.el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: action })
		}
		/** Read the selected group policy while respecting the save lock and draft replacement confirmation.
		 * @param {string} gid native Nextcloud group ID
		 * @param {boolean} [preserve=false] retain the current draft when policy details are refreshed
		 * @param {boolean} [keepMessage=false] retain the current status announcement after loading
		 * @param {string|null} [displayName=null] directory name to show for the open group
		 * @return {Promise<void>}
		 */
		async function open(gid, preserve = false, keepMessage = false, displayName = null) {
			if (context.busy || (!preserve && !context.discardDraft())) return
			if (displayName !== null) context.shownName = displayName
			const seq = ++context.detailSeq
			// Keep the draft's base until a read succeeds, including after a failed refresh.
			context.preview = null
			context.detail.replaceChildren(context.el('p', { textContent: t('mcp', 'Loading…') }))
			try {
				const data = await context.api('GET', '/api/group-directory/detail?' + new URLSearchParams({ gid }))
				if (seq !== context.detailSeq) return
				context.current = data
				context.rows = context.UnknownGroupGrants.refreshRow(context.rows, context.current)
				if (!preserve) context.draft = context.copyGrants(data.grants)
				if (!keepMessage) context.say('')
				context.drawList()
				context.setListOpen(false)
				context.render('heading')
			} catch (e) {
				if (seq !== context.detailSeq) return
				context.detail.replaceChildren(context.retry(() => open(gid, preserve)))
				context.say(t('mcp', 'Could not read the group policy. Your draft is kept. Retry.'), true)
			}
		}

		Object.defineProperties(context, {
			LOCK_ICON: { configurable: true, get: () => LOCK_ICON },
			buttons: { configurable: true, get: () => buttons, set: (value) => { buttons = value } },
			items: { configurable: true, get: () => items },
		})

		Object.assign(context, { drawFilters, drawList, grantShort, grantSummary, groupRow, keepVisible, loadList, open, retry, rove, rowState, setListOpen })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/groups/drafts.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the groups drafts unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	/** Attach group level calculations, change summaries and draft operation editing.
	 * @param {object} context group directory controls, live policy draft and attached editor operations
	 * @return {void}
	 */
	admin.modules.groups.drafts = function (context) {
		/** Operations of a module in a grants object, whether it came as an object or as PHP's empty array. */
		const operationsIn = (grants, module) => (grants && !Array.isArray(grants) && grants[module]) || []
		/** The catalog's modules, without the "Can connect" key. */
		const modules = () => Object.entries(context.current.catalog).filter(([module]) => module !== '_system')
		/**
		 * The level a set of granted operations makes, with the matrix's tiers (src/admin/admin-levels.js). A group only grants, so an
		 * operation outside the set is "not granted", never denied.
		 * @param {Object<string, string[]>} grants granted operations indexed by module
		 * @param {string} module catalog module ID
		 * @return {string}
		 */
		function levelIn(grants, module) {
			const operations = context.current.catalog[module]
			const granted = operationsIn(grants, module)
			return window.OCA.Mcp.Admin.levels.levelOf(module, operations, Object.fromEntries(operations.map((operation) => [operation, granted.includes(operation)])))
		}
		/** What a member gets without any grant: GrantPolicy::defaultGranted(), reading except the server log.
		 * @param {string} module catalog module ID
		 * @return {string}
		 */
		function defaultLevel(module) {
			const operations = context.current.catalog[module]
			return window.OCA.Mcp.Admin.levels.levelOf(module, operations, Object.fromEntries(operations.map((operation) => [operation, operation === 'read' && module !== 'logs'])))
		}
		/** For a group the lowest level is "Not granted": the members keep what they have from elsewhere.
		 * @param {string} level permission level ID
		 * @return {string}
		 */
		function levelName(level) {
			return { ...window.OCA.Mcp.Admin.pollsGrants?.levelLabels(), forms_write: t('mcp', 'Read and write forms'), forms_write_results: t('mcp', 'Read and write forms and read responses'), none: t('mcp', 'Not granted'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom') }[level] || level
		}
		/** A level in words, a custom one with its operations, since "Custom → Custom" would say nothing.
		 * @param {Object<string, string[]>} grants granted operations indexed by module
		 * @param {string} module catalog module ID
		 * @return {string}
		 */
		function levelText(grants, module) {
			const level = context.levelIn(grants, module)
			if (level !== 'custom') return context.levelName(level)
			return t('mcp', 'Custom ({operations})', { operations: context.current.catalog[module].filter((operation) => operationsIn(grants, module).includes(operation)).map(context.operationName).join(', ') }, undefined, context.RAW)
		}
		/** One line per changed module, "Files: Read → Edit", and one for "Can connect".
		 * @param {Object<string, string[]>} before previous module grants
		 * @param {Object<string, string[]>} after replacement module grants
		 * @return {string[]}
		 */
		function changes(before, after) {
			const lines = []
			const eligible = (grants) => operationsIn(grants, '_system').includes('eligible')
			if (eligible(before) !== eligible(after)) {
				lines.push(t('mcp', '{module}: {before} → {after}', { module: t('mcp', 'Can connect'), before: eligible(before) ? t('mcp', 'Yes') : t('mcp', 'No'), after: eligible(after) ? t('mcp', 'Yes') : t('mcp', 'No') }, undefined, context.RAW))
			}
			for (const [module] of modules()) {
				const was = [...operationsIn(before, module)].sort().join()
				const will = [...operationsIn(after, module)].sort().join()
				if (was !== will) lines.push(t('mcp', '{module}: {before} → {after}', { module: context.moduleName(module), before: context.levelText(before, module), after: context.levelText(after, module) }, undefined, context.RAW))
			}
			return lines
		}
		/** Apply a preset while preserving operations outside all of its tiers, except when clearing with None.
		 * @param {string} module catalog module ID
		 * @param {string} level requested preset
		 * @param {string} focusKey editor control to focus after redraw
		 * @return {void}
		 */
		function setLevel(module, level, focusKey) {
			const operations = context.current.catalog[module]
			const target = window.OCA.Mcp.Admin.levels.targetFor(module, operations, level)
			context.setOperations(module, operations.filter(operation => target[operation] ?? operationsIn(context.draft, module).includes(operation)), focusKey)
		}
		/** Replace a module's draft operations, invalidate its preview and redraw the editor.
		 * @param {string} module catalog module ID
		 * @param {string[]} operations catalog operation IDs
		 * @param {string|null|undefined} focusKey logical editor control to focus after redraw
		 * @return {void}
		 */
		function setOperations(module, operations, focusKey) {
			if (operations.length) context.draft[module] = [...operations].sort()
			else delete context.draft[module]
			context.preview = null
			context.render(focusKey)
		}
		/**
		 * The matrix, filtered by this group and by "With exceptions", for the members whose individual settings win over it.
		 * The matrix's group filter lists the groups it can filter by; a group it does not list (an external group, or one that
		 * no longer exists) is said so here, and the page does not move. The focus goes to the exceptions filter, a real
		 * control, which also tells a screen reader where it landed.
		 * @param {string} gid native Nextcloud group ID
		 * @param {string} name displayed group name
		 * @return {void}
		 */
		function viewInMatrix(gid, name) {
			const select = document.getElementById('mcp-group')
			const exceptionsOnly = document.getElementById('mcp-only-exceptions')
			if (!select || !Array.from(select.options).some((option) => option.value === gid)) {
				context.say(t('mcp', 'The matrix cannot show only the members of {group}: its group filter does not list this group.', { group: name }, undefined, context.RAW))
				return
			}
			select.value = gid
			select.dispatchEvent(new Event('change'))
			exceptionsOnly?.click()
			document.getElementById('mcp-block-matrix')?.scrollIntoView?.({ block: 'start' })
			exceptionsOnly?.focus()
		}
		/** A pressed level carries a check mark besides aria-pressed, as in the matrix popover, so it never depends on colour.
		 * @param {string} module catalog module ID
		 * @param {string} level permission level ID
		 * @param {boolean} pressed whether this choice is currently selected
		 * @param {function(MouseEvent): void} onclick handler invoked when the control is clicked
		 * @return {HTMLButtonElement}
		 */
		function levelButton(module, level, pressed, onclick) {
			const children = []
			if (pressed) {
				const mark = context.el('span', { className: 'mcp-pressed-mark', textContent: '✓' })
				mark.setAttribute('aria-hidden', 'true')
				children.push(mark)
			}
			children.push(context.levelName(level))
			const button = context.el('button', { type: 'button', name: 'mcp-group-level-' + module, value: level, className: 'mcp-group-level' + (level === 'none' ? ' mcp-group-level-none' : ''), onclick }, children)
			button.setAttribute('aria-pressed', String(pressed))
			return button
		}

		Object.defineProperties(context, {
			operationsIn: { configurable: true, get: () => operationsIn },
			modules: { configurable: true, get: () => modules },
		})

		Object.assign(context, { changes, defaultLevel, levelButton, levelIn, levelName, levelText, setLevel, setOperations, viewInMatrix })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/groups/rendering.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the groups rendering unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	/** Attach group policy rendering with level, operation and preview controls.
	 * @param {object} context group directory controls, live policy draft and attached editor operations
	 * @return {void}
	 */
	admin.modules.groups.rendering = function (context) {
		/**
		 * Draws the open group and focuses the control drawn under focusKey, so a change redraws without losing the place.
		 * @param {string|null} [focusKey=null] key of the control to focus, or null to leave the focus alone
		 * @return {void}
		 */
		function render(focusKey = null) {
			const controls = new Map()
			const keyed = (key, node) => { controls.set(key, node); return node }
			const editable = !context.busy && context.current.trusted === true
			const name = context.shownName ?? context.current.groupId
			const heading = keyed('heading', context.el('h3', { tabIndex: -1, textContent: name }))
			heading.setAttribute('aria-describedby', 'mcp-group-alert mcp-group-diagnosis')
			const stats = context.current.counts
			const members = stats.members === null || stats.members === undefined
				? t('mcp', 'Unknown number of members')
				: n('mcp', '%n member', '%n members', stats.members)
			const head = context.el('div', { className: 'mcp-group-head' }, [heading])
			if (name !== context.current.groupId) head.append(context.el('span', { className: 'mcp-hint', textContent: t('mcp', 'ID: {id}', { id: context.current.groupId }, undefined, context.RAW) }))
			head.append(context.el('span', { className: 'mcp-hint', textContent: members }))
			const clear = context.el('button', { type: 'button', className: 'mcp-group-clear', textContent: t('mcp', 'Preview clearing group policy'), disabled: context.busy || context.grantKeys(context.current.grants).length === 0, onclick: () => {
				if (!context.discardDraft()) return
				context.draft = {}
				context.run('preview')
			} })
			head.append(clear)
			const confirm = context.el('button', { type: 'button', textContent: t('mcp', 'Confirm current policy'), disabled: !editable, onclick: () => {
				if (!context.discardDraft()) return
				context.draft = context.copyGrants(context.current.grants)
				context.run('preview')
			} })
			confirm.hidden = !['needs_confirm', 'pending_delete'].includes(context.current.state)
			// The group's notices live in its detail, under the title, with the action they ask for.
			const reason = context.diagnosis(context.current)
			const note = context.el('div', { className: 'mcp-note mcp-group-note', hidden: reason === '' }, [context.el('p', { id: 'mcp-group-diagnosis', textContent: reason }), confirm])

			const operationsSwitch = keyed('operations', context.el('input', { type: 'checkbox', id: 'mcp-group-operations', checked: context.advanced }))
			operationsSwitch.setAttribute('role', 'switch')
			operationsSwitch.addEventListener('change', () => { context.advanced = operationsSwitch.checked; context.render('operations') })
			const toolbar = context.el('div', { className: 'mcp-group-toolbar' }, [context.el('label', { htmlFor: 'mcp-group-operations' }, [operationsSwitch, t('mcp', 'Operations')])])

			const fields = context.el('fieldset', { disabled: !editable })
			fields.setAttribute('aria-describedby', 'mcp-group-diagnosis')
			fields.append(context.el('legend', { className: 'mcp-sr-only', textContent: t('mcp', 'Group grants') }))
			const eligible = keyed('eligible', context.el('input', { type: 'checkbox', name: 'mcp-group-eligible', id: 'mcp-group-eligible', checked: context.operationsIn(context.draft, '_system').includes('eligible') }))
			eligible.setAttribute('role', 'switch')
			eligible.addEventListener('change', () => context.setOperations('_system', eligible.checked ? ['eligible'] : [], 'eligible'))
			fields.append(context.el('div', { className: 'mcp-group-row' }, [context.el('label', { htmlFor: 'mcp-group-eligible', className: 'mcp-group-module' }, [t('mcp', 'Can connect')]), context.el('span', {}, [eligible]), context.el('span')]))
			for (const [module, operations] of context.modules()) {
				const label = context.el('span', { className: 'mcp-group-module', id: 'mcp-group-module-' + module, textContent: context.moduleName(module) })
				const granted = context.operationsIn(context.draft, module)
				const level = context.levelIn(context.draft, module)
				let control
				if (context.advanced) {
					control = context.el('span', { className: 'mcp-group-operations' }, operations.map((operation) => {
						const box = keyed('op:' + module + ':' + operation, context.el('input', { type: 'checkbox', name: 'mcp-group-op-' + module, value: operation, checked: granted.includes(operation) }))
						box.setAttribute('aria-label', context.moduleName(module) + ': ' + context.operationName(operation))
						box.addEventListener('change', () => {
							const values = new Set(granted)
							box.checked ? values.add(operation) : values.delete(operation)
							context.setOperations(module, [...values], 'op:' + module + ':' + operation)
						})
						return context.el('label', {}, [box, context.operationName(operation)])
					}))
				} else {
					control = context.el('span', { className: 'mcp-group-levels' }, window.OCA.Mcp.Admin.levels.levels(module, operations).map((choice) => keyed('level:' + module + ':' + choice,
						context.levelButton(module, choice, choice === level, () => context.setLevel(module, choice, 'level:' + module + ':' + choice)))))
				}
				control.setAttribute('role', 'group')
				control.setAttribute('aria-labelledby', 'mcp-group-module-' + module)
				let hint = ''
				if (level === 'none') hint = t('mcp', 'Default: {level}', { level: context.defaultLevel(module) === 'none' ? t('mcp', 'None') : context.levelName(context.defaultLevel(module)) }, undefined, context.RAW)
				else if (level === 'custom' && !context.advanced) hint = t('mcp', 'Custom: change it in Operations')
				fields.append(context.el('div', { className: 'mcp-group-row' }, [label, control, context.el('span', { className: 'mcp-hint', textContent: hint })]))
			}

			// One sentence and a link instead of a table of counters: who wins over this group, and where to see them.
			const overrides = stats.overrides
			const exceptions = context.el('p', { className: 'mcp-group-exceptions' }, [overrides === null || overrides === undefined
				? t('mcp', 'The members with individual settings could not be counted.')
				: overrides === 0 ? t('mcp', 'No member has individual settings that take precedence over this group.')
					: n('mcp', '%n member has individual settings that take precedence over this group.', '%n members have individual settings that take precedence over this group.', overrides)])
			if (overrides !== 0) exceptions.append(context.el('button', { type: 'button', className: 'mcp-link-button', textContent: t('mcp', 'View in the matrix'), onclick: () => context.viewInMatrix(context.current.groupId, name) }))

			const parts = [head, note, ...context.UnknownGroupGrants.render(context.current, context.busy, (row) => context.UnknownGroupGrants.clean(context.current, row, {
				api: context.api, say: context.say, setBusy: (value) => { context.busy = value; context.root.setAttribute('aria-busy', String(value)); context.render() },
				refresh: async (gid) => { await context.open(gid, true, true) },
				saved: () => window.dispatchEvent(new Event('mcp-group-policy-saved')),
			})), toolbar, fields, exceptions]
			const save = keyed('save', context.el('button', { type: 'button', className: 'primary', textContent: t('mcp', 'Save group policy'), disabled: context.busy || !context.preview, onclick: () => context.run('save') }))
			const review = keyed('review', context.el('button', { type: 'button', className: 'primary', textContent: t('mcp', 'Review and save'), disabled: !editable, onclick: () => context.run('preview') }))
			for (const button of [review, confirm, clear, save]) button.setAttribute('aria-describedby', 'mcp-group-diagnosis')
			if (context.preview) {
				// The review of the preview the server returned: what changes, who is affected, and the save it allows.
				const region = context.el('div', { className: 'mcp-group-review' })
				region.setAttribute('role', 'region')
				region.setAttribute('aria-labelledby', 'mcp-group-review-title')
				const lines = context.changes(context.preview.current || context.current.grants, context.preview.grants || context.draft)
				region.append(context.el('h4', { id: 'mcp-group-review-title', textContent: t('mcp', 'Review the changes to {group}', { group: name }, undefined, context.RAW) }),
					lines.length ? context.el('ul', {}, lines.map((line) => context.el('li', { textContent: line }))) : context.el('p', { textContent: t('mcp', 'No change to the saved policy.') }),
					context.el('p', { textContent: t('mcp', 'Group grants apply to the live group, including future members. Individual overrides take precedence. Nextcloud permissions still apply.') }),
					context.el('p', { textContent: t('mcp', 'Preview: {members} current members; {revokes} credential holders will be signed out.', { members: context.count(context.preview.members), revokes: context.count(context.preview.revokes) }) }))
				if (context.preview.withdraws) region.append(context.el('p', { className: 'mcp-group-warning', textContent: t('mcp', 'Losing the last source of eligibility revokes connections. Restoring this policy does not reconnect users.') }))
				const back = context.el('button', { type: 'button', textContent: t('mcp', 'Back'), disabled: context.busy, onclick: () => { context.preview = null; context.render('review') } })
				region.append(context.el('div', { className: 'mcp-group-actions' }, [back, save]))
				parts.push(region)
			} else if (context.dirty()) {
				const lines = context.changes(context.current.grants, context.draft)
				const bar = context.el('div', { className: 'mcp-group-savebar' }, [
					context.el('div', { className: 'mcp-group-savebar-text' }, [context.el('strong', { textContent: n('mcp', '%n unsaved change', '%n unsaved changes', lines.length) }), context.el('span', { className: 'mcp-hint', textContent: lines.join('; ') })]),
					context.el('button', { type: 'button', textContent: t('mcp', 'Discard'), disabled: context.busy, onclick: () => { context.draft = context.copyGrants(context.current.grants); context.render('heading') } }),
					review,
				])
				parts.push(bar)
			}
			context.detail.replaceChildren(...parts)
			if (focusKey !== null) (controls.get(focusKey) || heading).focus()
		}

		Object.assign(context, { render })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/groups/saving.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the groups saving unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	/** Attach group policy preview and save requests with draft-preserving recovery.
	 * @param {object} context group directory controls, live policy draft and attached editor operations
	 * @return {void}
	 */
	admin.modules.groups.saving = function (context) {
		/** Preview or save the group draft and refresh conflicting or uncertain state without replaying writes.
		 * @param {"preview"|"save"} action whether to calculate impact or commit the reviewed group draft
		 * @return {Promise<void>}
		 */
		async function run(action) {
			if (context.busy || !context.current || (action === 'save' && !context.preview)) return
			if (action === 'save' && window.OCA.Mcp.Admin.mailGrants && !window.OCA.Mcp.Admin.mailGrants.confirmWrites(Object.entries(context.draft)
				.flatMap(([module, operations]) => operations.filter((operation) => !(context.current.grants[module] || []).includes(operation)).map((operation) => ({ module, operation }))))) return
			context.busy = true
			const gid = context.current.groupId
			const body = { gid, grants: structuredClone(context.draft), expectedRevision: action === 'save' ? context.preview.revision : context.current.revision }
			if (action === 'save') body.previewId = context.preview.previewId
			context.render()
			context.root.setAttribute('aria-busy', 'true')
			let refresh = false
			try {
				const data = await context.api(action === 'save' ? 'PUT' : 'POST', '/api/group-grants' + (action === 'save' ? '' : '/preview'), body)
				if (action === 'preview') {
					context.preview = data
					context.say(t('mcp', 'Review the impact, then save the group policy.'))
				} else {
					context.preview = null
					refresh = true
					context.say(t('mcp', 'Saved'))
					window.dispatchEvent(new Event('mcp-group-policy-saved'))
				}
			} catch (e) {
				context.preview = null
				if (action === 'save' && e.message === 'unconfirmed') {
					refresh = true
					context.say(t('mcp', 'The change could not be confirmed. Your draft is kept. Retry after checking the group.'), true)
				} else if (e.message === '409' || e.message === '428') {
					// A fresh read pairs the revision with the stored policy. Never adopt an error revision or replay.
					refresh = true
					context.say(t('mcp', 'The policy or group members changed. Your draft is kept. Review a new preview before saving again.'), true)
				} else if (e.message === '503') {
					refresh = true
					context.say(t('mcp', 'The change could not be confirmed. Your draft is kept. Retry after checking the group.'), true)
				} else if (e.message === '422') {
					refresh = true
					context.say(context.untrusted(), true)
				} else {
					context.say(t('mcp', 'Could not save the group policy. Your draft is kept. Request a new preview and retry.'), true)
				}
			} finally {
				context.busy = false
				context.root.setAttribute('aria-busy', 'false')
				if (refresh) await context.open(gid, true, true)
				else context.render(action === 'preview' && context.preview ? 'save' : 'heading')
			}
		}

		Object.assign(context, { run })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/groups/start.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the groups editor and start its original effects once.
 * @param {object} admin shared OCA.Mcp.Admin registry and public surfaces
 * @return {void}
 */
(function (admin) {
	'use strict'
	const root = document.getElementById('mcp-group-editor')
	if (!root) return

	const context = { root }
	admin.sessions.groups = context
	admin.modules.groups.unknownGrants(context)
	admin.modules.groups.session(context)
	admin.modules.groups.directory(context)
	admin.modules.groups.drafts(context)
	admin.modules.groups.rendering(context)
	admin.modules.groups.saving(context)
	context.search.addEventListener('input', () => {
		clearTimeout(context.timer)
		context.timer = setTimeout(() => { context.page = 1; context.loadList() }, 300)
	})
	context.prev.addEventListener('click', () => { context.page = Math.max(1, context.page - 1); context.loadList() })
	context.next.addEventListener('click', () => { context.page++; context.loadList() })
	context.loadList()
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/scope/session.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the scope session unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/** Expose scope controls, live user and module state, JSON requests and feedback formatting.
	 * @param {object} context scope drawer controls, live module drafts and attached resource operations
	 * @return {void}
	 */
	admin.modules.scope.session = function (context) {
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
		/** Page event of a write this page committed: detail {from, to}; src/admin/matrix/start.js sends and follows it too. */
		const REVISION_MOVED = 'mcp-revision-moved'

		/** Create a DOM element, apply its properties and append its child nodes or text.
		 * @param {string} tag HTML element tag name
		 * @param {object} [props] DOM properties assigned to the element
		 * @param {Array<Node|string>} [children] child nodes and text appended in order
		 * @return {HTMLElement}
		 */
		function el(tag, props = {}, children = []) {
			const node = document.createElement(tag)
			Object.assign(node, props)
			node.append(...children)
			return node
		}

		/** Replace the status or alert message used to announce the current editor outcome.
		 * @param {string} message feedback text; an empty string clears the message
		 * @param {boolean} [error=false] announce through the alert region instead of the status region
		 * @return {void}
		 */
		function say(message, error = false) {
			clearTimeout(sayTimer)
			status.textContent = ''
			alertBox.textContent = ''
			if (message === '') return
			const region = error ? alertBox : status
			sayTimer = setTimeout(() => { region.textContent = message }, SAY_DELAY)
		}

		/** Fetch a JSON API response and reject unsuccessful requests with their response details.
		 * @param {string} method HTTP request method
		 * @param {string} path API path below /apps/mcp
		 * @param {Object<string, string>|null|undefined} query URL query parameters when present
		 * @param {object|undefined} body JSON request body when present
		 * @return {Promise<object>}
		 */
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

		/** Build a scope API path for the currently open user and module.
		 * @param {string} module catalog module ID
		 * @param {string} [suffix=""] suffix appended to the module API path
		 * @return {string}
		 */
		function scopePath(module, suffix = '') {
			return '/api/scope/' + encodeURIComponent(uid) + '/' + module + suffix
		}

		/** Resolve the scope drawer heading for a supported resource module.
		 * @param {string} module catalog module ID
		 * @return {string|undefined}
		 */
		function title(module) {
			return { files: t('mcp', 'Files: folders'), calendar: t('mcp', 'Calendar: calendars'), tasks: t('mcp', 'Tasks: task lists') }[module]
		}

		/** Describe the effect of an empty selected scope for the given resource module.
		 * @param {string} module catalog module ID
		 * @return {string|undefined}
		 */
		function emptyText(module) {
			return {
				files: t('mcp', 'Nothing selected: the Files tools of this user reach no folder.'),
				calendar: t('mcp', 'Nothing selected: the Calendar tools of this user reach no calendar.'),
				tasks: t('mcp', 'Nothing selected: the Tasks tools of this user reach no task list.'),
			}[module]
		}

		/** Translate a failed resource request into an administrator-facing explanation.
		 * @param {Error & {status?: number, data?: object}} error request failure with optional HTTP status and response details
		 * @return {string}
		 */
		function failure(error) {
			if (error.status === 403) return t('mcp', 'Only administrators can change the resource scope.')
			if (error.status === 400) return t('mcp', 'Check the user ID and try again.')
			if (error.status === 503) return t('mcp', 'The MCP policy cannot be read right now. Nothing was changed. Try again shortly.')
			return t('mcp', 'The request failed. Try again.')
		}

		/** What a saved or selected item is called on screen: its path for a folder, its name otherwise.
		 * @param {object} item resource ID, label or path and availability flags
		 * @return {string}
		 */
		function label(item) {
			if (item.found === false) return t('mcp', 'Resource no longer found ({id})', { id: item.id }, undefined, { escape: false })
			return item.path || item.name
		}

		/** Create visible badges for missing, excluded or inaccessible resources.
		 * @param {object} item resource ID, label or path and availability flags
		 * @return {HTMLElement[]}
		 */
		function badges(item) {
			const out = []
			if (item.found === false) out.push(context.el('span', { className: 'mcp-badge', textContent: t('mcp', 'not found: it has no effect') }))
			if (item.excluded) out.push(context.el('span', { className: 'mcp-badge', textContent: t('mcp', 'excluded by a tag: choosing it has no effect') }))
			if (item.inaccessible) out.push(context.el('span', { className: 'mcp-badge mcp-badge-error', textContent: t('mcp', 'the user cannot reach it in Nextcloud') }))
			return out
		}

		/**
		 * A message of one module: shown under it and announced through the page's live regions, which exist from the
		 * start (a region created with its text already in it is not reliably announced).
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @param {string} message feedback text; an empty string clears the message
		 * @param {boolean} [error=false] announce through the alert region instead of the status region
		 * @return {void}
		 */
		function note(state, message, error = false) {
			state.line = message
			if (message !== '') context.say(context.title(state.module) + ': ' + message, error)
		}

		/** Compare the module draft mode and selected IDs with the saved scope.
		 * @return {boolean} whether the draft differs from what is saved
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 */
		function dirty(state) {
			const ids = (items) => items.map((item) => item.id).sort().join(',')
			return state.mode !== state.savedMode || (state.mode === 'restricted' && ids(state.items) !== ids(state.savedItems))
		}

		Object.defineProperties(context, {
			MODULES: { configurable: true, get: () => MODULES },
			UNDO_SECONDS: { configurable: true, get: () => UNDO_SECONDS },
			SAY_DELAY: { configurable: true, get: () => SAY_DELAY },
			root: { configurable: true, get: () => root },
			form: { configurable: true, get: () => form },
			userInput: { configurable: true, get: () => userInput },
			status: { configurable: true, get: () => status },
			alertBox: { configurable: true, get: () => alertBox },
			container: { configurable: true, get: () => container },
			tabs: { configurable: true, get: () => tabs },
			drawerTitle: { configurable: true, get: () => drawerTitle },
			closeButton: { configurable: true, get: () => closeButton },
			entryButton: { configurable: true, get: () => entryButton },
			currentTab: { configurable: true, get: () => currentTab, set: (value) => { currentTab = value } },
			returnFocus: { configurable: true, get: () => returnFocus, set: (value) => { returnFocus = value } },
			returnRow: { configurable: true, get: () => returnRow, set: (value) => { returnRow = value } },
			uid: { configurable: true, get: () => uid, set: (value) => { uid = value } },
			seq: { configurable: true, get: () => seq, set: (value) => { seq = value } },
			sayTimer: { configurable: true, get: () => sayTimer, set: (value) => { sayTimer = value } },
			modules: { configurable: true, get: () => modules, set: (value) => { modules = value } },
			REVISION_MOVED: { configurable: true, get: () => REVISION_MOVED },
		})

		Object.assign(context, { api, badges, dirty, el, emptyText, failure, label, note, say, scopePath, title })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/scope/loading.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the scope loading unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/** Attach scope reads and revision-aware reloads that retain unrelated unsaved drafts.
	 * @param {object} context scope drawer controls, live module drafts and attached resource operations
	 * @return {void}
	 */
	admin.modules.scope.loading = function (context) {
		/** Load every scope module for a user and discard answers superseded by another user selection.
		 * @param {string} target Nextcloud user ID whose scope is read
		 * @param {string|null|undefined} focusModule resource module whose heading should receive focus
		 * @return {Promise<void>}
		 */
		async function openUser(target, focusModule) {
			const mine = ++context.seq
			context.uid = target
			context.modules = {}
			context.currentTab = focusModule || context.MODULES[0]
			context.tabs?.replaceChildren()
			context.container.replaceChildren()
			context.container.setAttribute('aria-busy', 'true')
			context.say(t('mcp', 'Loading the resource scope…'))
			try {
				const loaded = await Promise.all(context.MODULES.map((module) => context.api('GET', context.scopePath(module))))
				if (mine !== context.seq) return
				loaded.forEach((data) => {
					context.modules[data.module] = {
						module: data.module, active: data.active, limit: data.limit, revision: data.revision,
						mode: data.mode, savedMode: data.mode, items: [...data.items], savedItems: [...data.items],
						search: '', parents: [], results: null, page: 1, undo: null, saving: false, stale: false,
					}
				})
				context.MODULES.forEach((module) => context.container.append(context.renderModule(context.modules[module])))
				context.renderTabs()
				context.say(t('mcp', 'Resource scope of {user}.', { user: context.uid }, undefined, { escape: false }))
				const heading = document.getElementById('mcp-scope-heading-' + (focusModule || context.MODULES[0]))
				if (focusModule && heading) {
					heading.scrollIntoView?.({ block: 'start' })
					heading.focus?.()
				}
			} catch (error) {
				if (mine !== context.seq) return
				context.uid = null
				context.say(context.failure(error), true)
			} finally {
				if (mine === context.seq) context.container.setAttribute('aria-busy', 'false')
			}
		}

		/** Reads one module again from the server; its draft is replaced by the saved scope and its revision.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @return {Promise<void>}
		 */
		async function reload(state) {
			const data = await context.api('GET', context.scopePath(state.module))
			Object.assign(state, { active: data.active, revision: data.revision, stale: false,
				mode: data.mode, savedMode: data.mode, items: [...data.items], savedItems: [...data.items] })
			context.rerender(state)
		}

		/**
		 * The policy revision moved (a save, an undo, a conflict): the three modules are read again. A module with an
		 * unsaved draft keeps it. When it already took the revision this page's own write made (`current`), the draft is
		 * still valid and stays as it is. Otherwise it keeps the revision it was read at, and says so: saving it is refused
		 * by the server (409) rather than accepted against a scope it never saw, and discarding it loads the saved scope.
		 * @param {object} origin the module that saved, undid or was refused
		 * @param {string} [current] revision made by this page's own write, when it committed
		 * @return {Promise<void>}
		 */
		async function reloadAll(origin, current) {
			for (const module of context.MODULES) {
				const state = context.modules[module]
				if (state === origin || !context.dirty(state)) {
					await context.reload(state).catch(() => {})
				} else if (current === undefined || state.revision !== current) {
					state.stale = true
					state.line = t('mcp', 'Changed before the policy moved, so saving will be refused. Discard the changes to load the saved scope.')
					context.rerender(state)
				}
			}
		}

		Object.assign(context, { openUser, reload, reloadAll })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/scope/drawer.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the scope drawer unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/** Attach drawer placement, module tabs, draft confirmation and focus restoration.
	 * @param {object} context scope drawer controls, live module drafts and attached resource operations
	 * @return {void}
	 */
	admin.modules.scope.drawer = function (context) {
		/** Describe a module's saved scope and indicate whether its draft is unsaved.
		 * @return {string} the tab of a module: its saved state, and whether a draft is not saved
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 */
		function tabLabel(state) {
			const name = { files: t('mcp', 'Files'), calendar: t('mcp', 'Calendar'), tasks: t('mcp', 'Tasks') }[state.module]
			const saved = state.savedMode === 'restricted'
				? t('mcp', '{module}: {count}', { module: name, count: state.savedItems.length }, undefined, { escape: false })
				: t('mcp', '{module}: all', { module: name }, undefined, { escape: false })
			return context.dirty(state) ? saved + ' (' + t('mcp', 'not saved') + ')' : saved
		}

		/** The module tabs of the drawer (WAI-ARIA tabs: the arrow keys, Home and End move between them).
		 * @return {void}
		 */
		function renderTabs() {
			if (!context.tabs) return
			const shown = context.MODULES.filter((module) => context.modules[module])
			context.tabs.replaceChildren(...shown.map((module, index) => {
				const selected = module === context.currentTab
				const tab = context.el('button', { type: 'button', id: 'mcp-scope-tab-' + module, textContent: context.tabLabel(context.modules[module]), tabIndex: selected ? 0 : -1 })
				tab.setAttribute('role', 'tab')
				tab.setAttribute('aria-selected', String(selected))
				tab.setAttribute('aria-controls', 'mcp-scope-module-' + module)
				tab.addEventListener('click', () => context.selectTab(module, true))
				tab.addEventListener('keydown', (event) => {
					const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: shown.length - 1 }[event.key]
					if (next === undefined) return
					event.preventDefault?.()
					context.selectTab(shown[(next + shown.length) % shown.length], true)
				})
				return tab
			}))
		}

		/** Shows one module's section and marks its tab.
		 * @param {string} module catalog module ID
		 * @param {boolean} focus whether to focus the selected module tab
		 * @return {void}
		 */
		function selectTab(module, focus) {
			context.currentTab = module
			for (const other of context.MODULES) {
				const section = document.getElementById('mcp-scope-module-' + other)
				if (section) section.hidden = Boolean(context.tabs) && other !== module
			}
			context.renderTabs()
			if (focus) document.getElementById('mcp-scope-tab-' + module)?.focus?.()
		}

		/**
		 * Puts the open drawer right under the Nextcloud header. A drawer is position: fixed, but Nextcloud 35 blurs the content
		 * behind it (backdrop-filter on #content-vue), which makes that box, not the window, the one it is placed in: its top
		 * would then be counted from the box, one header too low. The offset is measured, not assumed, since a theme may turn
		 * the blur off.
		 * @return {void}
		 */
		function placeDrawer() {
			context.root.style?.removeProperty?.('top')
			const rect = context.root.getBoundingClientRect?.()
			if (!rect) return
			const header = document.getElementById('header')?.getBoundingClientRect?.().bottom ?? 0
			const shift = rect.top - header
			if (Math.abs(shift) >= 1) context.root.style.top = (parseFloat(getComputedStyle(context.root).top) - shift) + 'px'
		}

		/** Opens the drawer; the focus goes back to `trigger` when it closes.
		 * @param {HTMLElement|null|undefined} trigger control that opened the drawer or popover and may receive focus again
		 * @return {void}
		 */
		function openDrawer(trigger) {
			// Only one drawer is open at a time: the inspector closes when the scope opens.
			window.OCA.Mcp.Admin.inspector?.close?.()
			context.returnFocus = trigger || document.activeElement || null
			// The row of a "…" button that opened the drawer: the table may be drawn again meanwhile, and its new button is
			// the one that gets the focus back.
			context.returnRow = context.returnFocus?.closest?.('tr')?.dataset?.uid ?? null
			context.root.classList?.add('mcp-drawer-open')
			context.root.setAttribute('role', 'dialog')
			context.placeDrawer()
		}

		/** Whether a module has a draft that is not saved.
		 * @return {boolean} whether a module has a draft that is not saved */
		function hasDraft() {
			return Object.values(context.modules).some(context.dirty)
		}

		/** Drops the drafts the administrator agreed to lose, so they never come back when the drawer opens again.
		 * @return {void}
		 */
		function forget() {
			context.seq++
			context.uid = null
			context.modules = {}
			context.tabs?.replaceChildren()
			context.container.replaceChildren()
		}

		/**
		 * Closes the drawer, asking first when a draft of a module would be lost.
		 * @return {boolean} whether the drawer is closed now: false when the administrator kept the draft
		 */
		function closeDrawer() {
			if (!context.root.classList?.contains('mcp-drawer-open')) return true
			if (context.hasDraft()) {
				if (!window.confirm(t('mcp', 'Close the resource scope? The changes that are not saved are lost.'))) return false
				context.forget()
			}
			context.root.classList.remove('mcp-drawer-open')
			context.root.removeAttribute?.('role')
			context.root.style?.removeProperty?.('top')
			const target = context.returnFocus
			context.returnFocus = null
			context.focusBack(target)
			window.dispatchEvent?.(new Event('mcp-drawer-closed'))
			return true
		}

		/**
		 * Whether the drafts of the open user may be lost to open another one (or the same one read again): asked, never
		 * assumed.
		 * @return {boolean}
		 */
		function mayReplace() {
			return !context.hasDraft() || window.confirm(t('mcp', 'Open another person? The changes that are not saved for {user} are lost.', { user: context.uid }, undefined, { escape: false }))
		}

		/**
		 * Gives the focus back to the control that opened the drawer, or, when the table was drawn again and that control is
		 * gone, to the same row's "…" button, then to the drawer's own button on the Access tab: never to the page.
		 * @param {HTMLElement|null|undefined} target control to receive focus
		 * @return {void}
		 */
		function focusBack(target) {
			if (target && target.isConnected !== false) {
				target.focus?.()
				return
			}
			const rows = [...(document.getElementById('mcp-matrix')?.tBodies?.[0]?.rows || [])]
			const row = context.returnRow === null ? null : rows.find((candidate) => candidate.dataset.uid === context.returnRow)
			;(row?.querySelector('button.mcp-row-actions-button') || context.entryButton)?.focus?.()
		}

		Object.assign(context, { closeDrawer, focusBack, forget, hasDraft, mayReplace, openDrawer, placeDrawer, renderTabs, selectTab, tabLabel })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/scope/rendering.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the scope rendering unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/** Attach scope sections, selected resources and save, discard and undo controls.
	 * @param {object} context scope drawer controls, live module drafts and attached resource operations
	 * @return {void}
	 */
	admin.modules.scope.rendering = function (context) {
		/** Replace a module section, update its tab and optionally restore focus to a named control.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @param {string|undefined} focusId DOM ID to focus after the module is redrawn
		 * @return {void}
		 */
		function rerender(state, focusId) {
			const old = document.getElementById('mcp-scope-module-' + state.module)
			const fresh = context.renderModule(state)
			if (old) old.replaceWith(fresh)
			context.renderTabs()
			if (focusId) document.getElementById(focusId)?.focus?.()
		}

		/** Build one scope module section with mode choices, resource selection and persistence controls.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @return {HTMLElement}
		 */
		function renderModule(state) {
			const module = state.module
			const section = context.el('section', { id: 'mcp-scope-module-' + module, className: 'mcp-scope-module', hidden: Boolean(context.tabs) && module !== context.currentTab })
			const heading = context.el('h3', { id: 'mcp-scope-heading-' + module, textContent: context.title(module), tabIndex: -1 })
			section.setAttribute('aria-labelledby', heading.id)
			section.append(heading)
			if (state.stale) {
				section.append(context.el('p', { className: 'mcp-scope-inactive', textContent: t('mcp', 'Changed before the policy moved, so saving will be refused. Discard the changes to load the saved scope.') }))
			}
			if (!state.active) {
				section.append(context.el('p', { className: 'mcp-scope-inactive', textContent: t('mcp', 'Not available in this version: the tools of this module do not apply a scope yet, so nothing can be saved. You can still browse.') }))
			}
			const fieldset = context.el('fieldset', {}, [context.el('legend', { textContent: t('mcp', 'Resources the MCP tools of {user} may reach', { user: context.uid }, undefined, { escape: false }) })])
			;[['unrestricted', t('mcp', 'All resources')], ['restricted', t('mcp', 'Only selected')]].forEach(([value, text]) => {
				const id = 'mcp-scope-mode-' + module + '-' + value
				const radio = context.el('input', { type: 'radio', name: 'mcp-scope-mode-' + module, id, value, checked: state.mode === value })
				radio.addEventListener('change', () => {
					if (!radio.checked) return
					state.mode = value
					context.rerender(state, id)
				})
				fieldset.append(context.el('span', { className: 'mcp-scope-choice' }, [radio, context.el('label', { htmlFor: id, textContent: text })]))
			})
			section.append(fieldset)
			if (state.mode === 'restricted') {
				section.append(context.renderSelection(state), context.renderPicker(state))
			}
			// Nothing can be sent twice: Save, Discard and Undo wait for the answer of the request in flight.
			const save = context.el('button', { type: 'button', id: 'mcp-scope-save-' + module, textContent: t('mcp', 'Save'), disabled: state.saving || !state.active || !context.dirty(state) })
			save.addEventListener('click', () => context.saveModule(state))
			const discard = context.el('button', { type: 'button', id: 'mcp-scope-discard-' + module, textContent: t('mcp', 'Discard changes'), disabled: state.saving || (!context.dirty(state) && !state.stale) })
			discard.addEventListener('click', async () => {
				if (state.stale) {
					// A draft of an older revision: what is saved now comes from the server, not from the old copy.
					await context.reload(state).catch((error) => context.note(state, context.failure(error), true))
					context.rerender(state, 'mcp-scope-mode-' + module + '-' + state.mode)
					return
				}
				Object.assign(state, { mode: state.savedMode, items: [...state.savedItems] })
				context.rerender(state, 'mcp-scope-mode-' + module + '-' + state.mode)
			})
			const actions = context.el('div', { className: 'mcp-scope-actions' }, [save, discard])
			if (state.undo) {
				const offer = state.undo
				const undo = context.el('button', { type: 'button', id: 'mcp-scope-undo-' + module, textContent: t('mcp', 'Undo'), disabled: state.saving })
				undo.addEventListener('click', () => context.undoModule(state))
				// The ten seconds pause while the pointer or the focus is on the button (WCAG 2.2.1).
				const hold = (key, value) => () => {
					offer[key] = value
					if (!value) context.expireUndo(state, offer)
				}
				undo.addEventListener('mouseenter', hold('hover', true))
				undo.addEventListener('mouseleave', hold('hover', false))
				undo.addEventListener('focus', hold('focus', true))
				undo.addEventListener('blur', hold('focus', false))
				actions.append(undo)
			}
			section.append(actions)
			section.append(context.el('p', { id: 'mcp-scope-line-' + module, className: 'mcp-scope-line', textContent: state.line || '', tabIndex: -1 }))
			return section
		}

		/** Build the selected resource list with removal controls and accessible draft feedback.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @return {HTMLElement}
		 */
		function renderSelection(state) {
			const module = state.module
			const wrap = context.el('div', { className: 'mcp-scope-selection' })
			wrap.append(context.el('p', { textContent: state.items.length === 0 ? context.emptyText(module) : t('mcp', '{count} of at most {limit} selected', { count: state.items.length, limit: state.limit }, undefined, { escape: false }) }))
			if (state.items.length > 0) {
				const list = context.el('ul', { className: 'mcp-scope-list' })
				list.setAttribute('aria-label', t('mcp', 'Selected resources'))
				state.items.forEach((item, index) => {
					const remove = context.el('button', { type: 'button', id: 'mcp-scope-remove-' + module + '-' + item.id, textContent: t('mcp', 'Remove') })
					remove.setAttribute('aria-label', t('mcp', 'Remove {name}', { name: context.label(item) }, undefined, { escape: false }))
					remove.addEventListener('click', () => {
						state.items = state.items.filter((other) => other.id !== item.id)
						// The focus goes to the next item, else the previous one, else the mode choice.
						const next = state.items[index] || state.items[index - 1]
						context.rerender(state, next ? 'mcp-scope-remove-' + module + '-' + next.id : 'mcp-scope-mode-' + module + '-restricted')
					})
					list.append(context.el('li', {}, [context.el('span', { textContent: context.label(item) }), ...context.badges(item), remove]))
				})
				wrap.append(list)
			}
			return wrap
		}

		Object.assign(context, { renderModule, renderSelection, rerender })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/scope/browsing.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the scope browsing unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/** Attach candidate browsing, folder navigation and draft resource selection.
	 * @param {object} context scope drawer controls, live module drafts and attached resource operations
	 * @return {void}
	 */
	admin.modules.scope.browsing = function (context) {
		/** Build resource search, folder navigation, candidate results and paging controls.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @return {HTMLElement}
		 */
		function renderPicker(state) {
			const module = state.module
			const picker = context.el('div', { className: 'mcp-scope-picker' })
			const searchId = 'mcp-scope-search-' + module
			const search = context.el('input', { type: 'search', id: searchId, value: state.search, autocomplete: 'off', spellcheck: false })
			const go = context.el('button', { type: 'button', textContent: t('mcp', 'Search') })
			const run = () => { state.search = search.value; state.page = 1; state.results = null; context.browse(state, searchId) }
			go.addEventListener('click', run)
			search.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); run() } })
			picker.append(context.el('label', { htmlFor: searchId, textContent: module === 'files' ? t('mcp', 'Find a folder of the user') : t('mcp', 'Find a calendar or task list of the user') }), search, go)
			if (module === 'files') {
				const here = state.parents[state.parents.length - 1]
				picker.append(context.el('p', { className: 'mcp-scope-where', textContent: here ? t('mcp', 'In folder {path}', { path: here.path }, undefined, { escape: false }) : t('mcp', 'In the top folder of the user') }))
				if (here) {
					const up = context.el('button', { type: 'button', id: 'mcp-scope-up-files', textContent: t('mcp', 'Up one folder') })
					up.addEventListener('click', () => { state.parents.pop(); state.page = 1; state.results = null; context.browse(state, 'mcp-scope-up-files') })
					picker.append(up, context.addButton(state, here, t('mcp', 'Add this folder')))
				}
			}
			const results = context.el('ul', { className: 'mcp-scope-results' })
			results.setAttribute('aria-label', t('mcp', 'Resources found'))
			const count = context.el('p', { className: 'mcp-scope-count' })
			count.setAttribute('aria-live', 'polite')
			if (state.results === null) {
				count.textContent = t('mcp', 'Search, or press Search with an empty field to list everything.')
			} else {
				count.textContent = state.results.total === 0 ? t('mcp', 'Nothing found.') : t('mcp', '{shown} of {total} shown', { shown: state.results.items.length, total: state.results.total }, undefined, { escape: false })
				state.results.items.forEach((item) => {
					const row = context.el('li', {}, [context.el('span', { textContent: context.label(item) }), ...context.badges(item), context.addButton(state, item)])
					if (module === 'files') {
						const open = context.el('button', { type: 'button', textContent: t('mcp', 'Open') })
						open.setAttribute('aria-label', t('mcp', 'Open {name}', { name: context.label(item) }, undefined, { escape: false }))
						open.addEventListener('click', () => { state.parents.push(item); state.page = 1; state.results = null; state.search = ''; context.browse(state, searchId) })
						row.append(open)
					}
					results.append(row)
				})
				if (state.results.hasMore) {
					const more = context.el('button', { type: 'button', id: 'mcp-scope-more-' + module, textContent: t('mcp', 'Show more') })
					more.addEventListener('click', () => { state.page += 1; context.browse(state, 'mcp-scope-more-' + module, true) })
					picker.append(results, count, more)
					return picker
				}
			}
			picker.append(results, count)
			return picker
		}

		/** Create a resource selection button that enforces the module limit and redraws the draft.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @param {object} item resource ID, label or path and availability flags
		 * @param {string} [text] button label instead of the default selection text
		 * @return {HTMLButtonElement}
		 */
		function addButton(state, item, text) {
			const chosen = state.items.some((other) => other.id === item.id)
			const add = context.el('button', { type: 'button', textContent: text || (chosen ? t('mcp', 'Added') : t('mcp', 'Add')), disabled: chosen })
			add.setAttribute('aria-label', chosen ? t('mcp', '{name} is selected', { name: context.label(item) }, undefined, { escape: false }) : t('mcp', 'Add {name}', { name: context.label(item) }, undefined, { escape: false }))
			add.addEventListener('click', () => {
				if (state.items.length >= state.limit) {
					context.note(state, t('mcp', 'At most {limit} resources can be selected.', { limit: state.limit }, undefined, { escape: false }), true)
					context.rerender(state)
					return
				}
				state.items = [...state.items, { id: item.id, name: item.name, path: item.path, found: true, excluded: item.excluded }]
				context.note(state, t('mcp', '{name} added. Save to apply it.', { name: context.label(item) }, undefined, { escape: false }))
				context.rerender(state, 'mcp-scope-search-' + state.module)
			})
			return add
		}

		/** Fetch resource candidates, optionally append another page and redraw with the requested focus.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @param {string|undefined} focusId DOM ID to focus after the module is redrawn
		 * @param {boolean} [append=false] append this candidate page to the already displayed results
		 * @return {Promise<void>}
		 */
		async function browse(state, focusId, append = false) {
			const query = { search: state.search, page: String(state.page) }
			const here = state.parents[state.parents.length - 1]
			if (state.module === 'files' && here) query.parent = here.id
			try {
				const data = await context.api('GET', context.scopePath(state.module, '/candidates'), query)
				state.results = append && state.results ? Object.assign(data, { items: [...state.results.items, ...data.items] }) : data
				state.line = ''
			} catch (error) {
				state.results = null
				context.note(state, context.failure(error), true)
			}
			context.rerender(state, focusId)
		}

		Object.assign(context, { addButton, browse, renderPicker })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/scope/saving.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the scope saving unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/** Attach scope persistence, error recovery, undo offers and revision announcements.
	 * @param {object} context scope drawer controls, live module drafts and attached resource operations
	 * @return {void}
	 */
	admin.modules.scope.saving = function (context) {
		/** Save one scope draft against its read revision and refresh modules after a confirmed change.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @return {Promise<void>}
		 */
		async function saveModule(state) {
			if (state.saving) return
			// The revision this module's draft was read at, never one another module moved to since.
			const body = state.mode === 'unrestricted'
				? { expectedRevision: state.revision, mode: 'unrestricted' }
				: { expectedRevision: state.revision, mode: 'restricted', items: state.items.map((item) => item.id) }
			state.saving = true
			context.note(state, t('mcp', 'Saving…'))
			context.rerender(state)
			try {
				const result = await context.api('PUT', context.scopePath(state.module), null, body)
				state.saving = false
				context.announce(body.expectedRevision, result.revision)
				await context.reloadAll(state, result.revision)
				context.note(state, result.changed ? t('mcp', 'Saved. It applies from the next MCP call of the user; sessions are not ended.') : t('mcp', 'Nothing changed.'))
				if (result.changed && result.receiptId) context.offerUndo(state, result.receiptId, result.revision)
				context.rerender(state, 'mcp-scope-line-' + state.module)
				window.dispatchEvent(new Event('mcp-scope-saved'))
			} catch (error) {
				state.saving = false
				await context.saveFailed(state, error)
			}
		}

		/** Explain a refused or uncertain scope save and refresh only the state needed for recovery.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @param {Error & {status?: number, data?: object}} error request failure with optional HTTP status and response details
		 * @return {Promise<void>}
		 */
		async function saveFailed(state, error) {
			const data = error.data || {}
			if (error.message === 'unconfirmed') {
				context.note(state, t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.'), true)
				const line = state.line
				await context.reloadAll(state)
				state.line = line
			} else if (error.status === 409) {
				// Never written again from here: the admin sees what is saved now and decides.
				context.note(state, t('mcp', 'The policy changed after this scope was loaded, so nothing was saved. The saved scope is shown again.'), true)
				const line = state.line
				await context.reloadAll(state)
				state.line = line
			} else if (error.status === 422 && data.error === 'ScopeInactive') {
				state.active = false
				context.note(state, t('mcp', 'Not saved: the tools of this module do not apply a resource scope in this version.'), true)
			} else if (error.status === 422 && data.error === 'Inaccessible') {
				const missing = new Set(data.items || [])
				state.items = state.items.map((item) => missing.has(item.id) ? Object.assign({}, item, { inaccessible: true }) : item)
				context.note(state, t('mcp', 'Not saved: the user cannot reach the marked resources in Nextcloud. Remove them and save again.'), true)
			} else if (error.status === 422 && data.error === 'TooManyResources') {
				context.note(state, t('mcp', 'Not saved: at most {limit} resources can be selected.', { limit: data.limit || state.limit }, undefined, { escape: false }), true)
			} else if (error.status === 503 && data.uncertain) {
				context.note(state, t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.'), true)
				const line = state.line
				await context.reloadAll(state)
				state.line = line
			} else {
				context.note(state, context.failure(error), true)
			}
			context.rerender(state, 'mcp-scope-line-' + state.module)
		}

		/** Record a scope undo receipt and start its expiry timer without stealing focus.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @param {string} receiptId server receipt identifying the committed change
		 * @param {string} savedRevision revision returned for the committed change
		 * @return {void}
		 */
		function offerUndo(state, receiptId, savedRevision) {
			const offer = { receiptId, savedRevision, hover: false, focus: false, expired: false }
			state.undo = offer
			setTimeout(() => {
				offer.expired = true
				context.expireUndo(state, offer)
			}, context.UNDO_SECONDS * 1000)
		}

		/** Removes an expired undo once neither the pointer nor the focus holds it; the focus never falls to the page.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @param {{receiptId: string, savedRevision: string, hover: boolean, focus: boolean, expired: boolean}} offer the exact undo offer whose expiry is being checked
		 * @return {void}
		 */
		function expireUndo(state, offer) {
			if (state.undo !== offer || !offer.expired || offer.hover || offer.focus) return
			const focused = document.activeElement && document.activeElement.id === 'mcp-scope-undo-' + state.module
			state.undo = null
			context.rerender(state, focused ? 'mcp-scope-line-' + state.module : undefined)
		}

		/** Undo a scope receipt once, announce its revision and reload saved scopes after the attempt.
		 * @param {object} state live scope module draft, saved values, revision and persistence state
		 * @return {Promise<void>}
		 */
		async function undoModule(state) {
			if (state.saving) return
			const { receiptId, savedRevision } = state.undo
			state.undo = null
			state.saving = true
			context.rerender(state)
			let current
			try {
				current = (await context.api('POST', '/api/grants/undo', null, { receiptId, expectedRevision: savedRevision })).revision
				context.announce(savedRevision, current)
				context.note(state, t('mcp', 'Change undone.'))
				window.dispatchEvent(new Event('mcp-scope-saved'))
			} catch (error) {
				context.note(state, error.message === 'unconfirmed'
					? t('mcp', 'The save could not be confirmed: it may have been applied. The saved scope is shown again; check it before changing it.')
					: error.status === 409
						? t('mcp', 'The policy changed after this change, so it can no longer be undone.')
						: error.status === 410
							? t('mcp', 'This change can no longer be undone: the time to undo it has passed.')
							: context.failure(error), true)
			}
			state.saving = false
			const line = state.line
			await context.reloadAll(state, current)
			state.line = line
			context.rerender(state, 'mcp-scope-line-' + state.module)
		}

		/**
		 * Tells the other blocks of the page that a write of this panel moved the revision from `from` to `to`.
		 * @param {string} from revision the write was sent with
		 * @param {string} to revision the server answered with
		 * @return {void}
		 */
		function announce(from, to) {
			if (typeof from === 'string' && typeof to === 'string' && from !== to) {
				window.dispatchEvent(new CustomEvent(context.REVISION_MOVED, { detail: { from, to } }))
			}
		}

		Object.assign(context, { announce, expireUndo, offerUndo, saveFailed, saveModule, undoModule })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/scope/entry.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Resource scope public entry and original listener registration.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/** Publish scope entry points and register revision and drawer interaction handlers.
	 * @param {object} context scope drawer controls, live module drafts and attached resource operations
	 * @return {void}
	 */
	admin.modules.scope.entry = function (context) {
		/**
		 * The matrix cell mark of a module that is restricted, or whose scope cannot be read: visible text, no colour or
		 * icon alone, and a way into the panel. An unrestricted module gets no mark.
		 * @param {string} target uid of the row
		 * @param {string} module scope module
		 * @param {{mode: string, count: ?number}} scope the cell's scope from the matrix
		 * @return {HTMLButtonElement|null}
		 */
		function indicator(target, module, scope) {
			const unknown = scope.mode === 'unavailable'
			if (!unknown && scope.mode !== 'restricted') return null
			const mark = context.el('span', { className: 'mcp-scope-mark', textContent: unknown ? '?' : '◩' })
			mark.setAttribute('aria-hidden', 'true')
			const button = context.el('button', { type: 'button', className: 'mcp-scope-indicator' }, [mark, ' ' + (unknown ? t('mcp', 'Scope unknown') : t('mcp', 'Restricted to {count}', { count: scope.count }, undefined, { escape: false }))])
			button.title = unknown
				? t('mcp', 'The scope cannot be read now, so the tools of this module refuse until it can. Open the resource scope.')
				: t('mcp', 'The MCP tools of this user reach only the selected resources of this module. Open the resource scope.')
			button.addEventListener('click', () => open(target, module, button))
			return button
		}

		/**
		 * Opens the drawer on one user, at one module when given.
		 * @param {string} target user ID
		 * @param {string} [module] module whose tab opens
		 * @param {HTMLElement} [trigger] control the focus goes back to when the drawer closes
		 * @return {boolean}
		 */
		function open(target, module, trigger) {
			if (!context.root) return false
			// The same person in the open drawer: only the tab changes, and the draft stays.
			if (context.root.classList?.contains('mcp-drawer-open') && target === context.uid && Object.keys(context.modules).length > 0) {
				if (module) context.selectTab(module, true)
				return true
			}
			// The drawer is not modal: another person can be opened from the matrix while a draft is open.
			if (!context.mayReplace()) {
				trigger?.focus?.()
				return false
			}
			context.openDrawer(trigger)
			context.userInput.value = target
			context.drawerTitle?.focus?.()
			context.openUser(target, module)
			return true
		}

		window.OCA.Mcp.Admin.scope = { indicator, open, close: context.closeDrawer }
		// A module exactly at the revision a write of this page committed from takes the one it made; its draft stays.
		window.addEventListener(context.REVISION_MOVED, (event) => {
			for (const state of Object.values(context.modules)) {
				if (state.revision === event.detail.from) state.revision = event.detail.to
			}
		})
		// A matrix drawn before this script ran is drawn again, now with the scope marks.
		window.OCA.Mcp.Admin.matrix?.refresh?.()
		if (!context.root) return
		context.entryButton?.addEventListener('click', () => {
			context.openDrawer(context.entryButton)
			context.userInput.focus?.()
		})
		context.closeButton?.addEventListener('click', () => context.closeDrawer())
		context.root.addEventListener('keydown', (event) => {
			if (event.key === 'Escape' && context.root.classList?.contains('mcp-drawer-open')) {
				event.preventDefault?.()
				context.closeDrawer()
			}
		})
		context.form.addEventListener('submit', (event) => {
			event.preventDefault()
			const target = context.userInput.value.trim()
			if (target === '') {
				context.say(t('mcp', 'Enter the user ID.'), true)
				return
			}
			if (!context.mayReplace()) return
			context.openUser(target, null)
		})

		Object.assign(context, { indicator, open })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/scope/start.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the scope editor and start its original effects once.
 * @param {object} admin shared OCA.Mcp.Admin registry and public surfaces
 * @return {void}
 */
(function (admin) {
	'use strict'

	const context = {}
	admin.sessions.scope = context
	admin.modules.scope.session(context)
	admin.modules.scope.loading(context)
	admin.modules.scope.drawer(context)
	admin.modules.scope.rendering(context)
	admin.modules.scope.browsing(context)
	admin.modules.scope.saving(context)
	admin.modules.scope.entry(context)
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/inspector/session.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the inspector session unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/** Expose inspector controls, live request sequences and access result state.
	 * @param {object} context inspector controls, live request sequences and attached presentation operations
	 * @return {void}
	 */
	admin.modules.inspector.session = function (context) {
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

		const asked = { calendars: 0, path: 0, calendar: 0 }

		/** Create a DOM element, apply its properties and append its child nodes or text.
		 * @param {string} tag HTML element tag name
		 * @param {object} [props] DOM properties assigned to the element
		 * @param {Array<Node|string>} [children] child nodes and text appended in order
		 * @return {HTMLElement}
		 */
		function el(tag, props = {}, children = []) {
			const node = document.createElement(tag)
			Object.assign(node, props)
			node.append(...children)
			return node
		}

		/** Write inspection feedback into the existing status or alert live region.
		 * @param {string} message feedback text; an empty string clears the message
		 * @param {boolean} [error=false] announce through the alert region instead of the status region
		 * @return {void}
		 */
		function say(message, error = false) {
			status.textContent = ''
			alert.textContent = ''
			;(error ? alert : status).textContent = message
		}

		/** Fetch a JSON API response and reject unsuccessful requests with their response details.
		 * @param {string} path API path below /apps/mcp
		 * @param {Object<string, string>|null|undefined} query URL query parameters when present
		 * @return {Promise<object>}
		 */
		async function get(path, query) {
			const response = await fetch(OC.generateUrl('/apps/mcp' + path) + '?' + new URLSearchParams(query), {
				headers: { Accept: 'application/json', requesttoken: OC.requestToken },
			})
			const data = await response.json().catch(() => ({}))
			if (!response.ok) throw Object.assign(new Error(String(response.status)), { status: response.status, data })
			return data
		}

		/** Translate a failed resource request into an administrator-facing explanation.
		 * @param {Error & {status?: number, data?: object}} error request failure with optional HTTP status and response details
		 * @return {string}
		 */
		function failure(error) {
			if (error.status === 403) return t('mcp', 'Only administrators can inspect access.')
			if (error.status === 400) return t('mcp', 'Check the user ID and the value entered, then try again.')
			if (error.status === 503) return t('mcp', 'The MCP policy cannot be read right now, so nothing can be confirmed. Try again shortly.')
			return t('mcp', 'The inspection failed. Try again.')
		}

		/** Check whether a resource response belongs to an older request or a different inspected user.
		 * @param {string} kind resource request channel whose sequence is checked
		 * @param {number} mine sequence captured when this request began
		 * @param {string} user user ID captured when the resource request began
		 * @return {boolean}
		 */
		function stale(kind, mine, user) {
			return mine !== asked[kind] || user !== inspected
		}

		Object.defineProperties(context, {
			form: { configurable: true, get: () => form },
			user: { configurable: true, get: () => user },
			status: { configurable: true, get: () => status },
			alert: { configurable: true, get: () => alert },
			access: { configurable: true, get: () => access },
			resources: { configurable: true, get: () => resources },
			pathForm: { configurable: true, get: () => pathForm },
			pathInput: { configurable: true, get: () => pathInput },
			pathResult: { configurable: true, get: () => pathResult },
			calendarForm: { configurable: true, get: () => calendarForm },
			calendarModule: { configurable: true, get: () => calendarModule },
			calendarSelect: { configurable: true, get: () => calendarSelect },
			calendarOperation: { configurable: true, get: () => calendarOperation },
			calendarResult: { configurable: true, get: () => calendarResult },
			inspected: { configurable: true, get: () => inspected, set: (value) => { inspected = value } },
			catalog: { configurable: true, get: () => catalog, set: (value) => { catalog = value } },
			seq: { configurable: true, get: () => seq, set: (value) => { seq = value } },
			kinds: { configurable: true, get: () => kinds, set: (value) => { kinds = value } },
			shown: { configurable: true, get: () => shown, set: (value) => { shown = value } },
			asked: { configurable: true, get: () => asked },
		})

		Object.assign(context, { el, say, get, failure, stale })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/inspector/wording.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the inspector wording unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/** Attach translated access decisions, blocking gates and inheritance explanations.
	 * @param {object} context inspector controls, live request sequences and attached presentation operations
	 * @return {void}
	 */
	admin.modules.inspector.wording = function (context) {
		/** Resolve the translated module label, falling back to its catalog ID.
		 * @param {string} module catalog module ID
		 * @return {string}
		 */
		function moduleName(module) {
			return { files: t('mcp', 'Files'), notes: t('mcp', 'Notes'), deck: t('mcp', 'Deck'), calendar: t('mcp', 'Calendar'), contacts: t('mcp', 'Contacts'), tasks: t('mcp', 'Tasks'), talk: t('mcp', 'Talk'), people: t('mcp', 'People'), logs: t('mcp', 'Server log'), mail: t('mcp', 'Mail'), tables: t('mcp', 'Tables'), forms: t('mcp', 'Forms'), polls: t('mcp', 'Polls') }[module] || module
		}

		/** Resolve the translated operation label, falling back to its catalog ID.
		 * @param {string} operation catalog operation ID
		 * @return {string}
		 */
		function operationName(operation) {
			return { ...window.OCA.Mcp.Admin.pollsGrants?.operationLabels(), schema: t('mcp', 'schema'), results: t('mcp', 'results'), read: t('mcp', 'read'), edit: t('mcp', 'edit'), create: t('mcp', 'create'), move: t('mcp', 'move'), delete: t('mcp', 'delete'), transfer: t('mcp', 'transfer'), reply: t('mcp', 'reply'), attach: t('mcp', 'attach'), quote: t('mcp', 'quote'), share: t('mcp', 'share'), link: t('mcp', 'link'), restore: t('mcp', 'restore'), messages: t('mcp', 'messages'), attachments: t('mcp', 'attachments'), flags: t('mcp', 'flags'), draft: t('mcp', 'draft'), send: t('mcp', 'send') }[operation] || operation
		}

		/** The first check that blocks, by the code of the effective access (EffectiveResult::blockingGate).
		 * @param {string} gate effective access blocking gate code
		 * @return {string}
		 */
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
		 * @param {object} row grant row returned by the API
		 * @return {string}
		 */
		function grantText(row) {
			const kind = context.kinds?.get(row.module + '/' + row.operation)
			if (kind?.kind === 'redundant' && kind.after) {
				return context.sourceText(kind.after) + ' — ' + t('mcp', 'also set for this person, with the same value')
			}
			return context.sourceText(row.grant)
		}

		/** Describe a grant's source and contributing group IDs.
		 * @param {object} grant grant source, allowed value and contributing groups
		 * @return {string}
		 */
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

		/** Translate an allowed, blocked or unknown effective access decision.
		 * @param {boolean|null} allowed effective access decision, null when it cannot be confirmed
		 * @return {string}
		 */
		function resultText(allowed) {
			return allowed === true ? t('mcp', 'Allowed') : allowed === false ? t('mcp', 'Blocked') : t('mcp', 'Unknown')
		}

		/** What a path or calendar answer means, from its codes (AccessInspector, CalendarAccessExplainer).
		 * @param {object} answer resource explanation returned by the inspector
		 * @return {string}
		 */
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
			case 'blocked': return t('mcp', 'Not reached: {reason}', { reason: context.gateText(answer.gate) })
			case 'out_of_scope': return t('mcp', 'Not reached: outside the resource scope of this user.')
			case 'not_found': return t('mcp', 'Not reached: the path does not exist in the files of this user.')
			case 'no_native_access': return t('mcp', 'Not reached: the user has no access to it in Nextcloud.')
			case 'read_only': return t('mcp', 'Not reached for this operation: the user can only read it in Nextcloud.')
			case 'excluded': return exclusions[answer.exclusion] || t('mcp', 'Not reached: it is excluded from MCP access.')
			case 'not_evaluated': return t('mcp', 'Not confirmed: {check} could not be checked, so access is not known.', { check: checks[answer.gate] || answer.gate })
			default: return answer.reason
			}
		}

		Object.assign(context, { moduleName, operationName, gateText, grantText, sourceText, resultText, explanation })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/inspector/verdict.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the inspector verdict unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/** Attach general blocking verdicts and the matching administrator remedies.
	 * @param {object} context inspector controls, live request sequences and attached presentation operations
	 * @return {void}
	 */
	admin.modules.inspector.verdict = function (context) {
		const GENERAL = ['account', 'service', 'eligibility', 'connection']

		/**
		 * The general check that blocks every operation, or null when none does: every operation must be blocked by it.
		 * @param {Array<object>} operations the operations of the answer
		 * @return {string|null} a gate of GENERAL, or eligibility_unknown
		 */
		function generalBlock(operations) {
			const gates = new Set(operations.map((row) => row.blockingGate))
			if (operations.length === 0 || gates.size !== 1) return null
			const [gate] = gates
			return GENERAL.includes(gate) || gate === 'eligibility_unknown' ? gate : null
		}

		/** What to do about a general check that blocks, when the administrator or the person can do something.
		 * @param {string} gate effective access blocking gate code
		 * @return {string} translated remedy, or an empty string when none applies
		 */
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
		 * @param {Array<object>} operations the operations of the answer, not empty
		 * @return {string} usable, unknown, none_granted (every one blocked by its grant) or none
		 */
		function outcome(operations) {
			if (operations.some((row) => row.allowed === true)) return 'usable'
			if (operations.some((row) => row.allowed !== false)) return 'unknown'
			return operations.every((row) => row.blockingGate === 'grant') ? 'none_granted' : 'none'
		}

		/** The verdict and the four general checks: passed, the one that blocks, or not checked after it.
		 * @param {object[]} operations effective access rows returned by the inspector
		 * @return {HTMLElement[]}
		 */
		function renderVerdict(operations) {
			// An answer without operations confirms nothing, not even the general checks.
			if (operations.length === 0) {
				return [context.el('p', { className: 'mcp-inspector-verdict', textContent: t('mcp', 'No operation was answered, so nothing can be said about {user}.', { user: context.inspected }, undefined, { escape: false }) })]
			}
			const block = context.generalBlock(operations)
			const failed = block === 'eligibility_unknown' ? 'eligibility' : block
			const index = failed === null ? GENERAL.length : GENERAL.indexOf(failed)
			const names = {
				account: t('mcp', 'Account active'),
				service: t('mcp', 'MCP service on'),
				eligibility: t('mcp', 'Can connect'),
				connection: t('mcp', 'Personal connection on'),
			}
			const checks = context.el('ul', { className: 'mcp-inspector-checks' }, GENERAL.map((gate, i) => {
				const state = i < index ? 'passed' : i > index ? 'skipped' : block === 'eligibility_unknown' ? 'unknown' : 'failed'
				const mark = { passed: '✓', failed: '✕', unknown: '?', skipped: '—' }[state]
				const word = { passed: t('mcp', 'passed'), failed: t('mcp', 'blocks'), unknown: t('mcp', 'not confirmed'), skipped: t('mcp', 'not checked') }[state]
				const symbol = context.el('span', { className: 'mcp-inspector-mark', textContent: mark })
				symbol.setAttribute('aria-hidden', 'true')
				return context.el('li', { className: 'mcp-inspector-check-' + state }, [symbol, ' ', names[gate] + ': ' + word])
			}))
			if (block === null) {
				const result = context.outcome(operations)
				if (result === 'usable') {
					return [context.el('p', { className: 'mcp-inspector-verdict mcp-inspector-allowed', textContent: t('mcp', '{user} can use the MCP tools allowed below: the general checks passed.', { user: context.inspected }, undefined, { escape: false }) }), checks]
				}
				const text = {
					unknown: t('mcp', 'Not confirmed: no operation of {user} is allowed for sure now, and some cannot be confirmed.', { user: context.inspected }, undefined, { escape: false }),
					none_granted: t('mcp', '{user} cannot use any MCP tool: nothing is granted.', { user: context.inspected }, undefined, { escape: false }),
					none: t('mcp', '{user} cannot use any MCP tool: every operation is blocked, as each module below says.', { user: context.inspected }, undefined, { escape: false }),
				}[result]
				const said = context.el('div', { className: 'mcp-note ' + (result === 'unknown' ? 'mcp-note-warning' : 'mcp-note-error') }, [context.el('p', { textContent: text })])
				said.setAttribute('role', 'status')
				return [said, checks]
			}
			const note = context.el('div', { className: 'mcp-note ' + (block === 'eligibility_unknown' ? 'mcp-note-warning' : 'mcp-note-error') }, [
				context.el('p', {}, [context.el('strong', { textContent: t('mcp', '{user} cannot use any MCP tool.', { user: context.inspected }, undefined, { escape: false }) }), ' ' + context.gateText(block) + (context.remedy(block) ? ' ' + context.remedy(block) : '')]),
			])
			note.setAttribute('role', 'status')
			return [note, checks]
		}

		Object.defineProperties(context, {
			GENERAL: { configurable: true, get: () => GENERAL },
		})

		Object.assign(context, { generalBlock, remedy, outcome, renderVerdict })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/inspector/rendering.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the inspector rendering unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/** Attach effective access tables, module summaries and individual grant explanations.
	 * @param {object} context inspector controls, live request sequences and attached presentation operations
	 * @return {void}
	 */
	admin.modules.inspector.rendering = function (context) {
		/**
		 * One line per module: the level the grants give, where it comes from, the resource scope and what blocks it. While a
		 * general check blocks everything, the lines say what will apply once it is fixed, without repeating it on each.
		 * @param {object[]} operations effective access rows returned by the inspector
		 * @return {HTMLElement}
		 */
		function renderModules(operations) {
			const general = context.generalBlock(operations) !== null
			const levels = window.OCA.Mcp.Admin.levels
			const byModule = new Map()
			for (const row of operations) {
				if (!byModule.has(row.module)) byModule.set(row.module, [])
				byModule.get(row.module).push(row)
			}
			const levelName = { ...window.OCA.Mcp.Admin.pollsGrants?.levelLabels(), forms_write: t('mcp', 'Read and write forms'), forms_write_results: t('mcp', 'Read and write forms and read responses'), none: t('mcp', 'None'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom'), unknown: t('mcp', 'Unknown') }
			const items = [...byModule.entries()].map(([module, rows]) => {
				const operationList = rows.map((row) => row.operation)
				const values = Object.fromEntries(rows.map((row) => [row.operation, row.grant ? row.grant.allowed : row.allowed]))
				const level = levels ? levels.levelOf(module, operationList, values) : null
				const gates = new Set(rows.map((row) => row.blockingGate).filter((gate) => gate !== null && gate !== 'grant'))
				const blocked = !general && rows.every((row) => row.allowed === false) && gates.size === 1 ? [...gates][0] : null
				const origin = levels ? levels.originOf(operationList, Object.fromEntries(rows.map((row) => [row.operation, row.grant]))) : null
				const source = origin === null ? '' : origin.source === 'individual' ? context.individualText(rows) : {
					invalid: t('mcp', 'Invalid stored value (denied)'),
					group: t('mcp', 'Group: {groups}', { groups: origin.groupIds.join(', ') }),
					unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') }),
					default: t('mcp', 'Default'),
				}[origin.source]
				const scope = rows[0].scope && rows[0].scope.mode === 'restricted' ? t('mcp', 'Restricted to {count}', { count: rows[0].scope.count }) : ''
				const state = blocked !== null
					? context.el('span', { className: 'mcp-inspector-blocked', textContent: t('mcp', 'Blocked') })
					: context.el('span', { className: level === 'unknown' ? 'mcp-inspector-unknown' : 'mcp-inspector-level', textContent: level === null ? '' : levelName[level] || level })
				const detail = [blocked !== null ? context.gateText(blocked) : source, scope].filter((text) => text !== '').join(' — ')
				return context.el('li', { className: 'mcp-inspector-module' }, [context.el('span', { className: 'mcp-inspector-module-name', textContent: context.moduleName(module) }), state, context.el('span', { className: 'mcp-hint', textContent: detail })])
			})
			const list = context.el('ul', { className: 'mcp-inspector-modules' }, items)
			return general ? context.el('div', {}, [context.el('p', { className: 'mcp-hint', textContent: t('mcp', 'What each module grants, once the general check is fixed:') }), list]) : list
		}

		/**
		 * Where a module's individual values come from, as the matrix says it: an exception when one of them differs from what
		 * the person inherits, what they repeat when every one only repeats it, and "set for this person" until that is known.
		 * @param {object[]} rows effective access rows for one module
		 * @return {string}
		 */
		function individualText(rows) {
			const individual = rows.filter((row) => row.grant?.source === 'individual_allow' || row.grant?.source === 'individual_deny')
			const known = individual.map((row) => context.kinds?.get(row.module + '/' + row.operation))
			if (context.kinds === null || known.some((kind) => !kind)) return t('mcp', 'Set for this person')
			if (known.some((kind) => kind.kind === 'exception')) return t('mcp', 'Exception: differs from what the person inherits')
			const repeated = Object.fromEntries(rows.map((row) => {
				const index = individual.indexOf(row)
				return [row.operation, index < 0 ? row.grant : known[index].after || { source: 'default', groupIds: [] }]
			}))
			const origin = window.OCA.Mcp.Admin.levels.originOf(rows.map((row) => row.operation), repeated)
			const text = {
				group: t('mcp', 'Group: {groups}', { groups: origin.groupIds.join(', ') }),
				unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') }),
			}[origin.source] || t('mcp', 'Default')
			return text + ' — ' + t('mcp', 'also set for this person, with the same value')
		}

		/** Store and draw the inspected access result, catalog, verdict and operation details.
		 * @param {object} data settings or access response returned by the API
		 * @return {void}
		 */
		function renderAccess(data) {
			context.shown = data
			context.catalog = {}
			const rows = data.operations.map((row) => {
				;(context.catalog[row.module] = context.catalog[row.module] || []).push(row.operation)
				const scope = row.scope && row.scope.mode === 'restricted' ? ' · ' + t('mcp', 'Restricted to {count}', { count: row.scope.count }) : ''
				return context.el('tr', {}, [
					context.el('th', { scope: 'row', textContent: context.moduleName(row.module) + ': ' + context.operationName(row.operation) }),
					context.el('td', { textContent: context.resultText(row.allowed), className: 'mcp-inspector-' + (row.allowed === true ? 'allowed' : row.allowed === false ? 'blocked' : 'unknown') }),
					context.el('td', { textContent: row.blockingGate === null ? '—' : context.gateText(row.blockingGate) }),
					context.el('td', { textContent: context.grantText(row) + scope }),
				])
			})
			const head = context.el('tr', {}, [t('mcp', 'Operation'), t('mcp', 'Result'), t('mcp', 'First check that blocks'), t('mcp', 'Grant')].map((text) => context.el('th', { scope: 'col', textContent: text })))
			const table = context.el('table', { className: 'mcp-inspector-table' }, [context.el('caption', { textContent: t('mcp', 'Effective access of {user}', { user: context.inspected }) }), context.el('thead', {}, [head]), context.el('tbody', {}, rows)])
			context.access.replaceChildren(...context.renderVerdict(data.operations), context.renderModules(data.operations),
				context.el('details', { className: 'mcp-inspector-all' }, [context.el('summary', { textContent: t('mcp', 'Every operation') }), table]))
		}

		Object.assign(context, { renderModules, individualText, renderAccess })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/inspector/inspection.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the inspector inspection unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/** Attach access inspection, inherited value classification and resource explanation requests.
	 * @param {object} context inspector controls, live request sequences and attached presentation operations
	 * @return {void}
	 */
	admin.modules.inspector.inspection = function (context) {
		/**
		 * Asks what the individual values of the answer on screen repeat, then draws it again; an answer for another person
		 * or another inspection is dropped.
		 * @param {object} data effective access response being classified
		 * @param {number} mine inspection sequence captured before the read
		 * @return {Promise<void>}
		 */
		async function classifyShown(data, mine) {
			const exceptions = window.OCA.Mcp.Admin.exceptions
			if (!exceptions?.classifyPerson || typeof data.revision !== 'string') return
			const cells = data.operations.map((row) => ({ module: row.module, operation: row.operation, allowed: row.grant?.allowed ?? null, source: row.grant?.source }))
			if (!cells.some((cell) => cell.source === 'individual_allow' || cell.source === 'individual_deny')) return
			try {
				const answer = await exceptions.classifyPerson(context.inspected, cells, data.revision)
				if (mine !== context.seq || context.shown !== data) return
				context.kinds = answer
			} catch (e) {
				return
			}
			// Drawn again in place: the table of every operation stays open, and a focused summary keeps the focus.
			const details = context.access.querySelector?.('details.mcp-inspector-all')
			const wasOpen = Boolean(details?.open)
			const hadFocus = document.activeElement !== undefined && document.activeElement === details?.querySelector?.('summary')
			context.renderAccess(data)
			const again = context.access.querySelector?.('details.mcp-inspector-all')
			if (again && wasOpen) again.open = true
			if (hadFocus) again?.querySelector?.('summary')?.focus?.()
		}

		/** Read effective access for the entered user and initialize its resource inspection controls.
		 * @return {Promise<void>}
		 */
		async function inspect() {
			const uid = context.user.value.trim()
			if (uid === '') {
				context.say(t('mcp', 'Enter the user ID to inspect.'), true)
				return
			}
			const mine = ++context.seq
			context.access.setAttribute('aria-busy', 'true')
			context.say(t('mcp', 'Inspecting…'))
			try {
				const data = await context.get('/api/inspector/access', { uid })
				if (mine !== context.seq) return
				context.inspected = uid
				context.kinds = null
				context.renderAccess(data)
				context.classifyShown(data, mine)
				context.resources.hidden = false
				context.pathResult.textContent = ''
				context.calendarResult.textContent = ''
				await context.loadCalendars()
				context.say(t('mcp', 'Access of {user} as the tools see it now.', { user: uid }))
			} catch (error) {
				if (mine !== context.seq) return
				context.inspected = null
				context.kinds = null
				context.shown = null
				context.access.replaceChildren()
				context.resources.hidden = true
				context.say(context.failure(error), true)
			} finally {
				if (mine === context.seq) context.access.setAttribute('aria-busy', 'false')
			}
		}

		/** Load calendars or task lists for the current user while discarding stale responses.
		 * @return {Promise<void>}
		 */
		async function loadCalendars() {
			const module = context.calendarModule.value
			const mine = ++context.asked.calendars
			const user = context.inspected
			context.calendarOperation.replaceChildren(...(context.catalog[module] || ['read']).map((operation) => context.el('option', { value: operation, textContent: context.operationName(operation) })))
			context.calendarSelect.replaceChildren()
			try {
				const data = await context.get('/api/scope/' + encodeURIComponent(user) + '/' + module + '/candidates', {})
				if (context.stale('calendars', mine, user) || module !== context.calendarModule.value) return
				context.calendarSelect.replaceChildren(...data.items.map((item) => context.el('option', { value: item.id, textContent: item.name })))
				if (data.items.length === 0) context.calendarResult.textContent = t('mcp', 'This user has no calendar or task list of this kind in Nextcloud.')
			} catch (error) {
				if (context.stale('calendars', mine, user)) return
				context.say(context.failure(error), true)
			}
		}

		/** Read and present one resource access explanation if its user and request are still current.
		 * @param {"path"|"calendar"} kind resource request sequence channel
		 * @param {HTMLElement} target result element receiving the explanation
		 * @param {string} path API path below /apps/mcp
		 * @param {Object<string, string>} query user, resource and operation query parameters
		 * @param {string} module catalog module ID
		 * @return {Promise<void>}
		 */
		async function explain(kind, target, path, query, module) {
			const mine = ++context.asked[kind]
			const user = context.inspected
			target.textContent = t('mcp', 'Checking…')
			try {
				const answer = await context.get(path, Object.assign({ uid: user }, query))
				if (context.stale(kind, mine, user)) return
				target.textContent = context.explanation(answer)
				if (answer.reason === 'out_of_scope' && window.OCA.Mcp.Admin.scope) {
					const change = context.el('button', { type: 'button', textContent: t('mcp', 'Open the resource scope') })
					change.addEventListener('click', () => window.OCA.Mcp.Admin.scope.open(user, module))
					target.append(' ', change)
				}
			} catch (error) {
				if (context.stale(kind, mine, user)) return
				// An error is announced like every other error of the inspector, in its alert region.
				target.textContent = ''
				context.say(context.failure(error), true)
			}
		}

		Object.assign(context, { classifyShown, inspect, loadCalendars, explain })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/inspector/drawer.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the inspector drawer unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.inspector = admin.modules.inspector || {}
	/** Attach inspector placement, opening, closing and focus restoration.
	 * @param {object} context inspector controls, live request sequences and attached presentation operations
	 * @return {void}
	 */
	admin.modules.inspector.drawer = function (context) {
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
		 * @return {void}
		 */
		function placeDrawer() {
			context.root.style?.removeProperty?.('top')
			const rect = context.root.getBoundingClientRect?.()
			if (!rect) return
			const header = document.getElementById('header')?.getBoundingClientRect?.().bottom ?? 0
			const shift = rect.top - header
			if (Math.abs(shift) >= 1) context.root.style.top = (parseFloat(getComputedStyle(context.root).top) - shift) + 'px'
		}

		/**
		 * Opens the drawer; the focus goes back to `trigger` when it closes. Only one drawer is open at a time: when the
		 * administrator keeps a draft of the resource scope, its drawer stays and this one does not open.
		 * @return {boolean} whether the drawer opened
		 * @param {HTMLElement|null|undefined} trigger control that opened the drawer or popover and may receive focus again
		 */
		function openDrawer(trigger) {
			if (window.OCA.Mcp.Admin.scope?.close?.() === false) {
				trigger?.focus?.()
				return false
			}
			returnFocus = trigger || document.activeElement || null
			// The row of a "…" button that opened the drawer: the table may be drawn again meanwhile, and its new button is
			// the one that gets the focus back.
			returnRow = returnFocus?.closest?.('tr')?.dataset?.uid ?? null
			context.root.classList?.add('mcp-drawer-open')
			context.root.setAttribute('role', 'dialog')
			context.placeDrawer()
			return true
		}

		/**
		 * Closes the drawer and gives the focus back. The inspector only reads, so nothing can be lost.
		 * @return {boolean} always true: the drawer is closed now
		 */
		function closeDrawer() {
			if (!context.root.classList?.contains('mcp-drawer-open')) return true
			context.root.classList.remove('mcp-drawer-open')
			context.root.removeAttribute?.('role')
			context.root.style?.removeProperty?.('top')
			const target = returnFocus
			returnFocus = null
			context.focusBack(target)
			window.dispatchEvent?.(new Event('mcp-drawer-closed'))
			return true
		}

		/**
		 * Gives the focus back to the control that opened the drawer, or, when the table was drawn again and that control is
		 * gone, to the same row's "…" button, then to the drawer's own button on the Access tab: never to the page.
		 * @param {HTMLElement|null|undefined} target control to receive focus
		 * @return {void}
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

		Object.defineProperties(context, {
			drawerTitle: { configurable: true, get: () => drawerTitle },
			closeButton: { configurable: true, get: () => closeButton },
			entryButton: { configurable: true, get: () => entryButton },
			returnFocus: { configurable: true, get: () => returnFocus, set: (value) => { returnFocus = value } },
			returnRow: { configurable: true, get: () => returnRow, set: (value) => { returnRow = value } },
		})

		Object.assign(context, { placeDrawer, openDrawer, closeDrawer, focusBack })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/inspector/start.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Compose the inspector editor and start its original effects once.
 * @param {object} admin shared OCA.Mcp.Admin registry and public surfaces
 * @return {void}
 */
(function (admin) {
	'use strict'
	const root = document.getElementById('mcp-inspector')
	if (!root) return

	const context = { root }
	admin.sessions.inspector = context
	admin.modules.inspector.session(context)
	admin.modules.inspector.wording(context)
	admin.modules.inspector.verdict(context)
	admin.modules.inspector.rendering(context)
	admin.modules.inspector.inspection(context)
	context.form.addEventListener('submit', (event) => { event.preventDefault(); context.inspect() })
	admin.modules.inspector.drawer(context)
	context.entryButton?.addEventListener('click', () => {
		if (context.openDrawer(context.entryButton)) context.user.focus?.()
	})
	context.closeButton?.addEventListener('click', () => context.closeDrawer())
	context.root.addEventListener?.('keydown', (event) => {
		if (event.key === 'Escape' && context.root.classList?.contains('mcp-drawer-open')) {
			event.preventDefault?.()
			context.closeDrawer()
		}
	})

	// The actions menu of a matrix row inspects that user (src/admin/admin-row-actions.js), in the drawer.
	window.OCA.Mcp.Admin.inspector = {
		open(target, trigger) {
			if (!context.openDrawer(trigger)) return false
			context.user.value = target
			context.drawerTitle?.focus?.()
			context.inspect()
			return true
		},
		close: context.closeDrawer,
	}
	context.pathForm.addEventListener('submit', (event) => {
		event.preventDefault()
		const path = context.pathInput.value.trim()
		if (context.inspected === null || path === '') return
		context.explain('path', context.pathResult, '/api/inspector/path', { path: path.startsWith('/') ? path : '/' + path }, 'files')
	})
	context.calendarModule.addEventListener('change', () => { if (context.inspected !== null) context.loadCalendars() })
	context.calendarForm.addEventListener('submit', (event) => {
		event.preventDefault()
		if (context.inspected === null || context.calendarSelect.value === '') return
		context.explain('calendar', context.calendarResult, '/api/inspector/calendar', { module: context.calendarModule.value, calendarId: context.calendarSelect.value, operation: context.calendarOperation.value }, context.calendarModule.value)
	})
})((globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* shared/connections.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Active OAuth connections of the MCP app. This fragment is part of both generated bundles, js/mcp-admin.js and
 * js/mcp-personal.js, which the PHP builder joins from src/admin.manifest and src/settings.manifest.
 * The admin page lists every user (GET/DELETE /apps/mcp/api/connections) and the personal page only the signed-in
 * user (GET/DELETE /apps/mcp/api/my/connections); the root element's data-scope ("admin" or "personal") picks the
 * API and the columns. Plain browser JavaScript that runs as written, without module syntax;
 * strings go through t('mcp', …) for translation.
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
 * single-page layout. Plain browser JavaScript, concatenated by the PHP asset builder.
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
/* admin/file-write-policy/validation.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** file-write-policy/validation operations.
 * @param {object} scope browser primitives
 * @param {object} admin app registry
 * @return {void} registers and starts the original editor
 */
(function (scope, admin) {
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

	admin.fileWritePolicy = { phpTrim, extensions, validPolicy }
})((globalThis.window || globalThis), (globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/file-write-policy/editor.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** file-write-policy/editor operations.
 * @param {object} scope browser primitives
 * @param {object} admin app registry
 * @return {void} registers and starts the original editor
 */
(function (scope, admin) {
	'use strict'

	const { phpTrim, extensions, validPolicy } = admin.fileWritePolicy

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

	admin.fileWritePolicy.mount = mount
	if (scope.document) mount(scope.document, scope.fetch.bind(scope), scope.t,
		scope.OC.generateUrl('/apps/mcp/api/file-write-policy'), () => scope.OC.requestToken, scope.MutationObserver)
})((globalThis.window || globalThis), (globalThis.window || globalThis).OCA.Mcp.Admin)
;
/* admin/backups/editor.js */
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** backups/editor operations.
 * @param {object} scope browser primitives
 * @param {object} admin app registry
 * @return {void} registers and starts the original editor
 */
(function (scope, admin) {
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

	admin.backupSettings = { mount }
	if (scope.document) mount(scope.document, scope.fetch.bind(scope), scope.t, scope.OC.generateUrl('/apps/mcp/api/backup-settings'), () => scope.OC.requestToken)
})((globalThis.window || globalThis), (globalThis.window || globalThis).OCA.Mcp.Admin)
