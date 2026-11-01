/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Individual override decoration operations.
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
	admin.modules.overrides.decoration = function (context) {
		// --- Rows and cells -----------------------------------------------------------------------------------------

		/**
		 * Adds the selection box to a row. The reset of the whole row is in the row's actions menu (src/admin/admin-row-actions.js),
		 * through resetUser(), so the row carries no button repeated on every line.
		 *
		 * @param {HTMLTableRowElement} row row drawn by matrix/rows.js; its first child is the selection cell
		 * @param {object} user row from the API
		 * @return {*} operation result
		 */
		function decorateRow(row, user) {
			const slot = row.children[0]
			const box = context.el('input', { type: 'checkbox', className: 'mcp-row-select', checked: context.selected.has(user.uid) })
			box.setAttribute('aria-label', t('mcp', 'Select {user}', { user: user.displayName }, undefined, { escape: false }))
			box.addEventListener('change', () => {
				box.checked ? context.selected.add(user.uid) : context.selected.delete(user.uid)
				context.updateToolbar()
			})
			slot.append(box)
			context.updateToolbar()
		}


		/** @return {boolean} whether the user has any individual value a reset would remove
		 * @param {*} user operation input
		 */
		function hasOverrides(user) {
			return context.overrideCells(user).length > 0
		}


		/**
		 * Opens the reset preview for every individual value of one user, as the row's actions menu asks.
		 *
		 * @param {object} user row from the API
		 * @param {HTMLElement} trigger control that asked, which gets the focus back when the preview is cancelled
		 * @return {*} operation result
		 */
		function resetUser(user, trigger) {
			context.start([user], null, trigger)
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
		 * @return {*} operation result
		 */
		function decorateCell(cell, box, user, module, operation) {
			const origin = module === null ? user.origins?.eligible : user.origins?.grants?.[module]?.[operation]
			const kind = context.OVERRIDES[origin?.source]
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
				cell.append(context.el('span', { className: 'mcp-sr-only', textContent: stateName }))
			}
		}

		Object.assign(context, { decorateCell, decorateRow, hasOverrides, resetUser })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
