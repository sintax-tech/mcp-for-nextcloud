/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Behavioral tests of the shipped script, using only Node's built-in test runner and a minimal DOM. */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('./AdminContext.cjs')
const fs = require('node:fs')
const path = require('node:path')

class Element {
	constructor(tag, document) {
		this.tag = tag
		this.document = document
		this.children = []
		this.attributes = {}
		this.listeners = {}
		this.value = ''
		this.disabled = false
		this._text = ''
	}
	append(...children) { this.children.push(...children) }
	replaceChildren(...children) { this.children = children; this._text = '' }
	setAttribute(key, value) { this.attributes[key] = value }
	getAttribute(key) { return this.attributes[key] ?? null }
	addEventListener(name, action) { this.listeners[name] = action }
	focus() { this.document.activeElement = this }
	set textContent(value) { this._text = value; this.children = [] }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join(' ') }
	all(tag) { return this.children.flatMap((child) => typeof child === 'string' ? [] : [...(child.tag === tag ? [child] : []), ...child.all(tag)]) }
	querySelector(selector) {
		if (selector === 'button:last-child') return this.children.at(-1)
		return this.all(selector)[0]
	}
	async click() { if (!this.disabled) await this.onclick?.() }
}
/** Announcement timers of the app under test, run by tick() but not by settle(), so a test can see the cleared region. */
let flushTimers = () => {}
const settle = async () => { for (let i = 0; i < 12; i++) await new Promise(setImmediate) }
const tick = async () => { for (let i = 0; i < 12; i++) { await new Promise(setImmediate); flushTimers() } }
const stored = (extra = {}) => ({
	groupId: 'staff', trusted: true, exists: true, state: 'active', grants: [], revision: '9007199254740993',
	catalog: { _system: ['eligible'], notes: ['read', 'edit'] },
	counts: { members: null, overrides: null, permissions: null }, ...extra,
})
async function app(handler, group = stored()) {
	const document = { activeElement: null }
	const ids = Object.fromEntries(['editor', 'search', 'count', 'list', 'detail', 'status', 'alert', 'prev', 'next', 'picker', 'filter-all', 'filter-granted', 'filter-blocked'].map((id) => ['mcp-group-' + id, new Element('div', document)]))
	const template = require('./AdminTemplates.cjs').read(path.join(__dirname, '../../templates/admin.php'), 'utf8')
	for (const id of ['status', 'alert']) {
		const markup = template.match(new RegExp('<p id="mcp-group-' + id + '"[^>]+>'))[0]
		for (const name of ['role', 'aria-live']) ids['mcp-group-' + id].attributes[name] = markup.match(new RegExp(name + '="([^"]+)"'))[1]
	}
	ids['mcp-groups'] = new Element('div', document)
	document.getElementById = (id) => ids[id]
	document.createElement = (tag) => new Element(tag, document)
	const calls = []
	const events = []
	const confirmations = { allow: true, messages: [] }
	// The search debounce (300 ms) runs at once; every other timer (the live-region announcements) waits for a flush.
	const timers = new Map()
	let timerId = 1000
	flushTimers = () => { for (const [id, action] of [...timers]) { timers.delete(id); action() } }
	const context = {
		document, URLSearchParams, structuredClone, Event,
		window: { dispatchEvent: (event) => events.push(event.type), confirm: (message) => { confirmations.messages.push(message); return confirmations.allow } },
		OC: { generateUrl: (url) => url, requestToken: 'token' },
		t: (_, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		n: (_, one, many, count, params = {}) => (count === 1 ? one : many).replace('%n', String(count)).replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		setTimeout: (fn, ms) => { if (ms === 300) { fn(); return 1 } timers.set(++timerId, fn); return timerId }, clearTimeout: (id) => { timers.delete(id) },
		fetch: async (url, options) => {
			const call = { url, method: options.method, body: options.body ? JSON.parse(options.body) : undefined }
			calls.push(call)
			const result = await handler?.(call)
			const [status, body] = result || [200, url.includes('/detail?') ? group : { groups: [{ displayName: 'Staff', ...group }], hasMore: true }]
			return { ok: status < 400, status, json: async () => { if (body instanceof Error) throw body; return body } }
		},
	}
	vm.createContext(context)
	vm.runInContext(require('./AdminManifest.cjs').levelsSource(), context)
	vm.runInContext(require('./AdminManifest.cjs').script('admin-groups.js'), context)
	await tick()
	const open = async () => { await ids['mcp-group-list'].all('button')[0].click(); await tick() }
	const button = (text) => ids['mcp-group-detail'].all('button').find((node) => node.textContent === text)
	const input = (name, value) => ids['mcp-group-detail'].all('input').find((node) => node.name === name && (value === undefined || node.value === value))
	const level = (module, choice) => ids['mcp-group-detail'].all('button').find((node) => node.name === 'mcp-group-level-' + module && node.value === choice)
	const check = (box, checked = true) => { box.checked = checked; box.listeners.change() }
	const operations = () => { const box = ids['mcp-group-detail'].all('input').find((node) => node.id === 'mcp-group-operations'); check(box, !box.checked) }
	return { ids, calls, events, document, open, button, input, level, check, operations, confirmations, timers }
}
const preview = { previewId: 'receipt', revision: '9007199254740993', members: 51, revokes: 2, withdraws: true }

test('T17: unknown counters stay distinct from zero and the heading receives focus', async () => {
	const ui = await app()
	await ui.open()
	assert.match(ui.ids['mcp-group-detail'].textContent, /Unknown number of members/)
	assert.match(ui.ids['mcp-group-detail'].textContent, /members with individual settings could not be counted/)
	assert.doesNotMatch(ui.ids['mcp-group-detail'].textContent, /\b0 members|No member/)
	assert.equal(ui.document.activeElement.tag, 'h3')
	assert.equal(ui.button('Save group policy'), undefined, 'saving is offered only by a preview')
})
test('T16: edits from an empty PHP array produce an object payload; saving requires a preview', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : undefined)
	await ui.open()
	ui.check(ui.input('mcp-group-eligible'))
	await ui.button('Review and save').click()
	await tick()
	const call = ui.calls.find((call) => call.method === 'POST')
	assert.deepEqual(call.body.grants, { _system: ['eligible'] })
	assert.equal(call.body.expectedRevision, '9007199254740993')
	assert.match(ui.ids['mcp-group-detail'].textContent, /51 current members; 2 credential holders/)
	assert.equal(ui.document.activeElement, ui.button('Save group policy'))
})
test('T16/T17: confirm current policy previews the saved set and PUT carries the preview receipt', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : call.method === 'PUT' ? [200, { revision: '9007199254740994' }] : undefined,
		stored({ state: 'needs_confirm', grants: { notes: ['edit'] } }))
	await ui.open()
	await ui.button('Confirm current policy').click()
	await tick()
	assert.deepEqual(ui.calls.find((call) => call.method === 'POST').body.grants, { notes: ['edit'] })
	await ui.button('Save group policy').click()
	await tick()
	const save = ui.calls.find((call) => call.method === 'PUT')
	assert.equal(save.body.previewId, 'receipt')
	assert.equal(save.body.expectedRevision, preview.revision)
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 1)
	assert.deepEqual(ui.events, ['mcp-group-policy-saved'])
})
test('T16: a membership conflict keeps the draft, reloads, and never repeats the write', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : call.method === 'PUT' ? [409, { revision: '42', state: { preview } }] : undefined)
	await ui.open()
	ui.operations()
	ui.check(ui.input('mcp-group-op-notes', 'edit'))
	await ui.button('Review and save').click()
	await tick()
	await ui.button('Save group policy').click()
	await tick()
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 1)
	assert.equal(ui.input('mcp-group-op-notes', 'edit').checked, true)
	assert.equal(ui.button('Save group policy'), undefined)
	assert.match(ui.ids['mcp-group-alert'].textContent, /Your draft is kept/)
	assert.equal(ui.ids['mcp-group-status'].attributes.role, 'status')
	assert.equal(ui.ids['mcp-group-alert'].attributes.role, 'alert')
	assert.equal(ui.document.activeElement.attributes['aria-describedby'], 'mcp-group-alert mcp-group-diagnosis')
	assert.match(ui.ids['mcp-group-detail'].textContent, /1 unsaved change/)
	assert.match(ui.ids['mcp-group-detail'].textContent, /Notes: Not granted → Custom \(edit\)/)
})
test('T17: non-native and orphaned groups expose diagnosis, disabled grants and a clearing preview', async () => {
	const ui = await app(undefined, stored({ trusted: false, exists: false, grants: { notes: ['edit'] } }))
	await ui.open()
	assert.equal(ui.ids['mcp-group-detail'].all('fieldset')[0].disabled, true)
	assert.ok(ui.level('notes', 'read'), 'the levels are drawn inside the disabled fieldset')
	assert.equal(ui.ids['mcp-group-detail'].all('fieldset')[0].all('button').includes(ui.level('notes', 'read')), true)
	assert.equal(ui.button('Review and save'), undefined)
	assert.equal(ui.button('Preview clearing group policy').disabled, false)
	assert.match(ui.ids['mcp-group-detail'].textContent, /no longer exists/)
})
test('T17: 503 refuses the save, preserves draft and demands an explicit new preview', async () => {
	const ui = await app((call) => call.method === 'POST' ? [503, { error: 'Unavailable' }] : undefined)
	await ui.open()
	await ui.level('notes', 'read').click()
	await ui.button('Review and save').click()
	await tick()
	assert.equal(ui.calls.filter((call) => call.method === 'POST').length, 1)
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 0)
	assert.equal(ui.button('Save group policy'), undefined)
	assert.equal(ui.level('notes', 'read').attributes['aria-pressed'], 'true', 'the draft survives the refusal')
	assert.match(ui.ids['mcp-group-alert'].textContent, /Retry after checking the group/)
	assert.doesNotMatch(ui.ids['mcp-group-alert'].textContent, /credential holders/)
})
test('T17: group search and pagination use their own endpoint and page state', async () => {
	const ui = await app()
	await ui.ids['mcp-group-next'].listeners.click()
	await tick()
	assert.match(ui.calls.at(-1).url, /page=2/)
	ui.ids['mcp-group-search'].value = 'group 201'
	ui.ids['mcp-group-search'].listeners.input()
	await tick()
	assert.match(ui.calls.at(-1).url, /search=group\+201&page=1/)
})

