/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Individual override selection operations.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.overrides = admin.modules.overrides || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
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

		/** @return {object[]} the selected users that are on the page now */
		function selectedUsers() {
			return context.pageUsers().filter((user) => context.selected.has(user.uid))
		}

		/** Keeps the toolbar's counters, the select-all box and the button state in line with the selection and the lock.
		 * @return {*} operation result
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
		 * @return {*} operation result
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
		 * @return {*} operation result
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
		 * @return {*} operation result
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
		 * @return {*} operation result
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
		 *
		 * @param {HTMLTableCellElement} header the header cell of the selection column
		 * @return {*} operation result
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
