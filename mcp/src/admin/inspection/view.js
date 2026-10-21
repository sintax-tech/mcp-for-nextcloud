// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import * as Levels from '../access/levels.js'
const GENERAL = ['account', 'service', 'eligibility', 'connection']
export function createInspectionView({ t, el, getUser, getKinds }) {
	function moduleName(module) {
		return { files: t('mcp', 'Files'), notes: t('mcp', 'Notes'), deck: t('mcp', 'Deck'), calendar: t('mcp', 'Calendar'), contacts: t('mcp', 'Contacts'), tasks: t('mcp', 'Tasks'), talk: t('mcp', 'Talk'), people: t('mcp', 'People'), logs: t('mcp', 'Server log') }[module] || module
	}
	function operationName(operation) {
		return { read: t('mcp', 'read'), edit: t('mcp', 'edit'), create: t('mcp', 'create'), move: t('mcp', 'move'), delete: t('mcp', 'delete'), transfer: t('mcp', 'transfer'), reply: t('mcp', 'reply'), attach: t('mcp', 'attach'), quote: t('mcp', 'quote'), share: t('mcp', 'share'), link: t('mcp', 'link'), restore: t('mcp', 'restore') }[operation] || operation
	}
	/** The first check that blocks, by the code of the effective access (EffectiveResult::blockingGate). */
	function gateText(gate) {
		return {
			account: t('mcp', 'The account does not exist or is disabled.'),
			service: t('mcp', 'The MCP service is switched off.'),
			eligibility: t('mcp', 'The user may not connect to MCP.'),
			eligibility_unknown: t('mcp', 'Unknown: whether the user may connect depends on a group whose policy cannot be confirmed now.'),
			connection: t('mcp', 'The user has not switched on their MCP connection.'),
			app: t('mcp', 'The app of this module is not available to the user.'),
			logs_available: t('mcp', 'The server log cannot be read on this instance.'),
			logs_role: t('mcp', 'The user does not have the role needed to read the server log.'),
			grant: t('mcp', 'Not granted.'),
			grant_unknown: t('mcp', 'Unknown: the grant depends on a group whose policy cannot be confirmed now.'),
			resource_scope: t('mcp', 'Outside the resource scope of this user.'),
			resource_scope_unknown: t('mcp', 'Unknown: the resource scope of this user cannot be read.'),
		}[gate] || gate
	}
	/**
	 * Where one operation's value comes from, in words. An individual value that only repeats what the person inherits
	 * says what it repeats, as the matrix does.
	 */
	function grantText(row) {
		const kind = getKinds()?.get(row.module + '/' + row.operation)
		if (kind?.kind === 'redundant' && kind.after) {
			return sourceText(kind.after) + ' — ' + t('mcp', 'also set for this person, with the same value')
		}
		return sourceText(row.grant)
	}
	function sourceText(grant) {
		const groups = (grant.groupIds || []).join(', ')
		return {
			individual_allow: t('mcp', 'Individual: allowed'),
			individual_deny: t('mcp', 'Individual: denied'),
			group: t('mcp', 'Group: {groups}', { groups }),
			default: t('mcp', 'Default'),
			invalid: t('mcp', 'Invalid stored value (denied)'),
			unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups }),
		}[grant.source] || grant.source
	}
	function resultText(allowed) {
		return allowed === true ? t('mcp', 'Allowed') : allowed === false ? t('mcp', 'Blocked') : t('mcp', 'Unknown')
	}
	/** What a path or calendar answer means, from its codes (AccessInspector, CalendarAccessExplainer). */
	function explanation(answer) {
		const checks = { policy: t('mcp', 'the MCP policy'), native_acl: t('mcp', 'the Nextcloud permissions'), exclusion: t('mcp', 'the exclusion tags'), resource_scope_unknown: t('mcp', 'the resource scope'), grant_unknown: t('mcp', 'a group grant'), eligibility_unknown: t('mcp', 'a group eligibility') }
		const exclusions = {
			tag_direct: t('mcp', 'Not reached: it carries an exclusion tag.'),
			tag_folder: t('mcp', 'Not reached: it is inside the excluded folder {folder}.', { folder: answer.folder || '' }, undefined, { escape: false }),
			tag_share_origin: t('mcp', 'Not reached: the shared item is excluded in the files of its owner.'),
			backup_of_excluded: t('mcp', 'Not reached: it is an MCP backup of an excluded file.'),
			invalid_configuration: t('mcp', 'Not reached: the saved exclusion tags cannot be read, so every file is refused.'),
		}
		switch (answer.reason) {
		case 'allowed': return t('mcp', 'Reached: every check passed.')
		case 'blocked': return t('mcp', 'Not reached: {reason}', { reason: gateText(answer.gate) })
		case 'out_of_scope': return t('mcp', 'Not reached: outside the resource scope of this user.')
		case 'not_found': return t('mcp', 'Not reached: the path does not exist in the files of this user.')
		case 'no_native_access': return t('mcp', 'Not reached: the user has no access to it in Nextcloud.')
		case 'read_only': return t('mcp', 'Not reached for this operation: the user can only read it in Nextcloud.')
		case 'excluded': return exclusions[answer.exclusion] || t('mcp', 'Not reached: it is excluded from MCP access.')
		case 'not_evaluated': return t('mcp', 'Not confirmed: {check} could not be checked, so access is not known.', { check: checks[answer.gate] || answer.gate })
		default: return answer.reason
		}
	}
	/**
	 * The general check that blocks every operation, or null when none does: every operation must be blocked by it.
	 *
	 * @param {Array<object>} operations the operations of the answer
	 * @return {string|null} a gate of GENERAL, or eligibility_unknown
	 */
	function generalBlock(operations) {
		const gates = new Set(operations.map((row) => row.blockingGate))
		if (operations.length === 0 || gates.size !== 1) return null
		const [gate] = gates
		return GENERAL.includes(gate) || gate === 'eligibility_unknown' ? gate : null
	}
	/** What to do about a general check that blocks, when the administrator or the person can do something. */
	function remedy(gate) {
		return {
			service: t('mcp', 'Turn the service on in the Overview tab.'),
			eligibility: t('mcp', 'Turn on “Can connect” in the matrix or through a group.'),
			connection: t('mcp', 'They turn it on in their personal settings, under MCP for Cloud.'),
		}[gate] || ''
	}
	/**
	 * What the operations say once no general check blocks: the tools can be used when one operation is allowed, it is
	 * not confirmed when none is and one cannot be read, and nothing can be used when every one is blocked.
	 *
	 * @param {Array<object>} operations the operations of the answer, not empty
	 * @return {string} usable, unknown, none_granted (every one blocked by its grant) or none
	 */
	function outcome(operations) {
		if (operations.some((row) => row.allowed === true)) return 'usable'
		if (operations.some((row) => row.allowed !== false)) return 'unknown'
		return operations.every((row) => row.blockingGate === 'grant') ? 'none_granted' : 'none'
	}
	/** The verdict and the four general checks: passed, the one that blocks, or not checked after it. */
	function renderVerdict(operations) {
		// An answer without operations confirms nothing, not even the general checks.
		if (operations.length === 0) {
			return [el('p', { className: 'mcp-inspector-verdict', textContent: t('mcp', 'No operation was answered, so nothing can be said about {user}.', { user: getUser() }, undefined, { escape: false }) })]
		}
		const block = generalBlock(operations)
		const failed = block === 'eligibility_unknown' ? 'eligibility' : block
		const index = failed === null ? GENERAL.length : GENERAL.indexOf(failed)
		const names = {
			account: t('mcp', 'Account active'),
			service: t('mcp', 'MCP service on'),
			eligibility: t('mcp', 'Can connect'),
			connection: t('mcp', 'Personal connection on'),
		}
		const checks = el('ul', { className: 'mcp-inspector-checks' }, GENERAL.map((gate, i) => {
			const state = i < index ? 'passed' : i > index ? 'skipped' : block === 'eligibility_unknown' ? 'unknown' : 'failed'
			const mark = { passed: '✓', failed: '✕', unknown: '?', skipped: '—' }[state]
			const word = { passed: t('mcp', 'passed'), failed: t('mcp', 'blocks'), unknown: t('mcp', 'not confirmed'), skipped: t('mcp', 'not checked') }[state]
			const symbol = el('span', { className: 'mcp-inspector-mark', textContent: mark })
			symbol.setAttribute('aria-hidden', 'true')
			return el('li', { className: 'mcp-inspector-check-' + state }, [symbol, ' ', names[gate] + ': ' + word])
		}))
		if (block === null) {
			const result = outcome(operations)
			if (result === 'usable') {
				return [el('p', { className: 'mcp-inspector-verdict mcp-inspector-allowed', textContent: t('mcp', '{user} can use the MCP tools allowed below: the general checks passed.', { user: getUser() }, undefined, { escape: false }) }), checks]
			}
			const text = {
				unknown: t('mcp', 'Not confirmed: no operation of {user} is allowed for sure now, and some cannot be confirmed.', { user: getUser() }, undefined, { escape: false }),
				none_granted: t('mcp', '{user} cannot use any MCP tool: nothing is granted.', { user: getUser() }, undefined, { escape: false }),
				none: t('mcp', '{user} cannot use any MCP tool: every operation is blocked, as each module below says.', { user: getUser() }, undefined, { escape: false }),
			}[result]
			const said = el('div', { className: 'mcp-note ' + (result === 'unknown' ? 'mcp-note-warning' : 'mcp-note-error') }, [el('p', { textContent: text })])
			said.setAttribute('role', 'status')
			return [said, checks]
		}
		const note = el('div', { className: 'mcp-note ' + (block === 'eligibility_unknown' ? 'mcp-note-warning' : 'mcp-note-error') }, [
			el('p', {}, [el('strong', { textContent: t('mcp', '{user} cannot use any MCP tool.', { user: getUser() }, undefined, { escape: false }) }), ' ' + gateText(block) + (remedy(block) ? ' ' + remedy(block) : '')]),
		])
		note.setAttribute('role', 'status')
		return [note, checks]
	}
	/**
	 * One line per module: the level the grants give, where it comes from, the resource scope and what blocks it. While a
	 * general check blocks everything, the lines say what will apply once it is fixed, without repeating it on each.
	 */
	function renderModules(operations) {
		const general = generalBlock(operations) !== null
		const levels = Levels
		const byModule = new Map()
		for (const row of operations) {
			if (!byModule.has(row.module)) byModule.set(row.module, [])
			byModule.get(row.module).push(row)
		}
		const levelName = { none: t('mcp', 'None'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom'), unknown: t('mcp', 'Unknown') }
		const items = [...byModule.entries()].map(([module, rows]) => {
			const operationList = rows.map((row) => row.operation)
			const values = Object.fromEntries(rows.map((row) => [row.operation, row.grant ? row.grant.allowed : row.allowed]))
			const level = levels ? levels.levelOf(module, operationList, values) : null
			const gates = new Set(rows.map((row) => row.blockingGate).filter((gate) => gate !== null && gate !== 'grant'))
			const blocked = !general && rows.every((row) => row.allowed === false) && gates.size === 1 ? [...gates][0] : null
			const origin = levels ? levels.originOf(operationList, Object.fromEntries(rows.map((row) => [row.operation, row.grant]))) : null
			const source = origin === null ? '' : origin.source === 'individual' ? individualText(rows) : {
				invalid: t('mcp', 'Invalid stored value (denied)'),
				group: t('mcp', 'Group: {groups}', { groups: origin.groupIds.join(', ') }),
				unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') }),
				default: t('mcp', 'Default'),
			}[origin.source]
			const scope = rows[0].scope && rows[0].scope.mode === 'restricted' ? t('mcp', 'Restricted to {count}', { count: rows[0].scope.count }) : ''
			const state = blocked !== null
				? el('span', { className: 'mcp-inspector-blocked', textContent: t('mcp', 'Blocked') })
				: el('span', { className: level === 'unknown' ? 'mcp-inspector-unknown' : 'mcp-inspector-level', textContent: level === null ? '' : levelName[level] || level })
			const detail = [blocked !== null ? gateText(blocked) : source, scope].filter((text) => text !== '').join(' — ')
			return el('li', { className: 'mcp-inspector-module' }, [el('span', { className: 'mcp-inspector-module-name', textContent: moduleName(module) }), state, el('span', { className: 'mcp-hint', textContent: detail })])
		})
		const list = el('ul', { className: 'mcp-inspector-modules' }, items)
		return general ? el('div', {}, [el('p', { className: 'mcp-hint', textContent: t('mcp', 'What each module grants, once the general check is fixed:') }), list]) : list
	}
	/**
	 * Where a module's individual values come from, as the matrix says it: an exception when one of them differs from what
	 * the person inherits, what they repeat when every one only repeats it, and "set for this person" until that is known.
	 */
	function individualText(rows) {
		const individual = rows.filter((row) => row.grant?.source === 'individual_allow' || row.grant?.source === 'individual_deny')
		const known = individual.map((row) => getKinds()?.get(row.module + '/' + row.operation))
		if (getKinds() === null || known.some((kind) => !kind)) return t('mcp', 'Set for this person')
		if (known.some((kind) => kind.kind === 'exception')) return t('mcp', 'Exception: differs from what the person inherits')
		const repeated = Object.fromEntries(rows.map((row) => {
			const index = individual.indexOf(row)
			return [row.operation, index < 0 ? row.grant : known[index].after || { source: 'default', groupIds: [] }]
		}))
		const origin = Levels.originOf(rows.map((row) => row.operation), repeated)
		const text = {
			group: t('mcp', 'Group: {groups}', { groups: origin.groupIds.join(', ') }),
			unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') }),
		}[origin.source] || t('mcp', 'Default')
		return text + ' — ' + t('mcp', 'also set for this person, with the same value')
	}
    return { moduleName, operationName, gateText, grantText, resultText, explanation, renderVerdict, renderModules }
}
