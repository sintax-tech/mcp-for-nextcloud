/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const path = require('node:path')

/** Minimal DOM for the Polls inspector contract; all rendering comes from the production script. */
class PollsElement {
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
	focus() { this.focused = true }
	append(...children) { this.children.push(...children) }
	replaceChildren(...children) { this.children = children; this._text = '' }
	setAttribute(key, value) { this.attributes[key] = value }
	addEventListener(name, action) { this.listeners[name] = action }
	set textContent(value) { this._text = value; this.children = [] }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join(' ') }
	all(tag) { return this.children.flatMap((child) => typeof child === 'string' ? [] : [...(child.tag === tag ? [child] : []), ...child.all(tag)]) }
}

/** Protected-read presets and translated admin consumers, with no persisted-policy writes. */
class PollsProtectedAdmin {
	/** @param {string[]} files production scripts @param {object} extra globals @return {object} loaded context */
	static load(files, extra = {}) {
		const root = { t: (_, text, args = {}) => text.replace(/\{(\w+)\}/g, (_, key) => args[key] ?? key), ...extra }
		root.window = root
		for (const file of files) vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../js', file), 'utf8'), root)
		return root
	}

	/** @return {Promise<object>} submitted real inspector with explicit Polls operations and catalog */
	static async inspector() {
		const suffixes = ['', '-form', '-user', '-status', '-alert', '-access', '-resources', '-path-form', '-path', '-path-result',
			'-calendar-form', '-calendar-module', '-calendar', '-calendar-operation', '-calendar-result', '-title', '-close', '-entry']
		const ids = Object.fromEntries(suffixes.map((suffix) => ['mcp-inspector' + suffix, new PollsElement('div')]))
		ids['mcp-inspector-calendar-module'].value = 'polls'
		const rows = ['read', 'results', 'links'].map((operation) => ({ module: 'polls', operation, allowed: true,
			blockingGate: null, grant: { allowed: true, source: 'individual_allow', groupIds: [] }, scope: { mode: 'unrestricted', count: null } }))
		const root = this.load(['admin-levels.js', 'admin-polls-grants.js', 'admin-inspector.js'], {
			document: { getElementById: (id) => ids[id], createElement: (tag) => new PollsElement(tag) }, URLSearchParams,
			getComputedStyle: () => ({ top: '44px' }), OC: { generateUrl: (url) => url, requestToken: 'test' },
			fetch: async (url) => ({ ok: true, status: 200, json: async () => url.includes('/access')
				? { revision: '1', operations: rows } : url.includes('/candidates') ? { items: [] } : {} }),
		})
		ids['mcp-inspector-user'].value = 'alice'
		await ids['mcp-inspector-form'].listeners.submit({ preventDefault() {} })
		for (let i = 0; i < 12; i++) await new Promise(setImmediate)
		return { ids, root }
	}
}

test('Polls presets name every concession; isolated protected reads stay custom and legacy read stays read', () => {
	const root = PollsProtectedAdmin.load(['admin-levels.js', 'admin-polls-grants.js'])
	const levels = root.McpAdminLevels
	const operations = ['read', 'results', 'links']
	assert.deepEqual(Array.from(levels.levels('polls', operations)), ['none', 'polls_read', 'polls_read_results', 'polls_read_results_links'])
	for (const [name, expected] of [['polls_read', ['read']], ['polls_read_results', ['read', 'results']], ['polls_read_results_links', operations]]) {
		assert.deepEqual(Array.from(levels.operationsFor('polls', operations, name)), expected)
	}
	assert.equal(levels.levelOf('polls', operations, { read: true, results: false, links: false }), 'polls_read')
	for (const values of [{ read: false, results: true, links: false }, { read: false, results: false, links: true }, { read: true, results: false, links: true }]) {
		assert.equal(levels.levelOf('polls', operations, values), 'custom')
	}
	assert.throws(() => levels.operationsFor('polls', operations, 'full'), /Unknown level/)
	const hints = root.McpAdminPollsGrants
	assert.match(hints.operationHint('polls', 'results'), /starts off/)
	assert.match(hints.operationHint('polls', 'links'), /personal credentials.*native edit permission.*creates no links/)
	assert.equal(hints.operationHint('forms', 'results'), null)
	assert.equal(hints.operationHint('files', 'link'), null)
})

test('real inspector DOM renders the protected labels, cumulative level and selector, not raw ids', async () => {
	const { ids } = await PollsProtectedAdmin.inspector()
	const text = ids['mcp-inspector-access'].textContent
	assert.match(text, /Polls: results/)
	assert.match(text, /Polls: Read existing links \(credentials\)/)
	assert.match(text, /Read polls, votes, results and existing links \(credentials\)/)
	assert.doesNotMatch(text, /polls_read_results_links/)
	assert.deepEqual(ids['mcp-inspector-calendar-operation'].all('option').map((o) => o.textContent), ['read', 'results', 'Read existing links (credentials)'])
})

test('all admin label consumers delegate to the loaded Polls helper and translations agree with Forms common results', () => {
	for (const file of ['admin-grants.js', 'admin-groups.js', 'admin-inspector.js', 'admin-level-popover.js']) {
		assert.match(fs.readFileSync(path.join(__dirname, '../../js', file), 'utf8'), /McpAdminPollsGrants\?\.levelLabels/)
	}
	for (const file of ['admin-grants.js', 'admin-groups.js', 'admin-inspector.js']) {
		assert.match(fs.readFileSync(path.join(__dirname, '../../js', file), 'utf8'), /McpAdminPollsGrants\?\.operationLabels/)
	}
	const template = fs.readFileSync(path.join(__dirname, '../../templates/admin.php'), 'utf8')
	assert.ok(template.indexOf("'admin-polls-grants'") < template.indexOf("'admin-grants'"))
	for (const [lang, result, anonymous] of [['pt_BR', 'resultados', 'Anônimo'], ['es', 'resultados', 'Anónimo'], ['de', 'Ergebnisse', 'Anonym']]) {
		const json = JSON.parse(fs.readFileSync(path.join(__dirname, '../../l10n', lang + '.json'), 'utf8')).translations
		let js
		vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../l10n', lang + '.js'), 'utf8'), { OC: { L10N: { register: (_, map) => { js = map } } } })
		assert.equal(json.results, result)
		assert.equal(json.Anonymous, anonymous)
		for (const key of Object.keys(PollsProtectedAdmin.load(['admin-polls-grants.js']).McpAdminPollsGrants.levelLabels())) {
			const source = PollsProtectedAdmin.load(['admin-polls-grants.js']).McpAdminPollsGrants.levelLabels()[key]
			assert.ok(json[source], lang + ': ' + source)
			assert.equal(js[source], json[source])
		}
	}
})
