/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Behavioral tests of the OAuth clients block of src/admin/admin-grants.js (F2 of the review of PR #8) with Node's built-in
 * runner and a minimal DOM: every save carries the revision of the page, the saves of the block go one at a time, and a
 * 409 (another window saved first) rebuilds the cards from the server instead of resending the stale list.
 *
 * The block is one function of the admin script, so it is cut out of the source and run against stubs of the helpers it
 * shares with the rest of the page (api, el, flash, notifyError, load), with the real revision helpers of the page
 * (announce, oneStep, followRevision) cut out too.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('./AdminContext.cjs')
const fs = require('node:fs')
const path = require('node:path')

class Element {
	constructor(tag) {
		this.tag = tag
		this.children = []
		this.listeners = {}
		this.classes = new Set()
		this.disabled = false
		this.checked = false
		this.hidden = false
		this.value = ''
		this._text = ''
		this.parent = null
	}
	get classList() {
		return { add: (...names) => names.forEach((name) => this.classes.add(name)), remove: (...names) => names.forEach((name) => this.classes.delete(name)), contains: (name) => this.classes.has(name) }
	}
	set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)) }
	get className() { return [...this.classes].join(' ') }
	append(...children) { for (const child of children) { if (typeof child !== 'string') child.parent = this; this.children.push(child) } }
	replaceChildren(...children) { this.children = []; this._text = ''; this.append(...children) }
	replaceWith(node) { const siblings = this.parent.children; siblings[siblings.indexOf(this)] = node; node.parent = this.parent }
	remove() { this.parent.children = this.parent.children.filter((child) => child !== this) }
	setAttribute() {}
	addEventListener(name, action) { (this.listeners[name] ||= []).push(action) }
	async fire(name) { for (const action of this.listeners[name] || []) await action({}) }
	focus() {}
	set textContent(value) { this._text = String(value); this.children = [] }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join('') }
	matches(selector) {
		const typed = selector.match(/^(\w*)\[type="(\w+)"\]$/)
		if (typed) return (typed[1] === '' || typed[1] === this.tag) && this.type === typed[2]
		const [tag, ...classes] = selector.split('.')
		return (tag === '' || tag === this.tag) && classes.every((name) => this.classes.has(name))
	}
	querySelectorAll(selector) {
		return this.children.flatMap((child) => typeof child === 'string' ? [] : [...(child.matches(selector) ? [child] : []), ...child.querySelectorAll(selector)])
	}
	querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null }
}

const source = require('./AdminManifest.cjs').script('admin-grants.js')
const start = source.indexOf('\tasync function initOauthClients() {')
const block = source.slice(start, source.indexOf('\n\t}\n', start) + 4)
assert.ok(start > 0 && block.length > 500, 'the OAuth clients block was found in src/admin/admin-grants.js')
const helpersStart = source.indexOf('\t/** Page event of a write this page committed')
const helpers = source.slice(helpersStart, source.indexOf('\t/**\n\t * @param {HTMLElement} cell cell that shows the save result', helpersStart))
assert.ok(helpersStart > 0 && helpers.includes('function followRevision('), 'the revision helpers were found in src/admin/admin-grants.js')

const REDIRECT = 'https://harness.example.com/oauth/callback'
const client = (id, name, enabled = true) => ({ id, clientId: 'nextcloud-mcp-custom-' + id, name, enabled, redirectUris: [REDIRECT] })
const state = (revision, customClients, extra = {}) => ({
	revision, hosts: ['claude.ai'], hostsDefault: false, nativeClientEnabled: false, nativeClientId: 'nextcloud-mcp-native',
	nativeRedirectUris: ['http://localhost/oauth/callback'], customClients, ...extra,
})

/**
 * Runs the block against a fake server. `serve({method, path, body})` answers [status, body]; a function result is
 * awaited first, so a test can hold an answer back.
 */
