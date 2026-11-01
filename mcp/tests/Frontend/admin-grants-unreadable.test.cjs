/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
// Run the real request, write handlers and reload/lock functions against a small DOM and HTTP boundary.
const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const source = fs.readFileSync(path.join(__dirname, '../../src/admin/admin-grants.js'), 'utf8')
const uncertain = 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.'
function cut(declaration) {
	const start = source.indexOf('\t' + declaration)
	assert.ok(start > 0, declaration)
	return source.slice(start, source.indexOf('\n\t}\n', start) + 4)
}
function node() {
	const classes = new Set()
	return { children: [], attributes: {}, listeners: {}, disabled: false, checked: false,
		classList: { add: (v) => classes.add(v), remove: (v) => classes.delete(v), contains: (v) => classes.has(v) },
		setAttribute(k, v) { this.attributes[k] = v },
		append(...children) { this.children.push(...children) },
		addEventListener(k, fn) { this.listeners[k] = fn }, focus() {},
	}
}
function kit({ badRead = false, status = 200 } = {}) {
	const log = { calls: [], errors: [], notices: [], announced: [], flashes: [], renders: 0, loadErrors: 0 }
	const table = node(), control = node(), serviceBox = node(), serviceStatus = node()
	const saved = () => ({ revision: '9', users: [{ uid: 'ana', displayName: 'Ana', eligible: true }], catalog: {}, serviceEnabled: true })
	const context = {
		state: { search: '', group: '', filter: 'eligible', page: 1, data: { ...saved(), revision: '7', users: [{ uid: 'ana', displayName: 'Ana', eligible: false }] } },
		table, serviceBox, serviceStatus, URLSearchParams,
		OC: { generateUrl: (v) => v, requestToken: 'test' },
		fetch: async (url, options) => {
			log.calls.push({ url, method: options.method })
			return { ok: options.method === 'GET' || status < 400, status: options.method === 'GET' ? 200 : status,
				json: async () => {
					if (options.method !== 'GET' || badRead) throw new SyntaxError('HTML proxy response')
					return saved()
				} }
		},
		window: { confirm: () => true }, document: { activeElement: null },
		t: (_, text) => text, n: (_, text) => text,
		root: { querySelectorAll: () => [control] }, notice: node(), noticeTimer: null,
		clearTimeout() {}, armNotice() {},
		el: (_, props) => Object.assign(node(), props), paintState() {}, unavailableReason: () => null,
		notifyError: (text) => log.errors.push(text),
		announce: (...args) => log.announced.push(args),
		showNotice: (message, undo) => log.notices.push({ message, undo }),
		flash: (_, ok) => log.flashes.push(ok),
		render: () => { log.renders++ }, renderError: () => { log.loadErrors++ },
		renderRow: () => { throw new Error('Unreadable save must not replace a row') },
		levelLabels: () => ({}), moduleLabels: () => ({}), pendingUndo: null, undoButton: node(),
	}
	const functions = ['async function api(', 'async function load(', 'async function reloadLocked(', 'async function policyError(',
		'function permissionCell(', 'function setBulkBusy(', 'function bulkPlan(', 'async function sendCommand(', 'async function bulk(', 'async function undoBulk(']
	const serviceStart = source.indexOf("\tserviceBox.addEventListener('change',")
	const serviceEnd = source.indexOf("\n\twindow.addEventListener('mcp-group-policy-saved'", serviceStart)
	vm.runInNewContext('let matrixLocked = false, bulkBusy = false, loadSeq = 0;\n' + functions.map(cut).join('\n')
		+ source.slice(serviceStart, serviceEnd)
		+ '\nthis.api = api; this.load = load; this.bulk = bulk; this.undoBulk = undoBulk; this.permissionCell = permissionCell; this.locked = () => matrixLocked || bulkBusy', context)
	return { context, log, table, control, serviceBox, serviceStatus }
}
const reads = (k) => k.log.calls.filter((c) => c.method === 'GET')
function recovered(k) {
	assert.deepEqual(k.log.errors, [uncertain])
	assert.deepEqual(k.log.announced, [])
	assert.equal(reads(k).length, 1, 'exactly one reload, no replay or loop')
	assert.equal(k.table.attributes['aria-busy'], 'false')
	assert.equal(k.context.locked(), false)
}
test('issue #11 matrix: unreadable cell save reloads stored values without applying or announcing the response', async () => {
	const k = kit()
	const cell = k.context.permissionCell(k.context.state.data.users[0], null, false, 'Can connect', { eligible: null })
	const box = cell.children[0]
	box.checked = true
	await box.listeners.change()
	recovered(k)
	assert.equal(box.disabled, false)
	assert.equal(box.checked, false, 'optimistic checkbox is rolled back before reload')
	assert.equal(k.context.state.data.revision, '9', 'only the GET supplies the revision')
	assert.deepEqual(k.log.flashes, [false])
})
test('issue #11 matrix: unreadable bulk command offers no success or undo and restores busy/aria', async () => {
	const k = kit()
	await k.context.bulk(null, 'read', ['ana'])
	recovered(k)
	assert.deepEqual(k.log.notices, [])
	assert.equal(k.control.disabled, false)
	assert.equal(k.control.attributes['aria-disabled'], 'false')
	assert.equal(k.log.calls.filter((c) => c.method === 'POST').length, 1)
})
test('issue #11 matrix: unreadable undo says uncertain and reloads once, without a success or retry offer', async () => {
	const k = kit()
	await k.context.undoBulk('receipt', '7')
	recovered(k)
	assert.equal(k.log.notices.length, 1)
	assert.equal(k.log.notices[0].message, uncertain)
	assert.equal(k.log.notices[0].undo, null)
	assert.equal(k.control.disabled, false)
	assert.equal(k.control.attributes['aria-disabled'], 'false')
})
test('issue #11 matrix: unreadable service save never says Saved and restores its control', async () => {
	const k = kit()
	k.serviceBox.checked = true
	await k.serviceBox.listeners.change()
	recovered(k)
	assert.notEqual(k.serviceStatus.textContent, 'Saved')
	assert.equal(k.serviceBox.disabled, false)
	assert.equal(k.context.state.data.serviceEnabled, true, 'the saved state comes from GET')
})
test('issue #11 matrix: unreadable GET during recovery ends with load error and releases every lock', async () => {
	const k = kit({ badRead: true })
	await k.context.bulk(null, 'read', ['ana'])
	assert.deepEqual(k.log.errors, [uncertain, 'Could not load the users.'])
	assert.equal(reads(k).length, 1)
	assert.equal(k.log.loadErrors, 1)
	assert.equal(k.table.attributes['aria-busy'], 'false')
	assert.equal(k.control.attributes['aria-disabled'], 'false')
	assert.equal(k.context.locked(), false)
})
test('issue #11 matrix: an initial unreadable GET never invokes uncertain-save recovery', async () => {
	const k = kit({ badRead: true })
	await k.context.load()
	assert.deepEqual(k.log.errors, ['Could not load the users.'])
	assert.equal(reads(k).length, 1)
	assert.equal(k.table.attributes['aria-busy'], 'false')
})
test('issue #11 matrix: HTTP error status survives unreadable JSON; other settings and preview keep parse errors', async () => {
	const k = kit({ status: 412 })
	await assert.rejects(k.context.api('PUT', '/api/grants/ana', {}), (e) => e.message === '412' && e.data === null)
	const normal = kit()
	for (const endpoint of ['/api/admin/tags', '/api/oauth-clients', '/api/checkout-limit', '/api/logs-privacy', '/api/logs-access']) {
		await assert.rejects(normal.context.api('PUT', endpoint, {}), (e) => e instanceof SyntaxError && e.data === undefined)
	}
	await assert.rejects(normal.context.api('POST', '/api/grants/commands/preview', {}), SyntaxError)
})
