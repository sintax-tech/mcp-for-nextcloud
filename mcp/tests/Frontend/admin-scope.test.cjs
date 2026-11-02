/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** Behavioral tests of src/admin/admin-scope.js and src/settings/my-scope.js (issue #4, B7: S13 display, S14), with Node's test runner and a minimal DOM. */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('./AdminContext.cjs')
const fs = require('node:fs')
const path = require('node:path')

let active = null
class Element {
	constructor(tag) {
		this.tag = tag
		this.children = []
		this.attributes = {}
		this.listeners = {}
		this.parent = null
		this.value = ''
		this.hidden = false
		this.disabled = false
		this._text = ''
		this.classes = new Set()
	}
	get classList() { return { add: (name) => this.classes.add(name), remove: (name) => this.classes.delete(name), contains: (name) => this.classes.has(name) } }
	removeAttribute(key) { delete this.attributes[key] }
	append(...children) {
		for (const child of children) {
			if (typeof child !== 'string') child.parent = this
			this.children.push(child)
		}
	}
	replaceChildren(...children) { this.children = []; this._text = ''; this.append(...children) }
	replaceWith(other) {
		const siblings = this.parent.children
		siblings[siblings.indexOf(this)] = other
		other.parent = this.parent
	}
	setAttribute(key, value) { this.attributes[key] = value }
	addEventListener(name, action) { this.listeners[name] = action }
	focus() { active = this }
	scrollIntoView() {}
	set textContent(value) { this._text = value; this.children = []; this.writes?.push(value) }
	get textContent() { return [this._text, ...this.children.map((child) => typeof child === 'string' ? child : child.textContent)].filter((s) => s !== '').join(' ') }
	all(predicate) {
		return this.children.flatMap((child) => typeof child === 'string' ? [] : [...(predicate(child) ? [child] : []), ...child.all(predicate)])
	}
	find(predicate) { return this.all(predicate)[0] }
	button(text) { return this.find((node) => node.tag === 'button' && node.textContent === text) }
}
const tick = async () => { for (let i = 0; i < 20; i++) await new Promise(setImmediate) }

const show = {
	files: { uid: 'alice', module: 'files', active: false, mode: 'unrestricted', items: [], limit: 200, revision: '7' },
	calendar: { uid: 'alice', module: 'calendar', active: true, mode: 'restricted', items: [{ id: '11', found: true, name: 'Personal', path: null, excluded: false }], limit: 200, revision: '7' },
	tasks: { uid: 'alice', module: 'tasks', active: true, mode: 'unrestricted', items: [], limit: 200, revision: '7' },
}
const candidates = { items: [{ id: '11', name: 'Personal', path: null, excluded: false }, { id: '12', name: 'Team', path: null, excluded: false }], page: 1, pageSize: 50, total: 2, hasMore: false, parent: null }

async function app(handler = () => undefined, options = {}) {
	const page = new Element('div')
	// The drawer of S9 adds its title, tabs, close and entry buttons; without them the panel behaves as before.
	const drawer = options.drawer ? ['mcp-scope-tabs', 'mcp-scope-title', 'mcp-scope-close', 'mcp-scope-entry'] : []
	const ids = Object.fromEntries(['mcp-scope', 'mcp-scope-form', 'mcp-scope-user', 'mcp-scope-status', 'mcp-scope-alert', 'mcp-scope-modules', ...drawer].map((id) => {
		const node = new Element('div')
		node.id = id
		page.append(node)
		return [id, node]
	}))
	const document = {
		getElementById: (id) => ids[id] || page.find((node) => node.id === id) || null,
		createElement: (tag) => new Element(tag),
		get activeElement() { return active },
	}
	const calls = []
	const events = []
	const timers = []
	const context = {
		document, URLSearchParams,
		getComputedStyle: () => ({ top: '44px' }),
		OC: { generateUrl: (url) => url, requestToken: 'token' },
		// Like Nextcloud's t(): the values are HTML-escaped unless the caller passes { escape: false }.
		t: (_, text, params = {}, count, options = {}) => text.replace(/\{(\w+)\}/g, (match, key) => key in params
			? (options.escape === false ? String(params[key]) : String(params[key]).replace(/&/g, '&amp;').replace(/</g, '&lt;')) : match),
		// The short delay of an announcement runs at once; the ten seconds of an undo wait for the test.
		setTimeout: (action, delay) => { if (delay >= 1000) timers.push(action); else action() },
		clearTimeout: () => {},
		Event: class { constructor(type) { this.type = type } },
		CustomEvent: class { constructor(type, init = {}) { this.type = type; this.detail = init.detail } },
		fetch: async (url, options) => {
			const body = options.body === undefined ? undefined : JSON.parse(options.body)
			calls.push({ url, method: options.method, body, headers: options.headers })
			const custom = await handler(url, options.method, body)
			if (custom) {
				const [status, data] = custom
				return { ok: status < 400, status, json: async () => { if (data instanceof Error) throw data; return data } }
			}
			const module = (url.match(/\/api\/scope\/[^/]+\/(files|calendar|tasks)/) || [])[1]
			const data = url.includes('/candidates') ? candidates : options.method === 'GET' && module ? show[module] : {}
			return { ok: true, status: 200, json: async () => data }
		},
	}
	let refreshed = 0
	const listeners = {}
	context.window = {
		dispatchEvent: (event) => { events.push(event.detail ? [event.type, event.detail.from, event.detail.to] : event.type); (listeners[event.type] || []).forEach((action) => action(event)) },
		addEventListener: (name, action) => { (listeners[name] ||= []).push(action) },
		McpAdminMatrix: { refresh: () => { refreshed++ } },
		confirm: (text) => { confirms.push(text); return options.confirm !== false },
	}
	const confirms = []
	vm.runInNewContext(require('./AdminManifest.cjs').script('admin-scope.js'), context)
	const open = async (uid = 'alice') => {
		ids['mcp-scope-user'].value = uid
		await ids['mcp-scope-form'].listeners.submit({ preventDefault() {} })
		await tick()
	}
	const module = (name) => document.getElementById('mcp-scope-module-' + name)
	const click = async (node) => { assert.ok(node, 'the control exists'); assert.equal(node.disabled, false, 'the control is enabled'); node.listeners.click(); await tick() }
	ids['mcp-scope-alert'].writes = []
	return { ids, calls, events, timers, open, module, click, document, window: context.window, ui: context.window.McpScopeUi, refreshed: () => refreshed, confirms, active: () => active }
}

test('S14: each module shows its saved scope; a module the tools do not scope yet says so and cannot be saved', async () => {
	const ui = await app()
	await ui.open()
	assert.deepEqual(ui.calls.map((c) => c.url), ['/apps/mcp/api/scope/alice/files', '/apps/mcp/api/scope/alice/calendar', '/apps/mcp/api/scope/alice/tasks'])
	assert.equal(ui.calls[0].headers.requesttoken, 'token', 'the CSRF token travels with every request')
	const files = ui.module('files')
	assert.match(files.textContent, /Not available in this version: the tools of this module do not apply a scope yet/)
	assert.equal(files.button('Save').disabled, true)
	const calendar = ui.module('calendar')
	assert.match(calendar.textContent, /1 of at most 200 selected/)
	assert.ok(calendar.find((node) => node.attributes['aria-label'] === 'Remove Personal'), 'each item has a labelled remove button')
	assert.equal(ui.module('calendar').attributes['aria-labelledby'], 'mcp-scope-heading-calendar', 'the module region is named by its heading')
	assert.equal(ui.ids['mcp-scope-status'].textContent, 'Resource scope of alice.')
})

test('S14: choosing resources and saving sends the revision, offers undo for ten seconds and refreshes the matrix', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [200, { revision: '8', changed: true, receiptId: '41', scope: { mode: 'restricted', count: 2 } }] : undefined)
	await ui.open()
	await ui.click(ui.module('calendar').button('Search'))
	await ui.click(ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Add Team'))
	assert.match(ui.module('calendar').textContent, /Team added\. Save to apply it\./)
	await ui.click(ui.module('calendar').button('Save'))
	const put = ui.calls.find((c) => c.method === 'PUT')
	assert.deepEqual([put.url, put.body], ['/apps/mcp/api/scope/alice/calendar', { expectedRevision: '7', mode: 'restricted', items: ['11', '12'] }])
	assert.match(ui.module('calendar').textContent, /Saved\. It applies from the next MCP call of the user; sessions are not ended\./)
	assert.deepEqual(ui.events, [['mcp-revision-moved', '7', '8'], 'mcp-scope-saved'], 'the other blocks of the page learn the revision this save made')
	assert.equal(ui.ids['mcp-scope-status'].textContent, 'Calendar: calendars: Saved. It applies from the next MCP call of the user; sessions are not ended.', 'announced in the live region that exists from the start')
	assert.ok(ui.module('calendar').button('Undo'))
	ui.timers.forEach((action) => action())
	assert.equal(ui.module('calendar').button('Undo'), undefined, 'undo is offered for ten seconds only')
})

