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
 * Plain browser JavaScript, no build step. js/admin-grants.js opens it from a level cell through window.McpLevelPopover and
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

	/** @return {string} name of a level of js/admin-levels.js, or Yes / No for "Can connect" */
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
