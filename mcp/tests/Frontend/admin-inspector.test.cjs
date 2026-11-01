/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Behavioral tests of src/admin/admin-inspector.js, using only Node's built-in test runner and a minimal DOM. */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

class Element {
	constructor(tag) {
		this.tag = tag
		this.children = []
		this.attributes = {}
		this.listeners = {}
		this.value = ''
		this.hidden = false
		this._text = ''
		this.classes = new Set()
	}
	get classList() { return { add: (name) => this.classes.add(name), remove: (name) => this.classes.delete(name), contains: (name) => this.classes.has(name) } }
	removeAttribute(key) { delete this.attributes[key] }
	focus() { focused = this }
	append(...children) { this.children.push(...children) }
	replaceChildren(...children) { this.children = children; this._text = '' }
	setAttribute(key, value) { this.attributes[key] = value }
	addEventListener(name, action) { this.listeners[name] = action }
	set textContent(value) { this._text = value; this.children = [] }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join(' ') }
	all(tag) { return this.children.flatMap((child) => typeof child === 'string' ? [] : [...(child.tag === tag ? [child] : []), ...child.all(tag)]) }
}
const tick = async () => { for (let i = 0; i < 12; i++) await new Promise(setImmediate) }
let focused = null
const IDS = ['', '-form', '-user', '-status', '-alert', '-access', '-resources', '-path-form', '-path', '-path-result',
	'-calendar-form', '-calendar-module', '-calendar', '-calendar-operation', '-calendar-result', '-title', '-close', '-entry']
const row = (module, operation, allowed, gate, source, extra = {}) => ({ module, operation, allowed, reasonCode: gate ?? 'allowed',
	blockingGate: gate, grant: { allowed, source, groupIds: [] }, scope: { mode: 'unrestricted', count: null }, ...extra })
const matrix = { revision: '7', operations: [
	row('notes', 'read', true, null, 'default'),
	row('notes', 'edit', false, 'grant', 'default'),
	row('calendar', 'read', null, 'grant_unknown', 'unknown', { grant: { allowed: null, source: 'unknown', groupIds: ['ldap-team'] } }),
	row('calendar', 'edit', false, 'service', 'individual_allow', { grant: { allowed: true, source: 'individual_allow', groupIds: [] } }),
] }

