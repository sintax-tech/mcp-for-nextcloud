/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Behavioral tests of the row actions of the permission matrix (S5 of the admin redesign): src/admin/admin-row-actions.js runs
 * unchanged against a stub of window.McpAdminMatrix and a minimal DOM, and the row pieces of src/admin/admin-grants.js (the line
 * under the name and the "With exceptions" filter) are cut out of the source and run against stubs.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const tick = async () => { for (let i = 0; i < 12; i++) await new Promise(setImmediate) }

class Element {
	constructor(tag, document) {
		this.tag = tag
		this.document = document
		this.children = []
		this.attributes = {}
		this.listeners = {}
		this.classes = new Set()
		this.dataset = {}
		this.style = {}
		this.hidden = false
		this.disabled = false
		this.parent = null
		this.isConnected = true
		this._text = ''
	}
	set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)) }
	get className() { return [...this.classes].join(' ') }
	get classList() { return { add: (name) => this.classes.add(name), contains: (name) => this.classes.has(name) } }
	append(...children) { for (const child of children) { if (typeof child !== 'string') child.parent = this; this.children.push(child) } }
	replaceChildren(...children) { this.children = []; this._text = ''; this.append(...children) }
	remove() { if (this.parent) this.parent.children = this.parent.children.filter((child) => child !== this) }
	setAttribute(key, value) { this.attributes[key] = String(value) }
	getAttribute(key) { return this.attributes[key] ?? null }
	addEventListener(name, action) { (this.listeners[name] ||= []).push(action) }
	async fire(name, event = {}) { for (const action of this.listeners[name] || []) await action(event) }
	focus() { this.document.activeElement = this }
	contains(node) { for (let at = node; at; at = at.parent) if (at === this) return true; return false }
	getBoundingClientRect() { return { top: 0, left: 0, bottom: 20, right: 40, width: 800 } }
	get offsetParent() { return this.parent }
	get offsetWidth() { return 260 }
	set textContent(value) { this._text = String(value); this.children = [] }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join('') }
	all() { return this.children.flatMap((child) => typeof child === 'string' ? [] : [child, ...child.all()]) }
	querySelector(selector) {
		if (selector === 'h2') return this.all().find((node) => node.tag === 'h2') ?? null
		if (selector === 'tr.mcp-only-empty') return this.all().find((node) => node.tag === 'tr' && node.classes.has('mcp-only-empty')) ?? null
		throw new Error('unsupported selector ' + selector)
	}
	async click() { if (!this.disabled) for (const action of this.listeners.click || []) await action() }
}

const t = (app, text, vars) => text.replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? '{' + key + '}')

function setup({ overrides = true, clipboard = true } = {}) {
	const document = { activeElement: null, listeners: {} }
	document.createElement = (tag) => new Element(tag, document)
	const block = new Element('div', document)
	const menu = new Element('div', document)
	menu.hidden = true
	block.append(menu)
	const inspector = new Element('div', document)
	inspector.append(new Element('h2', document))
	const scope = new Element('div', document)
	scope.append(new Element('h2', document))
	inspector.scrollIntoView = () => { log.scrolled.push('inspector') }
	scope.scrollIntoView = () => { log.scrolled.push('scope') }
	document.getElementById = (id) => ({ 'mcp-row-menu': menu, 'mcp-inspector': inspector, 'mcp-scope': scope })[id] ?? null
	document.addEventListener = (name, action) => { (document.listeners[name] ||= []).push(action) }
	const log = { inspected: [], scoped: [], resets: [], copied: [], notices: [], errors: [], scrolled: [], events: [] }
	const users = [{ uid: 'ana', displayName: 'Ana Souza' }, { uid: 'bruno', displayName: 'Bruno Lima' }]
	const listeners = {}
	const window = {
		McpAdminMatrix: {
			data: () => ({ revision: '7', users }),
			offerUndo: (message, saved) => log.notices.push({ message, saved }),
			notifyError: (message) => log.errors.push(message),
		},
		McpInspectorUi: { open: (uid, trigger) => log.inspected.push([uid, trigger]) },
		McpScopeUi: { open: (uid, module, trigger) => log.scoped.push([uid, module, trigger]) },
		McpAdminOverrides: overrides ? { hasOverrides: (user) => user.uid === 'ana', resetUser: (user, trigger) => log.resets.push([user.uid, trigger]) } : undefined,
		addEventListener: (name, action) => { (listeners[name] ||= []).push(action) },
		dispatchEvent: (event) => log.events.push(event.type),
	}
	const navigator = !clipboard ? {} : { clipboard: { writeText: async (text) => {
		if (clipboard === 'refuses') throw new Error('NotAllowedError')
		log.copied.push(text)
	} } }
	vm.runInNewContext(require('./AdminManifest.cjs').script('admin-row-actions.js'), { window, document, navigator, t, Event: class { constructor(type) { this.type = type } } })
	const button = (uid) => {
		const trigger = window.McpRowActions.button(users.find((user) => user.uid === uid))
		block.append(trigger)
		return trigger
	}
	return { api: window.McpRowActions, menu, document, log, button, rendered: () => (listeners['mcp-matrix-rendered'] || []).forEach((action) => action()) }
}