/** Exercise the matrix's shipped render/planning functions with the same lightweight elements. */
function matrixFunctions() {
	const source = require('./AdminManifest.cjs').script('admin-grants.js')
	const document = {}
	const context = {
		t: (_, text) => text, document, window: {},
		el: (tag, props) => Object.assign(new Element(tag, document), props),
		paintState: (cell, state, detail) => { cell.title = state + ': ' + detail },
		reloadLocked: () => {},
		state: { data: { users: [] } },
	}
	for (const name of ['rowUnavailableReason', 'unavailableReason', 'permissionCell', 'bulkPlan']) {
		vm.runInNewContext(require('./AdminManifest.cjs').operation('function ' + name + '('), context)
	}
	return context
}
test('T10/T17: unknown eligibility is neither denied nor included in a bulk write, with a retry control', () => {
	const matrix = matrixFunctions()
	const user = { displayName: 'Ana', enabled: true, eligible: null, grants: { notes: { edit: null } } }
	assert.equal(matrix.rowUnavailableReason(user), null)
	const cell = matrix.permissionCell(user, null, null, 'Can connect', { eligible: null })
	assert.equal(cell.all('input').length, 0)
	assert.match(cell.textContent, /—/)
	assert.equal(cell.all('button')[0].textContent, 'Retry')
	matrix.state.data.users = [user]
	const plan = matrix.bulkPlan(null, ['eligible'], true)
	assert.equal(plan.changes.length, 0)
	assert.equal(plan.unavailable, 1)
})