test('S14: undo goes back through the receipt of the save', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [200, { revision: '8', changed: true, receiptId: '41', scope: { mode: 'unrestricted', count: null } }]
		: url.includes('/api/grants/undo') ? [200, { revision: '9', changed: 1 }] : undefined)
	await ui.open()
	const restricted = ui.module('tasks').find((node) => node.id === 'mcp-scope-mode-tasks-restricted')
	restricted.checked = true
	restricted.listeners.change()
	await tick()
	assert.match(ui.module('tasks').textContent, /Nothing selected: the Tasks tools of this user reach no task list\./)
	await ui.click(ui.module('tasks').button('Save'))
	assert.deepEqual(ui.calls.find((c) => c.method === 'PUT').body, { expectedRevision: '7', mode: 'restricted', items: [] }, 'restricted to nothing is not unrestricted')
	await ui.click(ui.module('tasks').button('Undo'))
	assert.deepEqual(ui.calls.find((c) => c.url === '/apps/mcp/api/grants/undo').body, { receiptId: '41', expectedRevision: '8' })
	assert.match(ui.module('tasks').textContent, /Change undone\./)
})

test('S14: a conflict never repeats the write; it shows the saved scope again', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [409, { error: 'Conflict', revision: '9' }] : undefined)
	await ui.open()
	await ui.click(ui.module('calendar').button('Remove'))
	await ui.click(ui.module('calendar').button('Save'))
	assert.equal(ui.calls.filter((c) => c.method === 'PUT').length, 1)
	assert.deepEqual(ui.calls.slice(-3).map((c) => c.url.split('/').pop()), ['files', 'calendar', 'tasks'], 'the revision moved: the three modules are read again')
	assert.match(ui.module('calendar').textContent, /The policy changed after this scope was loaded, so nothing was saved\./)
	assert.match(ui.module('calendar').textContent, /1 of at most 200 selected/, 'the saved scope is back on screen')
	assert.match(ui.ids['mcp-scope-alert'].textContent, /^Calendar: calendars: The policy changed after this scope was loaded/, 'a refusal is an alert')
})

