/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('./AdminContext.cjs')
const path = require('node:path')
const Context = require('./PollsCreateContext.cjs')
/** Exercises the real named preset policy and its existing consumers, never writing a stored policy. */
class PollsCreateAdmin {
	/** @return {object} actual frontend policy */
	static levels() {
		const root = Context.load()
		vm.runInNewContext(require('./AdminManifest.cjs').levelsSource(), root)
		return root.McpAdminLevels
	}
}
test('Polls creation is an explicit independent preset, protected reads do not acquire it', () => {
	const levels = PollsCreateAdmin.levels()
	const ops = ['read', 'results', 'links', 'create']
	assert.deepEqual([...levels.operationsFor('polls', ops, 'polls_read_create')], ['read', 'create'])
	assert.deepEqual([...levels.operationsFor('polls', ops, 'polls_read_results')], ['read', 'results'])
	assert.deepEqual([...levels.operationsFor('polls', ops, 'polls_read_results_links')], ['read', 'results', 'links'])
	assert.equal(levels.levelOf('polls', ops, { read: false, create: true, results: false, links: false }), 'custom')
	assert.equal(levels.levelOf('polls', ops, { read: true, create: false, results: true, links: true }), 'polls_read_results_links')
	assert.equal(levels.levelOf('polls', ops, { read: true, create: true, results: false, links: false }), 'polls_read_create')
})
test('the actual admin loads the presets with the levels, before the grants, and all four consumers use the helper', () => {
	const template = require('./AdminTemplates.cjs').read(path.join(__dirname, '../../templates/admin.php'), 'utf8')
	assert.equal(template.indexOf("'admin-polls-grants'"), -1)
	assert.ok(require('./AdminManifest.cjs').loadsBefore('admin/admin-levels.js', 'admin/admin-grants.js'))
	for (const name of ['admin-grants.js','admin-groups.js','admin-inspector.js','admin-level-popover.js']) {
		const source = require('./AdminManifest.cjs').script(name)
		assert.match(source, /pollsGrants/)
	}
	const helper = Context.load().McpAdminPollsGrants
	assert.match(helper.operationHint('polls', 'create'), /starts off/)
	assert.match(helper.operationHint('polls', 'create'), /does not grant votes, results or credential links/)
	assert.equal(helper.operationHint('forms', 'create'), null)
	assert.match(helper.levelLabels().polls_read_create, /create private polls/)
})

test('real inspector DOM retains create as an independent custom combination with results and links', async () => {
	const { ids } = await require('./PollsCreateDom.cjs').inspector()
	const text = ids['mcp-inspector-access'].textContent
	assert.match(text, /Polls: create/)
	assert.match(text, /Custom/)
	assert.deepEqual(ids['mcp-inspector-calendar-operation'].all('option').map(o => o.textContent), ['read', 'results', 'Read existing links (credentials)', 'create'])
})

test('missing Polls helper offers no preset and cannot grant an accumulated fallback', () => {
	const root = { t: (_, text) => text }
	vm.runInNewContext(require('./AdminManifest.cjs').levelsSource(), root)
	delete root.OCA.Mcp.Admin.pollsGrants
	const levels = root.McpAdminLevels
	const operations = ['read', 'results', 'links', 'create']
	assert.deepEqual([...levels.levels('polls', operations)], ['none'])
	assert.deepEqual([...levels.tiers('polls', operations)], [])
	for (const level of ['none', 'full', 'tier3', 'polls_read_create', 'polls_read_results_links']) {
		assert.deepEqual([...levels.operationsFor('polls', operations, level)], [])
	}
	assert.equal(levels.levelOf('polls', operations, Object.fromEntries(operations.map(op => [op, true]))), 'custom')
	assert.equal(root.McpAdminLevels.tierNames('polls', 3), null)
	assert.equal(root.McpAdminLevels.tierNames('polls', 4), null)
})

test('Polls presets come from admin-levels alone, and an unknown level grants nothing', () => {
	const root = { t: (_, text) => text }
	vm.runInNewContext(require('./AdminManifest.cjs').levelsSource(), root)
	const levels = root.McpAdminLevels
	const operations = ['read', 'results', 'links', 'create']
	assert.ok(root.McpAdminPollsGrants)
	assert.deepEqual([...levels.levels('polls', operations)], ['none', 'polls_read', 'polls_read_create', 'polls_read_results', 'polls_read_results_links'])
	assert.deepEqual([...levels.operationsFor('polls', operations, 'none')], [])
	assert.throws(() => levels.operationsFor('polls', operations, 'full'), /Unknown level/)
	assert.equal(levels.levelOf('polls', operations, Object.fromEntries(operations.map(op => [op, false]))), 'none')
	assert.equal(root.McpAdminLevels.tierNames('polls', 3), null)
	assert.equal(root.McpAdminLevels.tierNames('polls', 4), null)
})

test('partial creation message is translated consistently and differs from uncertain in all supported locales', () => {
	const partial = 'Poll creation stopped after partial progress. Confirmed items are shown only while authorized. No automatic retry or rollback. Check Polls before repeating.'
	const uncertain = 'Poll creation is uncertain; do not repeat automatically. Check Polls before trying again.'
	for (const lang of ['pt_BR', 'es', 'de']) {
		const json = JSON.parse(require('./AdminTemplates.cjs').read(path.join(__dirname, '../../l10n', lang + '.json'), 'utf8')).translations
		let js
		vm.runInNewContext(require('./AdminTemplates.cjs').read(path.join(__dirname, '../../l10n', lang + '.js'), 'utf8'), { OC: { L10N: { register: (_, map) => { js = map } } } })
		assert.ok(json[partial], lang)
		assert.equal(js[partial], json[partial], lang)
		assert.notEqual(json[partial], partial, lang)
		assert.notEqual(json[partial], json[uncertain], lang)
	}
})