async function app(handler) {
	const ids = Object.fromEntries(IDS.map((suffix) => ['mcp-inspector' + suffix, new Element('div')]))
	ids['mcp-inspector-calendar-module'].value = 'calendar'
	const document = { getElementById: (id) => ids[id], createElement: (tag) => new Element(tag) }
	const calls = []
	const window = {}
	const context = {
		document, window, URLSearchParams,
		getComputedStyle: (node) => ({ top: node.cssTop || '44px' }),
		OC: { generateUrl: (url) => url, requestToken: 'token' },
		t: (_, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		fetch: async (url, options) => {
			calls.push({ url, options })
			const [status, body] = (await handler?.(url)) || (url.startsWith('/apps/mcp/api/inspector/access') ? [200, matrix]
				: url.includes('/candidates') ? [200, { items: [{ id: '11', name: 'Personal' }] }] : [200, {}])
			return { ok: status < 400, status, json: async () => body }
		},
	}
	// The page loads src/admin/admin-levels.js before the inspector, in the same global, so its Polls and Mail helpers see t().
	vm.runInNewContext(require('./AdminManifest.cjs').levelsSource(), context)
	vm.runInNewContext(require('./AdminManifest.cjs').script('admin-inspector.js'), context)
	const submit = async (id) => { await ids[id].listeners.submit({ preventDefault() {} }); await tick() }
	return { ids, calls, submit, window }
}

test('T9: every operation shows its result, first gate and grant; unknown is never shown as allowed', async () => {
	const ui = await app()
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	const rows = ui.ids['mcp-inspector-access'].all('tr').slice(1).map((tr) => tr.all('th').concat(tr.all('td')).map((cell) => cell.textContent))
	assert.deepEqual(rows, [
		['Notes: read', 'Allowed', '—', 'Default'],
		['Notes: edit', 'Blocked', 'Not granted.', 'Default'],
		['Calendar: read', 'Unknown', 'Unknown: the grant depends on a group whose policy cannot be confirmed now.', 'Group awaiting confirmation: ldap-team'],
		['Calendar: edit', 'Blocked', 'The MCP service is switched off.', 'Individual: allowed'],
	])
	assert.match(ui.calls[0].url, /^\/apps\/mcp\/api\/inspector\/access\?uid=alice$/)
	assert.equal(ui.calls[0].options.headers.requesttoken, 'token', 'the CSRF token travels with every question')
	assert.equal(ui.ids['mcp-inspector-resources'].hidden, false)
	assert.deepEqual(ui.ids['mcp-inspector-calendar-operation'].all('option').map((o) => o.value), ['read', 'edit'])
})

test('A5: a path answer names the folder an exclusion is inherited from, for the administrator', async () => {
	const ui = await app((url) => url.startsWith('/apps/mcp/api/inspector/path')
		? [200, { reason: 'excluded', gate: 'exclusion', exclusion: 'tag_folder', folder: '/Secret' }] : undefined)
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	ui.ids['mcp-inspector-path'].value = 'Secret/plan.md'
	await ui.submit('mcp-inspector-path-form')
	assert.equal(ui.ids['mcp-inspector-path-result'].textContent, 'Not reached: it is inside the excluded folder /Secret.')
	assert.match(ui.calls.at(-1).url, /path=%2FSecret%2Fplan\.md/)
})

test('S13: a gate that could not be evaluated is named, never reported as reached', async () => {
	const ui = await app((url) => url.startsWith('/apps/mcp/api/inspector/calendar') ? [200, { reason: 'not_evaluated', gate: 'native_acl' }] : undefined)
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	// A browser selects the first option; the minimal DOM needs it set.
	ui.ids['mcp-inspector-calendar'].value = '11'
	ui.ids['mcp-inspector-calendar-operation'].value = 'read'
	await ui.submit('mcp-inspector-calendar-form')
	assert.equal(ui.ids['mcp-inspector-calendar-result'].textContent, 'Not confirmed: the Nextcloud permissions could not be checked, so access is not known.')
	assert.match(ui.calls.at(-1).url, /module=calendar&calendarId=11&operation=read/)
})

test('a refused or unavailable inspection shows no stale matrix', async () => {
	for (const [status, message] of [[403, 'Only administrators can inspect access.'], [503, 'The MCP policy cannot be read right now, so nothing can be confirmed. Try again shortly.']]) {
		const ui = await app((url) => url.startsWith('/apps/mcp/api/inspector/access') ? [status, { error: 'x' }] : undefined)
		ui.ids['mcp-inspector-user'].value = 'alice'
		await ui.submit('mcp-inspector-form')
		assert.equal(ui.ids['mcp-inspector-alert'].textContent, message)
		assert.equal(ui.ids['mcp-inspector-access'].children.length, 0)
		assert.equal(ui.ids['mcp-inspector-resources'].hidden, true)
	}
})

test('B3: an answer that arrives after another user was inspected is dropped, never shown under that user', async () => {
	let release
	const late = new Promise((resolve) => { release = resolve })
	const ui = await app(async (url) => url.startsWith('/apps/mcp/api/inspector/path') && url.includes('uid=alice')
		? (await late, [200, { reason: 'excluded', gate: 'exclusion', exclusion: 'tag_direct' }]) : undefined)
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	ui.ids['mcp-inspector-path'].value = '/Secret'
	await ui.submit('mcp-inspector-path-form')
	ui.ids['mcp-inspector-user'].value = 'bob'
	await ui.submit('mcp-inspector-form')
	release()
	await tick()
	assert.equal(ui.ids['mcp-inspector-path-result'].textContent, '', "alice's answer is not written under bob")
	assert.equal(ui.ids['mcp-inspector-status'].textContent, 'Access of bob as the tools see it now.')
})

test('B4: a failed explanation is announced in the alert region', async () => {
	const ui = await app((url) => url.startsWith('/apps/mcp/api/inspector/path') ? [503, { error: 'Unavailable' }] : undefined)
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	ui.ids['mcp-inspector-path'].value = '/Projects'
	await ui.submit('mcp-inspector-path-form')
	assert.equal(ui.ids['mcp-inspector-alert'].textContent, 'The MCP policy cannot be read right now, so nothing can be confirmed. Try again shortly.')
	assert.equal(ui.ids['mcp-inspector-path-result'].textContent, '')
})

test('S5: the actions menu of a matrix row inspects that user, as the form does', async () => {
	const ui = await app()
	ui.window.McpInspectorUi.open('alice')
	await tick()
	assert.equal(ui.ids['mcp-inspector-user'].value, 'alice')
	assert.ok(ui.calls.some((call) => call.url === '/apps/mcp/api/inspector/access?uid=alice'))
})

/* S10: the verdict, the general checks and one line per module, above the table of every operation. */

function verdictRows(gate) {
	const op = (module, operation, allowed, blockingGate = null, source = 'default', granted = allowed) => ({ module, operation, allowed, reasonCode: blockingGate || 'allowed', blockingGate, grant: { allowed: granted, source, groupIds: source === 'group' ? ['comercial'] : [] }, scope: { mode: 'all', count: null } })
	// Behind a general block every operation is blocked, while the grants still say what each module gives: read here.
	if (gate) return [op('notes', 'read', false, gate, 'default', true), op('notes', 'edit', false, gate, 'default', false)]
	return [op('notes', 'read', true, null, 'group'), op('notes', 'edit', true, null, 'individual_allow'), op('files', 'read', false, 'app')]
}

async function inspected(operations) {
	const ui = await app((url) => url.startsWith('/apps/mcp/api/inspector/access') ? [200, { revision: '1', operations }] : undefined)
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	return ui
}

test('S10: when one general check blocks every operation, one sentence says which and how to fix it', async () => {
	const ui = await inspected(verdictRows('connection'))
	const text = ui.ids['mcp-inspector-access'].textContent.replace(/\s+/g, ' ')
	assert.match(text, /alice cannot use any MCP tool\. The user has not switched on their MCP connection\. They turn it on in their personal settings, under MCP for Cloud\./)
	const squash = (li) => li.textContent.replace(/\s+/g, ' ')
	const checks = ui.ids['mcp-inspector-access'].all('li').map(squash)
	assert.deepEqual(checks.slice(0, 4), ['✓ Account active: passed', '✓ MCP service on: passed', '✓ Can connect: passed', '✕ Personal connection on: blocks'])
	const service = await inspected(verdictRows('service'))
	assert.deepEqual(service.ids['mcp-inspector-access'].all('li').map(squash).slice(0, 4), ['✓ Account active: passed', '✕ MCP service on: blocks', '— Can connect: not checked', '— Personal connection on: not checked'])
	assert.match(text, /What each module grants, once the general check is fixed:/)
	assert.ok(!/Notes Blocked/.test(text), 'the general block is not repeated on every module')
	assert.match(text, /Notes Read Default/, 'the level the grants give, not the blocked result')
	const unknown = await inspected(verdictRows('eligibility_unknown'))
	assert.match(unknown.ids['mcp-inspector-access'].all('li').map(squash)[2], /^\? Can connect: not confirmed$/)
})

test('S10: without a general block the verdict says so, and each module has one line with its level, origin and block', async () => {
	const ui = await inspected(verdictRows(null))
	const text = ui.ids['mcp-inspector-access'].textContent.replace(/\s+/g, ' ')
	assert.match(text, /alice can use the MCP tools allowed below: the general checks passed\./)
	const modules = ui.ids['mcp-inspector-access'].all('li').filter((li) => li.className === 'mcp-inspector-module').map((li) => li.textContent.replace(/\s+/g, ' '))
	assert.equal(modules.length, 2)
	assert.match(modules[0], /^Notes Full /, 'the level the grants give: with only read and edit in this answer, both allowed is the top level')
	assert.match(modules[0], /Set for this person/, 'an individual value makes the module individual')
	assert.match(modules[1], /^Files Blocked The app of this module is not available to the user\./)
	// The table of every operation is still there, one click away.
	assert.ok(ui.ids['mcp-inspector-access'].all('details').length === 1)
	assert.equal(ui.ids['mcp-inspector-access'].all('tr').length, 4, 'header and three operations')
})

test('F5: without a general block the verdict comes from the operations, never "can use" when nothing is allowed or known', async () => {
	// The server applies the general checks in one order for every operation (PolicyOperationContext), so with none of
	// them blocking, what is left to say comes from the operations themselves.
	const op = (operation, allowed, gate) => ({ module: 'notes', operation, allowed, reasonCode: gate || 'allowed', blockingGate: gate, grant: { allowed: gate === 'grant' ? false : allowed, source: 'default', groupIds: [] }, scope: { mode: 'all', count: null } })
	const verdict = async (operations) => {
		const ui = await inspected(operations)
		const access = ui.ids['mcp-inspector-access']
		return { text: access.textContent.replace(/\s+/g, ' '), checks: access.all('li').filter((li) => (li.className || '').startsWith('mcp-inspector-check-')).map((li) => li.className) }
	}
	const passed = ['mcp-inspector-check-passed', 'mcp-inspector-check-passed', 'mcp-inspector-check-passed', 'mcp-inspector-check-passed']
	const none = await verdict([op('read', false, 'grant'), op('edit', false, 'grant')])
	assert.match(none.text, /alice cannot use any MCP tool: nothing is granted\./)
	assert.doesNotMatch(none.text, /can use the MCP tools/)
	assert.deepEqual(none.checks, passed, 'the general checks did pass')
	const unknown = await verdict([op('read', null, 'grant_unknown'), op('edit', null, 'grant_unknown')])
	assert.match(unknown.text, /Not confirmed: no operation of alice is allowed for sure now, and some cannot be confirmed\./)
	assert.doesNotMatch(unknown.text, /can use the MCP tools/)
	const scope = await verdict([op('read', null, 'resource_scope_unknown'), op('edit', false, 'grant')])
	assert.match(scope.text, /Not confirmed:/, 'an unreadable scope is a doubt, not a "can use"')
	const blocked = await verdict([op('read', false, 'resource_scope'), op('edit', false, 'grant')])
	assert.match(blocked.text, /alice cannot use any MCP tool: every operation is blocked, as each module below says\./, 'blocked by more than its grant')
	const some = await verdict([op('read', true, null), op('edit', false, 'grant'), op('create', null, 'grant_unknown')])
	assert.match(some.text, /alice can use the MCP tools allowed below/, 'one allowed operation is enough')
	const empty = await verdict([])
	assert.match(empty.text, /No operation was answered, so nothing can be said about alice\./)
	assert.deepEqual(empty.checks, [], 'no check is shown as passed without an operation')
	assert.doesNotMatch(empty.text, /can use|cannot use/)
})

test('S10: the inspector opens as a drawer from a row and from its button, closes on Escape, and only one drawer is open', async () => {
	const ui = await app()
	const root = ui.ids['mcp-inspector']
	let scopeClosed = 0
	ui.window.McpScopeUi = { close: () => { scopeClosed++ } }
	const trigger = new Element('button')
	ui.window.McpInspectorUi.open('alice', trigger)
	await tick()
	assert.ok(root.classes.has('mcp-drawer-open'))
	assert.equal(root.attributes.role, 'dialog')
	assert.equal(scopeClosed, 1, 'the resource scope drawer closes first')
	assert.equal(ui.ids['mcp-inspector-user'].value, 'alice')
	assert.ok(ui.calls.some((call) => call.url === '/apps/mcp/api/inspector/access?uid=alice'))
	root.listeners.keydown({ key: 'Escape', preventDefault() {} })
	assert.ok(!root.classes.has('mcp-drawer-open'))
	assert.equal(focused, trigger, 'the focus goes back to the control that opened it')
	ui.ids['mcp-inspector-entry'].listeners.click()
	assert.ok(root.classes.has('mcp-drawer-open'))
	assert.equal(focused, ui.ids['mcp-inspector-user'], 'the button of the tab opens it on the user field')
	ui.ids['mcp-inspector-close'].listeners.click()
	assert.equal(focused, ui.ids['mcp-inspector-entry'])
})

test('F6: the inspector does not open over a resource scope whose draft the administrator kept', async () => {
	const ui = await app()
	const root = ui.ids['mcp-inspector']
	ui.window.McpScopeUi = { close: () => false }
	const trigger = new Element('button')
	assert.equal(ui.window.McpInspectorUi.open('alice', trigger), false)
	await tick()
	assert.ok(!root.classes.has('mcp-drawer-open'), 'one drawer at a time: the scope stays, the inspector does not open')
	assert.ok(!ui.calls.some((call) => call.url.startsWith('/apps/mcp/api/inspector/access')), 'nothing is inspected')
	assert.equal(focused, trigger, 'the focus stays on the control, never on the page')
	ui.ids['mcp-inspector-entry'].listeners.click()
	assert.ok(!root.classes.has('mcp-drawer-open'))
	ui.window.McpScopeUi = { close: () => true }
	assert.equal(ui.window.McpInspectorUi.open('alice', trigger), true)
	assert.ok(root.classes.has('mcp-drawer-open'))
})

test('F4: the inspector gives the focus to the new "…" of the row when the old one is gone, or to its own button', async () => {
	const ui = await app()
	const fresh = new Element('button')
	ui.ids['mcp-matrix'] = { tBodies: [{ rows: [{ dataset: { uid: 'alice' }, querySelector: (selector) => selector === 'button.mcp-row-actions-button' ? fresh : null }] }] }
	const old = new Element('button')
	old.closest = () => ({ dataset: { uid: 'alice' } })
	ui.window.McpInspectorUi.open('alice', old)
	await tick()
	old.isConnected = false
	ui.ids['mcp-inspector-close'].listeners.click()
	assert.equal(focused, fresh)
	const lone = new Element('button')
	ui.window.McpInspectorUi.open('alice', lone)
	await tick()
	lone.isConnected = false
	ui.ids['mcp-inspector-close'].listeners.click()
	assert.equal(focused, ui.ids['mcp-inspector-entry'])
})

test('S11: an individual value that repeats what the person inherits is said as the matrix says it, once the preview answers', async () => {
	const operations = [
		row('notes', 'read', true, null, 'individual_allow'),
		row('notes', 'edit', false, 'grant', 'individual_deny'),
		row('deck', 'read', true, null, 'individual_allow'),
		row('deck', 'edit', true, null, 'individual_allow'),
	]
	let answer
	const asked = []
	const ui = await app((url) => url.startsWith('/apps/mcp/api/inspector/access') ? [200, { revision: '5', operations }] : undefined)
	ui.window.McpAdminExceptions = { classifyPerson: (uid, cells, revision) => { asked.push({ uid, revision, sources: cells.map((cell) => cell.source) }); return new Promise((resolve, reject) => { answer = { resolve, reject } }) } }
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	const modules = () => ui.ids['mcp-inspector-access'].all('li').filter((li) => li.className === 'mcp-inspector-module').map((li) => li.textContent.replace(/\s+/g, ' '))
	assert.deepEqual(asked, [{ uid: 'alice', revision: '5', sources: ['individual_allow', 'individual_deny', 'individual_allow', 'individual_allow'] }])
	assert.ok(modules().every((line) => /Set for this person/.test(line)), 'until the preview answers, nothing is said to repeat the inheritance')
	answer.resolve(new Map([
		['notes/read', { kind: 'redundant', after: { source: 'group', groupIds: ['comercial'] } }],
		['notes/edit', { kind: 'redundant', after: { source: 'default', groupIds: [] } }],
		['deck/read', { kind: 'redundant', after: { source: 'default', groupIds: [] } }],
		['deck/edit', { kind: 'exception', after: { source: 'default', groupIds: [] } }],
	]))
	await tick()
	const [notes, deck] = modules()
	assert.match(notes, /Group: comercial — also set for this person, with the same value/)
	assert.match(deck, /Exception: differs from what the person inherits/, 'one exception makes the module an exception')
	const cells = ui.ids['mcp-inspector-access'].all('td').map((td) => td.textContent)
	assert.ok(cells.includes('Group: comercial — also set for this person, with the same value'), 'the table says it per operation')
	assert.ok(cells.includes('Individual: allowed'), 'an exception stays individual')
})

test('S11: when the preview cannot answer, or answers after another person was inspected, individual values stay "set for this person"', async () => {
	const operations = [row('notes', 'read', true, null, 'individual_allow')]
	const pending = []
	const ui = await app((url) => url.startsWith('/apps/mcp/api/inspector/access') ? [200, { revision: '5', operations }] : undefined)
	ui.window.McpAdminExceptions = { classifyPerson: () => new Promise((resolve, reject) => pending.push({ resolve, reject })) }
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	pending[0].reject(new Error('stale'))
	await tick()
	const text = () => ui.ids['mcp-inspector-access'].textContent.replace(/\s+/g, ' ')
	assert.match(text(), /Notes Read Set for this person/)
	ui.ids['mcp-inspector-user'].value = 'bruno'
	await ui.submit('mcp-inspector-form')
	ui.ids['mcp-inspector-user'].value = 'carla'
	await ui.submit('mcp-inspector-form')
	pending[1].resolve(new Map([['notes/read', { kind: 'redundant', after: { source: 'default', groupIds: [] } }]]))
	await tick()
	assert.doesNotMatch(text(), /also set/, 'the answer about bruno is never shown under carla')
})

test('S11: what was known about one person is never said about the next one', async () => {
	const operations = [row('notes', 'read', true, null, 'individual_allow')]
	const pending = []
	const ui = await app((url) => url.startsWith('/apps/mcp/api/inspector/access') ? [200, { revision: '5', operations }] : undefined)
	ui.window.McpAdminExceptions = { classifyPerson: () => new Promise((resolve) => pending.push(resolve)) }
	ui.ids['mcp-inspector-user'].value = 'alice'
	await ui.submit('mcp-inspector-form')
	pending[0](new Map([['notes/read', { kind: 'redundant', after: { source: 'default', groupIds: [] } }]]))
	await tick()
	const text = () => ui.ids['mcp-inspector-access'].textContent.replace(/\s+/g, ' ')
	assert.match(text(), /Notes Read Default — also set for this person, with the same value/)
	ui.ids['mcp-inspector-user'].value = 'bruno'
	await ui.submit('mcp-inspector-form')
	assert.match(text(), /Notes Read Set for this person/, 'bruno waits for his own answer')
})

test('S12: the open drawer sits right under the Nextcloud header, even when the page box it is placed in starts lower', async () => {
	const ui = await app()
	const root = ui.ids['mcp-inspector']
	const style = { top: '', removeProperty(key) { this[key] = '' } }
	root.style = style
	// Nextcloud 35 blurs the content box, which then places the fixed drawer: its 44px top lands 44px too low.
	let boxTop = 44
	root.getBoundingClientRect = () => ({ top: style.top === '' ? 44 + boxTop : parseFloat(style.top) + boxTop })
	ui.ids.header = { getBoundingClientRect: () => ({ bottom: 44 }) }
	ui.window.McpInspectorUi.open('alice', new Element('button'))
	await tick()
	assert.equal(style.top, '0px', 'moved up by exactly the offset of the box')
	ui.ids['mcp-inspector-close'].listeners.click()
	assert.equal(style.top, '', 'a closed drawer keeps no position of its own')
	// Without the blur the window places it, and nothing is moved.
	boxTop = 0
	ui.window.McpInspectorUi.open('alice', new Element('button'))
	await tick()
	assert.equal(style.top, '')
})

test('Forms presets render their explicit names in the inspector consumer', async () => {
	for (const [results, label] of [[false, 'Read and write forms'], [true, 'Read and write forms and read responses']]) {
		const operations = ['read', 'results', 'create', 'edit'].map((operation) => {
			const allowed = operation !== 'results' || results
			return row('forms', operation, allowed, allowed ? null : 'grant', 'default')
		})
		const ui = await inspected(operations)
		const module = ui.ids['mcp-inspector-access'].all('li').find((item) => item.className === 'mcp-inspector-module')
		assert.ok(module, 'the Forms summary is rendered')
		assert.ok(module.textContent.includes(label))
		assert.ok(!module.textContent.includes('forms_write'), 'no internal level identifier reaches the user')
	}
})