test('S14: refusals of the server are explained, and an inaccessible resource is marked', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [422, { error: 'Inaccessible', items: ['11'] }] : undefined)
	await ui.open()
	await ui.click(ui.module('calendar').button('Search'))
	await ui.click(ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Add Team'))
	await ui.click(ui.module('calendar').button('Save'))
	const text = ui.module('calendar').textContent
	assert.match(text, /Not saved: the user cannot reach the marked resources in Nextcloud\./)
	assert.match(text, /Personal the user cannot reach it in Nextcloud/)
	assert.doesNotMatch(text, /Team the user cannot reach it/)
})

test('S14: an excluded folder is marked as having no effect, and folders are browsed level by level', async () => {
	const folders = (parent) => ({ items: parent === undefined ? [{ id: '5', name: 'Projects', path: '/Projects', excluded: false }, { id: '6', name: 'Secret', path: '/Secret', excluded: true }]
		: [{ id: '7', name: 'Plans', path: '/Projects/Plans', excluded: false }], page: 1, pageSize: 50, total: parent === undefined ? 2 : 1, hasMore: false, parent: null })
	const ui = await app((url) => url.includes('/files/candidates') ? [200, folders(new URL('http://x' + url).searchParams.get('parent') ?? undefined)] : undefined)
	await ui.open()
	const restricted = ui.module('files').find((node) => node.id === 'mcp-scope-mode-files-restricted')
	restricted.checked = true
	restricted.listeners.change()
	await tick()
	await ui.click(ui.module('files').button('Search'))
	assert.match(ui.module('files').textContent, /\/Secret excluded by a tag: choosing it has no effect/)
	await ui.click(ui.module('files').find((node) => node.attributes['aria-label'] === 'Open /Projects'))
	assert.match(ui.calls.at(-1).url, /files\/candidates\?search=&page=1&parent=5$/)
	assert.match(ui.module('files').textContent, /In folder \/Projects/)
	assert.match(ui.module('files').textContent, /\/Projects\/Plans/)
	await ui.click(ui.module('files').button('Up one folder'))
	assert.match(ui.module('files').textContent, /In the top folder of the user/)
})