test('I2: fixed polite/status and assertive/alert regions survive errors; successful retry clears the old error', async () => {
	let failed = false
	const ui = await app((call) => {
		if (!call.url.includes('/detail?') && !failed) { failed = true; return [503, {}] }
	})
	assert.equal(ui.ids['mcp-group-alert'].attributes.role, 'alert')
	assert.equal(ui.ids['mcp-group-alert'].attributes['aria-live'], 'assertive')
	assert.equal(ui.ids['mcp-group-status'].attributes.role, 'status')
	assert.equal(ui.ids['mcp-group-status'].attributes['aria-live'], 'polite')
	assert.match(ui.ids['mcp-group-alert'].textContent, /Could not load groups/)
	await ui.ids['mcp-group-list'].all('button')[0].click()
	await tick()
	assert.equal(ui.ids['mcp-group-alert'].textContent, '')
	assert.equal(ui.ids['mcp-group-status'].attributes.role, 'status')
	assert.equal(ui.ids['mcp-group-alert'].attributes.role, 'alert')
})
test('M1/M2: abandoning edits requires confirmation and draft differences are visible before a preview', async () => {
	const ui = await app(undefined, stored({ state: 'needs_confirm', grants: { notes: ['read'] } }))
	await ui.open()
	ui.operations()
	ui.check(ui.input('mcp-group-op-notes', 'edit'))
	assert.match(ui.ids['mcp-group-detail'].textContent, /Notes: Read → Full/)
	ui.confirmations.allow = false
	await ui.button('Confirm current policy').click()
	await ui.button('Preview clearing group policy').click()
	await ui.open()
	assert.equal(ui.calls.filter((call) => call.method === 'POST').length, 0)
	assert.equal(ui.input('mcp-group-op-notes', 'edit').checked, true)
	assert.equal(ui.confirmations.messages.length, 3)
})
test('M3/M8: an HTML 503 retains its status, reloads the policy and gives a generic error', async () => {
	const ui = await app((call) => call.method === 'POST' ? [503, new SyntaxError('HTML error page')] : undefined)
	await ui.open()
	await ui.level('notes', 'full').click()
	await ui.button('Review and save').click()
	await tick()
	assert.equal(ui.calls.filter((call) => call.url.includes('/detail?')).length, 2)
	assert.match(ui.ids['mcp-group-alert'].textContent, /The change could not be confirmed/)
	assert.equal(ui.button('Save group policy'), undefined)
})
test('M5: group origin is part of the permission checkbox accessible name', () => {
	const matrix = matrixFunctions()
	const user = { displayName: 'Ana', enabled: true, eligible: true, origins: { eligible: { source: 'group', groupIds: ['staff'] } } }
	const cell = matrix.permissionCell(user, null, true, 'Can connect', { eligible: null })
	assert.match(cell.all('input')[0].attributes['aria-label'], /Granted by groups/)
})
test('M6: disabled group grant fields and actions describe their source diagnosis', async () => {
	const ui = await app(undefined, stored({ trusted: false }))
	await ui.open()
	assert.equal(ui.ids['mcp-group-detail'].all('fieldset')[0].attributes['aria-describedby'], 'mcp-group-diagnosis')
	assert.equal(ui.button('Preview clearing group policy').attributes['aria-describedby'], 'mcp-group-diagnosis')
	assert.equal(ui.button('Confirm current policy').attributes['aria-describedby'], 'mcp-group-diagnosis')
	const trusted = await app()
	await trusted.open()
	await trusted.level('notes', 'read').click()
	assert.equal(trusted.button('Review and save').attributes['aria-describedby'], 'mcp-group-diagnosis')
})
test('M7: an invalid directory identity has a diagnostic row without an editable replacement ID', async () => {
	const ui = await app(undefined, stored({ groupId: null, invalidId: true, trusted: null }))
	assert.equal(ui.ids['mcp-group-list'].all('button')[0].disabled, true)
	assert.match(ui.ids['mcp-group-list'].all('button')[0].attributes['aria-label'], /invalid group ID/)
})

test('O2: an identical error twice in a row is announced twice, and a replaced message never appears', async () => {
	const ui = await app((call) => call.method === 'POST' ? [503, { error: 'Unavailable' }] : undefined)
	await ui.open()
	const alert = ui.ids['mcp-group-alert']
	await ui.level('notes', 'read').click()
	await ui.button('Review and save').click()
	await settle()
	// Cleared at once and written in a later task: nothing is announced in the same task as the clearing.
	assert.equal(alert.textContent, '')
	assert.equal(ui.timers.size, 1)
	flushTimers()
	const first = alert.textContent
	assert.match(first, /Retry after checking the group/)
	await ui.button('Review and save').click()
	await settle()
	assert.equal(alert.textContent, '', 'the old identical text is cleared, so the repeat is a new announcement')
	flushTimers()
	assert.equal(alert.textContent, first)
})
test('O2: a success that follows an error cancels the pending announcement of the error', async () => {
	let fail = true
	const ui = await app((call) => call.method === 'POST' ? (fail ? [503, {}] : [200, preview]) : undefined)
	await ui.open()
	await ui.level('notes', 'read').click()
	await ui.button('Review and save').click()
	await settle()
	assert.equal(ui.timers.size, 1)
	fail = false
	await ui.button('Review and save').click()
	await tick()
	assert.equal(ui.ids['mcp-group-alert'].textContent, '')
	assert.match(ui.ids['mcp-group-status'].textContent, /Review the impact/)
})

