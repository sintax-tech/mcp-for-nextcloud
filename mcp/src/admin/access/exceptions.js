/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Exceptions of the permission matrix: of the individual values on the page, which differ from what the person would
 * inherit (a real exception) and which only repeat it (redundant).
 *
 * Every value 1.0.8 saved is an individual value in 1.1.0, so after an upgrade nearly every cell is individual and a mark
 * on each of them says nothing. The table only knows a cell's current source, never what the groups and defaults would
 * give without it, so once the page is drawn this asks the read-only preview (POST /apps/mcp/api/grants/commands/preview,
 * the one the reset uses) for every individual cell of the page and compares "before" with "after". Only a real exception
 * keeps the person icon; a redundant value is drawn like what it repeats, and a notice offers to review them.
 *
 * What the page may promise: until the answer comes no cell is marked as an exception, and when it cannot be read (an
 * error, or an answer read at another revision than the table) every individual value is marked, never fewer. An invalid
 * stored value is always an exception. Removing a redundant value changes nothing now, but it changes what happens when
 * the person leaves a group, so it is only ever done through the reset preview, never by itself.
 *
 * Explicitly composed ES module. src/admin/access/controller.js asks kindOf() while it draws a level cell, and the access
 * inspector asks classifyPerson() for the person it shows; this file never writes anything.
 */
