/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createScope } from '../platform/lifecycle.js'

export function mountTabs({ root }) {
	const scope = createScope()
	const tablist = root ? root.querySelector('[role="tablist"]') : null
	if (!root || !tablist) {
		return { destroy: scope.destroy }
	}

	const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'))
	/** @type {Map<string, HTMLElement>} data-tab-hash value to its tab */
	const byHash = new Map()
	// Query strings belong to each tab; navigation preserves them without knowing their meaning.
	const tabQueries = new Map()

	for (const tab of tabs) {
		const hash = tab.getAttribute('data-tab-hash')
		if (hash && tab.id && root.ownerDocument.getElementById(tab.getAttribute('aria-controls'))) {
			byHash.set(hash, tab)
		}
	}
	if (byHash.size === 0) {
		return { destroy: scope.destroy }
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
		const element = hash === '' ? null : root.ownerDocument.getElementById(hash)
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
			const panel = root.ownerDocument.getElementById(candidate.getAttribute('aria-controls'))
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

	scope.on(tablist, 'click', (event) => {
		const tab = event.target.closest('[role="tab"]')
		if (tab && validTabs.includes(tab)) {
			activate(tab, false, true)
		}
	})

	scope.on(tablist, 'keydown', (event) => {
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

	scope.on(window, 'hashchange', () => {
		// Back/forward or a pasted hash: activate without writing the address again.
		const resolved = resolveFromHash()
		activate(resolved.tab, false, false)
		resolved.element?.scrollIntoView()
	})

	root.classList.add('mcp-tabs-on')
	const initial = resolveFromHash()
	activate(initial.tab, false, false)
	initial.element?.scrollIntoView()
	scope.use(() => { root.classList.remove('mcp-tabs-on'); for (const panel of root.querySelectorAll('[role=tabpanel]')) panel.hidden = false })
	return { destroy: scope.destroy }
}
