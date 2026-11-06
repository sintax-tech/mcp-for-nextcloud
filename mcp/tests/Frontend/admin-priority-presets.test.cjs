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
