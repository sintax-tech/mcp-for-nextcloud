/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Register the matrix level cells unit on the shared app namespace.
 * @param {object} admin app registry
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.matrix = admin.modules.matrix || {}
	/** Attach level cells, inheritance markers and popover triggers.
	 * @param {object} context matrix controls, loaded policy, write locks and rendering dependencies
	 * @return {void}
	 */
	admin.modules.matrix.levelCells = function (context) {
		const INDIVIDUAL_SOURCES = ['individual_allow', 'individual_deny', 'invalid']

		/**
		 * Where a module's values come from, as a sentence for the tooltip and screen readers.
		 * @param {{source: string, groupIds: string[]}} origin what OCA.Mcp.Admin.levels.originOf() returned
		 * @return {string}
		 */
		function originText(origin) {
			switch (origin.source) {
			case 'individual': return t('mcp', 'Set for this person')
			case 'invalid': return t('mcp', 'Invalid stored value (denied)')
			case 'group': return t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') })
			case 'unknown': return t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') })
			default: return t('mcp', 'Default')
			}
		}

		/**
		 * One module of one user in the levels view: the level as a word (None, Read, Edit, Full, Custom) and where it comes
		 * from as an icon, so no state depends on colour. An individual value carries a person icon and a group a group icon;
		 * the default has none. A module the user cannot use keeps the "no entry" mark without a control, and an unknown value
		 * the dash, as in the operations view. Choosing the cell opens that module's operations for editing.
		 * @param {object} user row from the API
		 * @param {string} module module id
		 * @param {string[]} operations the module's operations in the catalog
		 * @param {number} index position of the module, for the alternating band
		 * @return {HTMLTableCellElement}
		 */
		function levelCell(user, module, operations, index) {
			const levels = window.OCA.Mcp.Admin.levels
			const cell = context.el('td', { className: 'mcp-cell mcp-level-cell mcp-module mcp-band-' + (index % 2) })
			cell.dataset.module = module
			cell.dataset.levelKey = module
			const name = context.moduleLabels()[module] || module
			const level = levels.levelOf(module, operations, user.grants[module])
			const reason = context.unavailableReason(user, module)
			if (reason !== null || level === 'unknown') {
				const unknown = reason === null
				context.paintState(cell, 'unavailable', unknown ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : reason)
				const mark = context.el('span', { className: 'mcp-state-mark', textContent: unknown ? '—' : '⊘' })
				mark.setAttribute('aria-hidden', 'true')
				const word = context.el('span', { className: 'mcp-level-word', textContent: unknown ? t('mcp', 'Unknown') : t('mcp', 'Unavailable') })
				word.setAttribute('aria-hidden', 'true')
				cell.append(mark, ' ', word, context.el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + name + ': ' + cell.title }))
				if (unknown) cell.append(context.el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: context.reloadLocked }))
			} else {
				const { origin, mark: kind } = context.originMark(user, module, operations)
				const labels = context.operationLabels()
				const allowed = operations.filter((operation) => user.grants[module][operation] === true)
				const levelName = context.levelLabels()[level] || level
				const summary = allowed.length === 0
					? t('mcp', 'Allows nothing')
					: t('mcp', 'Allows: {operations}', { operations: allowed.map((operation) => labels[operation] || operation).join(', ') }, undefined, { escape: false })
				context.paintState(cell, allowed.length === 0 ? 'denied' : 'allowed')
				const source = context.markText(origin, kind)
				const individual = kind === 'exception'
				const icon = context.markIcon(origin, kind)
				const parts = []
				if (icon !== null) {
					const glyph = context.el('span', { className: 'mcp-origin-icon ' + icon })
					glyph.setAttribute('aria-hidden', 'true')
					parts.push(glyph)
				}
				parts.push(context.el('span', { className: 'mcp-level-word', textContent: levelName }))
				const button = context.levelButton(user, module, 'mcp-level mcp-level-' + level + (individual ? ' mcp-level-individual' : ''), parts,
					levelName + '. ' + summary + ' — ' + source,
					t('mcp', '{user}, {module}: {level}. {source}.', { user: user.displayName, module: name, level: levelName, source }, undefined, { escape: false }))
				cell.title = button.title
				cell.dataset.level = level
				cell.dataset.origin = origin.source
				cell.dataset.mark = kind
				cell.append(button)
			}
			// "Restricted to N" or "Scope unknown" of a resource scope, drawn and opened by src/admin/scope/start.js.
			const mark = user.scope?.[module] && window.OCA.Mcp.Admin.scope ? window.OCA.Mcp.Admin.scope.indicator(user.uid, module, user.scope[module]) : null
			if (mark) cell.append(mark)
			return cell
		}

		/**
		 * Where a cell's values come from and how to mark it. Only a real exception, an individual value that differs from what
		 * the person would inherit (src/admin/admin-exceptions.js), gets the person icon; individual values that only repeat the
		 * inheritance are drawn like what they repeat, and while that is not known yet they get no mark. Without the
		 * classification every individual value is an exception, never fewer.
		 * @param {object} user row from the API
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {string[]} operations the module's operations, or ["eligible"]
		 * @return {{origin: {source: string, groupIds: string[]}, mark: string}} mark is exception, redundant, pending, or the
		 *     source (group, unknown, default) of a cell with no individual value
		 */
		function originMark(user, module, operations) {
			const levels = window.OCA.Mcp.Admin.levels
			const origins = module === null ? { eligible: user.origins?.eligible } : user.origins?.grants?.[module]
			const origin = levels.originOf(operations, origins)
			if (origin.source !== 'individual' && origin.source !== 'invalid') {
				return { origin, mark: origin.source }
			}
			const exceptions = window.OCA.Mcp.Admin.exceptions
			const individual = operations.filter((operation) => INDIVIDUAL_SOURCES.includes(origins?.[operation]?.source))
			const kinds = exceptions ? individual.map((operation) => exceptions.kindOf(user.uid, module, operation)) : []
			if (!exceptions || origin.source === 'invalid' || kinds.some((kind) => kind.kind === 'exception')) {
				return { origin, mark: 'exception' }
			}
			if (kinds.some((kind) => kind.kind === 'pending')) {
				return { origin, mark: 'pending' }
			}
			const repeated = Object.fromEntries(operations.map((operation) => {
				const index = individual.indexOf(operation)
				return [operation, index < 0 ? origins?.[operation] : kinds[index].after || { source: 'default', groupIds: [] }]
			}))
			return { origin: levels.originOf(operations, repeated), mark: 'redundant' }
		}

		/** Choose the person or group marker class for a classified inheritance source.
		 * @return {string|null} the icon class of a mark: a person for an exception, a group for a group, none otherwise
		 * @param {{source: string, groupIds?: string[]}} origin grant source and contributing groups
		 * @param {string} mark exception, redundant, pending or inherited source classification
		 */
		function markIcon(origin, mark) {
			if (mark === 'exception') return 'icon-user'
			if (mark === 'pending') return null
			return origin.source === 'group' || origin.source === 'unknown' ? 'icon-group' : null
		}

		/** Describe a classified inheritance source for the tooltip and screen reader.
		 * @return {string} where a cell's values come from, in words: a screen reader hears "Exception" where the eye sees the
		 *     person icon, and the inspector says the same
		 * @param {{source: string, groupIds?: string[]}} origin grant source and contributing groups
		 * @param {string} mark exception, redundant, pending or inherited source classification
		 */
		function markText(origin, mark) {
			if (mark === 'pending') return t('mcp', 'Set for this person')
			if (mark === 'exception' && origin.source === 'individual') return t('mcp', 'Exception: differs from what the person inherits')
			if (mark === 'redundant') return context.originText(origin) + ' — ' + t('mcp', 'also set for this person, with the same value')
			return context.originText(origin)
		}

		/**
		 * The control of a level cell: it opens the popover of src/admin/admin-level-popover.js, where the level is chosen.
		 * @param {object} user row from the API
		 * @param {string|null} module module id, null for "Can connect"
		 * @param {string} className classes of the button
		 * @param {Array<Node>} parts icon and word
		 * @param {string} title tooltip
		 * @param {string} label accessible name, which says the state in words
		 * @return {HTMLButtonElement}
		 */
		function levelButton(user, module, className, parts, title, label) {
			const button = context.el('button', { type: 'button', className, title }, parts)
			button.onclick = () => window.OCA.Mcp.Admin.levelPopover?.open(button, user.uid, module)
			button.setAttribute('aria-label', label)
			button.setAttribute('aria-haspopup', 'dialog')
			button.setAttribute('aria-expanded', 'false')
			return button
		}

		Object.defineProperties(context, {
			INDIVIDUAL_SOURCES: { configurable: true, get: () => INDIVIDUAL_SOURCES },
		})

		Object.assign(context, { levelButton, levelCell, markIcon, markText, originMark, originText })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