test('S14 keyboard: removing an item moves the focus to the next one, not to the page', async () => {
	show.calendar.items.push({ id: '12', found: true, name: 'Team', path: null, excluded: false }, { id: '99', found: false, name: null, path: null, excluded: false })
	try {
		const ui = await app()
		await ui.open()
		assert.match(ui.module('calendar').textContent, /Resource no longer found \(99\) not found: it has no effect/)
		await ui.click(ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Remove Personal'))
		assert.equal(ui.document.activeElement.id, 'mcp-scope-remove-calendar-12')
	} finally {
		show.calendar.items.splice(1)
	}
})

test('S14: the matrix mark is text, not color alone, and opens the scope of that user', async () => {
	const ui = await app()
	const mark = ui.ui.indicator('bob', 'tasks', { mode: 'restricted', count: 3 })
	assert.equal(mark.tag, 'button')
	assert.match(mark.textContent, /Restricted to 3/)
	assert.equal(mark.find((node) => node.attributes['aria-hidden'] === 'true').textContent, '◩', 'the icon is hidden from screen readers')
	mark.listeners.click()
	await tick()
	assert.equal(ui.ids['mcp-scope-user'].value, 'bob')
	assert.equal(ui.calls[0].url, '/apps/mcp/api/scope/bob/files')
	assert.equal(ui.document.activeElement.id, 'mcp-scope-heading-tasks', 'the focus lands on the module the mark belongs to')
})

test('a refused or unavailable scope is said in the alert region, with nothing shown', async () => {
	for (const [status, message] of [[403, 'Only administrators can change the resource scope.'], [503, 'The MCP policy cannot be read right now. Nothing was changed. Try again shortly.']]) {
		const ui = await app(() => [status, { error: 'x' }])
		await ui.open()
		assert.equal(ui.ids['mcp-scope-alert'].textContent, message)
		assert.equal(ui.ids['mcp-scope-modules'].children.length, 0)
	}
})

async function personal(handler) {
	const ids = { 'mcp-my-scope-status': new Element('p'), 'mcp-my-scope-list': new Element('ul') }
	const context = {
		document: { getElementById: (id) => ids[id], createElement: (tag) => new Element(tag) },
		OC: { generateUrl: (url) => url, requestToken: 'token' },
		t: (_, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		fetch: async (url) => { const [status, data] = handler(url); return { ok: status < 400, status, json: async () => data } },
	}
	vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../src/settings/my-scope.js'), 'utf8'), context)
	await tick()
	return ids
}

test('personal settings: the user reads their own scope, and an unreadable scope is never shown as unrestricted', async () => {
	const ids = await personal(() => [200, { modules: {
		files: { mode: 'unrestricted', items: [] },
		calendar: { mode: 'restricted', items: [{ id: '11', found: true, name: 'Personal', path: null }, { id: '9', found: false, name: null, path: null }] },
		tasks: { mode: 'restricted', items: [] },
	} }])
	assert.deepEqual(ids['mcp-my-scope-list'].children.map((li) => li.textContent),
		['Files: every folder', 'Calendar: only Personal, a resource that no longer exists', 'Tasks: no task list'])
	const failed = await personal(() => [503, { error: 'Unavailable' }])
	assert.equal(failed['mcp-my-scope-status'].textContent, 'Your resource scope cannot be read right now. Try again later.')
	assert.equal(failed['mcp-my-scope-list'].children.length, 0)
})

test('B7 review A1: an unreadable scope is marked "Scope unknown", never blank; an unrestricted one has no mark', async () => {
	const ui = await app()
	const unknown = ui.ui.indicator('bob', 'files', { mode: 'unavailable', count: null })
	assert.match(unknown.textContent, /Scope unknown/)
	assert.match(unknown.title, /The scope cannot be read now/)
	assert.equal(ui.ui.indicator('bob', 'files', { mode: 'unrestricted', count: null }), null)
	assert.equal(ui.refreshed(), 1, 'N1: a matrix drawn before the script loaded is drawn again with the marks')
})

/** Puts a draft in Calendar (adds Team) and switches Tasks to "Only selected", both unsaved. */
async function twoDrafts(ui) {
	await ui.click(ui.module('calendar').button('Search'))
	await ui.click(ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Add Team'))
	const restricted = ui.module('tasks').find((node) => node.id === 'mcp-scope-mode-tasks-restricted')
	restricted.checked = true
	restricted.listeners.change()
	await tick()
}

test('B7 review M1: a draft whose revision moved by a change this page did not make keeps its own revision and says it is stale', async () => {
	const ui = await app((url, method) => method === 'PUT' && url.endsWith('/tasks') ? [409, { error: 'Conflict', revision: '9' }]
		: method === 'PUT' ? [200, { revision: '10', changed: true, receiptId: '41', scope: { mode: 'restricted', count: 0 } }] : undefined)
	await ui.open()
	await twoDrafts(ui)
	await ui.click(ui.module('tasks').button('Save'))
	// The refusal moved the revision by a write this page never saw: Files and Tasks are read again, the Calendar draft
	// is kept, never overwritten, and says it is stale.
	assert.deepEqual(ui.calls.filter((c) => c.method === 'GET' && !c.url.includes('candidates')).slice(-2).map((c) => c.url.split('/').pop()), ['files', 'tasks'])
	assert.match(ui.module('calendar').textContent, /Changed before the policy moved, so saving will be refused\./)
	assert.deepEqual(ui.events, [], 'a refused save announces nothing')
	await ui.click(ui.module('calendar').button('Save'))
	assert.equal(ui.calls.filter((c) => c.method === 'PUT').at(-1).body.expectedRevision, '7', 'the Calendar draft keeps the revision it was read at')
})

test('T14 D1: a save of another module of this panel moves the revision of a draft that was at the same one; the draft stays and is not stale', async () => {
	let revision = '7'
	const ui = await app((url, method) => {
		if (method === 'PUT') { revision = String(Number(revision) + 1); return [200, { revision, changed: true, receiptId: '41', scope: { mode: 'restricted', count: 0 } }] }
		const module = (url.match(/\/api\/scope\/[^/]+\/(files|calendar|tasks)$/) || [])[1]
		return module ? [200, Object.assign({}, show[module], { revision })] : undefined
	})
	await ui.open()
	await twoDrafts(ui)
	await ui.click(ui.module('tasks').button('Save'))
	assert.doesNotMatch(ui.module('calendar').textContent, /Changed before the policy moved/)
	assert.match(ui.module('calendar').textContent, /2 of at most 200 selected/, 'the Calendar draft is still on screen')
	await ui.click(ui.module('calendar').button('Save'))
	const put = ui.calls.filter((c) => c.method === 'PUT').at(-1)
	assert.deepEqual([put.url, put.body], ['/apps/mcp/api/scope/alice/calendar', { expectedRevision: '8', mode: 'restricted', items: ['11', '12'] }], 'sent with the revision the Tasks save made')
	assert.match(ui.module('calendar').textContent, /Saved\./)
})

test('T14 D1: a write of the matrix on this page moves the revision of the open scope; a move that does not start from it is ignored', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [200, { revision: '9', changed: true, receiptId: '41', scope: { mode: 'restricted', count: 0 } }] : undefined)
	await ui.open()
	await twoDrafts(ui)
	const moved = (from, to) => ui.window.dispatchEvent({ type: 'mcp-revision-moved', detail: { from, to } })
	moved('5', '6')
	moved('7', '8')
	await ui.click(ui.module('tasks').button('Save'))
	assert.deepEqual(ui.calls.find((c) => c.method === 'PUT').body, { expectedRevision: '8', mode: 'restricted', items: [] })
	assert.doesNotMatch(ui.module('tasks').textContent, /The policy changed after this scope was loaded/)
	assert.match(ui.module('calendar').textContent, /2 of at most 200 selected/, 'the other draft is still on screen')
})

test('B7 review M1: discarding a stale draft loads the saved scope from the server', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [409, { error: 'Conflict', revision: '9' }] : undefined)
	await ui.open()
	await ui.click(ui.module('calendar').button('Remove'))
	const restricted = ui.module('tasks').find((node) => node.id === 'mcp-scope-mode-tasks-restricted')
	restricted.checked = true
	restricted.listeners.change()
	await tick()
	await ui.click(ui.module('tasks').button('Save'))
	assert.match(ui.module('calendar').textContent, /Changed before the policy moved/)
	const before = ui.calls.length
	await ui.click(ui.module('calendar').button('Discard changes'))
	assert.equal(ui.calls.at(-1).url, '/apps/mcp/api/scope/alice/calendar', 'the saved scope comes from the server')
	assert.ok(ui.calls.length > before)
	assert.match(ui.module('calendar').textContent, /1 of at most 200 selected/)
})

