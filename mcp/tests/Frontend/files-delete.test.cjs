/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** FD-1: old persisted Full grants stay off; new explicit Full choices expose Delete and a visible admin warning. */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('./AdminContext.cjs')
const path = require('node:path')
const root = path.join(__dirname, '../..')
const context = {}
vm.runInNewContext(require('./AdminManifest.cjs').levelsSource(), context)
const levels = context.McpAdminLevels

test('old Full cells do not acquire Delete; a new Full grant includes it explicitly', () => {
	const old = ['read', 'edit', 'create', 'move', 'restore', 'share', 'link']
	const current = [...old, 'delete']
	const values = Object.fromEntries(current.map(operation => [operation, old.includes(operation)]))
	assert.equal(values.delete, false)
	assert.equal(levels.levelOf('files', current, values), 'custom')
	assert.ok(levels.operationsFor('files', current, 'full').includes('delete'))
	assert.ok(!levels.operationsFor('files', current, 'edit').includes('delete'))
})

test('the existing admin permission page renders the deliberate grant and residual risk warning', () => {
	const page = require('./AdminTemplates.cjs').read(path.join(root, 'templates/admin.php'), 'utf8')
	const warning = require('./AdminTemplates.cjs').read(path.join(root, 'templates/files-delete-warning.php'), 'utf8')
	assert.ok(page.includes("require dirname(__DIR__) . '/files-delete-warning.php'"))
	assert.ok(warning.includes('Deletion is denied by default.'))
	assert.ok(warning.includes('Try the trash bin first: Nextcloud tries to move the item to the trash bin and may permanently delete it if that fails'))
	assert.ok(warning.includes('Recovery is not guaranteed.'))
	assert.ok(!warning.includes('only to the trash bin'))
	assert.ok(warning.includes('maintain backups'))
})