async function page(serve) {
	const elements = {}
	const document = {
		getElementById: (id) => elements[id] ??= new Element('div'),
		createElement: (tag) => new Element(tag),
	}
	const log = { calls: [], errors: [], matrixReloads: 0, announced: [] }
	// The page's window: the other blocks of the page talk to this one through its events.
	const window = Object.assign(new EventTarget(), { confirm: () => true })
	window.addEventListener('mcp-revision-moved', (event) => log.announced.push([event.detail.from, event.detail.to]))
	const context = {
		document,
		window,
		CustomEvent,
		navigator: { clipboard: { writeText: async () => {} } },
		t: (app, text) => text,
		el: (tag, props, children) => {
			const node = document.createElement(tag)
			Object.assign(node, props || {})
			for (const child of children || []) node.append(child)
			return node
		},
		flash: () => {},
		notifyError: (message) => log.errors.push(message),
		load: () => { log.matrixReloads++ },
		api: async (method, apiPath, body) => {
			log.calls.push({ method, path: apiPath, body: body === undefined ? undefined : JSON.parse(JSON.stringify(body)) })
			const [status, data] = await serve({ method, path: apiPath, body })
			if (status >= 400) throw Object.assign(new Error(String(status)), { data })
			return JSON.parse(JSON.stringify(data))
		},
	}
	vm.createContext(context)
	vm.runInContext(helpers + block + '\nthis.run = initOauthClients', context)
	await context.run()
	const cards = () => elements['mcp-custom-clients'].children
	/** A save of another block of this page committed from `from` to `to`. */
	const moved = (from, to) => window.dispatchEvent(new CustomEvent('mcp-revision-moved', { detail: { from, to } }))
	return { elements, log, cards, context, moved }
}

const puts = (log) => log.calls.filter((call) => call.method === 'PUT')

test('every save carries the revision of the page and adopts the one the server answers with', async () => {
	let revision = 5
	const { log, cards, elements } = await page(({ method }) => method === 'GET'
		? [200, state('5', [client('aaaaaaaaaaaa', 'A')])]
		: [200, state(String(++revision), [client('aaaaaaaaaaaa', 'A', false)])])
	assert.equal(cards().length, 1)
	const enabled = cards()[0].querySelector('input.checkbox')
	enabled.checked = false
	await enabled.fire('change')
	elements['mcp-native-client'].checked = true
	await elements['mcp-native-client'].fire('change')
	elements['mcp-oauth-hosts'].value = 'claude.ai'
	await elements['mcp-oauth-hosts-save'].fire('click')
	assert.deepEqual(puts(log).map((call) => call.body.expectedRevision), ['5', '6', '7'])
	assert.equal(log.matrixReloads, 3, 'the policy revision moved, so the matrix is reloaded')
	assert.deepEqual(puts(log)[0].body.customClients.map(({ enabled: on }) => on), [false])
})

test('a 409 rebuilds the cards from the server and never resends the stale list', async () => {
	let reads = 0
	const { log, cards } = await page(({ method }) => {
		if (method === 'PUT') return [409, { error: 'Conflict', revision: '9', state: null }]
		return [200, reads++ === 0 ? state('5', [client('aaaaaaaaaaaa', 'A'), client('bbbbbbbbbbbb', 'B')])
			: state('9', [client('aaaaaaaaaaaa', 'A', false), client('bbbbbbbbbbbb', 'B')])]
	})
	assert.equal(cards().length, 2)
	assert.equal(cards()[0].querySelector('input.checkbox').checked, true)
	const second = cards()[1].querySelector('input.checkbox')
	second.checked = false
	await second.fire('change')
	assert.equal(puts(log).length, 1, 'the conflict is not retried')
	assert.equal(log.calls.filter((call) => call.method === 'GET').length, 2, 'the current state was read again')
	assert.equal(cards()[0].querySelector('input.checkbox').checked, false, 'the other administrator\'s change is on screen')
	assert.equal(cards()[1].querySelector('input.checkbox').checked, true, 'the unsaved change of this page was dropped')
	assert.match(log.errors[0], /nothing was overwritten/)
})