const labels = (kit) => kit.menu.children.map((item) => item.textContent)

test('the "…" button names the user, says it opens a menu, and opens the four actions in order', async () => {
	const kit = setup()
	const trigger = kit.button('ana')
	assert.equal(trigger.textContent, '…')
	assert.equal(trigger.getAttribute('aria-label'), 'Actions for Ana Souza')
	assert.equal(trigger.getAttribute('aria-haspopup'), 'menu')
	assert.equal(trigger.getAttribute('aria-expanded'), 'false')
	await trigger.click()
	assert.equal(kit.menu.hidden, false)
	assert.equal(trigger.getAttribute('aria-expanded'), 'true')
	assert.equal(kit.menu.getAttribute('aria-label'), 'Actions for Ana Souza')
	assert.deepEqual(labels(kit), ['Inspect access', 'Restrict resources', 'Give everything back to inheritance…', 'Copy user ID'])
	assert.ok(kit.menu.children.every((item) => item.getAttribute('role') === 'menuitem'))
	assert.equal(kit.document.activeElement, kit.menu.children[0], 'the focus moves to the first item')
})

test('a user without individual values cannot be given back to inheritance', async () => {
	const kit = setup()
	await kit.button('bruno').click()
	assert.equal(kit.menu.children[2].disabled, true)
	const without = setup({ overrides: false })
	await without.button('ana').click()
	assert.equal(without.menu.children[2].disabled, true, 'nor without the overrides script')
})

test('each action does what it says, and the menu closes first', async () => {
	const kit = setup()
	const trigger = kit.button('ana')
	await trigger.click()
	await kit.menu.children[0].click()
	assert.deepEqual(kit.log.inspected, [['ana', trigger]], 'the inspector drawer gives the focus back to the menu button')
	assert.equal(kit.menu.hidden, true)

	await trigger.click()
	await kit.menu.children[1].click()
	assert.deepEqual(kit.log.scoped, [['ana', undefined, trigger]], 'the scope drawer gives the focus back to the menu button')

	await trigger.click()
	await kit.menu.children[2].click()
	assert.deepEqual(kit.log.resets, [['ana', trigger]], 'the reset preview opens with the menu button as its trigger')

	await trigger.click()
	await kit.menu.children[3].click()
	await tick()
	assert.deepEqual(kit.log.copied, ['ana'])
	assert.equal(kit.log.notices[0].message, 'User ID of Ana Souza copied: ana')
	assert.equal(kit.log.notices[0].saved.receiptId, null, 'nothing to undo')
})

test('the keyboard: arrows move, Home and End jump, Escape closes and gives the focus back, Tab closes', async () => {
	const kit = setup()
	const trigger = kit.button('ana')
	await trigger.fire('keydown', { key: 'ArrowUp', preventDefault() {} })
	assert.equal(kit.document.activeElement, kit.menu.children[3], 'ArrowUp opens on the last item')
	const key = (name) => kit.menu.fire('keydown', { key: name, preventDefault() {} })
	await key('ArrowDown')
	assert.equal(kit.document.activeElement, kit.menu.children[0], 'it wraps around')
	await key('End')
	assert.equal(kit.document.activeElement, kit.menu.children[3])
	await key('Home')
	assert.equal(kit.document.activeElement, kit.menu.children[0])
	await key('Escape')
	assert.equal(kit.menu.hidden, true)
	assert.deepEqual(kit.log.events, ['mcp-row-menu-closed'], 'the matrix may draw itself again now (F3)')
	assert.equal(trigger.getAttribute('aria-expanded'), 'false')
	assert.equal(kit.document.activeElement, trigger)
	await trigger.click()
	await key('Tab')
	assert.equal(kit.menu.hidden, true)
})

test('disabled items are skipped, a second click closes, and a redrawn table closes the menu', async () => {
	const kit = setup()
	const trigger = kit.button('bruno')
	await trigger.click()
	const key = (name) => kit.menu.fire('keydown', { key: name, preventDefault() {} })
	await key('ArrowDown')
	await key('ArrowDown')
	assert.equal(kit.document.activeElement, kit.menu.children[3], 'the disabled reset is skipped')
	await trigger.click()
	assert.equal(kit.menu.hidden, true, 'the same button closes it')
	await trigger.click()
	kit.rendered()
	assert.equal(kit.menu.hidden, true)
})

test('a copy the browser refuses is reported, and without a clipboard the item is off', async () => {
	const kit = setup({ clipboard: 'refuses' })
	await kit.button('ana').click()
	await kit.menu.children[3].click()
	await tick()
	assert.deepEqual(kit.log.errors, ['Could not copy the user ID.'])
	assert.deepEqual(kit.log.notices, [])
	const none = setup({ clipboard: false })
	await none.button('ana').click()
	assert.equal(none.menu.children[3].disabled, true)
})

