/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Group policy editor. Plain JavaScript, no build. Drafts survive refused saves; writes are never replayed. */
(function () {
	'use strict'
	const root = document.getElementById('mcp-group-editor')
	if (!root) return
	const search = document.getElementById('mcp-group-search')
	const list = document.getElementById('mcp-group-list')
	const detail = document.getElementById('mcp-group-detail')
	const status = document.getElementById('mcp-group-status')
	const alert = document.getElementById('mcp-group-alert')
	const prev = document.getElementById('mcp-group-prev')
	const next = document.getElementById('mcp-group-next')
	const counter = document.getElementById('mcp-group-count')
	const columns = document.getElementById('mcp-groups')
	const picker = document.getElementById('mcp-group-picker')
	const filters = { all: document.getElementById('mcp-group-filter-all'), granted: document.getElementById('mcp-group-filter-granted'), blocked: document.getElementById('mcp-group-filter-blocked') }
	let page = 1
	/** Filter of the list, sent to the server with the search and the page: all, granted or blocked. */
	let filter = 'all'
	/** Rows of the page on screen, kept so the open group can be marked without reading the list again. */
	let rows = []
	let listSeq = 0
	let detailSeq = 0
	let current = null
	/** Name of the open group as the list shows it; the detail API returns only the ID. */
	let shownName = null
	let draft = {}
	let preview = null
	/** Whether the detail shows each module's operations instead of its level (the "Operations" switch). */
	let advanced = false
	let busy = false
	let timer
	let sayTimer
	/** Milliseconds between clearing a live region and writing into it. */
	const SAY_DELAY = 60
	/** Groups per page of the directory API. */
	const PAGE_SIZE = 50
	/** Options of t() for text written with textContent, which escapes by itself. */
	const RAW = { escape: false }

	function el(tag, props = {}, children = []) {
		const node = document.createElement(tag)
		Object.assign(node, props)
		node.append(...children)
		return node
	}
	/**
	 * Writes into the fixed live regions. Both are cleared at once and the message is written shortly after, in
	 * another task, so the same error twice in a row is announced again, and a message that was replaced or cleared
	 * in the meantime never appears.
	 */
	function say(message, error = false) {
		clearTimeout(sayTimer)
		status.textContent = ''
		alert.textContent = ''
		if (message === '') return
		const region = error ? alert : status
		sayTimer = setTimeout(() => { region.textContent = message }, SAY_DELAY)
	}
	async function api(method, path, body) {
		const response = await fetch(OC.generateUrl('/apps/mcp' + path), {
			method,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		// A proxy or expired session can return HTML with status 200. Only a readable JSON response confirms a save.
		const data = await response.json().catch((error) => {
			if (response.ok) throw Object.assign(new Error('unconfirmed'), { cause: error })
			return {}
		})
		if (!response.ok) throw Object.assign(new Error(String(response.status)), { data })
		return data
	}
	const copyGrants = (grants) => Object.fromEntries(Object.entries(grants).map(([module, ops]) => [module, [...ops]]))
	const unknown = () => t('mcp', 'Unknown count')
	const count = (value) => value === null || value === undefined ? unknown() : String(value)
	const untrusted = () => t('mcp', 'This group comes from a source that cannot guarantee group identity (for example LDAP). Grant MCP access to its members individually.')
	function moduleName(module) {
		return { files: t('mcp', 'Files'), notes: t('mcp', 'Notes'), deck: t('mcp', 'Deck'), calendar: t('mcp', 'Calendar'), contacts: t('mcp', 'Contacts'), tasks: t('mcp', 'Tasks'), talk: t('mcp', 'Talk'), people: t('mcp', 'People'), logs: t('mcp', 'Server log'), mail: t('mcp', 'Mail'), tables: t('mcp', 'Tables'), forms: t('mcp', 'Forms'), polls: t('mcp', 'Polls') }[module] || module
	}
	function operationName(operation) {
		return { ...window.McpAdminPollsGrants?.operationLabels(), results: t('mcp', 'results'), read: t('mcp', 'read'), edit: t('mcp', 'edit'), create: t('mcp', 'create'), move: t('mcp', 'move'), delete: t('mcp', 'delete'), transfer: t('mcp', 'transfer'), reply: t('mcp', 'reply'), attach: t('mcp', 'attach'), quote: t('mcp', 'quote'), share: t('mcp', 'share'), link: t('mcp', 'link'), restore: t('mcp', 'restore'), messages: t('mcp', 'messages'), attachments: t('mcp', 'attachments'), flags: t('mcp', 'flags'), draft: t('mcp', 'draft'), send: t('mcp', 'send') }[operation] || operation
	}
	function grantKeys(grants) {
		return Object.entries(grants).flatMap(([module, operations]) => operations.map((operation) => module + ':' + operation)).sort()
	}
	function dirty() {
		return current && JSON.stringify(grantKeys(draft)) !== JSON.stringify(grantKeys(current.grants))
	}
	function discardDraft() {
		return !dirty() || window.confirm(t('mcp', 'Discard the unsaved group changes?'))
	}
	function diagnosis(group) {
		if (group.invalidId) return t('mcp', 'The directory returned an invalid group ID. This group cannot be edited.')
		if (group.trusted === null) return t('mcp', 'The group source is unavailable. Retry before editing.')
		if (group.exists === false) return t('mcp', 'This group no longer exists. Its saved policy does not grant access; you can clear it.')
		if (!group.trusted) return untrusted()
		if (group.state === 'needs_confirm') return t('mcp', 'This policy is awaiting confirmation and does not grant access. Confirming or saving will let it grant access again.')
		if (group.state === 'pending_delete') return t('mcp', 'Group deletion is pending. This policy does not grant access. Check the group before confirming it.')
		return ''
	}
	/** Material Design Icons "lock-outline", in the text colour, so it follows the theme; decoration, hidden from assistive technology. */
	const LOCK_ICON = '<svg class="mcp-group-lock" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true" focusable="false"><path d="M12,17C10.89,17 10,16.1 10,15C10,13.89 10.89,13 12,13A2,2 0 0,1 14,15A2,2 0 0,1 12,17M18,20V10H6V20H18M18,8A2,2 0 0,1 20,10V20A2,2 0 0,1 18,22H6C4.89,22 4,21.1 4,20V10C4,8.89 4.89,8 6,8H7V6A5,5 0 0,1 12,1A5,5 0 0,1 17,6V8H18M12,3A3,3 0 0,0 9,6V8H15V6A3,3 0 0,0 12,3Z"/></svg>'
	/**
	 * The state of a group in one short phrase, for its row in the list; '' when the group can grant normally. The
	 * phrase always carries the state in words, next to a symbol, so it never depends on colour.
	 *
	 * @return {{symbol: string, lock?: boolean, text: string, warning: boolean}|null} a lock is drawn as an icon, not as a symbol
	 */
	function rowState(group) {
		if (group.invalidId) return { symbol: '⊘', text: t('mcp', 'Cannot be edited: invalid group ID'), warning: false }
		if (group.trusted === null) return { symbol: '?', text: t('mcp', 'Source unavailable'), warning: true }
		if (group.exists === false) return { symbol: '⊘', text: t('mcp', 'Deleted group'), warning: false }
		if (!group.trusted) return { symbol: '', lock: true, text: t('mcp', 'Cannot grant access'), warning: false }
		if (group.state === 'needs_confirm') return { symbol: '⚠', text: t('mcp', 'Awaiting confirmation'), warning: true }
		if (group.state === 'pending_delete') return { symbol: '⚠', text: t('mcp', 'Deletion pending'), warning: true }
		return null
	}
	/** @return {string} what a group grants, in words, for the accessible name and the tooltip of its row */
	function grantSummary(group) {
		const granted = Object.entries(group.grants || {}).filter(([, operations]) => operations.length > 0).map(([module]) => module)
		if (granted.length === 0) return t('mcp', 'Grants nothing')
		return t('mcp', 'Grants: {permissions}', { permissions: granted.map((module) => module === '_system' ? t('mcp', 'Can connect') : moduleName(module)).join(', ') }, undefined, RAW)
	}
	/** @return {string} what a group grants in one or two words, for the end of its row: "—", "Can connect" or "N modules" */
	function grantShort(group) {
		const granted = Object.entries(group.grants || {}).filter(([, operations]) => operations.length > 0).map(([module]) => module)
		const apps = granted.filter((module) => module !== '_system').length
		if (apps > 0) return n('mcp', '%n module', '%n modules', apps)
		return granted.length > 0 ? t('mcp', 'Can connect') : '—'
	}
	/**
	 * One line per group: the name, cut with an ellipsis; the ID, quietly, only when it differs from the name; and at the
	 * end what it grants in short or the symbol of its state. The full name, the ID and the state or grants in words are
	 * the row's accessible name and tooltip, so nothing is told by the symbol or a colour alone.
	 *
	 * @return {HTMLButtonElement} the row's button; drawList() puts each in its list item
	 */
	function groupRow(group) {
		const differs = group.groupId !== null && group.groupId !== group.displayName
		const children = [el('span', { className: 'mcp-group-name', textContent: group.displayName })]
		if (differs) children.push(el('span', { className: 'mcp-group-id', textContent: group.groupId }))
		const state = rowState(group)
		let end
		if (state) {
			end = el('span', { className: 'mcp-group-end mcp-group-state' + (state.warning ? ' mcp-group-state-warning' : ''), textContent: state.symbol })
			if (state.lock) end.innerHTML = LOCK_ICON
		} else {
			end = el('span', { className: 'mcp-group-end', textContent: grantShort(group) })
		}
		end.setAttribute('aria-hidden', 'true')
		children.push(end)
		const label = [group.displayName, differs ? t('mcp', 'ID: {id}', { id: group.groupId }, undefined, RAW) : '', state ? state.text : grantSummary(group)].filter(Boolean).join('. ')
		const selected = current !== null && group.groupId !== null && current.groupId === group.groupId
		const button = el('button', { type: 'button', className: 'mcp-group-item', title: label, disabled: group.invalidId === true, tabIndex: -1, onclick: () => open(group.groupId, false, false, group.displayName) }, children)
		button.setAttribute('aria-label', label)
		button.setAttribute('aria-current', String(selected))
		return button
	}
	/** The row buttons drawn by drawList(), in order. */
	let buttons = []
	/** The rows that can take the focus, in order. */
	const items = () => buttons.filter((button) => !button.disabled)
	/** One stop in the tab order for the whole list (the open group, else the first); the arrows move inside it. */
	function rove(target) {
		for (const button of items()) button.tabIndex = button === target ? 0 : -1
	}
	/** Scrolls the list, and only the list, so that a row is in view. */
	function keepVisible(button) {
		if (typeof button.offsetTop !== 'number' || typeof list.clientHeight !== 'number') return
		const top = button.offsetTop - list.offsetTop
		if (top < list.scrollTop) list.scrollTop = top
		else if (top + button.offsetHeight > list.scrollTop + list.clientHeight) list.scrollTop = top + button.offsetHeight - list.clientHeight
	}
	function drawList() {
		buttons = rows.map(groupRow)
		list.replaceChildren(...buttons.map((button) => el('li', {}, [button])))
		if (!rows.length) list.append(el('li', { className: 'mcp-hint', textContent: t('mcp', 'No groups found.') }))
		const all = items()
		const open = all.find((button) => button.getAttribute('aria-current') === 'true')
		rove(open || all[0])
		if (open) keepVisible(open)
	}
	list.addEventListener('keydown', (event) => {
		const all = items()
		const at = all.indexOf(document.activeElement)
		if (at < 0) return
		const to = { ArrowDown: at + 1, ArrowUp: at - 1, Home: 0, End: all.length - 1 }[event.key]
		if (to === undefined) return
		event.preventDefault()
		const target = all[Math.max(0, Math.min(all.length - 1, to))]
		rove(target)
		target.focus()
	})
	/** The filter buttons say how many groups each shows, when the server knows it without extra work. */
	function drawFilters(counts) {
		const names = { all: t('mcp', 'All'), granted: t('mcp', 'With grants'), blocked: t('mcp', 'Blocked groups') }
		const counted = { all: (count) => t('mcp', 'All ({count})', { count }, undefined, RAW), granted: (count) => t('mcp', 'With grants ({count})', { count }, undefined, RAW), blocked: (count) => t('mcp', 'Blocked groups ({count})', { count }, undefined, RAW) }
		for (const [key, button] of Object.entries(filters)) {
			if (!button) continue
			const known = counts?.[key]
			button.textContent = typeof known === 'number' ? counted[key](known) : names[key]
			button.setAttribute('aria-pressed', String(key === filter))
		}
	}
	for (const [key, button] of Object.entries(filters)) {
		button?.addEventListener('click', () => {
			if (filter === key) return
			filter = key
			page = 1
			drawFilters(null)
			loadList()
		})
	}
	/** On a narrow screen the list folds into the picker above the editor; on a wide one both columns stay. */
	function setListOpen(isOpen) {
		if (!columns || !picker) return
		columns.setAttribute('data-list', isOpen ? 'open' : 'closed')
		picker.setAttribute('aria-expanded', String(isOpen))
		picker.textContent = current && !isOpen ? t('mcp', 'Group: {group}', { group: shownName ?? current.groupId }, undefined, RAW) : t('mcp', 'Choose a group')
	}
	picker?.addEventListener('click', () => {
		const isOpen = picker.getAttribute('aria-expanded') !== 'true'
		setListOpen(isOpen)
		if (isOpen) search.focus()
	})
	async function loadList() {
		const seq = ++listSeq
		list.setAttribute('aria-busy', 'true')
		prev.disabled = next.disabled = true
		try {
			const data = await api('GET', '/api/group-directory?' + new URLSearchParams({ search: search.value.trim(), page: String(page), filter }))
			if (seq !== listSeq) return
			rows = data.groups
			list.scrollTop = 0
			drawList()
			drawFilters(data.counts)
			const total = typeof data.total === 'number' ? data.total : rows.length
			const first = (page - 1) * PAGE_SIZE + 1
			counter.textContent = rows.length === 0 ? ''
				: page === 1 && !data.hasMore ? n('mcp', '%n group', '%n groups', total)
					: t('mcp', 'Groups {first}–{last} of {total}', { first, last: first + rows.length - 1, total }, undefined, RAW)
			prev.disabled = page <= 1
			next.disabled = !data.hasMore
			say('')
		} catch (e) {
			if (seq !== listSeq) return
			rows = []
			counter.textContent = ''
			list.replaceChildren(el('li', {}, [t('mcp', 'Could not load groups.'), retry(loadList)]))
			say(t('mcp', 'Could not load groups.'), true)
		} finally {
			if (seq === listSeq) list.setAttribute('aria-busy', 'false')
		}
	}
	function retry(action) {
		return el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: action })
	}
	async function open(gid, preserve = false, keepMessage = false, displayName = null) {
		if (busy || (!preserve && !discardDraft())) return
		if (displayName !== null) shownName = displayName
		const seq = ++detailSeq
		// Keep the draft's base until a read succeeds, including after a failed refresh.
		preview = null
		detail.replaceChildren(el('p', { textContent: t('mcp', 'Loading…') }))
		try {
			const data = await api('GET', '/api/group-directory/detail?' + new URLSearchParams({ gid }))
			if (seq !== detailSeq) return
			current = data
			if (!preserve) draft = copyGrants(data.grants)
			if (!keepMessage) say('')
			drawList()
			setListOpen(false)
			render('heading')
		} catch (e) {
			if (seq !== detailSeq) return
			detail.replaceChildren(retry(() => open(gid, preserve)))
			say(t('mcp', 'Could not read the group policy. Your draft is kept. Retry.'), true)
		}
	}
	/** Operations of a module in a grants object, whether it came as an object or as PHP's empty array. */
	const operationsIn = (grants, module) => (grants && !Array.isArray(grants) && grants[module]) || []
	/** The catalog's modules, without the "Can connect" key. */
	const modules = () => Object.entries(current.catalog).filter(([module]) => module !== '_system')
	/**
	 * The level a set of granted operations makes, with the matrix's tiers (js/admin-levels.js). A group only grants, so an
	 * operation outside the set is "not granted", never denied.
	 */
	function levelIn(grants, module) {
		const operations = current.catalog[module]
		const granted = operationsIn(grants, module)
		return window.McpAdminLevels.levelOf(module, operations, Object.fromEntries(operations.map((operation) => [operation, granted.includes(operation)])))
	}
	/** What a member gets without any grant: GrantPolicy::defaultGranted(), reading except the server log. */
	function defaultLevel(module) {
		const operations = current.catalog[module]
		return window.McpAdminLevels.levelOf(module, operations, Object.fromEntries(operations.map((operation) => [operation, operation === 'read' && module !== 'logs'])))
	}
	/** For a group the lowest level is "Not granted": the members keep what they have from elsewhere. */
	function levelName(level) {
		return { ...window.McpAdminPollsGrants?.levelLabels(), forms_write: t('mcp', 'Read and write forms'), forms_write_results: t('mcp', 'Read and write forms and read responses'), none: t('mcp', 'Not granted'), read: t('mcp', 'Read'), edit: t('mcp', 'Edit'), full: t('mcp', 'Full'), custom: t('mcp', 'Custom') }[level] || level
	}
	/** A level in words, a custom one with its operations, since "Custom → Custom" would say nothing. */
	function levelText(grants, module) {
		const level = levelIn(grants, module)
		if (level !== 'custom') return levelName(level)
		return t('mcp', 'Custom ({operations})', { operations: current.catalog[module].filter((operation) => operationsIn(grants, module).includes(operation)).map(operationName).join(', ') }, undefined, RAW)
	}
	/** One line per changed module, "Files: Read → Edit", and one for "Can connect". */
	function changes(before, after) {
		const lines = []
		const eligible = (grants) => operationsIn(grants, '_system').includes('eligible')
		if (eligible(before) !== eligible(after)) {
			lines.push(t('mcp', '{module}: {before} → {after}', { module: t('mcp', 'Can connect'), before: eligible(before) ? t('mcp', 'Yes') : t('mcp', 'No'), after: eligible(after) ? t('mcp', 'Yes') : t('mcp', 'No') }, undefined, RAW))
		}
		for (const [module] of modules()) {
			const was = [...operationsIn(before, module)].sort().join()
			const will = [...operationsIn(after, module)].sort().join()
			if (was !== will) lines.push(t('mcp', '{module}: {before} → {after}', { module: moduleName(module), before: levelText(before, module), after: levelText(after, module) }, undefined, RAW))
		}
		return lines
	}
	function setOperations(module, operations, focusKey) {
		if (operations.length) draft[module] = [...operations].sort()
		else delete draft[module]
		preview = null
		render(focusKey)
	}
	/**
	 * The matrix, filtered by this group and by "With exceptions", for the members whose individual settings win over it.
	 * The matrix's group filter lists the groups it can filter by; a group it does not list (an external group, or one that
	 * no longer exists) is said so here, and the page does not move. The focus goes to the exceptions filter, a real
	 * control, which also tells a screen reader where it landed.
	 */
	function viewInMatrix(gid, name) {
		const select = document.getElementById('mcp-group')
		const exceptionsOnly = document.getElementById('mcp-only-exceptions')
		if (!select || !Array.from(select.options).some((option) => option.value === gid)) {
			say(t('mcp', 'The matrix cannot show only the members of {group}: its group filter does not list this group.', { group: name }, undefined, RAW))
			return
		}
		select.value = gid
		select.dispatchEvent(new Event('change'))
		exceptionsOnly?.click()
		document.getElementById('mcp-block-matrix')?.scrollIntoView?.({ block: 'start' })
		exceptionsOnly?.focus()
	}
	/** A pressed level carries a check mark besides aria-pressed, as in the matrix popover, so it never depends on colour. */
	function levelButton(module, level, pressed, onclick) {
		const children = []
		if (pressed) {
			const mark = el('span', { className: 'mcp-pressed-mark', textContent: '✓' })
			mark.setAttribute('aria-hidden', 'true')
			children.push(mark)
		}
		children.push(levelName(level))
		const button = el('button', { type: 'button', name: 'mcp-group-level-' + module, value: level, className: 'mcp-group-level' + (level === 'none' ? ' mcp-group-level-none' : ''), onclick }, children)
		button.setAttribute('aria-pressed', String(pressed))
		return button
	}
	/**
	 * Draws the open group and focuses the control drawn under focusKey, so a change redraws without losing the place.
	 *
	 * @param {string|null} focusKey key of the control to focus, or null to leave the focus alone
	 */
	function render(focusKey = null) {
		const controls = new Map()
		const keyed = (key, node) => { controls.set(key, node); return node }
		const editable = !busy && current.trusted === true
		const name = shownName ?? current.groupId
		const heading = keyed('heading', el('h3', { tabIndex: -1, textContent: name }))
		heading.setAttribute('aria-describedby', 'mcp-group-alert mcp-group-diagnosis')
		const stats = current.counts
		const members = stats.members === null || stats.members === undefined
			? t('mcp', 'Unknown number of members')
			: n('mcp', '%n member', '%n members', stats.members)
		const head = el('div', { className: 'mcp-group-head' }, [heading])
		if (name !== current.groupId) head.append(el('span', { className: 'mcp-hint', textContent: t('mcp', 'ID: {id}', { id: current.groupId }, undefined, RAW) }))
		head.append(el('span', { className: 'mcp-hint', textContent: members }))
		const clear = el('button', { type: 'button', className: 'mcp-group-clear', textContent: t('mcp', 'Preview clearing group policy'), disabled: busy || grantKeys(current.grants).length === 0, onclick: () => {
			if (!discardDraft()) return
			draft = {}
			run('preview')
		} })
		head.append(clear)
		const confirm = el('button', { type: 'button', textContent: t('mcp', 'Confirm current policy'), disabled: !editable, onclick: () => {
			if (!discardDraft()) return
			draft = copyGrants(current.grants)
			run('preview')
		} })
		confirm.hidden = !['needs_confirm', 'pending_delete'].includes(current.state)
		// The group's notices live in its detail, under the title, with the action they ask for.
		const reason = diagnosis(current)
		const note = el('div', { className: 'mcp-note mcp-group-note', hidden: reason === '' }, [el('p', { id: 'mcp-group-diagnosis', textContent: reason }), confirm])

		const operationsSwitch = keyed('operations', el('input', { type: 'checkbox', id: 'mcp-group-operations', checked: advanced }))
		operationsSwitch.setAttribute('role', 'switch')
		operationsSwitch.addEventListener('change', () => { advanced = operationsSwitch.checked; render('operations') })
		const toolbar = el('div', { className: 'mcp-group-toolbar' }, [el('label', { htmlFor: 'mcp-group-operations' }, [operationsSwitch, t('mcp', 'Operations')])])

		const fields = el('fieldset', { disabled: !editable })
		fields.setAttribute('aria-describedby', 'mcp-group-diagnosis')
		fields.append(el('legend', { className: 'mcp-sr-only', textContent: t('mcp', 'Group grants') }))
		const eligible = keyed('eligible', el('input', { type: 'checkbox', name: 'mcp-group-eligible', id: 'mcp-group-eligible', checked: operationsIn(draft, '_system').includes('eligible') }))
		eligible.setAttribute('role', 'switch')
		eligible.addEventListener('change', () => setOperations('_system', eligible.checked ? ['eligible'] : [], 'eligible'))
		fields.append(el('div', { className: 'mcp-group-row' }, [el('label', { htmlFor: 'mcp-group-eligible', className: 'mcp-group-module' }, [t('mcp', 'Can connect')]), el('span', {}, [eligible]), el('span')]))
		for (const [module, operations] of modules()) {
			const label = el('span', { className: 'mcp-group-module', id: 'mcp-group-module-' + module, textContent: moduleName(module) })
			const granted = operationsIn(draft, module)
			const level = levelIn(draft, module)
			let control
			if (advanced) {
				control = el('span', { className: 'mcp-group-operations' }, operations.map((operation) => {
					const box = keyed('op:' + module + ':' + operation, el('input', { type: 'checkbox', name: 'mcp-group-op-' + module, value: operation, checked: granted.includes(operation) }))
					box.setAttribute('aria-label', moduleName(module) + ': ' + operationName(operation))
					box.addEventListener('change', () => {
						const values = new Set(granted)
						box.checked ? values.add(operation) : values.delete(operation)
						setOperations(module, [...values], 'op:' + module + ':' + operation)
					})
					return el('label', {}, [box, operationName(operation)])
				}))
			} else {
				control = el('span', { className: 'mcp-group-levels' }, window.McpAdminLevels.levels(module, operations).map((choice) => keyed('level:' + module + ':' + choice,
					levelButton(module, choice, choice === level, () => setOperations(module, window.McpAdminLevels.operationsFor(module, operations, choice), 'level:' + module + ':' + choice)))))
			}
			control.setAttribute('role', 'group')
			control.setAttribute('aria-labelledby', 'mcp-group-module-' + module)
			let hint = ''
			if (level === 'none') hint = t('mcp', 'Default: {level}', { level: defaultLevel(module) === 'none' ? t('mcp', 'None') : levelName(defaultLevel(module)) }, undefined, RAW)
			else if (level === 'custom' && !advanced) hint = t('mcp', 'Custom: change it in Operations')
			fields.append(el('div', { className: 'mcp-group-row' }, [label, control, el('span', { className: 'mcp-hint', textContent: hint })]))
		}

		// One sentence and a link instead of a table of counters: who wins over this group, and where to see them.
		const overrides = stats.overrides
		const exceptions = el('p', { className: 'mcp-group-exceptions' }, [overrides === null || overrides === undefined
			? t('mcp', 'The members with individual settings could not be counted.')
			: overrides === 0 ? t('mcp', 'No member has individual settings that take precedence over this group.')
				: n('mcp', '%n member has individual settings that take precedence over this group.', '%n members have individual settings that take precedence over this group.', overrides)])
		if (overrides !== 0) exceptions.append(el('button', { type: 'button', className: 'mcp-link-button', textContent: t('mcp', 'View in the matrix'), onclick: () => viewInMatrix(current.groupId, name) }))

		const parts = [head, note, toolbar, fields, exceptions]
		const save = keyed('save', el('button', { type: 'button', className: 'primary', textContent: t('mcp', 'Save group policy'), disabled: busy || !preview, onclick: () => run('save') }))
		const review = keyed('review', el('button', { type: 'button', className: 'primary', textContent: t('mcp', 'Review and save'), disabled: !editable, onclick: () => run('preview') }))
		for (const button of [review, confirm, clear, save]) button.setAttribute('aria-describedby', 'mcp-group-diagnosis')
		if (preview) {
			// The review of the preview the server returned: what changes, who is affected, and the save it allows.
			const region = el('div', { className: 'mcp-group-review' })
			region.setAttribute('role', 'region')
			region.setAttribute('aria-labelledby', 'mcp-group-review-title')
			const lines = changes(preview.current || current.grants, preview.grants || draft)
			region.append(el('h4', { id: 'mcp-group-review-title', textContent: t('mcp', 'Review the changes to {group}', { group: name }, undefined, RAW) }),
				lines.length ? el('ul', {}, lines.map((line) => el('li', { textContent: line }))) : el('p', { textContent: t('mcp', 'No change to the saved policy.') }),
				el('p', { textContent: t('mcp', 'Group grants apply to the live group, including future members. Individual overrides take precedence. Nextcloud permissions still apply.') }),
				el('p', { textContent: t('mcp', 'Preview: {members} current members; {revokes} credential holders will be signed out.', { members: count(preview.members), revokes: count(preview.revokes) }) }))
			if (preview.withdraws) region.append(el('p', { className: 'mcp-group-warning', textContent: t('mcp', 'Losing the last source of eligibility revokes connections. Restoring this policy does not reconnect users.') }))
			const back = el('button', { type: 'button', textContent: t('mcp', 'Back'), disabled: busy, onclick: () => { preview = null; render('review') } })
			region.append(el('div', { className: 'mcp-group-actions' }, [back, save]))
			parts.push(region)
		} else if (dirty()) {
			const lines = changes(current.grants, draft)
			const bar = el('div', { className: 'mcp-group-savebar' }, [
				el('div', { className: 'mcp-group-savebar-text' }, [el('strong', { textContent: n('mcp', '%n unsaved change', '%n unsaved changes', lines.length) }), el('span', { className: 'mcp-hint', textContent: lines.join('; ') })]),
				el('button', { type: 'button', textContent: t('mcp', 'Discard'), disabled: busy, onclick: () => { draft = copyGrants(current.grants); render('heading') } }),
				review,
			])
			parts.push(bar)
		}
		detail.replaceChildren(...parts)
		if (focusKey !== null) (controls.get(focusKey) || heading).focus()
	}
	async function run(action) {
		if (busy || !current || (action === 'save' && !preview)) return
		if (action === 'save' && window.McpAdminMailGrants && !window.McpAdminMailGrants.confirmWrites(Object.entries(draft)
			.flatMap(([module, operations]) => operations.filter((operation) => !(current.grants[module] || []).includes(operation)).map((operation) => ({ module, operation }))))) return
		busy = true
		const gid = current.groupId
		const body = { gid, grants: structuredClone(draft), expectedRevision: action === 'save' ? preview.revision : current.revision }
		if (action === 'save') body.previewId = preview.previewId
		render()
		root.setAttribute('aria-busy', 'true')
		let refresh = false
		try {
			const data = await api(action === 'save' ? 'PUT' : 'POST', '/api/group-grants' + (action === 'save' ? '' : '/preview'), body)
			if (action === 'preview') {
				preview = data
				say(t('mcp', 'Review the impact, then save the group policy.'))
			} else {
				preview = null
				refresh = true
				say(t('mcp', 'Saved'))
				window.dispatchEvent(new Event('mcp-group-policy-saved'))
			}
		} catch (e) {
			preview = null
			if (action === 'save' && e.message === 'unconfirmed') {
				refresh = true
				say(t('mcp', 'The change could not be confirmed. Your draft is kept. Retry after checking the group.'), true)
			} else if (e.message === '409' || e.message === '428') {
				// A fresh read pairs the revision with the stored policy. Never adopt an error revision or replay.
				refresh = true
				say(t('mcp', 'The policy or group members changed. Your draft is kept. Review a new preview before saving again.'), true)
			} else if (e.message === '503') {
				refresh = true
				say(t('mcp', 'The change could not be confirmed. Your draft is kept. Retry after checking the group.'), true)
			} else if (e.message === '422') {
				refresh = true
				say(untrusted(), true)
			} else {
				say(t('mcp', 'Could not save the group policy. Your draft is kept. Request a new preview and retry.'), true)
			}
		} finally {
			busy = false
			root.setAttribute('aria-busy', 'false')
			if (refresh) await open(gid, true, true)
			else render(action === 'preview' && preview ? 'save' : 'heading')
		}
	}
	search.addEventListener('input', () => {
		clearTimeout(timer)
		timer = setTimeout(() => { page = 1; loadList() }, 300)
	})
	prev.addEventListener('click', () => { page = Math.max(1, page - 1); loadList() })
	next.addEventListener('click', () => { page++; loadList() })
	loadList()
})()