import { createScope } from '../../platform/lifecycle.js'
export function mountExceptions({ root: pageRoot, request, t, n, events, policyChannel, getMatrix, getOverrides }) {
    const lifetime = createScope()
    const find = id => pageRoot.querySelector('#' + id)

	const matrix = getMatrix()
    const api = lifetime.request((...args) => matrix.api(...args))
	if (!matrix) {
		return
	}

	/** Most cells and users one preview accepts (PolicyLimits::MAX_CELLS and MAX_UIDS). */
	const MAX_CELLS = 2500
	const MAX_USERS = 50
	const INDIVIDUAL = ['individual_allow', 'individual_deny', 'invalid']

	/**
	 * The classification of the page on screen: the read of the table it is about (data, the object matrix.data() returned,
	 * and key, its revision and users), status (pending, done or failed) and, per cell, {kind: exception|redundant, after}.
	 * A new read of the table is a new classification even at the same revision and with the same users: a group can lose
	 * a member without moving the revision, and what was redundant becomes an exception.
	 */
	let result = { data: null, key: null, status: 'pending', cells: new Map() }
	/** A redraw waits while the level popover is open, so the answer never closes what the administrator is using. */
	let refreshWaiting = false

	const notice = find('mcp-redundant-notice')
	const noticeText = find('mcp-redundant-notice-text')
	const reviewButton = find('mcp-redundant-review')

	/** @return {string} key of one cell of one user */
	function cellKey(uid, module, operation) {
		return uid + '\0' + (module ?? '') + '\0' + operation
	}

	/** @return {string} key of the page on screen: a new revision or another set of users is another classification */
	function pageKey(data) {
		return data.revision + '\0' + data.users.map((user) => user.uid).join('\0')
	}

	/** @return {boolean} whether the classification is about this read of the table */
	function current(data) {
		return result.data === data && result.key === pageKey(data)
	}

	/**
	 * @param {object} user row of the table
	 * @return {Array<{uid: string, module: string|null, operation: string, before: boolean|null, source: string}>} its
	 *     individual cells, "Can connect" included
	 */
	function individualCells(user) {
		const cells = []
		if (INDIVIDUAL.includes(user.origins?.eligible?.source)) {
			cells.push({ uid: user.uid, module: null, operation: 'eligible', before: user.eligible, source: user.origins.eligible.source })
		}
		for (const [module, operations] of Object.entries(user.origins?.grants || {})) {
			for (const [operation, origin] of Object.entries(operations)) {
				if (INDIVIDUAL.includes(origin?.source)) {
					cells.push({ uid: user.uid, module, operation, before: user.grants?.[module]?.[operation] ?? null, source: origin.source })
				}
			}
		}
		return cells
	}

	/** Splits the cells into requests the preview accepts: at most MAX_USERS users and MAX_CELLS cells each. */
	function chunks(byUser) {
		const out = []
		let current = []
		let users = 0
		for (const cells of byUser) {
			if (cells.length === 0) continue
			if (current.length > 0 && (users + 1 > MAX_USERS || current.length + cells.length > MAX_CELLS)) {
				out.push(current)
				current = []
				users = 0
			}
			current = current.concat(cells)
			users++
		}
		if (current.length > 0) out.push(current)
		return out
	}

	/**
	 * Whether an individual value is a real exception, given what the preview says the person would have without it.
	 *
	 * @param {{before: boolean|null, source: string}} cell an individual cell of the table
	 * @param {object|undefined} answer the preview's cell
	 * @return {string} exception or redundant
	 */
	function kindOfAnswer(cell, answer) {
		const after = answer?.after?.allowed
		if (cell.source === 'invalid' || cell.before === null || after === null || after === undefined) {
			return 'exception'
		}
		return after === cell.before ? 'redundant' : 'exception'
	}

	/**
	 * Asks the preview what each individual cell would be without its value, and classifies it.
	 *
	 * @param {Array<Array<object>>} byUser the individual cells, one list per user (see individualCells())
	 * @param {string} revision the revision the cells were read at
	 * @return {Promise<Map<string, {kind: string, after: object|null}>>} per cellKey(); it fails when the preview fails or
	 *     answers at another revision, since what it says may then not be about these values
	 */
	async function ask(byUser, revision) {
		const answers = new Map()
		for (const chunk of chunks(byUser)) {
			const reply = await api('POST', '/api/grants/commands/preview', {
				commands: chunk.map((cell) => ({ uid: cell.uid, module: cell.module, operation: cell.operation, state: 'inherit' })),
			})
			if (typeof reply.revision !== 'string' || reply.revision !== revision) {
				throw new Error('stale')
			}
			for (const answer of Array.isArray(reply.cells) ? reply.cells : []) {
				answers.set(cellKey(answer.uid, answer.module ?? null, answer.operation), answer)
			}
		}
		const kinds = new Map()
		for (const cell of byUser.flat()) {
			const answer = answers.get(cellKey(cell.uid, cell.module, cell.operation))
			kinds.set(cellKey(cell.uid, cell.module, cell.operation), {
				kind: kindOfAnswer(cell, answer),
				after: answer?.after ? { source: answer.after.source, groupIds: answer.after.groupIds || [] } : null,
			})
		}
		return kinds
	}

	/**
	 * The classification of the individual values of one person, for the access inspector: the same preview and the same
	 * rule as the matrix, for any person, at the revision the inspector read.
	 *
	 * @param {string} uid the person
	 * @param {Array<{module: string, operation: string, allowed: boolean|null, source: string}>} cells their individual
	 *     values, as the inspector reads them
	 * @param {string} revision the revision of the inspector's answer
	 * @return {Promise<Map<string, {kind: string, after: object|null}>>} per "module/operation"; it fails as ask() does
	 */
	async function classifyPerson(uid, cells, revision) {
		const individual = cells.filter((cell) => INDIVIDUAL.includes(cell.source))
			.map((cell) => ({ uid, module: cell.module, operation: cell.operation, before: cell.allowed ?? null, source: cell.source }))
		const kinds = await ask([individual], revision)
		return new Map(individual.map((cell) => [cell.module + '/' + cell.operation, kinds.get(cellKey(uid, cell.module, cell.operation))]))
	}

	/** Reads the classification of the page on screen, once per revision and set of users. */
	async function classify() {
		const data = matrix.data()
		if (!data) {
			return
		}
		if (current(data)) {
			renderNotice()
			return
		}
		const key = pageKey(data)
		const byUser = data.users.map(individualCells)
		const all = byUser.flat()
		result = { data, key, status: all.length === 0 ? 'done' : 'pending', cells: new Map() }
		renderNotice()
		if (all.length === 0) {
			return
		}
		const open = result
		try {
			const kinds = await ask(byUser, data.revision)
			if (result !== open) return
			open.cells = kinds
			open.status = 'done'
		} catch (e) {
            if (lifetime.disposed) return
			if (result !== open) return
			open.status = 'failed'
		}
		renderNotice()
		refresh()
	}

	/**
	 * Draws the table again with the classification. It waits while the level popover, a row's actions menu or a drawer
	 * (the resource scope, the inspector) is open, so
	 * the answer never closes what the administrator is using, and otherwise gives the focus back to the same control of
	 * the same cell of the same row: a level button, a box, the "…" button or the selection box.
	 */
	function refresh() {
		const popover = find('mcp-level-popover')
		const menu = find('mcp-row-menu')
		const drawer = ['mcp-scope', 'mcp-inspector'].some((id) => find(id)?.classList?.contains('mcp-drawer-open'))
		if ((popover && !popover.hidden) || (menu && !menu.hidden) || drawer) {
			refreshWaiting = true
			return
		}
		refreshWaiting = false
		const active = document.activeElement
		const cell = active?.closest?.('td, th')
		const uid = cell?.parentElement?.dataset?.uid
		const index = cell?.cellIndex
		const kind = active?.classList?.[0] || null
		const tag = active?.tagName?.toLowerCase?.() || null
		matrix.refresh()
		if (uid === undefined || index === undefined || tag === null) {
			return
		}
		const table = find('mcp-matrix')
		const row = [...(table?.tBodies?.[0]?.rows || [])].find((candidate) => candidate.dataset.uid === uid)
		const again = row?.cells?.[index]
		const target = (kind !== null && again?.querySelector(tag + '.' + kind)) || again?.querySelector(tag)
		target?.focus()
	}

	/** @return {Array<{uid: string, module: string|null, operation: string}>} the redundant cells of the page */
	function redundantCells() {
		if (result.status !== 'done') {
			return []
		}
		return [...result.cells.entries()].filter(([, value]) => value.kind === 'redundant').map(([key]) => {
			const [uid, module, operation] = key.split('\0')
			return { uid, module: module === '' ? null : module, operation }
		})
	}

	/** Shows how many individual values on the page only repeat what the person inherits, with a way to review them. */
	function renderNotice() {
		if (!notice || !noticeText) {
			return
		}
		const count = redundantCells().length
		notice.hidden = count === 0
		noticeText.textContent = count === 0 ? '' : n('mcp',
			'%n individual value on this page only repeats what is inherited, so a group change would not reach that person.',
			'%n individual values on this page only repeat what is inherited, so group changes would not reach those people.', count)
	}

	lifetime.on(reviewButton, 'click', () => {
		const cells = redundantCells()
		if (cells.length > 0) {
			getOverrides()?.reviewCells(cells, reviewButton)
		}
	})

	/**
	 * How src/admin/access/controller.js marks an individual value: exception (the person icon), redundant (drawn like what it repeats,
	 * with that origin), or pending (no mark yet).
	 *
	 * @param {string} uid user
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {string} operation operation, "eligible" for "Can connect"
	 * @return {{kind: string, after: object|null}}
	 */
	function kindOf(uid, module, operation) {
		const data = matrix.data()
		if (!data || !current(data) || result.status === 'pending') {
			return { kind: 'pending', after: null }
		}
		if (result.status === 'failed') {
			return { kind: 'exception', after: null }
		}
		return result.cells.get(cellKey(uid, module, operation)) || { kind: 'exception', after: null }
	}

	lifetime.on(events, 'mcp-matrix-rendered', classify)
	// A save of one cell moves the revision and updates its row without drawing the table again: the classification is
	// read again once that write has finished updating the page.
	lifetime.use(policyChannel.follow(() => lifetime.timeout(classify, 0)))
	// After the closing, not during it: a menu item runs its action after the menu closes, and that action (a drawer opened
	// from the "…" button) must still find the button on the page.
	for (const closed of ['mcp-level-popover-closed', 'mcp-row-menu-closed', 'mcp-drawer-closed']) {
		lifetime.on(events, closed, () => lifetime.timeout(() => {
			if (refreshWaiting) refresh()
		}, 0))
	}

	const controller = { kindOf, redundantCells, kindOfAnswer, chunks, classifyPerson, status: () => result.status }

	const ready = Promise.resolve()

    return { ...controller, ready, destroy() { lifetime.destroy(); notice.hidden = true; noticeText.textContent = '' } }
}