test('a save of this page after a 409 uses the revision of the reload', async () => {
	let reads = 0
	let conflict = true
	const { log, cards } = await page(({ method }) => {
		if (method === 'GET') return [200, state(reads++ === 0 ? '5' : '9', [client('aaaaaaaaaaaa', 'A')])]
		if (conflict) { conflict = false; return [409, { error: 'Conflict' }] }
		return [200, state('10', [client('aaaaaaaaaaaa', 'A', false)])]
	})
	for (let attempt = 0; attempt < 2; attempt++) {
		const box = cards()[0].querySelector('input.checkbox')
		box.checked = false
		await box.fire('change')
	}
	assert.deepEqual(puts(log).map((call) => call.body.expectedRevision), ['5', '9'])
})

test('428 and an uncertain 503 also reload; any other failure keeps the card message', async () => {
	for (const [status, data, reloads] of [[428, { error: 'PreconditionRequired' }, 2], [503, { error: 'Unavailable', uncertain: true }, 2], [503, { error: 'Unavailable', uncertain: false }, 1], [400, { error: 'Invalid request' }, 1]]) {
		const { log, cards } = await page(({ method }) => method === 'GET' ? [200, state('5', [client('aaaaaaaaaaaa', 'A')])] : [status, data])
		const box = cards()[0].querySelector('input.checkbox')
		box.checked = false
		await box.fire('change')
		assert.equal(log.calls.filter((call) => call.method === 'GET').length, reloads, `${status} ${JSON.stringify(data)}`)
	}
})

test('saves of the block go one at a time, the second with the revision the first answered', async () => {
	let release
	const gate = new Promise((resolve) => { release = resolve })
	let revision = 5
	const { log, elements } = await page(async ({ method, body }) => {
		if (method === 'GET') return [200, state('5', [])]
		if (body.nativeClientEnabled === true) await gate
		return [200, state(String(++revision), [])]
	})
	const native = elements['mcp-native-client']
	native.checked = true
	const first = native.fire('change')
	elements['mcp-oauth-hosts'].value = 'claude.ai, example.org'
	const second = elements['mcp-oauth-hosts-save'].fire('click')
	await new Promise(setImmediate)
	assert.equal(puts(log).length, 1, 'the second save waits for the first')
	release()
	await Promise.all([first, second])
	assert.deepEqual(puts(log).map((call) => call.body.expectedRevision), ['5', '6'])
})

/**
 * A fake server with real revision checks: a PUT with a revision other than the current one answers 409, an accepted
 * one stores the list it carries. `hold` delays the answer of the first PUT until released.
 */
function revisionServer(initial) {
	const server = { revision: 5, clients: initial, puts: [] }
	let release
	server.held = new Promise((resolve) => { release = resolve })
	server.release = () => release()
	server.serve = async ({ method, body }) => {
		if (method === 'GET') return [200, state(String(server.revision), server.clients)]
		server.puts.push(body)
		if (server.puts.length === 1) await server.held
		if (body.expectedRevision !== String(server.revision)) return [409, { error: 'Conflict' }]
		server.revision++
		// A new client (no id yet) gets one, as the server gives it.
		server.clients = body.customClients.map((c, i) => ({ ...c, id: c.id ?? 'new' + i, clientId: 'nextcloud-mcp-custom-' + (c.id ?? 'new' + i) }))
		return [200, state(String(server.revision), server.clients)]
	}
	return server
}

test('R1: two quick toggles build the second list from the first answer and never turn A back on', async () => {
	const server = revisionServer([client('aaaaaaaaaaaa', 'A'), client('bbbbbbbbbbbb', 'B')])
	const { cards } = await page(server.serve)
	const [a, b] = cards().map((card) => card.querySelector('input.checkbox'))
	a.checked = false
	const first = a.fire('change')
	b.checked = false
	const second = b.fire('change')
	await new Promise(setImmediate)
	server.release()
	await Promise.all([first, second])
	assert.equal(server.puts.length, 2)
	assert.deepEqual(server.puts.map((body) => body.expectedRevision), ['5', '6'])
	assert.deepEqual(server.puts[1].customClients.map((c) => c.enabled), [false, false], 'the second save must not re-enable A')
	assert.deepEqual(server.clients.map((c) => c.enabled), [false, false])
})

