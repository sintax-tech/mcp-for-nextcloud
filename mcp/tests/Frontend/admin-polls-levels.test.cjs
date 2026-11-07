/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('./AdminContext.cjs')
const path = require('node:path')

/** Polls registration has one read tier, with no automatic results or future write capability. */
class PollsAdminContract {
	/** @return {object} the actual admin level implementation */
	static levels() {
		const context = require('./PollsCreateContext.cjs').load()
		for (const file of ['admin-levels.js']) {
			vm.runInNewContext(require('./AdminManifest.cjs').script(file), context)
		}
		return context.McpAdminLevels
	}
}

test('Polls has only None and Read, without a future Full grant', () => {
	const levels = PollsAdminContract.levels()
	assert.deepEqual(Array.from(levels.levels('polls', ['read'])), ['none', 'polls_read'])
	assert.deepEqual(Array.from(levels.operationsFor('polls', ['read'], 'polls_read')), ['read'])
	assert.equal(levels.levelOf('polls', ['read'], { read: false }), 'none')
	assert.equal(levels.levelOf('polls', ['read'], { read: true }), 'polls_read')
})

test('every module label map names Polls', () => {
	for (const file of ['admin-grants.js', 'admin-groups.js', 'admin-inspector.js']) {
		assert.match(require('./AdminManifest.cjs').script(file), /polls: t\('mcp', 'Polls'\)/)
	}
})
