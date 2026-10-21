/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * The actions of one row of the permission matrix, in a "…" menu at the end of the row, so the row does not repeat a
 * button on every line: inspect the user's access, restrict their resources, give every individual value back to
 * inheritance (the reset preview of src/admin/access/overrides.js, nothing is removed without it) and copy the user ID.
 *
 * A menu button with role="menu" items: Enter, Space or the arrow keys open it, the arrow keys move between the items,
 * Escape and Tab close it, and the focus goes back to the button. Bundled ES module; src/admin/access/controller.js
 * asks button() for every row it draws, and this file never touches the matrix state.
 */
import { createScope } from '../../platform/lifecycle.js'
export function mountRowActions({ root: pageRoot, request, t, n, events, policyChannel, getMatrix, getOverrides, getResourceScope, getInspector }) {
    const lifetime = createScope()
    const find = id => pageRoot.querySelector('#' + id)

	const matrix = getMatrix()
	const menu = find('mcp-row-menu')
	if (!matrix || !menu) {
		return
	}

	/** The open menu: {uid, trigger}, or null. */
	let current = null

	function el(tag, props, children) {
		const node = document.createElement(tag)
		for (const [key, value] of Object.entries(props || {})) node[key] = typeof value === 'function' ? lifetime.guard(value) : value
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
		const overrides = getOverrides()
		return [
			{
				label: t('mcp', 'Inspect access'),
				disabled: !getInspector(),
				// The inspector opens as a drawer that takes the focus and gives it back to this button.
				run: () => getInspector().open(user.uid, trigger),
			},
			{
				label: t('mcp', 'Restrict resources'),
				disabled: !getResourceScope(),
				// The resource scope opens as a drawer that takes the focus and gives it back to this button.
				run: () => getResourceScope().open(user.uid, undefined, trigger),
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
            if (lifetime.disposed) return
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
			lifetime.on(item, 'click', () => {
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
		// src/admin/access/exceptions.js waits for this to draw the table again.
		events.dispatchEvent?.(new Event('mcp-row-menu-closed'))
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
		lifetime.on(trigger, 'click', () => open(trigger, user.uid))
		lifetime.on(trigger, 'keydown', (event) => {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault()
				open(trigger, user.uid, event.key === 'ArrowUp' ? -1 : 0)
			}
		})
		return trigger
	}

	lifetime.on(menu, 'keydown', (event) => {
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
	lifetime.on(document, 'pointerdown', (event) => {
		if (current !== null && !menu.contains(event.target) && event.target !== current.trigger) {
			close(false)
		}
	})
	// A table drawn again replaces the button the menu belongs to.
	lifetime.on(events, 'mcp-matrix-rendered', () => close(false))

	const controller = { button, open, close }

	const ready = Promise.resolve()

    return { ...controller, ready, destroy() { lifetime.destroy(); close(false) } }
}
