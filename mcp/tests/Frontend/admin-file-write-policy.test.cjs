/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')
const path = require('node:path')
const { extensions, validPolicy, mount } = require('./PolicyEditors.cjs').fileWritePolicy
const off = { version: 1, mode: 'off', extensions: [], allowExtensionless: false }
const response = (data, status = 200) => ({ ok: status === 200, status, json: async () => data })

function fixture(request) {
	const elements = new Map()
	const ids = ['form', 'mode', 'extensions', 'extensionless', 'save', 'status', 'invalid', 'overview', 'empty', 'mode-error', 'extensions-error', 'allowExtensionless-error']
	for (const id of [...ids.map(id => 'mcp-file-policy-' + id), 'mcp-tags-invalid-overview', 'mcp-tab-protection-alert']) {
		elements.set(id, { value: '', checked: false, hidden: true, disabled: true, textContent: '', attrs: {}, listeners: {},
			setAttribute(key, value) { this.attrs[key] = value }, focus() { this.focused = true },
			addEventListener(key, fn) { this.listeners[key] = fn },
		})
	}
	const calls = []
	const document = { getElementById: id => elements.get(id) }
	let csrf = 'csrf-test'
	const observations = []
	class Observer {
		constructor(callback) { observations.push(callback) }
		observe() {}
	}
	const api = mount(document, async (...args) => { calls.push(args); return request(...args) }, (app, text) => text, '/policy', () => csrf, Observer)
	return { api, calls, el: name => elements.get(name.startsWith('mcp-') ? name : 'mcp-file-policy-' + name), observe: () => observations.forEach(fn => fn()), setToken: value => { csrf = value } }
}

test('textarea canonicalization agrees with server grammar, separators and maximum', () => {
	for (const text of [' .TXT,md\n', 'txt,\nmd', 'txt\n\nmd', 'txt,md,', 'txt\fmd', 'txt\vmd', '\0txt,md\0']) assert.deepEqual(extensions(text), ['md', 'txt'])
	assert.deepEqual(extensions(''), [])
	for (const text of ['a,,b', ',txt', '*.txt', 'tar.gz', '../txt', 'аbc', 'K', 'php:txt', 'md\u0085txt', 'md\u2028txt', 'md\u2029txt', 'md,\u00a0txt', '\u00a0md', '\fmd', 'a'.repeat(33)]) assert.throws(() => extensions(text))
	assert.throws(() => extensions(Array.from({ length: 129 }, (_, i) => 'x' + i).join(',')))
	assert.equal(validPolicy({ ...off, mode: 'bad' }), false)
	assert.equal(validPolicy({ ...off, extensions: ['TXT'] }), false)
})

test('successful load and save use CSRF, busy state and verified canonical response', async () => {
	let finish
	const f = fixture((url, init) => init.method === 'GET' ? response(off) : new Promise(resolve => { finish = resolve }))
	assert.equal(f.el('mode').value, '')
	assert.equal(f.el('save').disabled, true)
	await f.api.ready
	assert.equal(f.el('mode').value, 'off')
	assert.equal(f.el('extensionless').disabled, true)
	f.el('mode').value = 'allowlist'
	f.el('extensions').value = '.TXT,md'
	f.el('extensionless').checked = true
	f.setToken('csrf-after-heartbeat')
	const pending = f.api.submit({ preventDefault() {} })
	assert.equal(f.el('form').attrs['aria-busy'], 'true')
	assert.equal(f.el('save').disabled, true)
	assert.equal(f.calls[0][1].headers.requesttoken, 'csrf-test')
	assert.equal(f.calls[1][1].headers.requesttoken, 'csrf-after-heartbeat')
	assert.equal(f.calls[1][1].credentials, 'same-origin')
	assert.deepEqual(JSON.parse(f.calls[1][1].body), { mode: 'allowlist', extensions: ['md', 'txt'], allowExtensionless: true })
	finish(response({ ...off, mode: 'allowlist', extensions: ['md', 'txt'], allowExtensionless: true }))
	await pending
	assert.equal(f.el('extensions').value, 'md\ntxt')
	assert.equal(f.el('status').textContent, 'File type policy saved.')
	assert.equal(f.el('form').attrs['aria-busy'], 'false')
})

test('load failure requires an explicit mode and never writes automatically; off recovery preserves tag warning', async () => {
	const f = fixture((url, init) => init.method === 'GET' ? response({ error: 'policy_unavailable' }, 503) : response(off))
	await f.api.ready
	assert.equal(f.calls.length, 1)
	assert.equal(f.el('mode').value, '')
	assert.equal(f.el('invalid').hidden, false)
	assert.equal(f.el('overview').hidden, false)
	assert.equal(f.el('mcp-tab-protection-alert').hidden, false)
	await f.api.submit({ preventDefault() {} })
	assert.equal(f.calls.length, 1)
	assert.equal(f.el('mode-error').hidden, false)
	f.el('mcp-tags-invalid-overview').hidden = false
	f.el('mode').value = 'off'
	await f.api.submit({ preventDefault() {} })
	assert.equal(f.el('invalid').hidden, true)
	assert.equal(f.el('overview').hidden, true)
	assert.equal(f.el('mcp-tab-protection-alert').hidden, false)
	f.el('mcp-tags-invalid-overview').hidden = true
	f.observe()
	assert.equal(f.el('mcp-tab-protection-alert').hidden, true)
})

