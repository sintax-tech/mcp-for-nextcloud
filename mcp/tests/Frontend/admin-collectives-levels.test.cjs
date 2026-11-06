/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Tests of the Collectives column of the admin page (#22): GrantPolicy::CATALOG offers the Collectives operations,
 * src/admin/access/levels/collectives.js turns them into levels with no implicit grant, every place that names a module
 * names Collectives, and the bulk change of 331bdede works on the new column unchanged: an explicit confirmation, who is
 * left out (Collectives unavailable for them) and the receipt offered as the undo.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('./AdminContext.cjs')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const plain = (value) => JSON.parse(JSON.stringify(value))

/** @return {object} the levels, as the bundle loads them */
function levels() {
	const context = {}
	vm.runInNewContext(require('./AdminManifest.cjs').levelsSource(), context)
	return context.McpAdminLevels
}

/** @return {string[]} GrantPolicy::CATALOG['collectives'], read from the PHP source */
function collectivesOperations() {
	const policy = fs.readFileSync(path.join(root, 'lib/Access/Grants/GrantCatalog.php'), 'utf8')
	const line = policy.match(/'collectives' => \[([^\]]*)\]/)
	assert.ok(line, 'the Collectives entry of GrantPolicy::CATALOG')
	return [...line[1].matchAll(/'(\w+)'/g)].map((match) => match[1])
}

test('Collectives levels follow the catalog and grant nothing implicitly', () => {
	const Levels = levels()
	const operations = collectivesOperations()
	assert.deepEqual(operations, ['read'])
	assert.deepEqual(plain(Levels.levels('collectives', operations)), ['none', 'read'])
	assert.deepEqual(plain(Levels.operationsFor('collectives', operations, 'read')), ['read'])
	assert.equal(Levels.levelOf('collectives', operations, { read: true }), 'read')
	assert.equal(Levels.levelOf('collectives', operations, { read: false }), 'none')
})

test('every module label map names Collectives', () => {
	for (const file of ['admin/matrix/labels.js', 'admin/groups/session.js', 'admin/inspector/wording.js']) {
		const source = fs.readFileSync(path.join(root, 'src', file), 'utf8')
		assert.match(source, /collectives: t\('mcp', 'Collectives'\)/, file)
	}
	const bundle = fs.readFileSync(path.join(root, 'js/mcp-admin.js'), 'utf8')
	assert.match(bundle, /catalog\.tiers\.collectives = /, 'the level script is in the admin bundle')
})

test('a bulk change of the Collectives column is explicit, counts who is left out and offers the undo', async () => {
	const source = require('./AdminManifest.cjs')
	const cut = (declaration) => source.operation(declaration)
	const log = { calls: [], confirms: [], notices: [] }
	const user = (uid, read, available = true) => ({ uid, enabled: true, eligible: true, appsEnabled: { collectives: available }, grants: { collectives: { read } } })
	const sandbox = {
		window: { McpAdminLevels: levels(), confirm: (text) => { log.confirms.push(text); return true } },
		state: { data: { revision: '1', catalog: { collectives: ['read'] }, users: [user('ana', false), user('bruno', false, false), user('carla', true)] } },
		t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? '{' + key + '}'),
		n: (app, one, many, count, vars) => (count === 1 ? one : many).replace('%n', count).replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? '{' + key + '}'),
		moduleLabels: () => ({ collectives: 'Collectives' }),
		levelLabels: () => ({ none: 'None', read: 'Read' }),
		api: async (method, url, body) => { log.calls.push(plain(body)); return { revision: '2', changed: body.commands.length, receiptId: 'r-1' } },
		announce: () => {},
		showNotice: (message, undo) => log.notices.push({ message, undo: undo !== null }),
		setBulkBusy: () => {},
		policyError: async () => false,
		notifyError: () => {},
		reloadLocked: async () => {},
		undoBulk: () => {},
		bulkBusy: false,
		matrixLocked: false,
		pendingUndo: null,
		undoButton: { focus() {} },
	}
	vm.runInNewContext([cut('function unavailableReason('), cut('function bulkPlan('), cut('async function sendCommand('), cut('async function bulk(')].join('\n')
		+ '\nthis.bulk = bulk', sandbox)
	await sandbox.bulk('collectives', 'read', ['ana', 'bruno', 'carla'])
	assert.equal(log.confirms.length, 1)
	assert.match(log.confirms[0], /“Collectives” to Read for 1 selected person/)
	assert.match(log.confirms[0], /1 selected person is left out/)
	assert.deepEqual(log.calls[0].commands.map((c) => c.uid + ':' + c.module + ':' + c.operation + ':' + c.state), ['ana:collectives:read:allow'])
	assert.deepEqual(log.notices, [{ message: '“Collectives” set to Read for 1 person.', undo: true }])
})
