/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Tests of the Forms column of the admin page: GrantPolicy::CATALOG offers Forms reading, results, creating and editing,
 * src/admin/admin-levels.js keeps response reading independent from the writing preset, and every place that
 * names a module names Forms.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const plain = (value) => JSON.parse(JSON.stringify(value))

/**
 * The level scripts in one context, in the order the template loads them.
 *
 * @return {object} the levels
 */
function levels() {
	const context = { t: (app, text) => text }
	for (const file of ['admin-levels.js']) {
		vm.runInNewContext(fs.readFileSync(path.join(root, 'src/admin', file), 'utf8'), context)
	}
	return context.McpAdminLevels
}

/** @return {string[]} GrantPolicy::CATALOG['forms'], read from the PHP source */
function formsOperations() {
	const policy = fs.readFileSync(path.join(root, 'lib/Service/GrantPolicy.php'), 'utf8')
	const line = policy.match(/'forms' => \[([^\]]*)\]/)
	assert.ok(line, 'the Forms entry of GrantPolicy::CATALOG')
	return [...line[1].matchAll(/'(\w+)'/g)].map((match) => match[1])
}

test('Forms writing never implicitly grants response reading', () => {
	const Levels = levels()
	const operations = formsOperations()
	assert.deepEqual(operations, ['read', 'results', 'create', 'edit'])
	assert.deepEqual(plain(Levels.levels('forms', operations)), ['none', 'read', 'forms_write', 'forms_write_results'])
	assert.deepEqual(plain(Levels.operationsFor('forms', operations, 'read')), ['read'])
	assert.deepEqual(plain(Levels.operationsFor('forms', operations, 'forms_write')), ['read', 'create', 'edit'])
	assert.deepEqual(plain(Levels.operationsFor('forms', operations, 'forms_write_results')), operations)
	const prior = { read: true, results: false, create: true, edit: true }
	const copy = plain(prior)
	assert.equal(Levels.levelOf('forms', operations, prior), 'forms_write')
	assert.deepEqual(prior, copy, 'rendering never rewrites a stored policy')
	assert.equal(Levels.levelOf('forms', operations, { read: true, results: true, create: false, edit: false }), 'custom', 'responses alone remains granular')
	assert.equal(Levels.levelOf('forms', operations, { read: true, results: true, create: true, edit: true }), 'forms_write_results', 'old Full values are retained with an explicit name')
	assert.equal(Levels.levelOf('forms', operations, { read: true, results: false, create: false, edit: false }), 'read', 'old/default read remains read')
})

test('every module label map names Forms', () => {
	for (const file of ['admin-grants.js', 'admin-groups.js', 'admin-inspector.js']) {
		const source = fs.readFileSync(path.join(root, 'src/admin', file), 'utf8')
		assert.match(source, /forms: t\('mcp', 'Forms'\)/, file)
	}
})

/** Builds only the public popover planning functions; no DOM actions or HTTP are needed to discriminate commands.
 * @return {object} Public planner and its synthetic user.
 */
function planner() {
	const user = { uid: 'alice', grants: { forms: { read: true, results: false, create: false, edit: false } } }
	const document = { getElementById: () => ({ addEventListener() {} }), addEventListener() {} }
	const context = { document, t: (app, text) => text }
	context.window = { McpAdminMatrix: { data: () => ({ catalog: { forms: formsOperations() } }) }, addEventListener() {} }
	for (const file of ['admin-levels.js', 'admin-level-popover.js']) {
		vm.runInNewContext(fs.readFileSync(path.join(root, 'src/admin', file), 'utf8'), context)
	}
	return { api: context.window.McpLevelPopover, user }
}

test('popover commands grant writes without results and grant results only when explicitly selected', () => {
	const { api, user } = planner()
	assert.deepEqual(plain(api.planLevel(user, 'forms', 'forms_write', null)), [
		{ uid: 'alice', module: 'forms', operation: 'create', state: 'allow' },
		{ uid: 'alice', module: 'forms', operation: 'edit', state: 'allow' },
	])
	assert.deepEqual(plain(api.planOperation(user, 'forms', 'results', 'allow')), [{ uid: 'alice', module: 'forms', operation: 'results', state: 'allow' }])
	assert.ok(plain(api.planLevel(user, 'forms', 'forms_write_results', null)).some((command) => command.operation === 'results' && command.state === 'allow'))
})

test('results labels use translation in all admin paths and combined presets have explicit names', () => {
	for (const file of ['admin-grants.js', 'admin-groups.js', 'admin-inspector.js']) {
		const source = fs.readFileSync(path.join(root, 'src/admin', file), 'utf8')
		assert.match(source, /results: t\('mcp', 'results'\)/, file)
	}
	for (const file of ['admin-grants.js', 'admin-groups.js', 'admin-level-popover.js', 'admin-inspector.js']) {
		assert.match(fs.readFileSync(path.join(root, 'src/admin', file), 'utf8'), /forms_write_results: t\('mcp', 'Read and write forms and read responses'\)/)
	}
	const hint = 'Reserved for reading responses allowed by native Forms permissions; starts off'
	assert.ok(fs.readFileSync(path.join(root, 'src/admin/admin-grants.js'), 'utf8').includes(hint))
	for (const locale of ['pt_BR', 'es', 'de']) {
		const translations = JSON.parse(fs.readFileSync(path.join(root, 'l10n', locale + '.json'), 'utf8')).translations
		assert.notEqual(translations.results, 'results')
		assert.ok(translations[hint])
		assert.ok(translations['Read and write forms and read responses'])
	}
})
