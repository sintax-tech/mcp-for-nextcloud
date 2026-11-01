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
 * draws and exposes window.OCA.Mcp.Admin.matrix; this file never reads or writes the matrix state itself. A reset is the
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

	const matrix = window.OCA.Mcp.Admin.matrix
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
			: window.OCA.Mcp.Admin.levels.levels(module, matrix.data().catalog[module]).map((level) => [level, names[level] || level])
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

	window.OCA.Mcp.Admin.overrides = { decorateRow, decorateHeader, decorateCell, plan, reviewCells, resetUser, hasOverrides }
	// The matrix may have been drawn before this file ran: draw it again so its rows carry the controls.
	matrix.refresh()
})()
