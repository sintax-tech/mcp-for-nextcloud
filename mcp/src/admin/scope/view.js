// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
export function createScopeView({ lifetime, t, el, tabs, title, dirty, rerender, saveModule, reload, note, failure, undoModule, expireUndo, browse, getUser, getCurrentTab }) {
	function emptyText(module) {
		return {
			files: t('mcp', 'Nothing selected: the Files tools of this user reach no folder.'),
			calendar: t('mcp', 'Nothing selected: the Calendar tools of this user reach no calendar.'),
			tasks: t('mcp', 'Nothing selected: the Tasks tools of this user reach no task list.'),
		}[module]
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
	function renderModule(state) {
		const module = state.module
		const section = el('section', { id: 'mcp-scope-module-' + module, className: 'mcp-scope-module', hidden: Boolean(tabs) && module !== getCurrentTab() })
		const heading = el('h3', { id: 'mcp-scope-heading-' + module, textContent: title(module), tabIndex: -1 })
		section.setAttribute('aria-labelledby', heading.id)
		section.append(heading)
		if (state.stale) {
			section.append(el('p', { className: 'mcp-scope-inactive', textContent: t('mcp', 'Changed before the policy moved, so saving will be refused. Discard the changes to load the saved scope.') }))
		}
		if (!state.active) {
			section.append(el('p', { className: 'mcp-scope-inactive', textContent: t('mcp', 'Not available in this version: the tools of this module do not apply a scope yet, so nothing can be saved. You can still browse.') }))
		}
		const fieldset = el('fieldset', {}, [el('legend', { textContent: t('mcp', 'Resources the MCP tools of {user} may reach', { user: getUser() }, undefined, { escape: false }) })])
		;[['unrestricted', t('mcp', 'All resources')], ['restricted', t('mcp', 'Only selected')]].forEach(([value, text]) => {
			const id = 'mcp-scope-mode-' + module + '-' + value
			const radio = el('input', { type: 'radio', name: 'mcp-scope-mode-' + module, id, value, checked: state.mode === value })
			lifetime.on(radio, 'change', () => {
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
		lifetime.on(save, 'click', () => saveModule(state))
		const discard = el('button', { type: 'button', id: 'mcp-scope-discard-' + module, textContent: t('mcp', 'Discard changes'), disabled: state.saving || (!dirty(state) && !state.stale) })
		lifetime.on(discard, 'click', async () => {
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
			lifetime.on(undo, 'click', () => undoModule(state))
			// The ten seconds pause while the pointer or the focus is on the button (WCAG 2.2.1).
			const hold = (key, value) => () => {
				offer[key] = value
				if (!value) expireUndo(state, offer)
			}
			lifetime.on(undo, 'mouseenter', hold('hover', true))
			lifetime.on(undo, 'mouseleave', hold('hover', false))
			lifetime.on(undo, 'focus', hold('focus', true))
			lifetime.on(undo, 'blur', hold('focus', false))
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
				lifetime.on(remove, 'click', () => {
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
		lifetime.on(go, 'click', run)
		lifetime.on(search, 'keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); run() } })
		picker.append(el('label', { htmlFor: searchId, textContent: module === 'files' ? t('mcp', 'Find a folder of the user') : t('mcp', 'Find a calendar or task list of the user') }), search, go)
		if (module === 'files') {
			const here = state.parents[state.parents.length - 1]
			picker.append(el('p', { className: 'mcp-scope-where', textContent: here ? t('mcp', 'In folder {path}', { path: here.path }, undefined, { escape: false }) : t('mcp', 'In the top folder of the user') }))
			if (here) {
				const up = el('button', { type: 'button', id: 'mcp-scope-up-files', textContent: t('mcp', 'Up one folder') })
				lifetime.on(up, 'click', () => { state.parents.pop(); state.page = 1; state.results = null; browse(state, 'mcp-scope-up-files') })
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
					lifetime.on(open, 'click', () => { state.parents.push(item); state.page = 1; state.results = null; state.search = ''; browse(state, searchId) })
					row.append(open)
				}
				results.append(row)
			})
			if (state.results.hasMore) {
				const more = el('button', { type: 'button', id: 'mcp-scope-more-' + module, textContent: t('mcp', 'Show more') })
				lifetime.on(more, 'click', () => { state.page += 1; browse(state, 'mcp-scope-more-' + module, true) })
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
		lifetime.on(add, 'click', () => {
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
    return { renderModule }
}
