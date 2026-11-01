/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Behavioral tests of src/admin/admin-exceptions.js (S4 of the admin redesign): of the individual values on the page, which are
 * real exceptions and which only repeat what the person inherits, read from the read-only preview the reset uses. The
 * script runs unchanged against a stub of window.McpAdminMatrix; it never writes, and the review goes through the reset.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const plain = (value) => JSON.parse(JSON.stringify(value))
const tick = async () => { for (let i = 0; i < 12; i++) await new Promise(setImmediate) }
/** Lets a setTimeout(…, 0) of the script run, then the promises after it. */
const later = async () => { await new Promise((resolve) => setTimeout(resolve, 5)); await tick() }
const REVISION = '9007199254740993'

const o = (source, groupIds = []) => ({ source, groupIds })
/** A row of the API with Files read and edit only; `origins` maps an operation ("eligible" for Can connect) to a source. */
function user(uid, values, origins = {}) {
	return {
		uid,
		eligible: values.eligible ?? true,
		grants: { files: { read: values.read ?? true, edit: values.edit ?? false } },
		origins: { eligible: origins.eligible || o('default'), grants: { files: { read: origins.read || o('default'), edit: origins.edit || o('default') } } },
	}
}

function setup({ users, preview, popoverOpen = false, menuOpen = false, drawerOpen = false, table = null } = {}) {
	const listeners = {}
	const element = (hidden = false) => ({ hidden, textContent: '', listeners: {}, addEventListener(name, action) { (this.listeners[name] ||= []).push(action) } })
	const drawer = { classes: new Set(drawerOpen ? ['mcp-drawer-open'] : []), get classList() { return { contains: (name) => this.classes.has(name) } } }
	const elements = { 'mcp-scope': drawer, 'mcp-redundant-notice': element(true), 'mcp-redundant-notice-text': element(), 'mcp-redundant-review': element(), 'mcp-level-popover': element(!popoverOpen), 'mcp-row-menu': element(!menuOpen), 'mcp-matrix': table }
	const document = { activeElement: null, getElementById: (id) => elements[id] ?? null }
	const calls = []
	const reviews = []
	let refreshes = 0
	let data = { revision: REVISION, users: users || [] }
	const matrix = {
		data: () => data,
		api: async (method, url, body) => {
			calls.push({ method, url, body: plain(body) })
			if (preview instanceof Error) throw preview
			return preview(body)
		},
		refresh: () => { refreshes++; onRefresh?.(); fire('mcp-matrix-rendered') },
	}
	const window = {
		McpAdminMatrix: matrix,
		McpAdminOverrides: { reviewCells: (cells, trigger) => reviews.push({ cells: plain(cells), trigger }) },
		addEventListener: (name, action) => { (listeners[name] ||= []).push(action) },
	}
	function fire(name) { for (const action of listeners[name] || []) action() }
	let onRefresh = null
	const n = (app, one, many, count) => (count === 1 ? one : many).replace('%n', count)
	vm.runInNewContext(fs.readFileSync(path.join(root, 'src/admin/admin-exceptions.js'), 'utf8'), { window, document, n, setTimeout })
	return {
		api: window.McpAdminExceptions,
		elements,
		calls,
		reviews,
		refreshes: () => refreshes,
		rendered: async () => { fire('mcp-matrix-rendered'); await tick() },
		popoverClosed: async () => { elements['mcp-level-popover'].hidden = true; fire('mcp-level-popover-closed'); await later() },
		menuClosed: async () => { elements['mcp-row-menu'].hidden = true; fire('mcp-row-menu-closed'); await later() },
		drawerClosed: async () => { elements['mcp-scope'].classes.delete('mcp-drawer-open'); fire('mcp-drawer-closed'); await later() },
		fire,
		revisionMoved: async () => { fire('mcp-revision-moved'); await new Promise((resolve) => setTimeout(resolve, 5)); await tick() },
		onRefresh: (action) => { onRefresh = action },
		document,
		setData: (next) => { data = next },
	}
}

/** A preview whose "after" per cell comes from `after(uid, module, operation)`. */
const previewOf = (after, revision = REVISION) => (body) => ({
	revision,
	cells: body.commands.map((command) => ({ ...command, before: { allowed: true }, after: { allowed: after(command.uid, command.module, command.operation), source: 'default', groupIds: [] } })),
})

