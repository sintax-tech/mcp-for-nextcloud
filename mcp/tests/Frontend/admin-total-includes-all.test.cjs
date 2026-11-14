/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Full ("Total") is every operation of Tables and Collectives too (decision of 07/11/2026): the level covers structure,
 * sharing, creating collectives and members, the popover says so, a bulk change to Full saves those operations, and a
 * person saved with the old Full (no structure, sharing, collectives or members) is shown as Custom, never promoted.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('./AdminContext.cjs')
const manifest = require('./AdminManifest.cjs')

const plain = (value) => JSON.parse(JSON.stringify(value))

/** The catalog of GrantCatalog::CATALOG for both modules, and what the old Full allowed. */
const MODULES = {
	deck: { operations: ['read', 'create', 'edit', 'move', 'delete', 'labels'], oldFull: ['read', 'create', 'edit', 'move', 'delete'] },
	tables: { operations: ['read', 'create', 'edit', 'schema', 'share'], oldFull: ['read', 'create', 'edit'] },
	collectives: { operations: ['read', 'create', 'edit', 'move', 'collective.create', 'members'], oldFull: ['read', 'create', 'edit', 'move'] },
}

/** @return {object} the levels, as the bundle loads them */
function levels() {
	const context = {}
	vm.runInNewContext(manifest.levelsSource(), context)
	return context.McpAdminLevels
}

/**
 * @param {string[]} operations catalog operations
 * @param {string[]} granted operations allowed
 * @return {Object<string, boolean>} one value per operation
 */
function values(operations, granted) {
	return Object.fromEntries(operations.map((operation) => [operation, granted.includes(operation)]))
}

test('Full is every operation of Tables and Collectives, and the popover lists them', () => {
	const Levels = levels()
	const root = { t: (_, text) => text }
	vm.runInNewContext(manifest.levelsSource(), root)
	root.window = root
	vm.runInNewContext(manifest.fragment('admin/level-popover/wording.js'), root)
	const names = { labels: 'manage board labels', delete: 'delete', read: 'read', create: 'create', edit: 'edit', move: 'move', schema: 'schema', share: 'share', 'collective.create': 'create collectives', members: 'manage members' }
	for (const [module, { operations }] of Object.entries(MODULES)) {
		assert.deepEqual(plain(Levels.operationsFor(module, operations, 'full')), operations, module)
		assert.equal(Levels.levelOf(module, operations, values(operations, operations)), 'full', module)
		const popover = { levels: root.OCA.Mcp.Admin.levels, operationsOf: () => operations, matrix: { labels: () => ({ operations: names }) } }
		root.OCA.Mcp.Admin.modules.levelPopover.wording(popover)
		assert.equal(popover.levelDescription(module, 'full'), operations.map((operation) => names[operation]).join(', '), module)
	}
})

test('a person saved with the old Full is Custom, never promoted', () => {
	const Levels = levels()
	for (const [module, { operations, oldFull }] of Object.entries(MODULES)) {
		assert.equal(Levels.levelOf(module, operations, values(operations, oldFull)), 'custom', module)
		// Read and None never grant what Full adds, so choosing them cannot promote either.
		for (const level of ['none', 'read']) {
			const target = Levels.targetFor(module, operations, level)
			assert.ok(operations.filter((operation) => !oldFull.includes(operation)).every((operation) => target[operation] === false), module + ' ' + level)
		}
	}
})

test('a bulk change to Full saves structure, sharing, collectives and members, and only for who lacks them', async () => {
	for (const [module, { operations, oldFull }] of Object.entries(MODULES)) {
		const log = { calls: [], confirms: [] }
		const user = (uid, granted) => ({ uid, enabled: true, eligible: true, appsEnabled: { [module]: true }, grants: { [module]: values(operations, granted) } })
		const sandbox = {
			window: { McpAdminLevels: levels(), confirm: (text) => { log.confirms.push(text); return true } },
			state: { data: { revision: '1', catalog: { [module]: operations }, users: [user('ana', oldFull), user('bruno', operations)] } },
			t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? '{' + key + '}'),
			n: (app, one, many, count, vars) => (count === 1 ? one : many).replace('%n', count).replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? '{' + key + '}'),
			moduleLabels: () => ({ [module]: module }),
			levelLabels: () => ({ full: 'Full' }),
			api: async (method, url, body) => { log.calls.push(plain(body)); return { revision: '2', changed: body.commands.length, receiptId: 'r-1' } },
			announce: () => {},
			showNotice: () => {},
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
		const cut = (declaration) => manifest.operation(declaration)
		vm.runInNewContext([cut('function unavailableReason('), cut('function bulkPlan('), cut('async function sendCommand('), cut('async function bulk(')].join('\n')
			+ '\nthis.bulk = bulk', sandbox)
		await sandbox.bulk(module, 'full', ['ana', 'bruno'])
		assert.equal(log.confirms.length, 1, module)
		assert.match(log.confirms[0], /1 selected person already has it/, module)
		const added = operations.filter((operation) => !oldFull.includes(operation))
		assert.deepEqual(log.calls[0].commands.map((c) => c.uid + ':' + c.operation + ':' + c.state), added.map((operation) => 'ana:' + operation + ':allow'), module)
	}
})
