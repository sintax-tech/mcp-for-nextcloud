/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Matrix operation cells operations.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/**
	 * @param {object} context operation input
	 * @return {void} operation result
	 */
	admin.modules.matrix.operationCells = function (context) {
		/**
		 * The states a permission cell can show, drawn in one place: allowed and denied are a checkbox
		 * (checked or empty), unavailable is a "no entry" mark that never looks like an empty checkbox.
		 * Each state has a name for the tooltip and for screen readers, so none depends on colour.
		 */
		const PERMISSION_STATES = {
			allowed: { className: 'mcp-state-allowed', name: () => t('mcp', 'Allowed') },
			denied: { className: 'mcp-state-denied', name: () => t('mcp', 'Denied') },
			unavailable: { className: 'mcp-state-unavailable', name: () => t('mcp', 'Unavailable') },
		}

		/**
		 * Why a user's permission cannot be changed here, or null when it can. The first reason wins:
		 * a disabled account, then "Can connect" off, then the app (or the log role) unavailable to the user.
		 *
		 * @param {object} user row from the API
		 * @param {string|null} module module id, null for "Can connect"
		 * @return {string|null} reason shown to the admin
		 */
		function unavailableReason(user, module) {
			if (!user.enabled) {
				return t('mcp', 'The account is disabled.')
			}
			if (module === null) {
				return null
			}
			if (user.eligible === false) {
				return t('mcp', 'This user may not connect: “Can connect” is off.')
			}
			if (!user.appsEnabled[module]) {
				return module === 'logs'
					? t('mcp', 'Only administrators and members of the groups selected in “Server log” can read the log; the saved permission is kept.')
					: t('mcp', 'This app is unavailable for this user; the saved permission is kept.')
			}
			return null
		}

		/**
		 * Paints one state on a cell: its class, tooltip and the name read by screen readers.
		 *
		 * @param {HTMLTableCellElement} cell the cell
		 * @param {string} key key of PERMISSION_STATES
		 * @param {string} [detail] extra sentence (the reason of an unavailable cell)
		 * @return {*} operation result
		 */
		function paintState(cell, key, detail) {
			for (const other of Object.values(PERMISSION_STATES)) {
				cell.classList.remove(other.className)
			}
			cell.classList.add(PERMISSION_STATES[key].className)
			cell.title = detail ? PERMISSION_STATES[key].name() + ': ' + detail : PERMISSION_STATES[key].name()
		}

		/**
		 * A permission cell: a checkbox that saves at once, or the unavailable mark with its reason.
		 *
		 * @param {object} user row from the API
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {boolean} checked saved value
		 * @param {string} label accessible name of the permission
		 * @param {object} body request body template; the null field receives the new value
		 * @return {HTMLTableCellElement}
		 */
		function permissionCell(user, module, checked, label, body) {
			const cell = context.el('td', { className: 'mcp-cell' })
			const reason = checked === null ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : context.unavailableReason(user, module)
			if (reason !== null) {
				const saved = checked === null ? t('mcp', 'Unknown') : checked ? t('mcp', 'Saved value: allowed.') : t('mcp', 'Saved value: denied.')
				context.paintState(cell, 'unavailable', reason + ' ' + saved)
				const mark = context.el('span', { className: 'mcp-state-mark', textContent: checked === null ? '—' : '⊘' })
				mark.setAttribute('aria-hidden', 'true')
				cell.append(mark, context.el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + label + ': ' + cell.title }))
				if (checked === null) cell.append(context.el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: context.reloadLocked }))
				// A saved individual override stays visible and removable here, even though the cell cannot be edited.
				window.OCA.Mcp.Admin.overrides?.decorateCell(cell, null, user, module, module === null ? 'eligible' : body.operation)
				return cell
			}
			const box = context.el('input', { type: 'checkbox', checked })
			box.setAttribute('aria-label', user.displayName + ' — ' + label)
			context.paintState(cell, checked ? 'allowed' : 'denied')
			const origin = module === null ? user.origins?.eligible : user.origins?.grants?.[module]?.[body.operation]
			if (origin?.source === 'group') {
				const source = t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') })
				cell.title += ' — ' + source
				box.setAttribute('aria-label', user.displayName + ' — ' + label + ' — ' + source)
			}
			box.addEventListener('change', async () => {
				const value = box.checked
				if (context.matrixLocked) {
					// The table on screen is known to be outdated; the reloaded one replaces this checkbox.
					box.checked = !value
					return
				}
				// Taking "Can connect" away signs the person out of every MCP client: never without asking.
				if (module === null && !value && !window.confirm(t('mcp', 'Turn off “Can connect” for {user}?', { user: user.displayName }, undefined, { escape: false }) + '\n'
					+ t('mcp', 'This signs the person out of every MCP client, and undoing it does not sign them back in.'))) {
					box.checked = true
					return
				}
				if (value && module !== null && window.OCA.Mcp.Admin.mailGrants && !window.OCA.Mcp.Admin.mailGrants.confirmWrites([{ module, operation: body.operation }])) {
					box.checked = false
					return
				}
				const payload = Object.fromEntries(Object.entries(body).map(([key, v]) => [key, v === null ? value : v]))
				const hadFocus = document.activeElement === box
				// The focus comes back to this checkbox only when the admin did not move it during the save.
				const focusStayed = () => hadFocus && (document.activeElement === null || document.activeElement === document.body || document.activeElement === box)
				box.disabled = true
				try {
					const sent = context.state.data.revision
					const updated = await context.api('PUT', '/api/grants/' + encodeURIComponent(user.uid), { ...payload, expectedRevision: sent })
					// Committed from the revision on screen, so the page plus this row is exactly the new revision.
					context.state.data.revision = updated.revision
					context.announce(sent, updated.revision)
					const index = context.state.data.users.findIndex((u) => u.uid === user.uid)
					context.state.data.users[index] = updated
					const restore = focusStayed()
					const fresh = context.renderRow(updated)
					cell.parentElement.replaceWith(fresh)
					const freshCell = fresh.children[cell.cellIndex]
					context.flash(freshCell, true)
					if (restore) {
						freshCell.querySelector('input')?.focus()
					}
				} catch (e) {
					const restore = focusStayed()
					box.checked = !value
					box.disabled = false
					if (restore) {
						box.focus()
					}
					context.flash(cell, false)
					if (!(await context.policyError(e))) {
						context.notifyError(t('mcp', 'Could not save the permission for {user}.', { user: user.displayName }))
					}
				}
			})
			cell.append(box)
			// The override state and the per-cell reset come from src/admin/overrides/start.js, when it is loaded.
			window.OCA.Mcp.Admin.overrides?.decorateCell(cell, box, user, module, module === null ? 'eligible' : body.operation)
			return cell
		}

		Object.defineProperties(context, {
			PERMISSION_STATES: { configurable: true, get: () => PERMISSION_STATES },
		})

		Object.assign(context, { paintState, permissionCell, unavailableReason })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