test('B7 review B1: names are shown as they are, never HTML-escaped twice', async () => {
	const ui = await app((url) => url.includes('/calendar/candidates') ? [200, { items: [{ id: '12', name: 'R&D <team>', path: null, excluded: false }], page: 1, pageSize: 50, total: 1, hasMore: false, parent: null }] : undefined)
	await ui.open()
	await ui.click(ui.module('calendar').button('Search'))
	const add = ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Add R&D <team>')
	assert.ok(add, 'the accessible name keeps the raw name')
	await ui.click(add)
	assert.match(ui.module('calendar').textContent, /R&D <team> added\./)
	assert.doesNotMatch(ui.module('calendar').textContent, /&amp;|&lt;/)
})

test('B7 review B3: Save, Discard and Undo wait for the request in flight', async () => {
	let release
	const pending = new Promise((resolve) => { release = resolve })
	const ui = await app(async (url, method) => method === 'PUT' ? (await pending, [200, { revision: '8', changed: true, receiptId: '41', scope: { mode: 'restricted', count: 0 } }]) : undefined)
	await ui.open()
	await ui.click(ui.module('calendar').button('Remove'))
	ui.module('calendar').button('Save').listeners.click()
	await tick()
	assert.equal(ui.module('calendar').button('Save').disabled, true)
	assert.equal(ui.module('calendar').button('Discard changes').disabled, true)
	ui.module('calendar').button('Save').listeners.click()
	release()
	await tick()
	assert.equal(ui.calls.filter((c) => c.method === 'PUT').length, 1, 'a second click sends nothing')
})

