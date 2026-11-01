/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Group policy directory operations.
 * @param {object} context live bindings of this editor
 * @return {void}
 */
(function (admin) {
	'use strict'

	admin.modules.groups = admin.modules.groups || {}
	admin.modules.groups.directory = function (context) {
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
			return context.UnknownGroupGrants.warning(group)
		}
		/** @return {string} what a group grants, in words, for the accessible name and the tooltip of its row */
		function grantSummary(group) {
			const granted = Object.entries(group.grants || {}).filter(([, operations]) => operations.length > 0).map(([module]) => module)
			if (granted.length === 0) return t('mcp', 'Grants nothing')
			return t('mcp', 'Grants: {permissions}', { permissions: granted.map((module) => module === '_system' ? t('mcp', 'Can connect') : context.moduleName(module)).join(', ') }, undefined, context.RAW)
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
			const children = [context.el('span', { className: 'mcp-group-name', textContent: group.displayName })]
			if (differs) children.push(context.el('span', { className: 'mcp-group-id', textContent: group.groupId }))
			const state = context.rowState(group)
			let end
			if (state) {
				end = context.el('span', { className: 'mcp-group-end mcp-group-state' + (state.warning ? ' mcp-group-state-warning' : ''), textContent: state.symbol })
				if (state.lock) end.innerHTML = LOCK_ICON
			} else {
				end = context.el('span', { className: 'mcp-group-end', textContent: context.grantShort(group) })
			}
			end.setAttribute('aria-hidden', 'true')
			children.push(end)
			const label = [group.displayName, differs ? t('mcp', 'ID: {id}', { id: group.groupId }, undefined, context.RAW) : '', state ? state.text : context.grantSummary(group)].filter(Boolean).join('. ')
			const selected = context.current !== null && group.groupId !== null && context.current.groupId === group.groupId
			const button = context.el('button', { type: 'button', className: 'mcp-group-item', title: label, disabled: group.invalidId === true, tabIndex: -1, onclick: () => open(group.groupId, false, false, group.displayName) }, children)
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
			if (typeof button.offsetTop !== 'number' || typeof context.list.clientHeight !== 'number') return
			const top = button.offsetTop - context.list.offsetTop
			if (top < context.list.scrollTop) context.list.scrollTop = top
			else if (top + button.offsetHeight > context.list.scrollTop + context.list.clientHeight) context.list.scrollTop = top + button.offsetHeight - context.list.clientHeight
		}
		function drawList() {
			buttons = context.rows.map(context.groupRow)
			context.list.replaceChildren(...buttons.map((button) => context.el('li', {}, [button])))
			if (!context.rows.length) context.list.append(context.el('li', { className: 'mcp-hint', textContent: t('mcp', 'No groups found.') }))
			const all = items()
			const open = all.find((button) => button.getAttribute('aria-current') === 'true')
			context.rove(open || all[0])
			if (open) context.keepVisible(open)
		}
		context.list.addEventListener('keydown', (event) => {
			const all = items()
			const at = all.indexOf(document.activeElement)
			if (at < 0) return
			const to = { ArrowDown: at + 1, ArrowUp: at - 1, Home: 0, End: all.length - 1 }[event.key]
			if (to === undefined) return
			event.preventDefault()
			const target = all[Math.max(0, Math.min(all.length - 1, to))]
			context.rove(target)
			target.focus()
		})
		/** The filter buttons say how many groups each shows, when the server knows it without extra work. */
		function drawFilters(counts) {
			const names = { all: t('mcp', 'All'), granted: t('mcp', 'With grants'), blocked: t('mcp', 'Blocked groups') }
			const counted = { all: (count) => t('mcp', 'All ({count})', { count }, undefined, context.RAW), granted: (count) => t('mcp', 'With grants ({count})', { count }, undefined, context.RAW), blocked: (count) => t('mcp', 'Blocked groups ({count})', { count }, undefined, context.RAW) }
			for (const [key, button] of Object.entries(context.filters)) {
				if (!button) continue
				const known = counts?.[key]
				button.textContent = typeof known === 'number' ? counted[key](known) : names[key]
				button.setAttribute('aria-pressed', String(key === context.filter))
			}
		}
		for (const [key, button] of Object.entries(context.filters)) {
			button?.addEventListener('click', () => {
				if (context.filter === key) return
				context.filter = key
				context.page = 1
				context.drawFilters(null)
				context.loadList()
			})
		}
		/** On a narrow screen the list folds into the picker above the editor; on a wide one both columns stay. */
		function setListOpen(isOpen) {
			if (!context.columns || !context.picker) return
			context.columns.setAttribute('data-list', isOpen ? 'open' : 'closed')
			context.picker.setAttribute('aria-expanded', String(isOpen))
			context.picker.textContent = context.current && !isOpen ? t('mcp', 'Group: {group}', { group: context.shownName ?? context.current.groupId }, undefined, context.RAW) : t('mcp', 'Choose a group')
		}
		context.picker?.addEventListener('click', () => {
			const isOpen = context.picker.getAttribute('aria-expanded') !== 'true'
			context.setListOpen(isOpen)
			if (isOpen) context.search.focus()
		})
		async function loadList() {
			const seq = ++context.listSeq
			context.list.setAttribute('aria-busy', 'true')
			context.prev.disabled = context.next.disabled = true
			try {
				const data = await context.api('GET', '/api/group-directory?' + new URLSearchParams({ search: context.search.value.trim(), page: String(context.page), filter: context.filter }))
				if (seq !== context.listSeq) return
				context.rows = data.groups
				context.list.scrollTop = 0
				context.drawList()
				context.drawFilters(data.counts)
				const total = typeof data.total === 'number' ? data.total : context.rows.length
				const first = (context.page - 1) * context.PAGE_SIZE + 1
				context.counter.textContent = context.rows.length === 0 ? ''
					: context.page === 1 && !data.hasMore ? n('mcp', '%n group', '%n groups', total)
						: t('mcp', 'Groups {first}–{last} of {total}', { first, last: first + context.rows.length - 1, total }, undefined, context.RAW)
				context.prev.disabled = context.page <= 1
				context.next.disabled = !data.hasMore
				context.say('')
			} catch (e) {
				if (seq !== context.listSeq) return
				context.rows = []
				context.counter.textContent = ''
				context.list.replaceChildren(context.el('li', {}, [t('mcp', 'Could not load groups.'), context.retry(context.loadList)]))
				context.say(t('mcp', 'Could not load groups.'), true)
			} finally {
				if (seq === context.listSeq) context.list.setAttribute('aria-busy', 'false')
			}
		}
		function retry(action) {
			return context.el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: action })
		}
		async function open(gid, preserve = false, keepMessage = false, displayName = null) {
			if (context.busy || (!preserve && !context.discardDraft())) return
			if (displayName !== null) context.shownName = displayName
			const seq = ++context.detailSeq
			// Keep the draft's base until a read succeeds, including after a failed refresh.
			context.preview = null
			context.detail.replaceChildren(context.el('p', { textContent: t('mcp', 'Loading…') }))
			try {
				const data = await context.api('GET', '/api/group-directory/detail?' + new URLSearchParams({ gid }))
				if (seq !== context.detailSeq) return
				context.current = data
				context.rows = context.UnknownGroupGrants.refreshRow(context.rows, context.current)
				if (!preserve) context.draft = context.copyGrants(data.grants)
				if (!keepMessage) context.say('')
				context.drawList()
				context.setListOpen(false)
				context.render('heading')
			} catch (e) {
				if (seq !== context.detailSeq) return
				context.detail.replaceChildren(context.retry(() => open(gid, preserve)))
				context.say(t('mcp', 'Could not read the group policy. Your draft is kept. Retry.'), true)
			}
		}

		Object.defineProperties(context, {
			LOCK_ICON: { configurable: true, get: () => LOCK_ICON },
			buttons: { configurable: true, get: () => buttons, set: (value) => { buttons = value } },
			items: { configurable: true, get: () => items },
		})

		Object.assign(context, { drawFilters, drawList, grantShort, grantSummary, groupRow, keepVisible, loadList, open, retry, rove, rowState, setListOpen })
	}
})((globalThis.window || globalThis).OCA.Mcp.Admin)
