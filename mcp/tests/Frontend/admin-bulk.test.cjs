/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Tests of the bulk change of the selection bar (S6 of the admin redesign): bulk() and bulkPlan() of src/admin/admin-grants.js,
 * cut out of the source and run against stubs, as in admin-revision.test.cjs. A bulk change stays explicit, as the "All"
 * menus of 331bdede made it: the confirmation says how many change, how many are left out and why and how many already
 * have it, nothing is sent when nobody changes, the command is one all-or-nothing write of explicit allow and deny
 * (never an inheritance, as F1 requires of a removal), and the receipt is offered as the undo.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('./AdminContext.cjs')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const source = require('./AdminManifest.cjs').script('admin-grants.js')
const tick = async () => { for (let i = 0; i < 12; i++) await new Promise(setImmediate) }
const plain = (value) => JSON.parse(JSON.stringify(value))
const REVISION = '9007199254740993'

function cut(declaration) {
	const start = source.indexOf('\t' + declaration)
	assert.ok(start > 0, declaration + ' was found in src/admin/admin-grants.js')
	return source.slice(start, source.indexOf('\n\t}\n', start) + 4)
}

const files = ['read', 'edit', 'create', 'move', 'restore', 'share', 'link']
/** A row with Files values from `allowed`, "Can connect" `eligible`, and Files available unless `filesOff`. */
function user(uid, allowed, { eligible = true, filesOff = false, enabled = true } = {}) {
	return { uid, enabled, eligible, appsEnabled: { files: !filesOff }, grants: { files: Object.fromEntries(files.map((op) => [op, allowed === null ? null : allowed.includes(op)])) } }
}

function kit({ users, answer, confirm = true } = {}) {
	const levels = {}
	vm.runInNewContext(require('./AdminManifest.cjs').levelsSource(), levels)
	const log = { calls: [], confirms: [], notices: [], errors: [], busy: [], reloads: 0, policyErrors: [], announced: [] }
	const sandbox = {
		window: { McpAdminLevels: levels.McpAdminLevels, confirm: (text) => { log.confirms.push(text); return confirm } },
		state: { data: { revision: REVISION, catalog: { files }, users } },
		t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? '{' + key + '}'),
		n: (app, one, many, count, vars) => (count === 1 ? one : many).replace('%n', count).replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? '{' + key + '}'),
		moduleLabels: () => ({ files: 'Files' }),
		levelLabels: () => ({ none: 'None', read: 'Read', edit: 'Edit', full: 'Full' }),
		api: async (method, url, body) => {
			log.calls.push({ method, url, body: plain(body) })
			const reply = answer ? answer(body) : { revision: '9007199254740994', changed: body.commands.length, receiptId: 'r-1' }
			if (reply instanceof Error) throw reply
			return reply
		},
		announce: (from, to) => log.announced.push([from, to]),
		showNotice: (message, undo) => log.notices.push({ message, undo: undo !== null }),
		setBulkBusy: (on) => log.busy.push(on),
		policyError: async (e) => { log.policyErrors.push(e.message); return e.message === '409' },
		notifyError: (message) => log.errors.push(message),
		reloadLocked: async () => { log.reloads++ },
		undoBulk: () => {},
		bulkBusy: false,
		matrixLocked: false,
		pendingUndo: null,
		undoButton: { focus() {} },
	}
	vm.runInNewContext([cut('function unavailableReason('), cut('function bulkPlan('), cut('async function sendCommand('), cut('async function bulk(')].join('\n')
		+ '\nthis.bulk = bulk; this.bulkPlan = bulkPlan', sandbox)
	return { bulk: sandbox.bulk, bulkPlan: sandbox.bulkPlan, log, sandbox }
}

const posts = (k) => k.log.calls.filter((call) => call.url === '/api/grants/commands')