/** S7: a page of the directory with one group of each state. */
const directory = [
	{ groupId: 'staff', displayName: 'staff', trusted: true, exists: true, state: 'active', grants: { _system: ['eligible'], notes: ['read'] } },
	{ groupId: 'ti-interno', displayName: 'Equipe TI', trusted: true, exists: true, state: 'active', grants: [] },
	{ groupId: 'finance', displayName: 'Finance', trusted: true, exists: true, state: 'needs_confirm', grants: {} },
	{ groupId: 'sales-ldap', displayName: 'Sales', trusted: false, exists: true, state: 'active', grants: {} },
	{ groupId: 'gone', displayName: 'gone', trusted: true, exists: false, state: 'active', grants: {} },
	{ groupId: 'leaving', displayName: 'leaving', trusted: true, exists: true, state: 'pending_delete', grants: {} },
	{ groupId: 'remote', displayName: 'remote', trusted: null, exists: null, state: null, grants: {} },
]
const items = (ui) => ui.ids['mcp-group-list'].all('button')
test('S7/L2: one line per group: the ID only when it differs, a short end, and the state or grants in words in its name', async () => {
	const ui = await app((call) => call.url.includes('/detail?') ? undefined : [200, { groups: directory, hasMore: false, total: 7 }])
	const label = (item) => item.attributes['aria-label']
	const [staff, ti, finance, sales, gone, leaving, remote] = items(ui)
	assert.equal(staff.all('span').some((span) => span.className === 'mcp-group-id'), false, 'no ID when it equals the name')
	assert.doesNotMatch(label(staff), /ID:/)
	assert.match(label(staff), /^staff\. Grants: Can connect, Notes$/)
	assert.equal(staff.all('span').find((span) => span.className === 'mcp-group-end').textContent, '1 module')
	assert.equal(ti.all('span').find((span) => span.className === 'mcp-group-id').textContent, 'ti-interno')
	assert.equal(label(ti), 'Equipe TI. ID: ti-interno. Grants nothing')
	assert.equal(ti.all('span').find((span) => span.className === 'mcp-group-end').textContent, '—')
	assert.equal(ti.title, label(ti), 'the tooltip carries the full name, ID and state')
	const ends = (item) => item.all('span').find((span) => span.className.startsWith('mcp-group-end'))
	for (const [item, symbol, words] of [[finance, '⚠', 'Awaiting confirmation'], [sales, null, 'Cannot grant access'], [gone, '⊘', 'Deleted group'], [leaving, '⚠', 'Deletion pending'], [remote, '?', 'Source unavailable']]) {
		if (symbol === null) {
			// A group that cannot grant shows the lock as an inline SVG icon, never as an emoji.
			assert.equal(ends(item).textContent, '')
			assert.match(ends(item).innerHTML, /^<svg [^>]*fill="currentColor"[^>]*aria-hidden="true"/)
			assert.doesNotMatch(ends(item).innerHTML, /[\u{1F000}-\u{1FAFF}]/u)
		} else assert.equal(ends(item).textContent, symbol)
		assert.equal(ends(item).attributes['aria-hidden'], 'true', 'the symbol is decoration; the words carry the state')
		assert.ok(label(item).endsWith(words), label(item))
	}
	assert.equal(new Set([finance, sales, gone, remote].map((item) => ends(item).innerHTML || ends(item).textContent)).size, 4, 'states differ in shape, not only in colour')
	assert.equal(ui.ids['mcp-group-count'].textContent, '7 groups')
})
test('S7: only the open group is marked with aria-current, and the list keeps the mark across pages of the detail', async () => {
	const ui = await app((call) => call.url.includes('/detail?') ? [200, stored({ groupId: 'ti-interno' })] : [200, { groups: directory, hasMore: false }])
	assert.deepEqual(items(ui).map((item) => item.attributes['aria-current']), directory.map(() => 'false'))
	await items(ui)[1].click()
	await tick()
	assert.deepEqual(items(ui).map((item) => item.attributes['aria-current']), ['false', 'true', 'false', 'false', 'false', 'false', 'false'])
	assert.equal(ui.document.activeElement.textContent, 'Equipe TI', 'the detail names the group as the list does')
	assert.match(ui.ids['mcp-group-detail'].textContent, /ID: ti-interno/)
})
test('S7: the counter tells the range when there are more pages, and nothing when the list failed', async () => {
	let fail = false
	const ui = await app((call) => fail ? [503, {}] : call.url.includes('/detail?') ? undefined : [200, { groups: directory.slice(0, 3), hasMore: true, total: 103 }])
	assert.equal(ui.ids['mcp-group-count'].textContent, 'Groups 1–3 of 103')
	await ui.ids['mcp-group-next'].listeners.click()
	await tick()
	assert.equal(ui.ids['mcp-group-count'].textContent, 'Groups 51–53 of 103')
	fail = true
	await ui.ids['mcp-group-prev'].listeners.click()
	await tick()
	assert.equal(ui.ids['mcp-group-count'].textContent, '')
	assert.match(ui.ids['mcp-group-alert'].textContent, /Could not load groups/)
})
test('S7: the notices of a group live in its detail, with the action they ask for', async () => {
	const ui = await app(undefined, stored({ state: 'needs_confirm' }))
	await ui.open()
	const note = ui.ids['mcp-group-detail'].all('div').find((node) => node.className.includes('mcp-group-note'))
	assert.equal(note.hidden, false)
	assert.match(note.textContent, /awaiting confirmation/)
	assert.ok(note.all('button').some((button) => button.textContent === 'Confirm current policy'))
	const quiet = await app()
	await quiet.open()
	assert.equal(quiet.ids['mcp-group-detail'].all('div').find((node) => node.className.includes('mcp-group-note')).hidden, true)
})

