/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * The admin bundle as it ships (RE-11): js/mcp-admin.js is read out of the package built by scripts/package.sh, not
 * from the sources, and must be exactly the manifest's fragments joined as the builder joins them; then it runs on a
 * page without the admin markup and installs the same access levels as the sources.
 *
 * The archive is $MCP_PACKAGE, the package scripts/package.sh just built. When the variable is set, a missing or
 * unreadable archive fails the tests: a wrong path never passes as a skip. Only when it is not set at all are they
 * skipped, with the reason, so a run of tests/Frontend alone stays usable; the release gate sets it and accepts no
 * skip. There is no fallback to an archive found in build/, which could be an older package than the one under test.
 * The authoritative check of the package is scripts/check_package.php (PHP); this one is a verification on top.
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
const archive = process.env.MCP_PACKAGE === undefined ? undefined : path.resolve(process.env.MCP_PACKAGE)
const skip = archive === undefined ? 'MCP_PACKAGE is not set: run scripts/package.sh and set it to the archive' : false

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

test('MCP_PACKAGE names a readable package archive', { skip }, () => {
	assert.notEqual(process.env.MCP_PACKAGE, '', 'MCP_PACKAGE is set but empty')
	assert.ok(fs.existsSync(archive), 'MCP_PACKAGE names no file: ' + archive)
	assert.ok(fs.statSync(archive).isFile(), 'MCP_PACKAGE is not a file: ' + archive)
	fs.accessSync(archive, fs.constants.R_OK)
})

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