test('R1: the intents queued behind a refused save are dropped, not sent on the reloaded page', async () => {
	const server = revisionServer([client('aaaaaaaaaaaa', 'A'), client('bbbbbbbbbbbb', 'B')])
	const { cards, log } = await page(server.serve)
	server.revision = 9 // another administrator saved after this page loaded
	const [a, b] = cards().map((card) => card.querySelector('input.checkbox'))
	a.checked = false
	const first = a.fire('change')
	b.checked = false
	const second = b.fire('change')
	await new Promise(setImmediate)
	server.release()
	await Promise.all([first, second])
	assert.equal(server.puts.length, 1, 'only the refused save was sent; the one behind it was dropped')
	assert.deepEqual(server.clients.map((c) => c.enabled), [true, true])
	assert.ok(cards().every((card) => card.querySelector('input.checkbox').checked), 'the page shows what is stored')
	assert.equal(log.errors.length, 1)
})

/** Adds a new client card and types a name and one redirect URI into it, as the admin does. */
async function typeNewClient(elements, cards, name, uri) {
	await elements['mcp-custom-add'].fire('click')
	const card = cards().at(-1)
	card.querySelector('input[type="text"]').value = name
	card.querySelector('textarea').value = uri
	return card
}
const button = (card, text) => card.querySelectorAll('button').find((node) => node.textContent === text)
const typed = (card) => [card.querySelector('input[type="text"]').value, card.querySelector('textarea').value]

test('T14 D1: a save of another block of this page is adopted, so the new client is saved instead of refused', async () => {
	const server = revisionServer([client('aaaaaaaaaaaa', 'A')])
	server.release()
	const { elements, cards, log, moved } = await page(server.serve)
	// The matrix of this page saved a cell: 5 -> 6. A move that does not start from this block's revision is ignored.
	server.revision = 6
	moved('4', '5')
	moved('5', '6')
	const card = await typeNewClient(elements, cards, 'Harness C', 'https://harness-c.example.com/cb')
	await button(card, 'Save').fire('click')
	assert.deepEqual(server.puts.map((body) => body.expectedRevision), ['6'])
	assert.deepEqual([...server.clients].map((c) => c.name), ['A', 'Harness C'], 'the new client is stored')
	assert.deepEqual(log.errors, [], 'no conflict, no toast')
	assert.equal(cards().length, 2)
	assert.match(cards()[1].textContent, /client_id: nextcloud-mcp-custom-/, 'the card is the saved client now')
})

test('T14 D1: a refused save never drops the new client typed in; the card stays, says so, and the next save goes through', async () => {
	const server = revisionServer([client('aaaaaaaaaaaa', 'A')])
	server.release()
	const { elements, cards, log } = await page(server.serve)
	server.revision = 6 // another administrator saved; nothing on this page announced it
	const card = await typeNewClient(elements, cards, 'Harness C', 'https://harness-c.example.com/cb')
	await button(card, 'Save').fire('click')
	assert.equal(server.puts.length, 1)
	assert.equal(server.puts[0].expectedRevision, '5')
	assert.equal(log.errors.length, 1, 'the conflict is told once')
	assert.equal(cards().length, 2, 'the saved client and the typed one')
	assert.equal(cards()[1], card, 'the very card the admin typed in')
	assert.deepEqual(typed(card), ['Harness C', 'https://harness-c.example.com/cb'])
	assert.match(card.querySelector('.mcp-status').textContent, /Not saved: the settings changed after this page was loaded\. Your changes are kept here/)
	assert.equal(button(card, 'Save').disabled, false)
	await button(card, 'Save').fire('click')
	assert.deepEqual(server.puts.map((body) => body.expectedRevision), ['5', '6'], 'sent again with the revision of the reload')
	assert.deepEqual([...server.clients].map((c) => c.name), ['A', 'Harness C'])
})

