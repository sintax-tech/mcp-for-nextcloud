/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** level_popover rendering operations.
 * @param {object} admin app registry
 * @return {void} registers the focused operations
 */
(function (admin) {
	'use strict'

	admin.modules.level_popover = admin.modules.level_popover || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.level_popover.rendering = function (context) {
		/** A pressed button carries a check mark besides aria-pressed, so the choice never depends on colour.
		 * @param {*} text operation input
		 * @param {*} pressed operation input
		 * @param {*} onclick operation input
		 * @return {*} operation result
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


		/** @return {HTMLElement|null} the control of the popover drawn with this focus key
		 * @param {*} node operation input
		 * @param {*} key operation input
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
		 * @return {*} operation result
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
		 * @return {*} operation result
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
		 * @param {*} user operation input
		 * @param {*} module operation input
		 * @param {*} operations operation input
		 * @return {*} operation result
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