test('a level for the selected people: one command of explicit allow and deny, only for who changes', async () => {
	const k = kit({ users: [user('ana', ['read']), user('bruno', ['read', 'edit', 'create', 'move', 'restore']), user('carla', ['read', 'share']), user('diego', ['read'])] })
	await k.bulk('files', 'edit', ['ana', 'bruno', 'carla'])
	assert.deepEqual(k.log.confirms, ['Set “Files” to Edit for 2 selected people?\n1 selected person already has it.'], 'diego is not selected; bruno already has it')
	const [call] = posts(k)
	assert.equal(call.body.expectedRevision, REVISION)
	const states = call.body.commands.map((c) => c.uid + ':' + c.operation + ':' + c.state).sort()
	assert.deepEqual(states, ['ana:create:allow', 'ana:edit:allow', 'ana:move:allow', 'ana:restore:allow', 'carla:create:allow', 'carla:edit:allow', 'carla:move:allow', 'carla:restore:allow', 'carla:share:deny'])
	assert.ok(call.body.commands.every((c) => c.state !== 'inherit'), 'never an inheritance')
	assert.ok(call.body.commands.every((c) => c.module === 'files'))
	assert.deepEqual(k.log.notices, [{ message: '“Files” set to Edit for 2 people.', undo: true }])
	assert.deepEqual(k.log.busy, [true, false])
	assert.equal(k.log.reloads, 1)
	assert.deepEqual(k.log.announced, [[REVISION, '9007199254740994']])
})

test('who is left out and why is said, and nothing is sent when nobody changes', async () => {
	const k = kit({ users: [user('ana', ['read']), user('bruno', ['read'], { filesOff: true }), user('carla', null), user('diego', ['read'], { enabled: false })] })
	await k.bulk('files', 'read', ['ana', 'bruno', 'carla', 'diego'])
	assert.equal(k.log.confirms.length, 0)
	assert.equal(posts(k).length, 0)
	assert.deepEqual(k.log.notices, [{ message: 'Nothing to change for the selected people: “Files” is already Read. 3 selected people are left out: this permission is unavailable or unknown for them. 1 selected person already has it.', undo: false }])
})

test('a "no" at the confirmation sends nothing', async () => {
	const k = kit({ users: [user('ana', ['read'])], confirm: false })
	await k.bulk('files', 'none', ['ana'])
	assert.equal(k.log.confirms.length, 1)
	assert.equal(posts(k).length, 0)
	assert.deepEqual(k.log.busy, [])
})

test('turning "Can connect" off for the selection says they are signed out; on does not', async () => {
	const k = kit({ users: [user('ana', ['read']), user('bruno', ['read'], { eligible: false }), user('carla', ['read'], { eligible: null })] })
	await k.bulk(null, 'none', ['ana', 'bruno', 'carla'])
	assert.deepEqual(k.log.confirms, ['Set “Can connect” to No for 1 selected person?\n1 selected person is left out: this permission is unavailable or unknown for them.\n1 selected person already has it.\nThey are signed out of their MCP clients, and undoing this does not sign them back in.'])
	assert.deepEqual(posts(k)[0].body.commands, [{ uid: 'ana', module: null, operation: 'eligible', state: 'deny' }])
	const on = kit({ users: [user('bruno', ['read'], { eligible: false })] })
	await on.bulk(null, 'read', ['bruno'])
	assert.deepEqual(on.log.confirms, ['Set “Can connect” to Yes for 1 selected person?'])
	assert.deepEqual(posts(on)[0].body.commands, [{ uid: 'bruno', module: null, operation: 'eligible', state: 'allow' }])
})

test('a conflict saves nothing and leaves the reload to the conflict handler; another failure is reported and reloads', async () => {
	const conflict = kit({ users: [user('ana', ['read'])], answer: () => new Error('409') })
	await conflict.bulk('files', 'none', ['ana'])
	assert.deepEqual(conflict.log.notices, [])
	assert.equal(conflict.log.reloads, 0)
	assert.deepEqual(conflict.log.busy, [true, false])
	const failure = kit({ users: [user('ana', ['read'])], answer: () => new Error('500') })
	await failure.bulk('files', 'none', ['ana'])
	assert.deepEqual(failure.log.errors, ['Could not update the selected people.'])
	assert.equal(failure.log.reloads, 1)
})

test('while another change saves, or the table is being replaced, nothing is planned or asked', async () => {
	const k = kit({ users: [user('ana', ['read'])] })
	k.sandbox.bulkBusy = true
	vm.runInNewContext('bulkBusy = true', k.sandbox)
	await k.bulk('files', 'none', ['ana'])
	assert.equal(k.log.confirms.length, 0)
})

test('the page-wide plan still leaves unknown values out (T10)', () => {
	const k = kit({ users: [user('ana', null)] })
	const plan = k.bulkPlan('files', files, false)
	assert.equal(plan.unavailable, 1)
	assert.equal(plan.changes.length, 0)
})