/** S8: the catalog the server sends, with the matrix's tiers. */
const fullCatalog = {
	_system: ['eligible'],
	files: ['read', 'edit', 'create', 'move', 'restore', 'share', 'link'],
	talk: ['read', 'reply', 'attach', 'quote', 'create'],
	people: ['read'],
	logs: ['read'],
}
const pressed = (ui, module) => ui.ids['mcp-group-detail'].all('button').filter((node) => node.name === 'mcp-group-level-' + module && node.attributes['aria-pressed'] === 'true').map((node) => node.value)
test('S8: each module shows the matrix levels, the chosen one pressed with a check mark, and "Not granted" first', async () => {
	const ui = await app(undefined, stored({ catalog: fullCatalog, grants: { files: ['create', 'edit', 'move', 'read', 'restore'], logs: ['read'] } }))
	await ui.open()
	const names = (module) => ui.ids['mcp-group-detail'].all('button').filter((node) => node.name === 'mcp-group-level-' + module).map((node) => node.value)
	assert.deepEqual(names('files'), ['none', 'read', 'edit', 'full'])
	assert.deepEqual(names('people'), ['none', 'read'])
	assert.deepEqual(pressed(ui, 'files'), ['edit'])
	assert.deepEqual(pressed(ui, 'talk'), ['none'])
	assert.deepEqual(pressed(ui, 'logs'), ['read'])
	const edit = ui.level('files', 'edit')
	const mark = edit.all('span').find((span) => span.className === 'mcp-pressed-mark')
	assert.equal(mark?.textContent, '✓', 'the pressed level never depends on colour')
	assert.equal(mark.attributes['aria-hidden'], 'true')
	assert.equal(ui.level('files', 'read').all('span').length, 0, 'only the pressed level has the mark')
	assert.match(ui.level('talk', 'none').textContent, /Not granted/)
	assert.match(ui.level('talk', 'none').className, /mcp-group-level-none/)
	assert.doesNotMatch(ui.level('files', 'edit').className, /mcp-group-level-none/)
	const text = ui.ids['mcp-group-detail'].textContent
	assert.match(text, /Default: Read/, 'a module the group does not grant shows what members get anyway')
	assert.doesNotMatch(ui.ids['mcp-group-detail'].all('div').find((row) => row.className === 'mcp-group-row' && row.textContent.startsWith('Server log'))?.textContent ?? '', /Default/)
})
test('S8: choosing a level puts exactly its operations in the draft, lower or higher, and keeps the focus on it', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : undefined, stored({ catalog: fullCatalog, grants: { files: ['read', 'edit', 'create', 'move', 'restore', 'share', 'link'], talk: ['read'] } }))
	await ui.open()
	await ui.level('files', 'read').click()
	assert.deepEqual(pressed(ui, 'files'), ['read'])
	assert.equal(ui.document.activeElement, ui.level('files', 'read'))
	await ui.level('talk', 'edit').click()
	await ui.level('people', 'none').click()
	await ui.button('Review and save').click()
	await tick()
	const body = ui.calls.find((call) => call.method === 'POST').body
	assert.deepEqual(body.grants, { files: ['read'], talk: ['attach', 'quote', 'read', 'reply'] })
	assert.equal(body.expectedRevision, '9007199254740993')
})
test('S8: "Operations" shows two-state boxes per operation, and a set that is no level reads as Custom with nothing pressed', async () => {
	const ui = await app(undefined, stored({ catalog: fullCatalog, grants: { files: ['read'] } }))
	await ui.open()
	ui.operations()
	assert.equal(ui.input('mcp-group-op-files', 'share').checked, false)
	assert.equal(ui.input('mcp-group-op-files', 'read').checked, true)
	assert.equal(ui.level('files', 'read'), undefined, 'the levels give way to the boxes')
	ui.check(ui.input('mcp-group-op-files', 'share'))
	assert.equal(ui.document.activeElement, ui.input('mcp-group-op-files', 'share'))
	ui.operations()
	assert.deepEqual(pressed(ui, 'files'), [])
	assert.match(ui.ids['mcp-group-detail'].textContent, /Custom: change it in Operations/)
	assert.match(ui.ids['mcp-group-detail'].textContent, /Files: Read → Custom \(read, share\)/)
	ui.operations()
	ui.check(ui.input('mcp-group-op-files', 'edit'))
	assert.match(ui.ids['mcp-group-detail'].textContent, /Custom \(read, edit, share\)/, 'operations in catalog order, not sorted by ID')
})
test('S8: the save bar counts the changes, Discard restores the saved policy, and nothing is sent without a review', async () => {
	const ui = await app(undefined, stored({ catalog: fullCatalog, grants: { files: ['read'] } }))
	await ui.open()
	assert.equal(ui.button('Review and save'), undefined, 'no bar without changes')
	await ui.level('files', 'edit').click()
	ui.check(ui.input('mcp-group-eligible'))
	assert.match(ui.ids['mcp-group-detail'].textContent, /2 unsaved changes/)
	assert.match(ui.ids['mcp-group-detail'].textContent, /Can connect: No → Yes; Files: Read → Edit/)
	await ui.button('Discard').click()
	assert.equal(ui.button('Review and save'), undefined)
	assert.deepEqual(pressed(ui, 'files'), ['read'])
	assert.equal(ui.input('mcp-group-eligible').checked, false)
	assert.equal(ui.calls.filter((call) => call.method !== 'GET').length, 0)
})
test('S8: Review and save uses the group preview, shows a short review, Back returns, and Save sends the receipt once', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, { ...preview, current: { files: ['read'] }, grants: { files: ['create', 'edit', 'move', 'read', 'restore'] } }]
		: call.method === 'PUT' ? [200, { revision: '9007199254740994' }] : undefined, stored({ catalog: fullCatalog, grants: { files: ['read'] } }))
	await ui.open()
	await ui.level('files', 'edit').click()
	await ui.button('Review and save').click()
	await tick()
	const region = ui.ids['mcp-group-detail'].all('div').find((node) => node.className === 'mcp-group-review')
	assert.equal(region.attributes.role, 'region')
	assert.match(region.textContent, /Review the changes to Staff/)
	assert.match(region.textContent, /Files: Read → Edit/)
	assert.match(region.textContent, /51 current members; 2 credential holders will be signed out/)
	assert.match(region.textContent, /Losing the last source of eligibility revokes connections/)
	assert.equal(ui.document.activeElement, ui.button('Save group policy'))
	await ui.button('Back').click()
	assert.equal(ui.button('Save group policy'), undefined)
	assert.equal(ui.document.activeElement, ui.button('Review and save'))
	await ui.button('Review and save').click()
	await tick()
	await ui.button('Save group policy').click()
	await tick()
	const puts = ui.calls.filter((call) => call.method === 'PUT')
	assert.equal(puts.length, 1)
	assert.equal(puts[0].body.previewId, 'receipt')
	assert.deepEqual(puts[0].body.grants, { files: ['create', 'edit', 'move', 'read', 'restore'] })
	assert.deepEqual(ui.events, ['mcp-group-policy-saved'])
})
test('S8: a change after the review drops the preview, so the old receipt can never be saved', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : undefined, stored({ catalog: fullCatalog, grants: { files: ['read'] } }))
	await ui.open()
	await ui.level('files', 'edit').click()
	await ui.button('Review and save').click()
	await tick()
	assert.ok(ui.button('Save group policy'))
	ui.operations()
	ui.check(ui.input('mcp-group-op-files', 'share'))
	assert.equal(ui.button('Save group policy'), undefined)
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 0)
})
test('S8: one sentence tells who wins over the group, unknown stays apart from zero, and the link filters the matrix', async () => {
	const changes = []
	const select = { value: '', options: [{ value: '' }, { value: 'staff' }], dispatchEvent: (event) => changes.push(event.type) }
	const matrix = { scrolled: false, scrollIntoView() { this.scrolled = true }, querySelector: () => null }
	const ui = await app(undefined, stored({ counts: { members: 24, overrides: 3, permissions: null } }))
	const only = new Element('button', ui.document)
	only.onclick = () => changes.push('exceptions')
	ui.ids['mcp-group'] = select
	ui.ids['mcp-block-matrix'] = matrix
	ui.ids['mcp-only-exceptions'] = only
	await ui.open()
	assert.match(ui.ids['mcp-group-detail'].textContent, /24 members/)
	assert.match(ui.ids['mcp-group-detail'].textContent, /3 members have individual settings that take precedence over this group/)
	assert.equal(ui.ids['mcp-group-detail'].all('table').length, 0, 'no table of counters')
	await ui.button('View in the matrix').click()
	assert.equal(select.value, 'staff')
	assert.deepEqual(changes, ['change', 'exceptions'], 'the group filter first, then "With exceptions"')
	assert.equal(matrix.scrolled, true)
	assert.equal(ui.document.activeElement, only, 'the focus lands on the exceptions filter, a control that takes it')
	const none = await app(undefined, stored({ counts: { members: 1, overrides: 0, permissions: null } }))
	await none.open()
	assert.match(none.ids['mcp-group-detail'].textContent, /1 member\b/)
	assert.match(none.ids['mcp-group-detail'].textContent, /No member has individual settings/)
	assert.equal(none.button('View in the matrix'), undefined)
})
test('S8: "Not granted" pressed stays neutral, and only focus and selection take the theme colour', () => {
	const css = require('./AdminTemplates.cjs').read(path.join(__dirname, '../../css/admin.css'), 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
	const rule = (selector) => [...css.matchAll(/([^{}]+)\{([^{}]*)\}/g)].filter(([, sel]) => sel.includes(selector))
	const none = rule('.mcp-group-level-none[aria-pressed="true"]')
	assert.ok(none.length > 0)
	for (const [, , body] of none) assert.doesNotMatch(body, /--color-primary/)
	assert.ok(rule('.mcp-group-level[aria-pressed="true"]').some(([, , body]) => /--color-primary-element-light/.test(body)))
})
test('O1: a group the matrix cannot filter by is said so, and neither the matrix nor the focus moves', async () => {
	const changes = []
	const select = { value: '', options: [{ value: '' }, { value: 'other' }], dispatchEvent: (event) => changes.push(event.type) }
	const matrix = { scrolled: false, scrollIntoView() { this.scrolled = true } }
	const ui = await app(undefined, stored({ counts: { members: 2, overrides: 1, permissions: null } }))
	const only = new Element('button', ui.document)
	only.onclick = () => changes.push('exceptions')
	ui.ids['mcp-group'] = select
	ui.ids['mcp-block-matrix'] = matrix
	ui.ids['mcp-only-exceptions'] = only
	await ui.open()
	const link = ui.button('View in the matrix')
	link.focus()
	await link.click()
	await tick()
	assert.equal(select.value, '')
	assert.deepEqual(changes, [])
	assert.equal(matrix.scrolled, false)
	assert.equal(ui.document.activeElement, link)
	assert.match(ui.ids['mcp-group-status'].textContent, /cannot show only the members of Staff: its group filter does not list this group/)
	assert.equal(ui.ids['mcp-group-alert'].textContent, '', 'a notice, not an error')
})

/** L: the list that scales: filter on the server, one tab stop with arrows, the narrow-screen picker. */
const pageOf = (count, extra = {}) => ({ groups: Array.from({ length: count }, (_, i) => ({ groupId: 'g' + i, displayName: 'G' + i, trusted: true, exists: true, state: 'active', grants: [] })), hasMore: false, total: count, ...extra })
test('L1: the filter goes to the server with the search, starts again at page 1, and names its counts', async () => {
	const ui = await app((call) => call.url.includes('/detail?') ? undefined : [200, pageOf(3, { hasMore: true, total: 120, counts: { all: 120, granted: 7, blocked: null } })])
	const filter = (key) => ui.ids['mcp-group-filter-' + key]
	assert.equal(filter('all').textContent, 'All (120)')
	assert.equal(filter('granted').textContent, 'With grants (7)')
	assert.equal(filter('blocked').textContent, 'Blocked groups', 'no count the server did not compute')
	assert.equal(filter('all').attributes['aria-pressed'], 'true')
	await ui.ids['mcp-group-next'].listeners.click()
	await tick()
	assert.match(ui.calls.at(-1).url, /page=2&filter=all/)
	filter('blocked').listeners.click()
	await tick()
	assert.match(ui.calls.at(-1).url, /page=1&filter=blocked/, 'a new filter starts at its first page')
	filter('all').listeners.click()
	await tick()
	ui.ids['mcp-group-search'].value = 'sales'
	ui.ids['mcp-group-search'].listeners.input()
	await tick()
	filter('granted').listeners.click()
	await tick()
	assert.match(ui.calls.at(-1).url, /search=sales&page=1&filter=granted/)
	assert.equal(filter('granted').attributes['aria-pressed'], 'true')
	assert.equal(filter('all').attributes['aria-pressed'], 'false')
	const before = ui.calls.length
	filter('granted').listeners.click()
	assert.equal(ui.calls.length, before, 'the active filter does not reload')
	filter('blocked').listeners.click()
	await tick()
	assert.match(ui.calls.at(-1).url, /filter=blocked/)
})
test('L1: a blocked count the server computed is shown on its button', async () => {
	const ui = await app((call) => call.url.includes('/detail?') ? undefined : [200, pageOf(2, { counts: { all: 24, granted: 0, blocked: 1 } })])
	assert.equal(ui.ids['mcp-group-filter-blocked'].textContent, 'Blocked groups (1)')
	assert.equal(ui.ids['mcp-group-filter-granted'].textContent, 'With grants (0)')
})
test('L3: the list is one tab stop; arrows, Home and End move inside it and skip a row that cannot open', async () => {
	const rows = pageOf(4)
	rows.groups[2] = { groupId: null, displayName: 'Broken', invalidId: true, trusted: null, exists: null, state: null, grants: null }
	const ui = await app((call) => call.url.includes('/detail?') ? [200, stored({ groupId: 'g1' })] : [200, rows])
	const [g0, g1, broken, g3] = items(ui)
	assert.deepEqual([g0, g1, broken, g3].map((item) => item.tabIndex), [0, -1, -1, -1], 'the first row is the stop while none is open')
	const key = (name) => { let prevented = false; ui.ids['mcp-group-list'].listeners.keydown({ key: name, preventDefault: () => { prevented = true } }); return prevented }
	g0.focus()
	assert.equal(key('ArrowDown'), true)
	assert.equal(ui.document.activeElement, g1)
	key('ArrowDown')
	assert.equal(ui.document.activeElement, g3, 'the disabled row is skipped')
	key('ArrowDown')
	assert.equal(ui.document.activeElement, g3, 'the last row stays')
	key('Home')
	assert.equal(ui.document.activeElement, g0)
	key('End')
	assert.equal(ui.document.activeElement, g3)
	key('ArrowUp')
	assert.equal(ui.document.activeElement, g1)
	assert.equal(g1.tabIndex, 0)
	assert.equal(g3.tabIndex, -1)
	assert.equal(key('a'), false, 'other keys are left alone')
	await g1.click()
	await tick()
	const [, open] = items(ui)
	assert.equal(open.attributes['aria-current'], 'true')
	assert.deepEqual(items(ui).map((item) => item.tabIndex), [-1, 0, -1, -1], 'the open group is the stop after a redraw')
})
test('L4: on a narrow screen the list folds into a picker once a group is open, and the picker opens it again', async () => {
	const ui = await app()
	const columns = ui.ids['mcp-groups']
	const picker = ui.ids['mcp-group-picker']
	await ui.open()
	assert.equal(columns.attributes['data-list'], 'closed')
	assert.equal(picker.attributes['aria-expanded'], 'false')
	assert.equal(picker.textContent, 'Group: Staff')
	picker.listeners.click()
	assert.equal(columns.attributes['data-list'], 'open')
	assert.equal(picker.attributes['aria-expanded'], 'true')
	assert.equal(picker.textContent, 'Choose a group')
	assert.equal(ui.document.activeElement, ui.ids['mcp-group-search'], 'the search takes the focus')
	picker.listeners.click()
	assert.equal(columns.attributes['data-list'], 'closed')
})
test('L5: a new page starts at the top of the list, and only the page the server sent is drawn', async () => {
	const ui = await app((call) => call.url.includes('/detail?') ? undefined : [200, pageOf(50, { hasMore: true, total: 500 })])
	assert.equal(ui.ids['mcp-group-list'].all('li').length, 50)
	ui.ids['mcp-group-list'].scrollTop = 900
	await ui.ids['mcp-group-next'].listeners.click()
	await tick()
	assert.equal(ui.ids['mcp-group-list'].scrollTop, 0)
	assert.equal(ui.ids['mcp-group-count'].textContent, 'Groups 51–100 of 500')
})

test('issue #11: a non-JSON save response never reports success or discards the draft', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview]
		: call.method === 'PUT' ? [200, new SyntaxError('Unexpected token < in HTML response')] : undefined)
	await ui.open()
	ui.check(ui.input('mcp-group-eligible'))
	await ui.level('notes', 'read').click()
	await ui.button('Review and save').click()
	await tick()
	await ui.button('Save group policy').click()
	await tick()
	assert.deepEqual(ui.events, [], 'an unreadable response does not confirm a committed save')
	assert.equal(ui.ids['mcp-group-status'].textContent, '')
	assert.match(ui.ids['mcp-group-alert'].textContent, /The change could not be confirmed/)
	assert.doesNotMatch(ui.ids['mcp-group-alert'].textContent, /SyntaxError|Unexpected token/)
	assert.equal(ui.ids['mcp-group-editor'].attributes['aria-busy'], 'false')
	assert.equal(ui.input('mcp-group-eligible').disabled, false)
	assert.equal(ui.button('Review and save').disabled, false)
	assert.equal(ui.calls.filter((call) => call.url.includes('/detail?')).length, 2, 'unconfirmed save refreshes the stored policy')
	assert.equal(ui.input('mcp-group-eligible').checked, true)
	assert.equal(ui.level('notes', 'read').attributes['aria-pressed'], 'true')
	assert.equal(ui.button('Save group policy'), undefined, 'retry requires a new preview')
})
for (const status of [405, 412, 500]) {
	test(`issue #11: HTTP ${status} never reports a successful group save`, async () => {
		const ui = await app((call) => call.method === 'POST' ? [200, preview]
			: call.method === 'PUT' ? [status, { error: 'Rejected' }] : undefined)
		await ui.open()
		ui.check(ui.input('mcp-group-eligible'))
		await ui.button('Review and save').click()
		await tick()
		await ui.button('Save group policy').click()
		await tick()
		assert.deepEqual(ui.events, [])
		assert.match(ui.ids['mcp-group-alert'].textContent, /Could not save/)
		assert.equal(ui.input('mcp-group-eligible').checked, true)
	})
}
test('issue #11: an unreadable preview does not offer a save or announce a successful review', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, new SyntaxError('HTML login page')] : undefined)
	await ui.open()
	ui.check(ui.input('mcp-group-eligible'))
	await ui.button('Review and save').click()
	await tick()
	assert.equal(ui.button('Save group policy'), undefined)
	assert.equal(ui.ids['mcp-group-status'].textContent, '')
	assert.match(ui.ids['mcp-group-alert'].textContent, /Could not save/)
	assert.equal(ui.input('mcp-group-eligible').checked, true)
})
test('issue #11: leaving after preview sends no PUT and never announces Saved', async () => {
	const ui = await app((call) => call.method === 'POST' ? [200, preview] : undefined)
	await ui.open()
	ui.check(ui.input('mcp-group-eligible'))
	await ui.button('Review and save').click()
	await tick()
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 0)
	assert.match(ui.ids['mcp-group-status'].textContent, /Review the impact/)
	assert.notEqual(ui.ids['mcp-group-status'].textContent, 'Saved')
	await ui.open()
	assert.equal(ui.input('mcp-group-eligible').checked, false)
	assert.equal(ui.calls.filter((call) => call.method === 'PUT').length, 0)
})

