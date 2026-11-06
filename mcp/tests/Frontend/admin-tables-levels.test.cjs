/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Tests of the Tables column of the admin page: GrantCatalog::CATALOG offers Tables reading, creating and editing rows,
 * src/admin/admin-levels.js gives it the three levels None, Read and Full (creating and editing together), and every place that
 * names a module names Tables.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('./AdminContext.cjs')
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
		vm.runInNewContext(require('./AdminManifest.cjs').script(file), context)
	}
	return context.McpAdminLevels
}

/** @return {string[]} GrantCatalog::CATALOG['tables'], read from the PHP source */
function tablesOperations() {
	const policy = fs.readFileSync(path.join(root, 'lib/Access/Grants/GrantCatalog.php'), 'utf8')
	const line = policy.match(/'tables' => \[([^\]]*)\]/)
	assert.ok(line, 'the Tables entry of GrantCatalog::CATALOG')
	return [...line[1].matchAll(/'([\w.]+)'/g)].map((match) => match[1])
}

test('Tables levels are None, Read and Full', () => {
	const Levels = levels()
	const operations = tablesOperations()
	assert.deepEqual(operations, ['read', 'create', 'edit', 'schema', 'share'])
	assert.deepEqual(plain(Levels.levels('tables', operations)), ['none', 'read', 'full'])
	assert.deepEqual(plain(Levels.operationsFor('tables', operations, 'read')), ['read'])
	assert.deepEqual(plain(Levels.operationsFor('tables', operations, 'full')), ['read', 'create', 'edit'])
	assert.equal(Levels.levelOf('tables', operations, { read: true, create: false, edit: false, schema: false, share: false }), 'read')
	assert.equal(Levels.levelOf('tables', operations, { read: true, create: true, edit: true, schema: false, share: false }), 'full')
	assert.equal(Levels.levelOf('tables', operations, { read: false, create: false, edit: false, schema: false, share: false }), 'none')
	assert.equal(Levels.levelOf('tables', operations, { read: false, create: true, edit: false, schema: false, share: false }), 'custom', 'creating without reading is shown operation by operation')
})

test('every module label map names Tables', () => {
	for (const file of ['admin-grants.js', 'admin-groups.js', 'admin-inspector.js']) {
		const source = require('./AdminManifest.cjs').script(file)
		assert.match(source, /tables: t\('mcp', 'Tables'\)/, file)
	}
})
