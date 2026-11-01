/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Tests of the access levels (S2 of the admin redesign): the level ↔ operations mapping of src/admin/admin-levels.js against the
 * catalog of GrantPolicy, and the level cell of src/admin/admin-grants.js, cut out of the source and run against stubs as in
 * admin-revision.test.cjs. A level is presentation only: the server keeps receiving operations.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('./AdminContext.cjs')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const context = require('./PollsCreateContext.cjs').load()
vm.runInNewContext(require('./AdminManifest.cjs').levelsSource(), context)
const Levels = context.McpAdminLevels
const plain = (value) => JSON.parse(JSON.stringify(value))

/** GrantPolicy::CATALOG as PHP declares it: module => list of operations. */
function catalog() {
	const policy = fs.readFileSync(path.join(root, 'lib/Access/Grants/GrantCatalog.php'), 'utf8')
	const body = policy.slice(policy.indexOf('public const CATALOG = ['), policy.indexOf('];', policy.indexOf('public const CATALOG = [')))
	const out = {}
	for (const [, module, operations] of body.matchAll(/'(\w+)' => \[([^\]]*)\]/g)) {
		out[module] = [...operations.matchAll(/'(\w+)'/g)].map((match) => match[1])
	}
	assert.ok(Object.keys(out).length >= 9, 'the catalog was read from GrantPolicy.php')
	return out
}

test('every operation of the catalog sits in exactly one tier, and the tiers name nothing else', () => {
	require('./PollsTierCoverage.cjs').assertCatalog(Levels, catalog())
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

/* The level cell of src/admin/admin-grants.js. */

const grants = require('./AdminManifest.cjs').script('admin-grants.js')
function cut(declaration) {
	return require('./AdminManifest.cjs').operation(declaration)
}

class Node {
	constructor(tag) { this.tag = tag; this.children = []; this.attributes = {}; this.classes = new Set(); this.dataset = {}; this.title = ''; this._text = '' }
	set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)) }
	get className() { return [...this.classes].join(' ') }
	get classList() { return { add: (name) => this.classes.add(name), remove: (name) => this.classes.delete(name), contains: (name) => this.classes.has(name) } }
	append(...children) { this.children.push(...children) }
	setAttribute(key, value) { this.attributes[key] = value }
	set textContent(value) { this._text = String(value) }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join('') }
	all() { return this.children.flatMap((child) => typeof child === 'string' ? [] : [child, ...child.all()]) }
	find(name) { return this.all().find((node) => node.classes.has(name)) ?? null }
}

function cellKit({ appsEnabled = true, scope = null, exceptions } = {}) {
	const opened = []
	const sandbox = {
		window: { McpAdminLevels: Levels, McpAdminExceptions: exceptions, McpLevelPopover: { open: (trigger, uid, module) => opened.push([module, uid, trigger.classes.has('mcp-level')]) }, McpScopeUi: { indicator: (uid, module, value) => Object.assign(new Node('button'), { className: 'mcp-scope-indicator', textContent: 'Restricted to ' + value.count }) } },
		t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? '{' + key + '}'),
		el: (tag, props = {}, children = []) => {
			const node = new Node(tag)
			for (const [key, value] of Object.entries(props)) node[key] = value
			node.append(...children)
			return node
		},
		moduleLabels: () => ({ files: 'Files', logs: 'Server log' }),
		operationLabels: () => ({ read: 'read', edit: 'edit', create: 'create', move: 'move', restore: 'restore', share: 'share', link: 'public link' }),
		unavailableReason: () => appsEnabled ? null : 'This app is unavailable for this user; the saved permission is kept.',
		reloadLocked: () => {},
	}
	vm.runInNewContext([cut('const PERMISSION_STATES = {'), cut('function paintState('), cut('function levelLabels('), cut('function originText('), cut('function levelCell('), cut('function levelButton('), cut('const INDIVIDUAL_SOURCES = '), cut('function originMark('), cut('function markIcon('), cut('function markText(')].join('\n')
		+ '\nthis.levelCell = levelCell', sandbox)
	const files = catalog().files
	const user = (allowed, sources = {}) => ({
		uid: 'ana',
		displayName: 'Ana Souza',
		scope,
		grants: { files: Object.fromEntries(files.map((operation) => [operation, allowed === null ? null : allowed.includes(operation)])) },
		origins: { grants: { files: Object.fromEntries(files.map((operation) => [operation, sources[operation] || { source: 'default', groupIds: [] }])) } },
	})
	return { cell: (u) => sandbox.levelCell(u, 'files', files, 0), user, opened }
}