test('asks the preview once for exactly the individual cells of the page, Can connect included, as inherit', async () => {
	const users = [
		user('ana', { edit: true }, { eligible: o('individual_allow'), edit: o('individual_allow') }),
		user('bruno', {}, {}),
	]
	const kit = setup({ users, preview: previewOf(() => true) })
	assert.equal(kit.api.kindOf('ana', 'files', 'edit').kind, 'pending', 'nothing is known before the page is drawn')
	await kit.rendered()
	assert.deepEqual(kit.calls, [{ method: 'POST', url: '/api/grants/commands/preview', body: { commands: [
		{ uid: 'ana', module: null, operation: 'eligible', state: 'inherit' },
		{ uid: 'ana', module: 'files', operation: 'edit', state: 'inherit' },
	] } }])
})

test('an individual value equal to what the person inherits is redundant; a different one, an invalid one or an unknown result is an exception', async () => {
	const users = [user('ana', { read: true, edit: false, eligible: true }, { read: o('individual_allow'), edit: o('individual_deny'), eligible: o('invalid') })]
	// Without her values Ana would read (same: redundant), edit (different: exception) and connect (the same value as
	// shown, but the stored value is invalid: still an exception).
	const kit = setup({ users, preview: previewOf(() => true) })
	await kit.rendered()
	assert.equal(kit.api.kindOf('ana', 'files', 'read').kind, 'redundant')
	assert.deepEqual(plain(kit.api.kindOf('ana', 'files', 'read').after), { source: 'default', groupIds: [] })
	assert.equal(kit.api.kindOf('ana', 'files', 'edit').kind, 'exception')
	assert.equal(kit.api.kindOf('ana', null, 'eligible').kind, 'exception', 'an invalid stored value is always an exception')
	assert.equal(kit.api.kindOfAnswer({ before: true, source: 'individual_allow' }, { after: { allowed: null } }), 'exception')
	assert.equal(kit.api.kindOfAnswer({ before: true, source: 'individual_allow' }, undefined), 'exception')
	assert.equal(kit.api.kindOfAnswer({ before: null, source: 'individual_allow' }, { after: { allowed: true } }), 'exception')
})

test('the table is drawn again once with the answer, and never asked again for the same revision and users', async () => {
	const kit = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true) })
	await kit.rendered()
	assert.equal(kit.refreshes(), 1)
	assert.equal(kit.calls.length, 1, 'the redraw it caused does not ask again')
	await kit.rendered()
	assert.equal(kit.calls.length, 1)
	kit.setData({ revision: '9007199254740994', users: [user('ana', {}, { read: o('individual_allow') })] })
	assert.equal(kit.api.kindOf('ana', 'files', 'read').kind, 'pending', 'a new revision is unknown until read')
	await kit.rendered()
	assert.equal(kit.calls.length, 2)
})

test('an error or an answer read at another revision marks every individual value as an exception, never fewer', async () => {
	const failed = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: new Error('503') })
	await failed.rendered()
	assert.equal(failed.api.status(), 'failed')
	assert.equal(failed.api.kindOf('ana', 'files', 'read').kind, 'exception')
	assert.equal(failed.elements['mcp-redundant-notice'].hidden, true)
	assert.equal(failed.refreshes(), 1, 'the marks are drawn')

	const stale = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true, '1') })
	await stale.rendered()
	assert.equal(stale.api.kindOf('ana', 'files', 'read').kind, 'exception')
})

test('the notice counts the redundant values of the page and the review opens the reset for exactly those cells', async () => {
	const users = [
		user('ana', { edit: false }, { read: o('individual_allow'), edit: o('individual_deny') }),
		user('bruno', { edit: true }, { edit: o('individual_allow') }),
	]
	// Inherited: read allowed, edit denied for both. Ana's two values repeat it; Bruno's Allow of edit is an exception.
	const kit = setup({ users, preview: previewOf((uid, module, operation) => operation === 'read') })
	await kit.rendered()
	assert.equal(kit.elements['mcp-redundant-notice'].hidden, false)
	assert.match(kit.elements['mcp-redundant-notice-text'].textContent, /^2 individual values on this page only repeat what is inherited, so group changes would not reach those people\./)
	assert.deepEqual(plain(kit.api.redundantCells()), [{ uid: 'ana', module: 'files', operation: 'read' }, { uid: 'ana', module: 'files', operation: 'edit' }])
	for (const action of kit.elements['mcp-redundant-review'].listeners.click) action()
	assert.deepEqual(kit.reviews.map((review) => review.cells), [[{ uid: 'ana', module: 'files', operation: 'read' }, { uid: 'ana', module: 'files', operation: 'edit' }]])
	assert.equal(kit.reviews[0].trigger, kit.elements['mcp-redundant-review'])

	const one = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true) })
	await one.rendered()
	assert.match(one.elements['mcp-redundant-notice-text'].textContent, /^1 individual value on this page only repeats/)
	const none = setup({ users: [user('ana', { edit: true }, { edit: o('individual_allow') })], preview: previewOf(() => false) })
	await none.rendered()
	assert.equal(none.elements['mcp-redundant-notice'].hidden, true, 'no notice without a redundant value')
	assert.equal(none.calls.length, 1)
})