test('B7 review B2: the undo waits while the pointer or the focus is on it, and the focus never falls to the page', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [200, { revision: '8', changed: true, receiptId: '41', scope: { mode: 'restricted', count: 0 } }] : undefined)
	await ui.open()
	await ui.click(ui.module('calendar').button('Remove'))
	await ui.click(ui.module('calendar').button('Save'))
	const undo = ui.module('calendar').button('Undo')
	undo.listeners.mouseenter()
	ui.timers.forEach((action) => action())
	assert.ok(ui.module('calendar').button('Undo'), 'kept while the pointer is on it')
	undo.focus()
	undo.listeners.focus()
	undo.listeners.mouseleave()
	assert.ok(ui.module('calendar').button('Undo'), 'kept while it has the focus')
	undo.listeners.blur()
	assert.equal(ui.module('calendar').button('Undo'), undefined, 'gone once released after the ten seconds')
})

test('B7 review B4: the same error twice in a row is announced twice', async () => {
	const ui = await app(() => [503, { error: 'Unavailable' }])
	await ui.open()
	await ui.open()
	const writes = ui.ids['mcp-scope-alert'].writes
	const shown = writes.map((w, i) => /^The MCP policy/.test(w) ? i : -1).filter((i) => i >= 0)
	assert.equal(shown.length, 2, 'written twice')
	assert.ok(writes.slice(shown[0] + 1, shown[1]).includes(''), 'cleared in between, so a screen reader announces it again')
})


test('S9: the drawer opens on a user from the matrix, shows one module per tab with its saved state, and gives the focus back', async () => {
	const ui = await app(undefined, { drawer: true })
	const root = ui.ids['mcp-scope']
	const trigger = new Element('button')
	let inspectorClosed = 0
	ui.window.McpInspectorUi = { close: () => { inspectorClosed++ } }
	ui.ui.open('alice', 'calendar', trigger)
	await tick()
	assert.ok(root.classes.has('mcp-drawer-open'))
	assert.equal(inspectorClosed, 1, 'only one drawer is open at a time')
	assert.equal(root.attributes.role, 'dialog')
	assert.equal(ui.ids['mcp-scope-user'].value, 'alice')
	const tabs = ui.ids['mcp-scope-tabs'].children
	assert.deepEqual(tabs.map((tab) => tab.textContent), ['Files: all', 'Calendar: 1', 'Tasks: all'])
	assert.deepEqual(tabs.map((tab) => tab.attributes['aria-selected']), ['false', 'true', 'false'], 'it opens on the module it was asked for')
	assert.ok(tabs.every((tab) => tab.attributes.role === 'tab'))
	assert.equal(tabs[1].attributes['aria-controls'], 'mcp-scope-module-calendar')
	assert.deepEqual(['files', 'calendar', 'tasks'].map((name) => ui.module(name).hidden), [true, false, true], 'one module at a time')
	// The arrow keys move between the tabs.
	tabs[1].listeners.keydown({ key: 'ArrowRight', preventDefault() {} })
	assert.deepEqual(['files', 'calendar', 'tasks'].map((name) => ui.module(name).hidden), [true, true, false])
	assert.equal(ui.active(), ui.ids['mcp-scope-tabs'].children[2])
	ui.ids['mcp-scope-tabs'].children[2].listeners.keydown({ key: 'Home', preventDefault() {} })
	assert.equal(ui.module('files').hidden, false)
	// Escape closes it and gives the focus back to the control that opened it.
	root.listeners.keydown({ key: 'Escape', preventDefault() {} })
	assert.ok(!root.classes.has('mcp-drawer-open'))
	assert.equal(root.attributes.role, undefined)
	assert.equal(ui.active(), trigger)
})

