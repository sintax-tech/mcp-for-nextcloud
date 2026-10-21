// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
export function createGroupDirectory({ lifetime, t, n, find, el, api, moduleName, say, retry, open, getCurrent, getShownName }) {
    const RAW = { escape: false }
    const PAGE_SIZE = 50
	const search = find('mcp-group-search')
	const list = find('mcp-group-list')
	const prev = find('mcp-group-prev')
	const next = find('mcp-group-next')
	const counter = find('mcp-group-count')
	const columns = find('mcp-groups')
	const picker = find('mcp-group-picker')
	const filters = { all: find('mcp-group-filter-all'), granted: find('mcp-group-filter-granted'), blocked: find('mcp-group-filter-blocked') }
	let page = 1
	let filter = 'all'
	let rows = []
	let listSeq = 0
	let timer
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
		const selected = getCurrent() !== null && group.groupId !== null && getCurrent().groupId === group.groupId
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
	lifetime.on(list, 'keydown', (event) => {
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
		lifetime.on(button, 'click', () => {
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
		picker.textContent = getCurrent() && !isOpen ? t('mcp', 'Group: {group}', { group: getShownName() ?? getCurrent().groupId }, undefined, RAW) : t('mcp', 'Choose a group')
	}
	lifetime.on(picker, 'click', () => {
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
            if (lifetime.disposed) return
			if (seq !== listSeq) return
			rows = []
			counter.textContent = ''
			list.replaceChildren(el('li', {}, [t('mcp', 'Could not load groups.'), retry(loadList)]))
			say(t('mcp', 'Could not load groups.'), true)
		} finally {
            if (lifetime.disposed) return
			if (seq === listSeq) list.setAttribute('aria-busy', 'false')
		}
	}
	lifetime.on(search, 'input', () => {
		lifetime.clearTimeout(timer)
		timer = lifetime.timeout(() => { page = 1; loadList() }, 300)
	})
	lifetime.on(prev, 'click', () => { page = Math.max(1, page - 1); loadList() })
	lifetime.on(next, 'click', () => { page++; loadList() })
    return { ready: loadList(), drawList, setListOpen, clear() { list.replaceChildren() } }
}
