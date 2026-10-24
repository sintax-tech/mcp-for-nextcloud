/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const { mount } = require('../../js/admin-backups.js')

/** @returns {Object} One loaded policy response. */
function state() { return { version: 1, folder: 'MCP backups', reserved: ['MCP backups'], copies: true, revision: '3', versionsAvailable: true, versionsForActor: true, effectiveCopies: true } }
/**
 * @param {Function} request Test transport.
 * @returns {Object} Document and editor controls.
 */
function fixture(request) {
	const elements = new Map()
	for (const id of ['form', 'folder', 'copies', 'save', 'reload', 'status', 'effective']) elements.set('mcp-backup-' + id, {
		value: '', checked: false, disabled: true, textContent: '', listeners: {},
		setAttribute() {}, addEventListener(key, fn) { this.listeners[key] = fn },
	})
	const document = { getElementById: id => elements.get(id), addEventListener() {} }
	return { elements, editor: mount(document, request, (_, text) => text, '/api/backup-settings', () => 'csrf') }
}
/** @param {Object} data Backend response. @param {number} status HTTP status. @returns {Object} Fetch reply. */
function response(data, status = 200) { return { ok: status === 200, status, json: async () => data } }

test('load and save use the canonical revision, CSRF token and returned persisted state', async () => {
	const calls = []
	const { elements, editor } = fixture(async (url, options) => { calls.push(options); return response(calls.length === 1 ? state() : { ...state(), folder: '.mcp-backups', copies: false, effectiveCopies: false, revision: '4' }) })
	await editor.ready
	elements.get('mcp-backup-folder').value = '.mcp-backups'
	elements.get('mcp-backup-copies').checked = false
	await elements.get('mcp-backup-form').listeners.submit({ preventDefault() {} })
	assert.deepEqual(JSON.parse(calls[1].body), { folder: '.mcp-backups', copies: false, expectedRevision: '3' })
	assert.equal(calls[1].headers.requesttoken, 'csrf')
	assert.equal(calls[1].credentials, 'same-origin')
	assert.match(elements.get('mcp-backup-effective').textContent, /suspended/)
})

test('failed load does not expose an invented default or enable saving', async () => {
	const { elements, editor } = fixture(async () => response({}, 503))
	await editor.ready
	assert.equal(elements.get('mcp-backup-save').disabled, true)
	assert.equal(elements.get('mcp-backup-folder').value, '')
	assert.match(elements.get('mcp-backup-status').textContent, /could not be loaded/)
})

test('revision conflict disables retry until explicit reload without discarding the draft', async () => {
	let calls = 0
	const { elements, editor } = fixture(async () => ++calls === 2 ? response({}, 409) : response(state()))
	await editor.ready
	elements.get('mcp-backup-folder').value = 'draft'
	await elements.get('mcp-backup-form').listeners.submit({ preventDefault() {} })
	assert.equal(elements.get('mcp-backup-save').disabled, true)
	assert.equal(elements.get('mcp-backup-folder').value, 'draft')
	await editor.reload()
	assert.equal(elements.get('mcp-backup-save').disabled, false)
})
