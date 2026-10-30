/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
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
class PollsCreateDom {
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
		const rows = ['read', 'results', 'links', 'create'].map((operation) => ({ module: 'polls', operation, allowed: true,
			blockingGate: null, grant: { allowed: true, source: 'individual_allow', groupIds: [] }, scope: { mode: 'unrestricted', count: null } }))
		const root = this.load(['admin-polls-grants.js', 'admin-levels.js', 'admin-inspector.js'], {
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

module.exports = PollsCreateDom