test('explicit off saves without validating disabled invalid extensions or retaining the disabled switch', async () => {
	const f = fixture((url, init) => response(init.method === 'GET' ? { ...off, mode: 'allowlist' } : off))
	await f.api.ready
	f.el('extensions').value = 'md, *.exe'
	f.el('extensionless').checked = true
	await f.api.submit({ preventDefault() {} })
	assert.equal(f.el('extensions-error').hidden, false)
	assert.equal(f.calls.length, 1)
	f.el('mode').value = 'off'
	f.el('mode').listeners.change()
	assert.equal(f.el('extensions').disabled, true)
	await f.api.submit({ preventDefault() {} })
	assert.deepEqual(JSON.parse(f.calls[1][1].body), { mode: 'off', extensions: [], allowExtensionless: false })
	assert.equal(f.el('extensions-error').hidden, true)
	assert.equal(f.el('status').textContent, 'File type policy saved.')
})

test('a late tags success cannot clear a simultaneous policy warning', async () => {
	const f = fixture(() => response({}, 503))
	await f.api.ready
	f.el('mcp-tab-protection-alert').hidden = true
	f.observe()
	assert.equal(f.el('mcp-tab-protection-alert').hidden, false)
})

test('client and server field errors preserve input; backend failure never announces saved', async () => {
	let result = response({ error: 'invalid_policy', field: 'extensions' }, 400)
	const f = fixture((url, init) => init.method === 'GET' ? response(off) : result)
	await f.api.ready
	f.el('mode').value = 'allowlist'
	f.el('extensions').value = 'a,,b'
	await f.api.submit({ preventDefault() {} })
	assert.equal(f.calls.length, 1)
	assert.equal(f.el('extensions').value, 'a,,b')
	assert.equal(f.el('extensions').attrs['aria-invalid'], 'true')
	f.el('extensions').value = 'txt'
	await f.api.submit({ preventDefault() {} })
	assert.equal(f.el('extensions').value, 'txt')
	assert.equal(f.el('extensions-error').hidden, false)
	result = response({ error: 'policy_unavailable' }, 503)
	await f.api.submit({ preventDefault() {} })
	assert.equal(f.el('extensions').value, 'txt')
	assert.match(f.el('status').textContent, /could not be saved/)
	assert.equal(f.el('save').disabled, false)
	assert.equal(f.el('overview').hidden, false)
})

test('empty list warning and extensionless switch follow mode without discarding input', async () => {
	const f = fixture(() => response(off))
	await f.api.ready
	f.el('mode').value = 'allowlist'
	f.el('mode').listeners.change()
	assert.equal(f.el('empty').hidden, false)
	assert.equal(f.el('extensionless').disabled, false)
	f.el('mode').value = 'off'
	f.el('mode').listeners.change()
	assert.equal(f.el('empty').hidden, true)
	assert.equal(f.el('extensionless').disabled, true)
})

test('all new UI strings have matching German, Spanish and Portuguese JS/JSON translations', () => {
	const sources = ['src/admin/file-write-policy/validation.js', 'src/admin/file-write-policy/editor.js', 'src/admin/backups/editor.js', 'templates/admin-file-write-policy.php', 'templates/admin-file-write-policy-overview.php']
	const strings = new Set()
	for (const file of sources) {
		const source = fs.readFileSync(path.join(__dirname, '../..', file), 'utf8')
		for (const match of source.matchAll(/(?:\$l->t\(|t\('mcp', )'((?:[^'\\]|\\.)*)'\)/g)) strings.add(match[1].replace(/\\'/g, "'"))
	}
	strings.add('Choose no restriction or only listed extensions.')
	strings.add('Enter valid extensions, separated by commas or new lines, without paths or wildcards.')
	strings.add('Choose whether files without an extension are allowed.')
	strings.add('The policy could not be saved and verified. Your input is preserved. Reload before relying on the saved state.')
	for (const locale of ['de', 'es', 'pt_BR']) {
		const json = JSON.parse(fs.readFileSync(path.join(__dirname, '../../l10n', locale + '.json'), 'utf8')).translations
		let registered
		vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../l10n', locale + '.js'), 'utf8'), { OC: { L10N: { register(app, translations) { registered = translations } } } })
		assert.equal(JSON.stringify(registered), JSON.stringify(json))
		for (const text of strings) assert.equal(typeof json[text], 'string', locale + ': ' + text)
	}
})