test('a page without individual values asks nothing', async () => {
	const kit = setup({ users: [user('ana', {})], preview: previewOf(() => true) })
	await kit.rendered()
	assert.equal(kit.calls.length, 0)
	assert.equal(kit.api.status(), 'done')
	assert.equal(kit.refreshes(), 0)
})

test('the redraw waits while the level popover is open and comes when it closes', async () => {
	const kit = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true), popoverOpen: true })
	await kit.rendered()
	assert.equal(kit.refreshes(), 0, 'the answer does not close the popover in use')
	await kit.popoverClosed()
	assert.equal(kit.refreshes(), 1)
})

test('cells are split so that no preview has more than 50 users or 2,500 cells', () => {
	const kit = setup({ users: [], preview: previewOf(() => true) })
	const cells = (count) => Array.from({ length: count }, () => ({}))
	assert.deepEqual(plain(kit.api.chunks(Array.from({ length: 51 }, () => cells(1))).map((chunk) => chunk.length)), [50, 1])
	assert.deepEqual(plain(kit.api.chunks([cells(1500), cells(1500)]).map((chunk) => chunk.length)), [1500, 1500])
	assert.deepEqual(plain(kit.api.chunks([cells(0), cells(3)]).map((chunk) => chunk.length)), [3], 'users without individual values do not count')
})

test('F2: every new read of the table is classified again, even at the same revision and with the same users', async () => {
	// Ana's individual Allow of edit repeats what a group gives; then the group loses her without moving the revision.
	let inherited = true
	const ana = () => [user('ana', { edit: true }, { edit: o('individual_allow') })]
	const kit = setup({ users: ana(), preview: previewOf(() => inherited) })
	await kit.rendered()
	assert.equal(kit.api.kindOf('ana', 'files', 'edit').kind, 'redundant')
	inherited = false
	kit.setData({ revision: REVISION, users: ana() })
	assert.equal(kit.api.kindOf('ana', 'files', 'edit').kind, 'pending', 'a new read is unknown until classified')
	await kit.rendered()
	assert.equal(kit.calls.length, 2, 'the preview is asked again for the new read')
	assert.equal(kit.api.kindOf('ana', 'files', 'edit').kind, 'exception')
	assert.equal(kit.refreshes(), 2, 'once per read, never in a loop')
})

test('F2: a failed second read falls back to marking every individual value as an exception', async () => {
	let fail = false
	const kit = setup({ users: [user('ana', { edit: true }, { edit: o('individual_allow') })], preview: (body) => {
		if (fail) throw new Error('503')
		return previewOf(() => true)(body)
	} })
	await kit.rendered()
	assert.equal(kit.api.kindOf('ana', 'files', 'edit').kind, 'redundant')
	fail = true
	kit.setData({ revision: REVISION, users: [user('ana', { edit: true }, { edit: o('individual_allow') })] })
	await kit.rendered()
	assert.equal(kit.api.status(), 'failed')
	assert.equal(kit.api.kindOf('ana', 'files', 'edit').kind, 'exception')
})

test('F2: a write that moves the revision without drawing the table again is classified again', async () => {
	const data = { revision: REVISION, users: [user('ana', { edit: true }, { edit: o('individual_allow') })] }
	const kit = setup({ users: data.users, preview: previewOf(() => true) })
	kit.setData(data)
	await kit.rendered()
	assert.equal(kit.calls.length, 1)
	// A save of one cell: the same table object, a new revision and the row replaced.
	data.revision = '9007199254740994'
	data.users[0] = user('ana', { edit: false }, { edit: o('individual_deny') })
	await kit.revisionMoved()
	assert.equal(kit.calls.length, 2)
	assert.deepEqual(kit.calls[1].body.commands, [{ uid: 'ana', module: 'files', operation: 'edit', state: 'inherit' }])
	assert.equal(kit.api.kindOf('ana', 'files', 'edit').kind, 'exception', 'Deny against an inherited Allow')
})

