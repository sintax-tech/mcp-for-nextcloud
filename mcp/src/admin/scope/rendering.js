/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Resource scope rendering operations.
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
	admin.modules.scope.rendering = function (context) {
		/**
		 * @param {*} state operation input
		 * @param {*} focusId operation input
		 * @return {*} operation result
		 */
		function rerender(state, focusId) {
			const old = document.getElementById('mcp-scope-module-' + state.module)
			const fresh = context.renderModule(state)
			if (old) old.replaceWith(fresh)
			context.renderTabs()
			if (focusId) document.getElementById(focusId)?.focus?.()
		}

		/**
		 * @param {*} state operation input
		 * @return {*} operation result
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

		/**
		 * @param {*} state operation input
		 * @return {*} operation result
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