test('T14 D1: on a refused save an edited saved client keeps its edits, and one deleted elsewhere comes back as a new card', async () => {
	let reads = 0
	const { cards, log } = await page(({ method }) => {
		if (method === 'PUT') return [409, { error: 'Conflict' }]
		// Another administrator deleted B after this page was loaded.
		return [200, reads++ === 0 ? state('5', [client('aaaaaaaaaaaa', 'A'), client('bbbbbbbbbbbb', 'B')]) : state('9', [client('aaaaaaaaaaaa', 'A')])]
	})
	const [a, b] = cards()
	a.querySelector('input[type="text"]').value = 'A renamed'
	b.querySelector('textarea').value = 'https://b.example.com/new'
	await button(a, 'Save').fire('click')
	assert.equal(log.errors.length, 1)
	assert.equal(cards().length, 2)
	assert.equal(cards()[0], a, 'A keeps its card with the edit')
	assert.deepEqual(typed(a), ['A renamed', REDIRECT])
	assert.match(a.querySelector('.mcp-status').textContent, /Your changes are kept here; check them and save again/)
	const again = cards()[1]
	assert.deepEqual(typed(again), ['B', 'https://b.example.com/new'], 'the edits of B are still on screen')
	assert.doesNotMatch(again.textContent, /client_id:/, 'as a new client: B no longer exists')
	assert.match(again.querySelector('.mcp-status').textContent, /this client was deleted in the meantime/)
})

test('T14 D1: a save of this block is announced to the page only when it alone lies between the two revisions', async () => {
	const cases = [
		// [answer revision, changes the settings, announced]
		['6', true, [['5', '6']]],
		['7', true, []], // another write landed before the state was read back
		['6', false, []], // nothing changed here, so the step is someone else's
	]
	for (const [revision, change, announced] of cases) {
		const { elements, log } = await page(({ method }) => method === 'GET' ? [200, state('5', [])] : [200, state(revision, [], { nativeClientEnabled: change })])
		elements['mcp-native-client'].checked = change
		await elements['mcp-native-client'].fire('change')
		assert.deepEqual(log.announced, announced, `answer ${revision}, changed ${change}`)
	}
})

test('T14 D1 review F1: a save that changes nothing, followed by another administrator\'s change before the read-back, is never announced', async () => {
	// Each request below is what is stored already: the server accepts it at 5 without a commit. Another administrator
	// then turns A off (revision 6) before the state is read back, so the answer is one step away and shows a change.
	const outside = state('6', [client('aaaaaaaaaaaa', 'A', false)], { hosts: ['claude.ai'] })
	const saves = {
		'hosts unchanged': async ({ elements }) => {
			elements['mcp-oauth-hosts'].value = ' Claude.AI, claude.ai '
			await elements['mcp-oauth-hosts-save'].fire('click')
		},
		'native client unchanged': async ({ elements }) => {
			elements['mcp-native-client'].checked = false
			await elements['mcp-native-client'].fire('change')
		},
		'client card saved as it is, with spaces and a repeated URI': async ({ cards }) => {
			const card = cards()[0]
			card.querySelector('input[type="text"]').value = ' A '
			card.querySelector('textarea').value = REDIRECT + '\n ' + REDIRECT
			await button(card, 'Save').fire('click')
		},
	}
	for (const [name, save] of Object.entries(saves)) {
		const ui = await page(({ method }) => method === 'GET' ? [200, state('5', [client('aaaaaaaaaaaa', 'A')])] : [200, outside])
		await save(ui)
		assert.equal(puts(ui.log).length, 1, name)
		assert.deepEqual(ui.log.announced, [], name)
	}
})

test('T14 D1 review F1: the default host list is stored as no list, so sending it again changes nothing', async () => {
	const stored = state('5', [], { hosts: ['claude.ai', 'chatgpt.com'], hostsDefault: true })
	for (const [hosts, announced] of [['chatgpt.com, claude.ai', []], ['claude.ai', [['5', '6']]]]) {
		const { elements, log } = await page(({ method }) => method === 'GET' ? [200, stored] : [200, state('6', [])])
		elements['mcp-oauth-hosts'].value = hosts
		await elements['mcp-oauth-hosts-save'].fire('click')
		assert.deepEqual(log.announced, announced, hosts)
	}
})