/* The row pieces of src/admin/admin-grants.js. */

const grants = require('./AdminManifest.cjs').script('admin-grants.js')
function cut(declaration) {
	const start = grants.indexOf('\t' + declaration)
	assert.ok(start > 0, declaration + ' was found in src/admin/admin-grants.js')
	return grants.slice(start, grants.indexOf('\n\t}\n', start) + 4)
}

function rowsKit(kinds) {
	const document = { activeElement: null }
	const el = (tag, props = {}, children = []) => {
		const node = new Element(tag, document)
		for (const [key, value] of Object.entries(props)) node[key] = value
		node.append(...children)
		return node
	}
	const tbody = new Element('tbody', document)
	const all = new Element('button', document)
	const exceptions = new Element('button', document)
	const users = Object.keys(kinds).map((uid) => ({ uid, origins: { eligible: { source: 'default' }, grants: { files: { read: { source: kinds[uid] === null ? 'default' : 'individual_allow' } } } } }))
	for (const user of users) {
		const row = new Element('tr', document)
		row.dataset.uid = user.uid
		tbody.append(row)
	}
	Object.defineProperty(tbody, 'rows', { get: () => tbody.children.filter((child) => child.tag === 'tr') })
	const sandbox = {
		el, t,
		table: { tBodies: [tbody] },
		state: { data: { users }, exceptionsOnly: false },
		onlyButtons: { all, exceptions },
		FIXED_COLUMNS: 4,
		visiblePermissionColumns: () => 9,
		window: { McpAdminExceptions: { kindOf: (uid) => ({ kind: kinds[uid] }) } },
	}
	vm.runInNewContext([cut('const OWN_SOURCES = '), cut('function hasException('), cut('function applyExceptionsFilter('), cut('function userLine(')].join('\n')
		+ '\nthis.apply = applyExceptionsFilter; this.userLine = userLine', sandbox)
	return { sandbox, tbody, all, exceptions, rows: () => tbody.rows }
}

test('"With exceptions (N)" counts the people of the page with a real exception and shows only them when chosen', () => {
	const kit = rowsKit({ ana: 'exception', bruno: 'redundant', carla: null })
	kit.sandbox.apply()
	assert.equal(kit.exceptions.textContent, 'With exceptions (1)')
	assert.equal(kit.exceptions.title, 'People on this page with an individual value that differs from what they inherit')
	assert.equal(kit.all.getAttribute('aria-pressed'), 'true')
	assert.ok(kit.rows().every((row) => !row.hidden), 'everyone while "All" is chosen')
	kit.sandbox.state.exceptionsOnly = true
	kit.sandbox.apply()
	assert.equal(kit.exceptions.getAttribute('aria-pressed'), 'true')
	assert.deepEqual(kit.rows().filter((row) => !row.hidden).map((row) => row.dataset.uid), ['ana'])
})

test('while the classification is pending the count is not shown and nobody is hidden; an empty result says so', () => {
	const pending = rowsKit({ ana: 'pending', bruno: 'redundant' })
	pending.sandbox.state.exceptionsOnly = true
	pending.sandbox.apply()
	assert.equal(pending.exceptions.textContent, 'With exceptions')
	assert.deepEqual(pending.rows().filter((row) => !row.hidden).map((row) => row.dataset.uid), ['ana'], 'unknown is shown, never hidden')

	const none = rowsKit({ ana: 'redundant', bruno: null })
	none.sandbox.state.exceptionsOnly = true
	none.sandbox.apply()
	const empty = none.tbody.children.find((child) => child.classes.has('mcp-only-empty'))
	assert.equal(empty.textContent, 'No one on this page has an exception.')
	assert.equal(empty.children[0].colSpan, 13)
	none.sandbox.state.exceptionsOnly = false
	none.sandbox.apply()
	assert.equal(none.tbody.children.find((child) => child.classes.has('mcp-only-empty')), undefined, 'and it goes with the filter')
})

test('the line under a name: "Account disabled", "Connected" with an aria-hidden mark, or the user ID', () => {
	const kit = rowsKit({})
	const line = (user) => kit.sandbox.userLine(user)
	assert.equal(line({ uid: 'ana', enabled: false }).textContent, 'Account disabled')
	const connected = line({ uid: 'ana', enabled: true, eligible: true, connected: true })
	assert.equal(connected.textContent, '● Connected')
	assert.ok(connected.classes.has('mcp-user-connected'))
	assert.equal(connected.children[0].getAttribute('aria-hidden'), 'true')
	assert.equal(connected.title, 'ana')
	assert.equal(line({ uid: 'ana', enabled: true, eligible: true, connected: false }).textContent, 'ana')
	assert.equal(line({ uid: 'ana', enabled: true, eligible: false, connected: true }).textContent, 'ana', 'connected without "Can connect" is not shown as connected')
})