test('S9: a draft that would be lost is asked about before the drawer closes, and a tab says it is not saved', async () => {
	const ui = await app(undefined, { drawer: true, confirm: false })
	const root = ui.ids['mcp-scope']
	ui.ui.open('alice', 'calendar', new Element('button'))
	await tick()
	await ui.click(ui.module('calendar').button('Search'))
	await ui.click(ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Add Team'))
	assert.equal(ui.ids['mcp-scope-tabs'].children[1].textContent, 'Calendar: 1 (not saved)')
	ui.ids['mcp-scope-close'].listeners.click()
	assert.deepEqual(ui.confirms, ['Close the resource scope? The changes that are not saved are lost.'])
	assert.ok(root.classes.has('mcp-drawer-open'), 'a "no" keeps the drawer and the draft')
})

test('S9: the entry button of the Access tab opens the drawer on the user field', async () => {
	const ui = await app(undefined, { drawer: true })
	ui.ids['mcp-scope-entry'].listeners.click()
	assert.ok(ui.ids['mcp-scope'].classes.has('mcp-drawer-open'))
	assert.equal(ui.active(), ui.ids['mcp-scope-user'])
	ui.ids['mcp-scope-close'].listeners.click()
	assert.equal(ui.active(), ui.ids['mcp-scope-entry'])
})

test('F4: when the table was drawn again and the "…" that opened the drawer is gone, the focus goes to the new "…" of that row', async () => {
	const ui = await app(undefined, { drawer: true })
	const fresh = new Element('button')
	ui.ids['mcp-matrix'] = { tBodies: [{ rows: [{ dataset: { uid: 'alice' }, querySelector: (selector) => selector === 'button.mcp-row-actions-button' ? fresh : null }] }] }
	const old = new Element('button')
	old.closest = () => ({ dataset: { uid: 'alice' } })
	ui.ui.open('alice', undefined, old)
	await tick()
	old.isConnected = false
	ui.ids['mcp-scope-close'].listeners.click()
	assert.equal(ui.active(), fresh)
	// Without a row to come back to, the button of the Access tab takes it, never the page.
	const lone = new Element('button')
	ui.ui.open('alice', undefined, lone)
	await tick()
	lone.isConnected = false
	ui.ids['mcp-scope-close'].listeners.click()
	assert.equal(ui.active(), ui.ids['mcp-scope-entry'])
	assert.ok(ui.events.includes('mcp-drawer-closed'), 'the matrix may draw itself again now')
})

/** Opens the drawer on alice with a draft in Calendar (Team added, not saved). */
async function withDraft(options) {
	const ui = await app(undefined, Object.assign({ drawer: true }, options))
	ui.ui.open('alice', 'calendar', new Element('button'))
	await tick()
	await ui.click(ui.module('calendar').button('Search'))
	await ui.click(ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Add Team'))
	assert.equal(ui.ids['mcp-scope-tabs'].children[1].textContent, 'Calendar: 1 (not saved)')
	return ui
}
const reads = (ui, uid) => ui.calls.filter((call) => call.method === 'GET' && call.url === '/apps/mcp/api/scope/' + uid + '/files').length

test('F6: opening another person from the matrix while a draft is open asks first; a "no" keeps the draft and the focus', async () => {
	const ui = await withDraft({ confirm: false })
	const trigger = new Element('button')
	assert.equal(ui.ui.open('bruno', 'files', trigger), false)
	await tick()
	assert.deepEqual(ui.confirms, ['Open another person? The changes that are not saved for alice are lost.'])
	assert.equal(reads(ui, 'bruno'), 0, 'bruno is not read')
	assert.equal(ui.ids['mcp-scope-user'].value, 'alice')
	assert.equal(ui.ids['mcp-scope-tabs'].children[1].textContent, 'Calendar: 1 (not saved)', 'the draft of alice stays')
	assert.equal(ui.active(), trigger, 'the focus goes back to the control, never to the page')
	// The form of the drawer asks too, even for the same person: reading her again would drop the draft.
	ui.ids['mcp-scope-user'].value = 'alice'
	await ui.ids['mcp-scope-form'].listeners.submit({ preventDefault() {} })
	await tick()
	assert.equal(ui.confirms.length, 2)
	assert.equal(reads(ui, 'alice'), 1, 'alice is not read again')
	assert.equal(ui.ids['mcp-scope-tabs'].children[1].textContent, 'Calendar: 1 (not saved)')
})

test('F6: a "yes" drops the draft and opens the other person; the same person only changes tab and keeps it', async () => {
	const same = await withDraft()
	assert.equal(same.ui.open('alice', 'tasks', new Element('button')), true)
	await tick()
	assert.deepEqual(same.confirms, [], 'nothing is lost, nothing is asked')
	assert.equal(reads(same, 'alice'), 1)
	assert.equal(same.module('tasks').hidden, false)
	assert.equal(same.ids['mcp-scope-tabs'].children[1].textContent, 'Calendar: 1 (not saved)')
	const ui = await withDraft()
	assert.equal(ui.ui.open('bruno', undefined, new Element('button')), true)
	await tick()
	assert.equal(ui.confirms.length, 1)
	assert.equal(reads(ui, 'bruno'), 1)
	assert.equal(ui.ids['mcp-scope-user'].value, 'bruno')
	assert.ok(ui.ids['mcp-scope-tabs'].children.every((tab) => !tab.textContent.includes('not saved')))
})

test('F6: close() says whether the drawer closed, and a draft given up does not come back', async () => {
	const kept = await withDraft({ confirm: false })
	assert.equal(kept.ui.close(), false, 'the administrator kept the draft: the drawer is still open')
	assert.ok(kept.ids['mcp-scope'].classes.has('mcp-drawer-open'))
	const ui = await withDraft()
	assert.equal(ui.ui.close(), true)
	assert.equal(ui.ui.close(), true, 'a closed drawer is closed')
	ui.ids['mcp-scope-entry'].listeners.click()
	assert.deepEqual(ui.ids['mcp-scope-tabs'].children, [], 'the draft that was given up is gone')
	assert.equal(ui.ui.open('bruno', undefined, new Element('button')), true)
	assert.equal(ui.confirms.length, 1, 'only the closing asked')
})

test('S12: the open drawer sits right under the Nextcloud header, and keeps no position once closed', async () => {
	const ui = await app(undefined, { drawer: true })
	const root = ui.ids['mcp-scope']
	const style = { top: '', removeProperty(key) { this[key] = '' } }
	root.style = style
	root.getBoundingClientRect = () => ({ top: style.top === '' ? 88 : parseFloat(style.top) + 44 })
	ui.ids.header = { getBoundingClientRect: () => ({ bottom: 44 }) }
	ui.ui.open('alice', undefined, new Element('button'))
	await tick()
	assert.equal(style.top, '0px')
	ui.ids['mcp-scope-close'].listeners.click()
	assert.equal(style.top, '')
})

test('issue #11: an unreadable scope save is unconfirmed and reloads the stored scope', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [200, new SyntaxError('HTML proxy response')] : undefined)
	await ui.open()
	await ui.click(ui.module('calendar').button('Search'))
	await ui.click(ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Add Team'))
	await ui.click(ui.module('calendar').button('Save'))
	assert.deepEqual(ui.events, [])
	assert.match(ui.ids['mcp-scope-alert'].textContent, /The save could not be confirmed: it may have been applied/)
	assert.doesNotMatch(ui.module('calendar').textContent, /Nothing changed\.|Saved\.|SyntaxError/)
	assert.equal(ui.module('calendar').find((node) => node.attributes['aria-label'] === 'Remove Team'), undefined, 'the stored scope is read again')
	assert.equal(ui.module('calendar').button('Save').disabled, true, 'the stored scope is clean after the refresh')
})
test('issue #11: an unreadable undo response never claims the scope change was undone', async () => {
	const ui = await app((url, method) => method === 'PUT' ? [200, { revision: '8', changed: true, receiptId: '41' }]
		: url.includes('/api/grants/undo') ? [200, new SyntaxError('HTML proxy response')] : undefined)
	await ui.open()
	const restricted = ui.module('tasks').find((node) => node.id === 'mcp-scope-mode-tasks-restricted')
	restricted.checked = true
	restricted.listeners.change()
	await tick()
	await ui.click(ui.module('tasks').button('Save'))
	const events = [...ui.events]
	await ui.click(ui.module('tasks').button('Undo'))
	assert.deepEqual(ui.events, events)
	assert.match(ui.ids['mcp-scope-alert'].textContent, /The save could not be confirmed: it may have been applied/)
	assert.doesNotMatch(ui.module('tasks').textContent, /Change undone\.|SyntaxError/)
})