test('F3: the redraw waits while a row actions menu is open and comes when it closes', async () => {
	const kit = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true), menuOpen: true })
	await kit.rendered()
	assert.equal(kit.refreshes(), 0, 'the answer does not close the menu in use, nor drop its focus')
	await kit.menuClosed()
	assert.equal(kit.refreshes(), 1)
})

test('F3: otherwise the focus goes back to the same control of the same cell of the same row', async () => {
	// A minimal table: one row for ana with two cells; the focus is on the "…" button of the second.
	const make = (tag, className) => ({ tagName: tag.toUpperCase(), classList: className ? [className] : [], children: [], focus() { kit.document.activeElement = this } })
	let cells
	const row = { dataset: { uid: 'ana' }, get cells() { return cells } }
	const draw = () => {
		const cellOf = (index, control) => ({ cellIndex: index, parentElement: row, dataset: {}, querySelector: (selector) => selector === 'button.mcp-row-actions-button' || selector === 'button' ? control : null })
		const select = make('input', 'mcp-row-select')
		const actions = make('button', 'mcp-row-actions-button')
		cells = [cellOf(0, select), cellOf(1, actions)]
		select.closest = () => cells[0]
		actions.closest = () => cells[1]
		return actions
	}
	const table = { tBodies: [{ get rows() { return [row] } }] }
	const kit = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true), table })
	const before = draw()
	kit.document.activeElement = before
	let after = null
	kit.onRefresh(() => { after = draw() })
	await kit.rendered()
	assert.equal(kit.refreshes(), 1)
	assert.notEqual(after, before, 'the table was drawn again')
	assert.equal(kit.document.activeElement, after, 'the new "…" button of the same row has the focus')
})

test('F4: the redraw after a closing comes after the closing, not during it', async () => {
	const kit = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true), menuOpen: true })
	await kit.rendered()
	kit.elements['mcp-row-menu'].hidden = true
	kit.fire('mcp-row-menu-closed')
	assert.equal(kit.refreshes(), 0, 'nothing is drawn while the menu item still runs its action')
	await later()
	assert.equal(kit.refreshes(), 1)
})

test('F4: the redraw also waits while a drawer is open, and comes when it closes', async () => {
	const kit = setup({ users: [user('ana', {}, { read: o('individual_allow') })], preview: previewOf(() => true), drawerOpen: true })
	await kit.rendered()
	assert.equal(kit.refreshes(), 0)
	await kit.drawerClosed()
	assert.equal(kit.refreshes(), 1)
})

test('S11: the inspector classifies one person with the same preview and rule, at the revision it read', async () => {
	const k = setup({ preview: (body) => ({ revision: REVISION, cells: body.commands.map((command) => ({ ...command, after: { allowed: command.operation === 'read', source: 'group', groupIds: ['comercial'] } })) }) })
	const kinds = await k.api.classifyPerson('ana', [
		{ module: 'files', operation: 'read', allowed: true, source: 'individual_allow' },
		{ module: 'files', operation: 'edit', allowed: true, source: 'individual_allow' },
		{ module: 'files', operation: 'move', allowed: false, source: 'group' },
	], REVISION)
	assert.deepEqual(k.calls.map((call) => call.body), [{ commands: [
		{ uid: 'ana', module: 'files', operation: 'read', state: 'inherit' },
		{ uid: 'ana', module: 'files', operation: 'edit', state: 'inherit' },
	] }], 'only the individual values, as inherit, in the read-only preview')
	assert.equal(k.calls[0].url, '/api/grants/commands/preview')
	assert.deepEqual(plain([...kinds]), [
		['files/read', { kind: 'redundant', after: { source: 'group', groupIds: ['comercial'] } }],
		['files/edit', { kind: 'exception', after: { source: 'group', groupIds: ['comercial'] } }],
	])
	await assert.rejects(k.api.classifyPerson('ana', [{ module: 'files', operation: 'read', allowed: true, source: 'individual_allow' }], '1'), /stale/, 'an answer at another revision is not about these values')
	const none = await k.api.classifyPerson('ana', [{ module: 'files', operation: 'read', allowed: true, source: 'default' }], REVISION)
	assert.equal(none.size, 0)
	assert.equal(k.calls.length, 2, 'nothing is asked without an individual value')
})