/** A canonical opaque row as returned by the admin-only directory. */
const orphanGroup = () => stored({ unknownGrants: [{ id: 'a'.repeat(64), module: 'future', operation: 'write', value: 'allow' }], grantSnapshot: 'b'.repeat(64) })
test('G1: unknown grants are shown as denied and cancellation sends no cleanup', async () => {
	const ui = await app(null, orphanGroup())
	assert.match(ui.ids['mcp-group-list'].all('button')[0].getAttribute('aria-label'), /Unknown grants: denied/)
	await ui.open()
	assert.match(ui.ids['mcp-group-detail'].textContent, /future.write/)
	assert.match(ui.ids['mcp-group-detail'].textContent, /Stored value: Allow; effective: denied/)
	ui.confirmations.allow = false
	await ui.button('Clear unknown grant').click()
	await tick()
	assert.equal(ui.calls.filter((call) => call.method === 'DELETE').length, 0)
	assert.match(ui.confirmations.messages[0], /Clear 1 unknown grant from group staff.*future.write.*Allow.*recorded/)
})
test('G1: cleanup sends exact IDs, snapshot, revision and strict confirmation, then reloads', async () => {
	let cleaned = false
	const ui = await app((call) => {
		if (call.method === 'DELETE') { cleaned = true; return [200, { auditId: 'receipt-1', removed: 1, report: { unknownGrants: [] } }] }
		if (cleaned && call.url.includes('/detail?')) return [200, stored()]
	}, orphanGroup())
	await ui.open()
	await ui.button('Clear unknown grant').click()
	await tick()
	const command = ui.calls.find((call) => call.method === 'DELETE')
	assert.equal(command.url, '/apps/mcp/api/group-grants/unknown')
	assert.deepEqual(command.body, { gid: 'staff', ids: ['a'.repeat(64)], grantSnapshot: 'b'.repeat(64), expectedRevision: '9007199254740993', confirm: true })
	assert.equal(ui.button('Clear unknown grant'), undefined)
	assert.doesNotMatch(ui.ids['mcp-group-list'].all('button')[0].getAttribute('aria-label'), /Unknown grants: denied/)
	assert.match(ui.ids['mcp-group-status'].textContent, /cleared and recorded/)
	assert.ok(ui.events.includes('mcp-group-policy-saved'))
})
test('G1: conflict and unreadable cleanup responses reload without replay or false success', async () => {
	for (const response of [[409, {}], [200, new SyntaxError('HTML')], [503, { uncertain: true }], [200, {}]]) {
		const ui = await app((call) => call.method === 'DELETE' ? response : undefined, orphanGroup())
		await ui.open()
		await ui.button('Clear unknown grant').click()
		await tick()
		assert.equal(ui.calls.filter((call) => call.method === 'DELETE').length, 1)
		assert.match(ui.ids['mcp-group-alert'].textContent, /changed|could not be confirmed/)
		assert.equal(ui.events.includes('mcp-group-policy-saved'), false)
		assert.equal(ui.calls.filter((call) => call.url.includes('/detail?')).length, 2)
	}
})