test('a level cell names the level in words and its origin by an icon, never by colour alone', () => {
	const kit = cellKit()
	const edit = ['read', 'edit', 'create', 'move', 'restore']
	const individual = kit.cell(kit.user(edit, { edit: { source: 'individual_allow', groupIds: [] } }))
	const button = individual.find('mcp-level')
	assert.equal(button.find('mcp-level-word').textContent, 'Edit')
	assert.ok(button.classes.has('mcp-level-individual'))
	const icon = button.find('mcp-origin-icon')
	assert.ok(icon.classes.has('icon-user'))
	assert.equal(icon.attributes['aria-hidden'], 'true', 'the icon is decoration; the words carry the state')
	assert.equal(button.attributes['aria-label'], 'Ana Souza, Files: Edit. Exception: differs from what the person inherits.')
	assert.equal(button.attributes['aria-haspopup'], 'dialog')
	assert.equal(button.attributes['aria-expanded'], 'false')
	assert.equal(individual.title, 'Edit. Allows: read, edit, create, move, restore — Exception: differs from what the person inherits')
	assert.equal(individual.dataset.level, 'edit')
	assert.ok(individual.classes.has('mcp-state-allowed'))

	const group = kit.cell(kit.user(['read'], { read: { source: 'group', groupIds: ['comercial'] } })).find('mcp-level')
	assert.ok(group.find('mcp-origin-icon').classes.has('icon-group'))
	assert.match(group.attributes['aria-label'], /Read\. Granted by groups: comercial\./)

	const none = kit.cell(kit.user([]))
	assert.equal(none.find('mcp-level').find('mcp-level-word').textContent, 'None')
	assert.ok(none.find('mcp-level').classes.has('mcp-level-none'))
	assert.equal(none.find('mcp-origin-icon'), null, 'the default has no icon')
	assert.ok(none.classes.has('mcp-state-denied'))
	assert.match(none.title, /Allows nothing — Default/)

	assert.equal(kit.cell(kit.user(['read', 'share'])).find('mcp-level-word').textContent, 'Custom')
})

test('choosing a level cell opens its popover for that user and module', async () => {
	const kit = cellKit()
	kit.cell(kit.user(['read'])).find('mcp-level').onclick()
	assert.deepEqual(kit.opened, [['files', 'ana', true]])
})

test('an unavailable module keeps the no entry mark without a control, and an unknown one the dash with a retry', () => {
	const kit = cellKit({ appsEnabled: false })
	const cell = kit.cell(kit.user(['read']))
	assert.equal(cell.find('mcp-level'), null, 'no control: an unavailable cell never looks editable')
	const mark = cell.find('mcp-state-mark')
	assert.equal(mark.textContent, '⊘')
	assert.equal(mark.attributes['aria-hidden'], 'true')
	assert.equal(cell.find('mcp-level-word').textContent, 'Unavailable')
	assert.ok(cell.classes.has('mcp-state-unavailable'))
	assert.equal(cell.find('mcp-sr-only').textContent, 'Ana Souza — Files: Unavailable: This app is unavailable for this user; the saved permission is kept.')

	const unknown = cellKit().cell(kit.user(null))
	assert.equal(unknown.find('mcp-state-mark').textContent, '—')
	assert.equal(unknown.find('mcp-level-word').textContent, 'Unknown')
	assert.ok(unknown.all().some((node) => node.tag === 'button' && node.textContent === 'Retry'))
})

test('the resource scope mark stays in the module cell', () => {
	const kit = cellKit({ scope: { files: { mode: 'restricted', count: 3 } } })
	const u = kit.user(['read'])
	u.scope = { files: { mode: 'restricted', count: 3 } }
	assert.equal(kit.cell(u).find('mcp-scope-indicator').textContent, 'Restricted to 3')
})

test('S4: only a real exception gets the person icon; a value that repeats the inheritance is drawn like what it repeats', () => {
	const edit = { edit: { source: 'individual_allow', groupIds: [] } }
	const kinds = (kind, after) => ({ kindOf: () => ({ kind, after }) })
	const exception = cellKit({ exceptions: kinds('exception', null) })
	const real = exception.cell(exception.user(['read', 'edit'], edit))
	assert.ok(real.find('mcp-origin-icon').classes.has('icon-user'))
	assert.ok(real.find('mcp-level').classes.has('mcp-level-individual'))
	assert.equal(real.dataset.mark, 'exception')

	const viaGroup = cellKit({ exceptions: kinds('redundant', { source: 'group', groupIds: ['comercial'] }) })
	const repeated = viaGroup.cell(viaGroup.user(['read', 'edit'], edit))
	assert.ok(repeated.find('mcp-origin-icon').classes.has('icon-group'), 'drawn like the group it repeats')
	assert.ok(!repeated.find('mcp-level').classes.has('mcp-level-individual'))
	assert.equal(repeated.dataset.mark, 'redundant')
	assert.match(repeated.title, /Granted by groups: comercial — also set for this person, with the same value$/)

	const viaDefault = cellKit({ exceptions: kinds('redundant', { source: 'default', groupIds: [] }) })
	const quiet = viaDefault.cell(viaDefault.user(['read'], { read: { source: 'individual_allow', groupIds: [] } }))
	assert.equal(quiet.find('mcp-origin-icon'), null, 'a value that repeats the default is silent')
	assert.match(quiet.title, /Default — also set for this person, with the same value$/)

	const pending = cellKit({ exceptions: kinds('pending', null) })
	const waiting = pending.cell(pending.user(['read', 'edit'], edit))
	assert.equal(waiting.find('mcp-origin-icon'), null, 'no exception mark before the answer')
	assert.equal(waiting.dataset.mark, 'pending')
	assert.match(waiting.find('mcp-level').attributes['aria-label'], /Set for this person\.$/)

	// An invalid stored value is always an exception, whatever the classification says.
	const invalid = viaDefault.cell(viaDefault.user(['read'], { share: { source: 'invalid', groupIds: [] } }))
	assert.ok(invalid.find('mcp-origin-icon').classes.has('icon-user'))
	// Without the classification every individual value is an exception, never fewer.
	const none = cellKit()
	assert.ok(none.cell(none.user(['read', 'edit'], edit)).find('mcp-origin-icon').classes.has('icon-user'))
})
