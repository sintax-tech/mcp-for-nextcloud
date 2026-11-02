/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix interactions unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach focus visibility and the service, reload and filter listener installers.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.interactions = function (context) {
		let searchTimer = null

		/**
		 * Keeps the control that takes the focus clear of the sticky columns of its row (the selection box and the name at the
		 * start, the "…" menu at the end, js/admin.css), and inside the block. The browser scrolls a focused control into the
		 * matrix block, but not out from under those columns, and it leaves one that shows in part where it is, so a cell
		 * reached by Tab could be hidden (WCAG 2.4.11). Measured on screen, so it holds at any width and in either direction.
		 * @param {FocusEvent} event focusin on the scrolling block of the matrix
		 * @return {void}
		 */
		function revealFocus(event) {
			const wrap = event.currentTarget
			const target = event.target
			const cell = target?.closest?.('td, th')
			const row = cell?.parentElement
			if (!cell || !row) return
			const rect = target.getBoundingClientRect()
			// The sticky cells before this one in the row sit at its start side, the ones after it at its end side; which
			// physical side that is depends on the direction of the page.
			const rtl = getComputedStyle(row).direction === 'rtl'
			// The visible part of the block itself: the browser leaves a control that shows in part where it is.
			const block = wrap.getBoundingClientRect()
			let left = block.left + (wrap.clientLeft || 0)
			let right = left + wrap.clientWidth
			let before = true
			for (const other of row.children) {
				if (other === cell) {
					before = false
					continue
				}
				if (getComputedStyle(other).position !== 'sticky') continue
				const box = other.getBoundingClientRect()
				if (before !== rtl) left = Math.max(left, box.right)
				else right = Math.min(right, box.left)
			}
			if (rect.left < left) {
				wrap.scrollLeft -= left - rect.left + 4
			} else if (rect.right > right) {
				// A control wider than the room left keeps its start in view.
				wrap.scrollLeft += Math.min(rect.right - right + 4, rect.left - left)
			}
		}

		/** Bind service switching to policy-aware persistence and a locked matrix reload.
		 * @return {void}
		 */
		function attachService() {
			context.serviceBox.addEventListener('change', async () => {
				const value = context.serviceBox.checked
				if (context.matrixLocked || context.state.data === null) {
					context.serviceBox.checked = !value
					return
				}
				context.serviceBox.disabled = true
				try {
					const sent = context.state.data.revision
					const result = await context.api('PUT', '/api/service', { enabled: value, expectedRevision: sent })
					// Committed from the revision on screen: the switch is the only thing that changed.
					context.state.data.revision = result.revision
					context.announce(sent, result.revision)
					context.state.data.serviceEnabled = result.enabled
					context.serviceBox.checked = result.enabled
					context.serviceStatus.textContent = t('mcp', 'Saved')
					context.flash(context.serviceStatus, true)
				} catch (e) {
					context.serviceBox.checked = !value
					context.serviceStatus.textContent = t('mcp', 'Not saved')
					context.flash(context.serviceStatus, false)
					// Enabled again first, so the reloaded page can paint the saved switch.
					context.serviceBox.disabled = false
					if (!(await context.policyError(e))) {
						context.notifyError(t('mcp', 'Could not change the MCP service.'))
					}
				} finally {
					context.serviceBox.disabled = false
				}
			})

		}

		/** Bind successful group and scope writes to matrix reloads.
		 * @return {void}
		 */
		function attachReloads() {
			window.addEventListener('mcp-group-policy-saved', context.reloadLocked)
			window.addEventListener('mcp-scope-saved', context.reloadLocked)

		}

		/** Bind user search, group and eligibility filters, paging and explicit matrix reload.
		 * @return {void}
		 */
		function attachInteractions() {
			context.table.parentElement?.addEventListener('focusin', context.revealFocus)

			context.searchInput.addEventListener('input', () => {
				clearTimeout(searchTimer)
				searchTimer = setTimeout(() => {
					context.state.search = context.searchInput.value.trim()
					context.state.page = 1
					context.load()
				}, 300)
			})
			context.groupSelect.addEventListener('change', () => {
				context.state.group = context.groupSelect.value
				context.state.page = 1
				context.load()
			})
			// The matrix opens on the users who can connect; "Everyone" stays one choice away.
			context.filterSelect.value = context.state.filter
			context.filterSelect.addEventListener('change', () => {
				context.state.filter = context.filterSelect.value
				context.state.page = 1
				context.load()
			})
			for (const button of context.prevButtons) {
				button.addEventListener('click', () => {
					context.state.page = Math.max(1, context.state.page - 1)
					context.load()
				})
			}
			for (const button of context.nextButtons) {
				button.addEventListener('click', () => {
					context.state.page += 1
					context.load()
				})
			}

			for (const button of context.root.querySelectorAll('.mcp-copy')) {
				button.addEventListener('click', () => {
					const text = document.getElementById(button.dataset.copy).textContent
					navigator.clipboard.writeText(text).then(
						() => { button.textContent = t('mcp', 'Copied') },
						() => context.notifyError(t('mcp', 'Could not copy the endpoint.')),
					)
				})
			}
		}

		Object.defineProperties(context, {
			searchTimer: { configurable: true, get: () => searchTimer, set: (value) => { searchTimer = value } },
		})

		Object.assign(context, { attachInteractions, attachReloads, attachService, revealFocus })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
