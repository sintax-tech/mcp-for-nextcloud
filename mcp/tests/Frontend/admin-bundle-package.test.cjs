/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * The admin bundle as it ships (RE-11): js/mcp-admin.js is read out of the package built by scripts/package.sh, not
 * from the sources, and must be exactly the manifest's fragments joined as the builder joins them; then it runs on a
 * page without the admin markup and installs the same access levels as the sources.
 *
 * The archive is $MCP_PACKAGE, or build/mcp-<version>.tar.gz for the version in appinfo/info.xml. Without one the
 * tests are skipped with the reason, so a run of tests/Frontend alone stays usable; the release gate runs them after
 * scripts/package.sh.
 */
'use strict'

const test = require('node:test')
const assert = require('node:assert')
const fs = require('node:fs')
const path = require('node:path')
const vm = require('node:vm')
const AdminManifest = require('./AdminManifest.cjs')
const { extractMember } = require('./extract-bundle.cjs')

const app = AdminManifest.app()
const version = /<version>([^<]+)<\/version>/.exec(fs.readFileSync(path.join(app, 'appinfo/info.xml'), 'utf8'))[1]
const archive = process.env.MCP_PACKAGE || path.join(app, 'build', 'mcp-' + version + '.tar.gz')
const skip = fs.existsSync(archive) ? false : 'no package at ' + archive + ': run scripts/package.sh first'

/**
 * A page with none of the admin elements: every admin script looks its root up and returns, so the bundle only
 * installs its globals.
 *
 * @return {object} the global object of the page
 */
function emptyPage() {
	const noop = () => {}
	const document = {
		readyState: 'complete',
		getElementById: () => null,
		querySelector: () => null,
		querySelectorAll: () => [],
		addEventListener: noop,
		removeEventListener: noop,
		createElement: () => ({ setAttribute: noop, appendChild: noop, classList: { add: noop, remove: noop, toggle: noop }, style: {}, dataset: {} }),
		documentElement: { dataset: {} },
	}
	const page = { document, t: (app, text) => text, n: (app, one) => one, OC: { generateUrl: (url) => url, requestToken: 'token' }, OCP: {}, location: { hash: '', search: '' }, history: { replaceState: noop }, addEventListener: noop, removeEventListener: noop, setTimeout, clearTimeout, confirm: () => true, console, fetch: async () => ({ ok: false, status: 503, json: async () => ({}) }), MutationObserver: class { observe() {} disconnect() {} } }
	page.window = page
	return page
}

test('the packaged admin bundle is the manifest joined as the builder joins it', { skip }, () => {
	const shipped = extractMember(archive, 'mcp/js/mcp-admin.js')
	const expected = AdminManifest.fragments().map((fragment) => '/* ' + fragment + ' */\n' + AdminManifest.fragment(fragment)).join(';\n')
	assert.equal(shipped, expected)
})

test('the packaged admin bundle installs the same access levels as the sources', { skip }, () => {
	const page = emptyPage()
	vm.runInNewContext(extractMember(archive, 'mcp/js/mcp-admin.js'), page)
	const sources = emptyPage()
	vm.runInNewContext(AdminManifest.levelsSource(), sources)
	const plain = (value) => JSON.parse(JSON.stringify(value))
	assert.deepEqual(plain(page.McpAdminLevels.TIERS), plain(sources.McpAdminLevels.TIERS))
	assert.deepEqual(plain(page.McpAdminLevels.MODULE_TIER_NAMES), plain(sources.McpAdminLevels.MODULE_TIER_NAMES))
	for (const module of Object.keys(sources.McpAdminLevels.TIERS)) {
		const operations = sources.McpAdminLevels.TIERS[module].flat()
		assert.deepEqual(plain(page.McpAdminLevels.levels(module, operations)), plain(sources.McpAdminLevels.levels(module, operations)), module)
	}
	assert.deepEqual(plain(page.McpAdminPollsGrants.levelLabels()), plain(sources.McpAdminPollsGrants.levelLabels()))
	assert.equal(page.McpAdminMailGrants.writeWarning([{ module: 'mail', operation: 'send' }]), sources.McpAdminMailGrants.writeWarning([{ module: 'mail', operation: 'send' }]))
})
