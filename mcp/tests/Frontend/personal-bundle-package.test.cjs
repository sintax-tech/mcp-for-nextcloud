/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * The personal bundle as it ships (RE-12): js/mcp-personal.js is read out of the package built by scripts/package.sh and
 * must be exactly the fragments of src/settings.manifest joined as the builder joins them; then it runs on the personal
 * page's markup: the user's own connections are listed and revoked with one DELETE, and the resource scope is read.
 *
 * The archive is $MCP_PACKAGE, the package scripts/package.sh just built. When the variable is set, a missing or
 * unreadable archive fails the tests. Only when it is not set at all are they skipped, with the reason; the release gate
 * sets it and accepts no skip. The authoritative check of the package is scripts/check_package.php (PHP).
 */
'use strict'

const test = require('node:test')
const assert = require('node:assert')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const { loadManifest } = require('./load-manifest.cjs')
const { extractMember } = require('./extract-bundle.cjs')

const app = path.join(__dirname, '../..')
const archive = process.env.MCP_PACKAGE === undefined ? undefined : path.resolve(process.env.MCP_PACKAGE)
const skip = archive === undefined ? 'MCP_PACKAGE is not set: run scripts/package.sh and set it to the archive' : false

/** A DOM node with just what the personal scripts use. */
class Node {
	constructor(tag) {
		this.tag = tag
		this.children = []
		this.attributes = {}
		this.listeners = {}
		this.disabled = false
		this._text = ''
	}
	get firstChild() { return this.children[0] }
	get tBodies() { return [this.body ??= new Node('tbody')] }
	append(...children) { this.children.push(...children) }
	replaceChildren(...children) { this.children = []; this._text = ''; this.append(...children) }
	setAttribute(key, value) { this.attributes[key] = value }
	addEventListener(name, action) { this.listeners[name] = action }
	set textContent(value) { this._text = value; this.children = [] }
	get textContent() { return [this._text, ...this.children.map((child) => typeof child === 'string' ? child : child.textContent)].filter((s) => s !== '').join(' ') }
	all(tag) { return this.children.flatMap((child) => typeof child === 'string' ? [] : [...(child.tag === tag ? [child] : []), ...child.all(tag)]) }
	find(tag) { return this.all(tag)[0] }
}
const tick = async () => { for (let i = 0; i < 20; i++) await new Promise(setImmediate) }

/**
 * The page with the personal markup and a fake server.
 *
 * @param {string[]} ids element ids present on the page
 * @param {(method: string, url: string) => [number, object]} server answers every request
 * @return {{page: object, calls: string[], nodes: object}} the global object, the requests made and the elements by id
 */
function personalPage(ids, server) {
	const nodes = Object.fromEntries(ids.map((id) => [id, new Node(id.endsWith('table') ? 'table' : 'div')]))
	if (nodes['mcp-connections']) nodes['mcp-connections'].dataset = { scope: 'personal' }
	const calls = []
	const page = {
		document: { getElementById: (id) => nodes[id] ?? null, createElement: (tag) => new Node(tag), documentElement: { dataset: {} } },
		t: (_, text, params = {}) => text.replace(/\{(\w+)\}/g, (match, key) => params[key] ?? match),
		OC: { generateUrl: (url) => url, requestToken: 'token' },
		confirm: () => true,
		URLSearchParams,
		fetch: async (url, options = {}) => {
			const method = options.method ?? 'GET'
			calls.push(method + ' ' + url)
			const [status, data] = server(method, url)
			return { ok: status < 400, status, json: async () => data }
		},
	}
	page.window = page
	return { page, calls, nodes }
}

const PERSONAL_IDS = ['mcp-connections', 'mcp-connections-table', 'mcp-connections-prev', 'mcp-connections-next', 'mcp-connections-page-info', 'mcp-connections-status', 'mcp-my-scope-status', 'mcp-my-scope-list']
const row = { id: 7, uid: 'alice', displayName: 'Alice', client: { host: 'claude.ai' }, createdAt: 1, expiresAt: 2 }
const scope = { modules: { files: { mode: 'unrestricted', items: [] }, calendar: { mode: 'restricted', items: [{ found: true, name: 'Personal' }] }, tasks: { mode: 'unrestricted', items: [] } } }

test('MCP_PACKAGE names a readable package archive', { skip }, () => {
	assert.notEqual(process.env.MCP_PACKAGE, '', 'MCP_PACKAGE is set but empty')
	assert.ok(fs.statSync(archive).isFile(), 'MCP_PACKAGE is not a file: ' + archive)
	fs.accessSync(archive, fs.constants.R_OK)
})

test('the packaged personal bundle is the manifest joined as the builder joins it', { skip }, () => {
	const fragments = loadManifest(path.join(app, 'src/settings.manifest'))
	assert.deepEqual(fragments, ['shared/connections.js', 'settings/my-scope.js'])
	const expected = fragments.map((fragment) => '/* ' + fragment + ' */\n' + fs.readFileSync(path.join(app, 'src', fragment), 'utf8')).join(';\n')
	assert.equal(extractMember(archive, 'mcp/js/mcp-personal.js'), expected)
})

test('the packaged personal bundle lists, revokes and reads the scope on the personal markup', { skip }, async () => {
	let rows = [row]
	const { page, calls, nodes } = personalPage(PERSONAL_IDS, (method, url) => {
		if (url.startsWith('/apps/mcp/api/my/scope')) return [200, scope]
		if (method === 'DELETE') { rows = []; return [200, { revoked: true }] }
		return [200, { connections: rows, page: 1, pageSize: 20, hasMore: false }]
	})
	vm.runInNewContext(extractMember(archive, 'mcp/js/mcp-personal.js'), page)
	await tick()
	assert.equal(nodes['mcp-connections-table'].tBodies[0].children.length, 1)
	assert.deepEqual(nodes['mcp-my-scope-list'].children.map((li) => li.textContent), ['Files: every folder', 'Calendar: only Personal', 'Tasks: every task list'])
	nodes['mcp-connections-table'].tBodies[0].children[0].find('button').onclick()
	await tick()
	assert.equal(calls.filter((call) => call.startsWith('DELETE')).length, 1)
	assert.ok(calls.includes('DELETE /apps/mcp/api/my/connections/7'))
	assert.match(nodes['mcp-connections-table'].tBodies[0].children[0].textContent, /You have no active connections\./)
	assert.equal(calls.filter((call) => call.startsWith('GET /apps/mcp/api/my/connections')).length, 2)
})

test('the packaged personal bundle does nothing on a page without the personal markup', { skip }, async () => {
	const { page, calls } = personalPage([], () => [200, {}])
	vm.runInNewContext(extractMember(archive, 'mcp/js/mcp-personal.js'), page)
	await tick()
	assert.deepEqual(calls, [])
})
