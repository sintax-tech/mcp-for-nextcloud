// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test } from '@playwright/test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import * as Levels from '../../../src/admin/access/levels.js'
const plain = value => JSON.parse(JSON.stringify(value))

function catalog() {
	const policy = readFileSync(new URL('../../../lib/Access/Grants/GrantPolicy.php', import.meta.url), 'utf8')
	const body = policy.slice(policy.indexOf('public const CATALOG = ['), policy.indexOf('];', policy.indexOf('public const CATALOG = [')))
	const out = {}
	for (const [, module, operations] of body.matchAll(/'(\w+)' => \[([^\]]*)\]/g)) {
		out[module] = [...operations.matchAll(/'(\w+)'/g)].map((match) => match[1])
	}
	assert.ok(Object.keys(out).length >= 9, 'the catalog was read from GrantPolicy.php')
	return out
}

test('every operation of the catalog sits in exactly one tier, and the tiers name nothing else', () => {
	const modules = catalog()
	assert.deepEqual(Object.keys(Levels.TIERS).sort(), Object.keys(modules).sort())
	for (const [module, operations] of Object.entries(modules)) {
		const listed = Levels.TIERS[module].flat()
		assert.deepEqual([...listed].sort(), [...operations].sort(), module)
		assert.equal(new Set(listed).size, listed.length, module + ' has no operation twice')
		assert.deepEqual(plain(Levels.TIERS[module][0]), ['read'], module + ' starts with Read')
	}
})

test('levels are None, Read, Edit and Full, or None and Read for a module with one operation', () => {
	const modules = catalog()
	for (const module of ['files', 'notes', 'deck', 'calendar', 'contacts', 'tasks', 'talk']) {
		assert.deepEqual(plain(Levels.levels(module, modules[module])), ['none', 'read', 'edit', 'full'], module)
	}
	assert.deepEqual(plain(Levels.levels('people', modules.people)), ['none', 'read'])
	assert.deepEqual(plain(Levels.levels('logs', modules.logs)), ['none', 'read'])
})

test('the documented mapping: each level adds its tier to the one below', () => {
	const modules = catalog()
	const expect = {
		files: { read: ['read'], edit: ['read', 'edit', 'create', 'move', 'restore'], full: modules.files },
		notes: { read: ['read'], edit: ['read', 'create', 'edit', 'move'], full: modules.notes },
		calendar: { read: ['read'], edit: ['read', 'create', 'edit', 'move'], full: modules.calendar },
		contacts: { read: ['read'], edit: ['read', 'create', 'edit'], full: modules.contacts },
		talk: { read: ['read'], edit: ['read', 'reply', 'attach', 'quote'], full: modules.talk },
	}
	for (const [module, byLevel] of Object.entries(expect)) {
		assert.deepEqual(plain(Levels.operationsFor(module, modules[module], 'none')), [], module + ' none')
		for (const [level, operations] of Object.entries(byLevel)) {
			assert.deepEqual([...Levels.operationsFor(module, modules[module], level)].sort(), [...operations].sort(), module + ' ' + level)
		}
	}
	assert.throws(() => Levels.operationsFor('logs', modules.logs, 'edit'), /Unknown level/)
})

test('a level read back from its own operations is that level, for every module', () => {
	for (const [module, operations] of Object.entries(catalog())) {
		for (const level of Levels.levels(module, operations)) {
			const allowed = Levels.operationsFor(module, operations, level)
			const values = Object.fromEntries(operations.map((operation) => [operation, allowed.includes(operation)]))
			assert.equal(Levels.levelOf(module, operations, values), level, module + ' ' + level)
		}
	}
})

test('a set that is no level is custom, and any unknown or missing value makes the module unknown', () => {
	const files = catalog().files
	const values = (allowed) => Object.fromEntries(files.map((operation) => [operation, allowed.includes(operation)]))
	assert.equal(Levels.levelOf('files', files, values(['read', 'share'])), 'custom')
	assert.equal(Levels.levelOf('files', files, values(['edit'])), 'custom', 'editing without reading is no level')
	assert.equal(Levels.levelOf('files', files, values(['read', 'edit'])), 'custom', 'part of a tier is no level')
	assert.equal(Levels.levelOf('files', files, { ...values(['read']), share: null }), 'unknown')
	const missing = values(['read'])
	delete missing.link
	assert.equal(Levels.levelOf('files', files, missing), 'unknown')
})

test('an operation the tiers do not name goes to the last tier, never to a lower level', () => {
	const operations = ['read', 'edit', 'create', 'move', 'restore', 'share', 'link', 'archive']
	assert.ok(Levels.operationsFor('files', operations, 'full').includes('archive'))
	assert.ok(!Levels.operationsFor('files', operations, 'edit').includes('archive'))
	// A module the tiers do not know: Read for its read, Full for the rest.
	assert.deepEqual(plain(Levels.levels('maps', ['read', 'pin', 'route'])), ['none', 'read', 'full'])
	assert.deepEqual(plain(Levels.operationsFor('maps', ['read', 'pin', 'route'], 'read')), ['read'])
	assert.deepEqual(plain(Levels.levels('stamps', ['stamp'])), ['none', 'read'], 'one operation is one level')
})

test('the origin of a module: invalid, then individual, then unknown group, then group, then default', () => {
	const ops = ['read', 'edit']
	const at = (read, edit) => ({ read, edit })
	const o = (source, groupIds = []) => ({ source, groupIds })
	assert.deepEqual(plain(Levels.originOf(ops, at(o('default'), o('default')))), o('default'))
	assert.deepEqual(plain(Levels.originOf(ops, undefined)), o('default'), 'an API row without origins reads as default')
	assert.deepEqual(plain(Levels.originOf(ops, at(o('group', ['b', 'a']), o('group', ['a'])))), o('group', ['a', 'b']))
	assert.deepEqual(plain(Levels.originOf(ops, at(o('group', ['a']), o('unknown', ['f'])))), o('unknown', ['f']))
	assert.deepEqual(plain(Levels.originOf(ops, at(o('individual_deny'), o('group', ['a'])))), o('individual'))
	assert.deepEqual(plain(Levels.originOf(ops, at(o('individual_allow'), o('invalid')))), o('invalid'))
})

/* The level cell of js/admin-grants.js. */
