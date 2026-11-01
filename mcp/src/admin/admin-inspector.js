/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Effective access inspector (ticket 13; issue #4 A5, B6). Plain JavaScript, no build. It only reads: the effective
 * access of one user per operation, and why one path or one calendar is reached or not. Every text comes from the
 * codes the server answers, the same codes the tools are decided by; nothing here decides access.
 *
 * It opens as a drawer at the side of the page (S10), from its button on the Access tab or from the actions menu of a
 * matrix row, and answers first whether the person can use the tools at all: when one general check blocks every
 * operation (the account, the service, "Can connect" or the personal connection), one sentence says which and how to
 * fix it, next to the four general checks. Then one line per module gives the level the person has, where it comes
 * from, the resource scope and what blocks it; the table of every operation stays one click away. An individual value
 * that only repeats what the person inherits is said as the matrix says it ("also set for this person, with the same
 * value"), through the same read-only preview (src/admin/admin-exceptions.js); until that is known it is "set for this person".
 */
(function () {
	'use strict'
	const root = document.getElementById('mcp-inspector')
	if (!root) return
	const form = document.getElementById('mcp-inspector-form')
	const user = document.getElementById('mcp-inspector-user')
	const status = document.getElementById('mcp-inspector-status')
	const alert = document.getElementById('mcp-inspector-alert')
	const access = document.getElementById('mcp-inspector-access')
	const resources = document.getElementById('mcp-inspector-resources')
	const pathForm = document.getElementById('mcp-inspector-path-form')
	const pathInput = document.getElementById('mcp-inspector-path')
	const pathResult = document.getElementById('mcp-inspector-path-result')
	const calendarForm = document.getElementById('mcp-inspector-calendar-form')
	const calendarModule = document.getElementById('mcp-inspector-calendar-module')
	const calendarSelect = document.getElementById('mcp-inspector-calendar')
	const calendarOperation = document.getElementById('mcp-inspector-calendar-operation')
	const calendarResult = document.getElementById('mcp-inspector-calendar-result')
	let inspected = null
	let catalog = {}
	let seq = 0
	/**
	 * What each individual value of the person on screen is (src/admin/admin-exceptions.js, the same preview and rule as the
	 * matrix): per "module/operation", {kind: exception|redundant, after}. Null until known, and when it cannot be read:
	 * every individual value is then said as set for this person, never as repeating what they inherit.
	 */
	let kinds = null
	/** The answer on screen, drawn again once the kinds are known. */
	let shown = null

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		Object.assign(node, props)
		node.append(...children)
		return node
	}
	function say(message, error = false) {
		status.textContent = ''
		alert.textContent = ''
		;(error ? alert : status).textContent = message
	}
	async function get(path, query) {
		const response = await fetch(OC.generateUrl('/apps/mcp' + path) + '?' + new URLSearchParams(query), {
			headers: { Accept: 'application/json', requesttoken: OC.requestToken },
		})
		const data = await response.json().catch(() => ({}))
		if (!response.ok) throw Object.assign(new Error(String(response.status)), { status: response.status, data })
		return data
	}
	function failure(error) {
		if (error.status === 403) return t('mcp', 'Only administrators can inspect access.')
		if (error.status === 400) return t('mcp', 'Check the user ID and the value entered, then try again.')
		if (error.status === 503) return t('mcp', 'The MCP policy cannot be read right now, so nothing can be confirmed. Try again shortly.')
		return t('mcp', 'The inspection failed. Try again.')
	}
	function moduleName(module) {
		return { files: t('mcp', 'Files'), notes: t('mcp', 'Notes'), deck: t('mcp', 'Deck'), calendar: t('mcp', 'Calendar'), contacts: t('mcp', 'Contacts'), tasks: t('mcp', 'Tasks'), talk: t('mcp', 'Talk'), people: t('mcp', 'People'), logs: t('mcp', 'Server log'), mail: t('mcp', 'Mail'), tables: t('mcp', 'Tables'), forms: t('mcp', 'Forms'), polls: t('mcp', 'Polls') }[module] || module
	}
	function operationName(operation) {
		return { ...window.OCA.Mcp.Admin.pollsGrants?.operationLabels(), results: t('mcp', 'results'), read: t('mcp', 'read'), edit: t('mcp', 'edit'), create: t('mcp', 'create'), move: t('mcp', 'move'), delete: t('mcp', 'delete'), transfer: t('mcp', 'transfer'), reply: t('mcp', 'reply'), attach: t('mcp', 'attach'), quote: t('mcp', 'quote'), share: t('mcp', 'share'), link: t('mcp', 'link'), restore: t('mcp', 'restore'), messages: t('mcp', 'messages'), attachments: t('mcp', 'attachments'), flags: t('mcp', 'flags'), draft: t('mcp', 'draft'), send: t('mcp', 'send') }[operation] || operation
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
		const kind = kinds?.get(row.module + '/' + row.operation)
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
			tag_folder: t('mcp', 'Not reached: it is inside the excluded folder {folder}.', { folder: answer.folder || '' }),
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

	/** The general checks, in the order the tools apply them; one of them blocking blocks every operation. */
	const GENERAL = ['account', 'service', 'eligibility', 'connection']

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
			return [el('p', { className: 'mcp-inspector-verdict', textContent: t('mcp', 'No operation was answered, so nothing can be said about {user}.', { user: inspected }, undefined, { escape: false }) })]
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
				return [el('p', { className: 'mcp-inspector-verdict mcp-inspector-allowed', textContent: t('mcp', '{user} can use the MCP tools allowed below: the general checks passed.', { user: inspected }, undefined, { escape: false }) }), checks]
			}
			const text = {
				unknown: t('mcp', 'Not confirmed: no operation of {user} is allowed for sure now, and some cannot be confirmed.', { user: inspected }, undefined, { escape: false }),
				none_granted: t('mcp', '{user} cannot use any MCP tool: nothing is granted.', { user: inspected }, undefined, { escape: false }),
				none: t('mcp', '{user} cannot use any MCP tool: every operation is blocked, as each module below says.', { user: inspected }, undefined, { escape: false }),
			}[result]
			const said = el('div', { className: 'mcp-note ' + (result === 'unknown' ? 'mcp-note-warning' : 'mcp-note-error') }, [el('p', { textContent: text })])
			said.setAttribute('role', 'status')
			return [said, checks]
		}
		const note = el('div', { className: 'mcp-note ' + (block === 'eligibility_unknown' ? 'mcp-note-warning' : 'mcp-note-error') }, [
			el('p', {}, [el('strong', { textContent: t('mcp', '{user} cannot use any MCP tool.', { user: inspected }, undefined, { escape: false }) }), ' ' + gateText(block) + (remedy(block) ? ' ' + remedy(block) : '')]),
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
		const levels = window.OCA.Mcp.Admin.levels
		const byModule = new Map()
		for (const row of operations) {
			if (!byModule.has(row.module)) byModule.set(row.module, [])
			byModule.get(row.module).push(row)
		}
		const levelName = { ...window.OCA.Mcp.Admin.pollsGrants?.levelLabels(), forms_write: t('mcp', 'Read and write forms'), forms_write_results: t('mcp', 'Read and write forms and read responses'), none: t('mcp', 'None'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom'), unknown: t('mcp', 'Unknown') }
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
		const known = individual.map((row) => kinds?.get(row.module + '/' + row.operation))
		if (kinds === null || known.some((kind) => !kind)) return t('mcp', 'Set for this person')
		if (known.some((kind) => kind.kind === 'exception')) return t('mcp', 'Exception: differs from what the person inherits')
		const repeated = Object.fromEntries(rows.map((row) => {
			const index = individual.indexOf(row)
			return [row.operation, index < 0 ? row.grant : known[index].after || { source: 'default', groupIds: [] }]
		}))
		const origin = window.OCA.Mcp.Admin.levels.originOf(rows.map((row) => row.operation), repeated)
		const text = {
			group: t('mcp', 'Group: {groups}', { groups: origin.groupIds.join(', ') }),
			unknown: t('mcp', 'Group awaiting confirmation: {groups}', { groups: origin.groupIds.join(', ') }),
		}[origin.source] || t('mcp', 'Default')
		return text + ' — ' + t('mcp', 'also set for this person, with the same value')
	}

	/**
	 * Asks what the individual values of the answer on screen repeat, then draws it again; an answer for another person
	 * or another inspection is dropped.
	 */
	async function classifyShown(data, mine) {
		const exceptions = window.OCA.Mcp.Admin.exceptions
		if (!exceptions?.classifyPerson || typeof data.revision !== 'string') return
		const cells = data.operations.map((row) => ({ module: row.module, operation: row.operation, allowed: row.grant?.allowed ?? null, source: row.grant?.source }))
		if (!cells.some((cell) => cell.source === 'individual_allow' || cell.source === 'individual_deny')) return
		try {
			const answer = await exceptions.classifyPerson(inspected, cells, data.revision)
			if (mine !== seq || shown !== data) return
			kinds = answer
		} catch (e) {
			return
		}
		// Drawn again in place: the table of every operation stays open, and a focused summary keeps the focus.
		const details = access.querySelector?.('details.mcp-inspector-all')
		const wasOpen = Boolean(details?.open)
		const hadFocus = document.activeElement !== undefined && document.activeElement === details?.querySelector?.('summary')
		renderAccess(data)
		const again = access.querySelector?.('details.mcp-inspector-all')
		if (again && wasOpen) again.open = true
		if (hadFocus) again?.querySelector?.('summary')?.focus?.()
	}

	function renderAccess(data) {
		shown = data
		catalog = {}
		const rows = data.operations.map((row) => {
			;(catalog[row.module] = catalog[row.module] || []).push(row.operation)
			const scope = row.scope && row.scope.mode === 'restricted' ? ' · ' + t('mcp', 'Restricted to {count}', { count: row.scope.count }) : ''
			return el('tr', {}, [
				el('th', { scope: 'row', textContent: moduleName(row.module) + ': ' + operationName(row.operation) }),
				el('td', { textContent: resultText(row.allowed), className: 'mcp-inspector-' + (row.allowed === true ? 'allowed' : row.allowed === false ? 'blocked' : 'unknown') }),
				el('td', { textContent: row.blockingGate === null ? '—' : gateText(row.blockingGate) }),
				el('td', { textContent: grantText(row) + scope }),
			])
		})
		const head = el('tr', {}, [t('mcp', 'Operation'), t('mcp', 'Result'), t('mcp', 'First check that blocks'), t('mcp', 'Grant')].map((text) => el('th', { scope: 'col', textContent: text })))
		const table = el('table', { className: 'mcp-inspector-table' }, [el('caption', { textContent: t('mcp', 'Effective access of {user}', { user: inspected }) }), el('thead', {}, [head]), el('tbody', {}, rows)])
		access.replaceChildren(...renderVerdict(data.operations), renderModules(data.operations),
			el('details', { className: 'mcp-inspector-all' }, [el('summary', { textContent: t('mcp', 'Every operation') }), table]))
	}

	async function inspect() {
		const uid = user.value.trim()
		if (uid === '') {
			say(t('mcp', 'Enter the user ID to inspect.'), true)
			return
		}
		const mine = ++seq
		access.setAttribute('aria-busy', 'true')
		say(t('mcp', 'Inspecting…'))
		try {
			const data = await get('/api/inspector/access', { uid })
			if (mine !== seq) return
			inspected = uid
			kinds = null
			renderAccess(data)
			classifyShown(data, mine)
			resources.hidden = false
			pathResult.textContent = ''
			calendarResult.textContent = ''
			await loadCalendars()
			say(t('mcp', 'Access of {user} as the tools see it now.', { user: uid }))
		} catch (error) {
			if (mine !== seq) return
			inspected = null
			kinds = null
			shown = null
			access.replaceChildren()
			resources.hidden = true
			say(failure(error), true)
		} finally {
			if (mine === seq) access.setAttribute('aria-busy', 'false')
		}
	}

	/** A request to the user or module that is no longer on screen is answered too late: its answer is dropped. */
	const asked = { calendars: 0, path: 0, calendar: 0 }
	function stale(kind, mine, user) {
		return mine !== asked[kind] || user !== inspected
	}

	async function loadCalendars() {
		const module = calendarModule.value
		const mine = ++asked.calendars
		const user = inspected
		calendarOperation.replaceChildren(...(catalog[module] || ['read']).map((operation) => el('option', { value: operation, textContent: operationName(operation) })))
		calendarSelect.replaceChildren()
		try {
			const data = await get('/api/scope/' + encodeURIComponent(user) + '/' + module + '/candidates', {})
			if (stale('calendars', mine, user) || module !== calendarModule.value) return
			calendarSelect.replaceChildren(...data.items.map((item) => el('option', { value: item.id, textContent: item.name })))
			if (data.items.length === 0) calendarResult.textContent = t('mcp', 'This user has no calendar or task list of this kind in Nextcloud.')
		} catch (error) {
			if (stale('calendars', mine, user)) return
			say(failure(error), true)
		}
	}

	async function explain(kind, target, path, query, module) {
		const mine = ++asked[kind]
		const user = inspected
		target.textContent = t('mcp', 'Checking…')
		try {
			const answer = await get(path, Object.assign({ uid: user }, query))
			if (stale(kind, mine, user)) return
			target.textContent = explanation(answer)
			if (answer.reason === 'out_of_scope' && window.OCA.Mcp.Admin.scope) {
				const change = el('button', { type: 'button', textContent: t('mcp', 'Open the resource scope') })
				change.addEventListener('click', () => window.OCA.Mcp.Admin.scope.open(user, module))
				target.append(' ', change)
			}
		} catch (error) {
			if (stale(kind, mine, user)) return
			// An error is announced like every other error of the inspector, in its alert region.
			target.textContent = ''
			say(failure(error), true)
		}
	}

	form.addEventListener('submit', (event) => { event.preventDefault(); inspect() })
	const drawerTitle = document.getElementById('mcp-inspector-title')
	const closeButton = document.getElementById('mcp-inspector-close')
	const entryButton = document.getElementById('mcp-inspector-entry')
	let returnFocus = null
	let returnRow = null

	/**
	 * Puts the open drawer right under the Nextcloud header. A drawer is position: fixed, but Nextcloud 35 blurs the content
	 * behind it (backdrop-filter on #content-vue), which makes that box, not the window, the one it is placed in: its top
	 * would then be counted from the box, one header too low. The offset is measured, not assumed, since a theme may turn
	 * the blur off.
	 */
	function placeDrawer() {
		root.style?.removeProperty?.('top')
		const rect = root.getBoundingClientRect?.()
		if (!rect) return
		const header = document.getElementById('header')?.getBoundingClientRect?.().bottom ?? 0
		const shift = rect.top - header
		if (Math.abs(shift) >= 1) root.style.top = (parseFloat(getComputedStyle(root).top) - shift) + 'px'
	}

	/**
	 * Opens the drawer; the focus goes back to `trigger` when it closes. Only one drawer is open at a time: when the
	 * administrator keeps a draft of the resource scope, its drawer stays and this one does not open.
	 *
	 * @return {boolean} whether the drawer opened
	 */
	function openDrawer(trigger) {
		if (window.OCA.Mcp.Admin.scope?.close?.() === false) {
			trigger?.focus?.()
			return false
		}
		returnFocus = trigger || document.activeElement || null
		// The row of a "…" button that opened the drawer: the table may be drawn again meanwhile, and its new button is
		// the one that gets the focus back.
		returnRow = returnFocus?.closest?.('tr')?.dataset?.uid ?? null
		root.classList?.add('mcp-drawer-open')
		root.setAttribute('role', 'dialog')
		placeDrawer()
		return true
	}

	/**
	 * Closes the drawer and gives the focus back. The inspector only reads, so nothing can be lost.
	 *
	 * @return {boolean} always true: the drawer is closed now
	 */
	function closeDrawer() {
		if (!root.classList?.contains('mcp-drawer-open')) return true
		root.classList.remove('mcp-drawer-open')
		root.removeAttribute?.('role')
		root.style?.removeProperty?.('top')
		const target = returnFocus
		returnFocus = null
		focusBack(target)
		window.dispatchEvent?.(new Event('mcp-drawer-closed'))
		return true
	}

	/**
	 * Gives the focus back to the control that opened the drawer, or, when the table was drawn again and that control is
	 * gone, to the same row's "…" button, then to the drawer's own button on the Access tab: never to the page.
	 */
	function focusBack(target) {
		if (target && target.isConnected !== false) {
			target.focus?.()
			return
		}
		const rows = [...(document.getElementById('mcp-matrix')?.tBodies?.[0]?.rows || [])]
		const row = returnRow === null ? null : rows.find((candidate) => candidate.dataset.uid === returnRow)
		;(row?.querySelector('button.mcp-row-actions-button') || entryButton)?.focus?.()
	}

	entryButton?.addEventListener('click', () => {
		if (openDrawer(entryButton)) user.focus?.()
	})
	closeButton?.addEventListener('click', () => closeDrawer())
	root.addEventListener?.('keydown', (event) => {
		if (event.key === 'Escape' && root.classList?.contains('mcp-drawer-open')) {
			event.preventDefault?.()
			closeDrawer()
		}
	})

	// The actions menu of a matrix row inspects that user (src/admin/admin-row-actions.js), in the drawer.
	window.OCA.Mcp.Admin.inspector = {
		open(target, trigger) {
			if (!openDrawer(trigger)) return false
			user.value = target
			drawerTitle?.focus?.()
			inspect()
			return true
		},
		close: closeDrawer,
	}
	pathForm.addEventListener('submit', (event) => {
		event.preventDefault()
		const path = pathInput.value.trim()
		if (inspected === null || path === '') return
		explain('path', pathResult, '/api/inspector/path', { path: path.startsWith('/') ? path : '/' + path }, 'files')
	})
	calendarModule.addEventListener('change', () => { if (inspected !== null) loadCalendars() })
	calendarForm.addEventListener('submit', (event) => {
		event.preventDefault()
		if (inspected === null || calendarSelect.value === '') return
		explain('calendar', calendarResult, '/api/inspector/calendar', { module: calendarModule.value, calendarId: calendarSelect.value, operation: calendarOperation.value }, calendarModule.value)
	})
})()
