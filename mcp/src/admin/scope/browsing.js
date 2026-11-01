/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Resource scope browsing operations.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.scope = admin.modules.scope || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.scope.browsing = function (context) {
		/**
		 * @param {*} state operation input
		 * @return {*} operation result
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


		/**
		 * @param {*} state operation input
		 * @param {*} item operation input
		 * @param {*} text operation input
		 * @return {*} operation result
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


		/**
		 * @param {*} state operation input
		 * @param {*} focusId operation input
		 * @param {boolean} append operation input
		 * @return {Promise<*>} operation result
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
