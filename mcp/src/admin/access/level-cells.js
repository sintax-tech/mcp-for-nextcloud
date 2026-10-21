// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import * as Levels from './levels.js'
const INDIVIDUAL_SOURCES = ['individual_allow', 'individual_deny', 'invalid']
export function createLevelCells({ t, el, paintState, reloadLocked, moduleLabels, operationLabels, unavailableReason, levelLabels, getExceptions, getResourceScope, getLevelPopover }) {
	function originText(origin) {
		switch (origin.source) {
		case 'individual': return t('mcp', 'Set for this person')
		case 'invalid': return t('mcp', 'Invalid stored value (denied)')
		case 'group': return t('mcp', 'Granted by groups: {groups}', { groups: origin.groupIds.join(', ') })
		case 'unknown': return t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') })
		default: return t('mcp', 'Default')
		}
	}
	function levelCell(user, module, operations, index) {
		const levels = Levels
		const cell = el('td', { className: 'mcp-cell mcp-level-cell mcp-module mcp-band-' + (index % 2) })
		cell.dataset.module = module
		cell.dataset.levelKey = module
		const name = moduleLabels()[module] || module
		const level = levels.levelOf(module, operations, user.grants[module])
		const reason = unavailableReason(user, module)
		if (reason !== null || level === 'unknown') {
			const unknown = reason === null
			paintState(cell, 'unavailable', unknown ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : reason)
			const mark = el('span', { className: 'mcp-state-mark', textContent: unknown ? '—' : '⊘' })
			mark.setAttribute('aria-hidden', 'true')
			const word = el('span', { className: 'mcp-level-word', textContent: unknown ? t('mcp', 'Unknown') : t('mcp', 'Unavailable') })
			word.setAttribute('aria-hidden', 'true')
			cell.append(mark, ' ', word, el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + name + ': ' + cell.title }))
			if (unknown) cell.append(el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: reloadLocked }))
		} else {
			const { origin, mark: kind } = originMark(user, module, operations)
			const labels = operationLabels()
			const allowed = operations.filter((operation) => user.grants[module][operation] === true)
			const levelName = levelLabels()[level] || level
			const summary = allowed.length === 0
				? t('mcp', 'Allows nothing')
				: t('mcp', 'Allows: {operations}', { operations: allowed.map((operation) => labels[operation] || operation).join(', ') }, undefined, { escape: false })
			paintState(cell, allowed.length === 0 ? 'denied' : 'allowed')
			const source = markText(origin, kind)
			const individual = kind === 'exception'
			const icon = markIcon(origin, kind)
			const parts = []
			if (icon !== null) {
				const glyph = el('span', { className: 'mcp-origin-icon ' + icon })
				glyph.setAttribute('aria-hidden', 'true')
				parts.push(glyph)
			}
			parts.push(el('span', { className: 'mcp-level-word', textContent: levelName }))
			const button = levelButton(user, module, 'mcp-level mcp-level-' + level + (individual ? ' mcp-level-individual' : ''), parts,
				levelName + '. ' + summary + ' — ' + source,
				t('mcp', '{user}, {module}: {level}. {source}.', { user: user.displayName, module: name, level: levelName, source }, undefined, { escape: false }))
			cell.title = button.title
			cell.dataset.level = level
			cell.dataset.origin = origin.source
			cell.dataset.mark = kind
			cell.append(button)
		}
		// "Restricted to N" or "Scope unknown" of a resource scope, drawn and opened by src/admin/scope/controller.js.
		const mark = user.scope?.[module] && getResourceScope() ? getResourceScope().indicator(user.uid, module, user.scope[module]) : null
		if (mark) cell.append(mark)
		return cell
	}
	function originMark(user, module, operations) {
		const levels = Levels
		const origins = module === null ? { eligible: user.origins?.eligible } : user.origins?.grants?.[module]
		const origin = levels.originOf(operations, origins)
		if (origin.source !== 'individual' && origin.source !== 'invalid') {
			return { origin, mark: origin.source }
		}
		const exceptions = getExceptions()
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
	function markIcon(origin, mark) {
		if (mark === 'exception') return 'icon-user'
		if (mark === 'pending') return null
		return origin.source === 'group' || origin.source === 'unknown' ? 'icon-group' : null
	}
	function markText(origin, mark) {
		if (mark === 'pending') return t('mcp', 'Set for this person')
		if (mark === 'exception' && origin.source === 'individual') return t('mcp', 'Exception: differs from what the person inherits')
		if (mark === 'redundant') return originText(origin) + ' — ' + t('mcp', 'also set for this person, with the same value')
		return originText(origin)
	}
	function levelButton(user, module, className, parts, title, label) {
		const button = el('button', { type: 'button', className, title }, parts)
		button.onclick = () => getLevelPopover()?.open(button, user.uid, module)
		button.setAttribute('aria-label', label)
		button.setAttribute('aria-haspopup', 'dialog')
		button.setAttribute('aria-expanded', 'false')
		return button
	}
	function eligibleCell(user) {
		const cell = el('td', { className: 'mcp-cell mcp-level-cell' })
		cell.dataset.levelKey = 'eligible'
		const canConnect = t('mcp', 'Can connect')
		const reason = user.eligible === null ? t('mcp', 'Access is unknown. A group policy or directory is unavailable; retry after checking the group.') : unavailableReason(user, null)
		if (reason !== null) {
			const unknown = user.eligible === null
			paintState(cell, 'unavailable', reason)
			const mark = el('span', { className: 'mcp-state-mark', textContent: unknown ? '—' : '⊘' })
			mark.setAttribute('aria-hidden', 'true')
			const word = el('span', { className: 'mcp-level-word', textContent: unknown ? t('mcp', 'Unknown') : t('mcp', 'Unavailable') })
			word.setAttribute('aria-hidden', 'true')
			cell.append(mark, ' ', word, el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + canConnect + ': ' + cell.title }))
			if (unknown) cell.append(el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: reloadLocked }))
			return cell
		}
		const { origin, mark: kind } = originMark(user, null, ['eligible'])
		const individual = kind === 'exception'
		const icon = markIcon(origin, kind)
		const parts = []
		if (icon !== null) {
			const glyph = el('span', { className: 'mcp-origin-icon ' + icon })
			glyph.setAttribute('aria-hidden', 'true')
			parts.push(glyph)
		}
		const word = user.eligible ? t('mcp', 'Yes') : t('mcp', 'No')
		parts.push(el('span', { className: 'mcp-level-word', textContent: word }))
		paintState(cell, user.eligible ? 'allowed' : 'denied')
		const source = markText(origin, kind)
		const button = levelButton(user, null, 'mcp-level mcp-level-' + (user.eligible ? 'read' : 'none') + (individual ? ' mcp-level-individual' : ''), parts,
			canConnect + ': ' + word + ' — ' + source,
			t('mcp', '{user}, {module}: {level}. {source}.', { user: user.displayName, module: canConnect, level: word, source }, undefined, { escape: false }))
		cell.title = button.title
		cell.dataset.level = user.eligible ? 'read' : 'none'
		cell.dataset.origin = origin.source
		cell.dataset.mark = kind
		cell.append(button)
		return cell
	}
return { levelCell, eligibleCell }
}
