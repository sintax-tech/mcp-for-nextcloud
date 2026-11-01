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
 * single-page layout. Plain browser JavaScript, no build step.
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