test('T14 D1 review F1 (re-read): values the server trims with PHP\'s trim (NUL, VT, TAB, CR at the ends) change nothing, so nothing is announced', async () => {
	// Stored: host claude.ai and client A with one URI. The server trims each value below back to the stored one and
	// commits nothing; another administrator turns A off before the read-back (revision 6).
	const outside = state('6', [client('aaaaaaaaaaaa', 'A', false)], { hosts: ['claude.ai'] })
	const edges = { NUL: '\u0000', VT: '\u000B', TAB: '\t', CR: '\r' }
	const saves = []
	for (const [name, edge] of Object.entries(edges)) {
		saves.push([`host with ${name}`, async ({ elements }) => {
			elements['mcp-oauth-hosts'].value = edge + 'claude.ai' + edge
			await elements['mcp-oauth-hosts-save'].fire('click')
		}])
		saves.push([`name with ${name}`, async ({ cards }) => {
			cards()[0].querySelector('input[type="text"]').value = edge + 'A' + edge
			await button(cards()[0], 'Save').fire('click')
		}])
		saves.push([`URI with ${name}`, async ({ cards }) => {
			cards()[0].querySelector('textarea').value = edge + REDIRECT + edge
			await button(cards()[0], 'Save').fire('click')
		}])
	}
	// A control character the server keeps (it then refuses the value) is never taken for a change either.
	saves.push(['host with a BEL inside', async ({ elements }) => {
		elements['mcp-oauth-hosts'].value = 'claude\u0007.ai'
		await elements['mcp-oauth-hosts-save'].fire('click')
	}])
	for (const [name, save] of saves) {
		const ui = await page(({ method }) => method === 'GET' ? [200, state('5', [client('aaaaaaaaaaaa', 'A')], { hosts: ['claude.ai'] })] : [200, outside])
		await save(ui)
		assert.equal(puts(ui.log).length, 1, name)
		assert.deepEqual(ui.log.announced, [], name)
	}
})

test('T14 D1 review F1 (re-read): a real change with NUL at the ends is trimmed as the server trims it, committed and announced', async () => {
	const cases = [
		['host', async ({ elements }) => {
			elements['mcp-oauth-hosts'].value = '\u0000example.org\u0000'
			await elements['mcp-oauth-hosts-save'].fire('click')
		}],
		['name', async ({ cards }) => {
			cards()[0].querySelector('input[type="text"]').value = '\u0000B\u0000'
			await button(cards()[0], 'Save').fire('click')
		}],
		['URI', async ({ cards }) => {
			cards()[0].querySelector('textarea').value = '\u0000https://other.example.com/cb\u0000'
			await button(cards()[0], 'Save').fire('click')
		}],
	]
	for (const [name, save] of cases) {
		const ui = await page(({ method }) => method === 'GET' ? [200, state('5', [client('aaaaaaaaaaaa', 'A')], { hosts: ['claude.ai'] })] : [200, state('6', [client('aaaaaaaaaaaa', 'A')])])
		await save(ui)
		assert.deepEqual(ui.log.announced, [['5', '6']], name)
	}
})

test('T14 D1 review F1 (re-read 2): a stored name with no-break spaces, which PHP\'s trim keeps, saved as the plain name is a change and is announced', async () => {
	// The server stored " A " with no-break spaces; the card sends "A" (String.trim() drops them), which differs.
	const ui = await page(({ method }) => method === 'GET' ? [200, state('5', [client('aaaaaaaaaaaa', ' A ')])] : [200, state('6', [client('aaaaaaaaaaaa', 'A')])])
	const card = ui.cards()[0]
	card.querySelector('input[type="text"]').value = 'A'
	await button(card, 'Save').fire('click')
	assert.equal(puts(ui.log)[0].body.customClients[0].name, 'A')
	assert.deepEqual(ui.log.announced, [['5', '6']])
})
