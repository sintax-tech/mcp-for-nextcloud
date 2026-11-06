/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const Levels = require('./PollsCreateContext.cjs').load().McpAdminLevels
const plain = value => JSON.parse(JSON.stringify(value))

/** Catalog additions stay outside the existing presets until their dedicated UI tickets. */
for (const [module, old, added] of [
	['tables', ['read', 'create', 'edit'], ['schema', 'share']],
	['polls', ['read', 'results', 'links', 'create'], ['configure', 'options', 'options.remove', 'share', 'invite']],
]) {
	test(module + ' preserves every previous level and preset', () => {
		const operations = [...old, ...added]
		assert.deepEqual(plain(Levels.levels(module, operations)), plain(Levels.levels(module, old)))
		assert.deepEqual(plain(Levels.tiers(module, operations)), plain(Levels.tiers(module, old)))
		for (const level of Levels.levels(module, old)) {
			assert.deepEqual(plain(Levels.operationsFor(module, operations, level)), plain(Levels.operationsFor(module, old, level)))
		}
		for (let mask = 0; mask < 2 ** old.length; mask++) {
			const previous = Object.fromEntries(old.map((operation, index) => [operation, !!(mask & (1 << index))]))
			const current = { ...previous, ...Object.fromEntries(added.map(operation => [operation, false])) }
			assert.equal(Levels.levelOf(module, operations, current), Levels.levelOf(module, old, previous), module + ' combination ' + mask)
		}
		const denied = Object.fromEntries(operations.map(operation => [operation, false]))
		for (const operation of [old[0], added[0]]) {
			assert.equal(Levels.levelOf(module, operations, { ...denied, [operation]: null }), 'unknown')
		}
		assert.equal(Levels.levelOf(module, operations, { ...denied, [added[0]]: true }), 'custom')
	})
}

/** Exercise the real popover, bulk and group planners without adding DOM or visual fixtures. */
test('presets preserve granted operation-only permissions; None clears them in every editor', async () => {
	const vm = require('./AdminContext.cjs')
	const manifest = require('./AdminManifest.cjs')
	const root = require('./PollsCreateContext.cjs').load()
	root.window = root
	root.n = (_, text) => text
	for (const file of ['level-popover/planning', 'matrix/commands', 'groups/drafts']) {
		vm.runInNewContext(manifest.fragment('admin/' + file + '.js'), root)
	}
	const admin = root.OCA.Mcp.Admin
	for (const [module, extra, level] of [['polls', 'configure', 'polls_read'], ['tables', 'schema', 'full']]) {
		const operations = module === 'polls' ? ['read', 'results', 'links', 'create', 'configure'] : ['read', 'create', 'edit', 'schema']
		const user = { uid: 'alice', grants: { [module]: Object.fromEntries(operations.map(op => [op, op === extra])) } }
		const popover = { levels: admin.levels, operationsOf: () => operations, valueOf: (u, m, op) => u.grants[m][op] }
		admin.modules.levelPopover.planning(popover)
		assert.ok(!popover.planLevel(user, module, level, null).some(command => command.operation === extra))
		assert.ok(popover.planLevel(user, module, 'none', null).some(command => command.operation === extra && command.state === 'deny'))
		const bulk = { state: { data: { catalog: { [module]: operations }, users: [user] } }, unavailableReason: () => null,
			levelLabels: () => ({}), moduleLabels: () => ({}), showNotice: () => {} }
		admin.modules.matrix.commands(bulk)
		const plan = bulk.bulkPlan
		const targets = []
		bulk.bulkPlan = (m, ops, target, uids) => {
			targets.push(target)
			return { ...plan(m, ops, target, uids), users: 0 }
		}
		await bulk.bulk(module, level, ['alice'])
		assert.equal(targets[0][extra], undefined)
		assert.ok(!plan(module, operations, targets[0]).changes.some(change => change.operation === extra))
		await bulk.bulk(module, 'none', ['alice'])
		assert.ok(plan(module, operations, targets[1]).changes.some(change => change.operation === extra && change.granted === false))
		const group = { current: { catalog: { [module]: operations } }, draft: { [module]: [extra] }, render: () => {} }
		admin.modules.groups.drafts(group)
		group.setLevel(module, level, 'level')
		assert.ok(group.draft[module].includes(extra))
		group.setLevel(module, 'none', 'level')
		assert.equal(group.draft[module], undefined)
	}
})
