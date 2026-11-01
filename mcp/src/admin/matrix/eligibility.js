/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Matrix eligibility operations.
 * @param {object} context live bindings of this editor
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	admin.modules.matrix.eligibility = function (context) {
		/**
		 * "Can connect" in the levels view: Yes or No in words, its origin as an icon like the module cells, and the popover to
		 * change it. A disabled account has no control, as in the operations view.
		 *
		 * @param {object} user row from the API
		 * @return {HTMLTableCellElement}
		 */
		function eligibleCell(user) {
			const cell = context.el('td', { className: 'mcp-cell mcp-level-cell' })
			cell.dataset.levelKey = 'eligible'
			const canConnect = t('mcp', 'Can connect')
			const reason = user.eligible === null ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : context.unavailableReason(user, null)
			if (reason !== null) {
				const unknown = user.eligible === null
				context.paintState(cell, 'unavailable', reason)
				const mark = context.el('span', { className: 'mcp-state-mark', textContent: unknown ? '—' : '⊘' })
				mark.setAttribute('aria-hidden', 'true')
				const word = context.el('span', { className: 'mcp-level-word', textContent: unknown ? t('mcp', 'Unknown') : t('mcp', 'Unavailable') })
				word.setAttribute('aria-hidden', 'true')
				cell.append(mark, ' ', word, context.el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + canConnect + ': ' + cell.title }))
				if (unknown) cell.append(context.el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: context.reloadLocked }))
				return cell
			}
			const { origin, mark: kind } = context.originMark(user, null, ['eligible'])
			const individual = kind === 'exception'
			const icon = context.markIcon(origin, kind)
			const parts = []
			if (icon !== null) {
				const glyph = context.el('span', { className: 'mcp-origin-icon ' + icon })
				glyph.setAttribute('aria-hidden', 'true')
				parts.push(glyph)
			}
			const word = user.eligible ? t('mcp', 'Yes') : t('mcp', 'No')
			parts.push(context.el('span', { className: 'mcp-level-word', textContent: word }))
			context.paintState(cell, user.eligible ? 'allowed' : 'denied')
			const source = context.markText(origin, kind)
			const button = context.levelButton(user, null, 'mcp-level mcp-level-' + (user.eligible ? 'read' : 'none') + (individual ? ' mcp-level-individual' : ''), parts,
				canConnect + ': ' + word + ' — ' + source,
				t('mcp', '{user}, {module}: {level}. {source}.', { user: user.displayName, module: canConnect, level: word, source }, undefined, { escape: false }))
			cell.title = button.title
			cell.dataset.level = user.eligible ? 'read' : 'none'
			cell.dataset.origin = origin.source
			cell.dataset.mark = kind
			cell.append(button)
			return cell
		}

		Object.assign(context, { eligibleCell })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
