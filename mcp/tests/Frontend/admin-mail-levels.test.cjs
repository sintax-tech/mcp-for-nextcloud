/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Tests of the Mail levels and tooltips of the admin page: src/admin/admin-levels.js gives Mail its own level names, and
 * src/admin/admin-levels.js also describes the seven Mail operations and warns before a Mail write is granted. The levels are None, Accounts and folders, Messages, Messages and attachments, Messages,
 * attachments and flags, Messages, attachments, flags and moving, the same with drafts, and with sending, never Edit or Full: no level
 * that existed before names a newer operation, and an operation without the ones before it stays a custom choice.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const plain = (value) => JSON.parse(JSON.stringify(value))

/**
 * The admin scripts run in one context, in the order the template loads them.
 *
 * @param {string[]} files scripts under src/admin/
 * @return {object} the context, with t() returning its source text
 */
function load(files) {
	const context = { t: (app, text) => text }
	for (const file of files) {
		vm.runInNewContext(require('./AdminManifest.cjs').script(file), context)
	}
	return context
}

/** @return {string[]} GrantPolicy::CATALOG['mail'], read from the PHP source */
function mailOperations() {
	const policy = fs.readFileSync(path.join(root, 'lib/Service/GrantPolicy.php'), 'utf8')
	const line = policy.match(/'mail' => \[([^\]]*)\]/)
	assert.ok(line, 'the Mail entry of GrantPolicy::CATALOG')
	return [...line[1].matchAll(/'(\w+)'/g)].map((match) => match[1])
}

test('Mail levels are None, Accounts and folders, Messages, Messages and attachments, with flags, with moving, with drafts and with sending', () => {
	const Levels = load(['admin-levels.js']).McpAdminLevels
	const operations = mailOperations()
	assert.deepEqual(operations, ['read', 'messages', 'attachments', 'flags', 'move', 'draft', 'send'])
	assert.deepEqual(plain(Levels.levels('mail', operations)), ['none', 'folders', 'messages', 'attachments', 'flags', 'move', 'draft', 'send'])
	assert.deepEqual(plain(Levels.operationsFor('mail', operations, 'folders')), ['read'])
	assert.deepEqual(plain(Levels.operationsFor('mail', operations, 'messages')), ['read', 'messages'], 'Messages never includes attachments')
	assert.deepEqual(plain(Levels.operationsFor('mail', operations, 'attachments')), ['read', 'messages', 'attachments'], 'Messages and attachments never includes flags')
	assert.deepEqual(plain(Levels.operationsFor('mail', operations, 'flags')), ['read', 'messages', 'attachments', 'flags'], 'flags never includes moving')
	assert.deepEqual(plain(Levels.operationsFor('mail', operations, 'move')), ['read', 'messages', 'attachments', 'flags', 'move'], 'moving never includes drafts')
	assert.deepEqual(plain(Levels.operationsFor('mail', operations, 'draft')), ['read', 'messages', 'attachments', 'flags', 'move', 'draft'], 'drafts never includes sending')
	assert.deepEqual(plain(Levels.operationsFor('mail', operations, 'send')), ['read', 'messages', 'attachments', 'flags', 'move', 'draft', 'send'])
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: false, attachments: false, flags: false, move: false, draft: false, send: false }), 'folders')
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: true, attachments: false, flags: false, move: false, draft: false, send: false }), 'messages')
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: true, attachments: true, flags: false, move: false, draft: false, send: false }), 'attachments')
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: true, attachments: true, flags: true, move: false, draft: false, send: false }), 'flags')
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: true, attachments: true, flags: true, move: true, draft: false, send: false }), 'move')
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: false, attachments: true, flags: false, move: false, draft: false, send: false }), 'custom', 'attachments alone is a granular choice')
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: false, attachments: false, flags: true, move: false, draft: false, send: false }), 'custom', 'flags alone is a granular choice')
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: false, attachments: false, flags: false, move: true, draft: false, send: false }), 'custom', 'moving alone is a granular choice')
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: true, attachments: true, flags: true, move: true, draft: true, send: false }), 'draft')
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: false, attachments: false, flags: false, move: false, draft: true, send: false }), 'custom', 'drafts alone is a granular choice')
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: true, attachments: true, flags: true, move: true, draft: true, send: true }), 'send')
	assert.equal(Levels.levelOf('mail', operations, { read: true, messages: false, attachments: false, flags: false, move: false, draft: false, send: true }), 'custom', 'sending alone is a granular choice')
	assert.throws(() => Levels.operationsFor('mail', operations, 'full'), /Unknown level/)
})

test('a Mail policy stored before the attachments grant keeps its level without attachments', () => {
	const Levels = load(['admin-levels.js']).McpAdminLevels
	// The attachments, flags, move, draft and send values of an old policy are the default, denied, so its level is unchanged.
	assert.equal(Levels.levelOf('mail', mailOperations(), { read: true, messages: true, attachments: false, flags: false, move: false, draft: false, send: false }), 'messages')
	assert.equal(Levels.levelOf('mail', mailOperations(), { read: true, messages: false, attachments: false, flags: false, move: false, draft: false, send: false }), 'folders')
	assert.equal(Levels.levelOf('mail', mailOperations(), { read: true, messages: true, attachments: true, flags: false, move: false, draft: false, send: false }), 'attachments')
	assert.equal(Levels.levelOf('mail', mailOperations(), { read: true, messages: true, attachments: true, flags: true, move: false, draft: false, send: false }), 'flags', 'a policy from before moving keeps its flags level')
	assert.equal(Levels.levelOf('mail', mailOperations(), { read: true, messages: true, attachments: true, flags: true, move: true, draft: false, send: false }), 'move', 'a policy from before drafts keeps its moving level')
	assert.equal(Levels.levelOf('mail', mailOperations(), { read: true, messages: true, attachments: true, flags: true, move: true, draft: true, send: false }), 'draft', 'a policy from before sending keeps its drafts level')
})

test('the Mail names apply only to the Mail tier count, and other modules keep the general names', () => {
	const Levels = load(['admin-levels.js']).McpAdminLevels
	assert.deepEqual(plain(Levels.tierNames('mail', 7)), ['folders', 'messages', 'attachments', 'flags', 'move', 'draft', 'send'])
	assert.equal(Levels.tierNames('mail', 3), null, 'a count that does not match falls back')
	assert.equal(Levels.tierNames('files', 3), null)
	assert.deepEqual(plain(Levels.levels('files', ['read', 'edit', 'share'])), ['none', 'read', 'edit', 'full'])
})

test('the Mail tooltips describe the seven operations and nothing else', () => {
	const Grants = load(['admin-levels.js']).McpAdminMailGrants
	assert.equal(Grants.operationHint('mail', 'read'), 'List mail accounts and folders, never messages')
	assert.equal(Grants.operationHint('mail', 'messages'), 'List, search and read messages, without marking them read; starts off')
	assert.equal(Grants.operationHint('mail', 'attachments'), 'Read the text of attachments, never the files, without marking messages read; starts off')
	assert.equal(Grants.operationHint('mail', 'flags'), 'Mark messages read or unread and flag them, on the mail server, after a plan the user confirms; starts off')
	assert.equal(Grants.operationHint('mail', 'move'), 'Move messages to another folder of the same account, on the mail server, after a plan the user confirms; starts off')
	assert.equal(Grants.operationHint('mail', 'draft'), 'Create new drafts in the Drafts folder, never sent, after a plan the user confirms; starts off')
	assert.equal(Grants.operationHint('mail', 'send'), 'Send new messages through the mail account, which cannot be undone, after a plan the user confirms; starts off')
	assert.equal(Grants.operationHint('mail', 'other'), null)
	assert.equal(Grants.operationHint('files', 'read'), null)
	const grants = require('./AdminManifest.cjs').script('admin-grants.js')
	assert.match(grants, /McpAdminMailGrants\.operationHint\(module, operation\)/)
	assert.match(grants, /folders: t\('mcp', 'Accounts and folders'\)/)
	assert.match(grants, /messages: t\('mcp', 'Messages'\)/)
	assert.match(grants, /attachments: t\('mcp', 'Messages and attachments'\)/)
	assert.match(grants, /attachments: t\('mcp', 'attachments'\)/)
	assert.match(grants, /flags: t\('mcp', 'Messages, attachments and flags'\)/)
	assert.match(grants, /flags: t\('mcp', 'flags'\)/)
	assert.match(grants, /move: t\('mcp', 'Messages, attachments, flags and moving'\)/)
	assert.match(grants, /move: t\('mcp', 'move'\)/)
	assert.match(grants, /draft: t\('mcp', 'Messages, attachments, flags, moving and drafts'\)/)
	assert.match(grants, /draft: t\('mcp', 'draft'\)/)
	assert.match(grants, /send: t\('mcp', 'Messages, attachments, flags, moving, drafts and sending'\)/)
	assert.match(grants, /send: t\('mcp', 'send'\)/)
	for (const file of ['admin-groups.js', 'admin-inspector.js']) {
		assert.match(require('./AdminManifest.cjs').script(file), /attachments: t\('mcp', 'attachments'\), flags: t\('mcp', 'flags'\), draft: t\('mcp', 'draft'\), send: t\('mcp', 'send'\)/, file)
	}
	const template = fs.readFileSync(path.join(root, 'templates/admin.php'), 'utf8')
	assert.equal(template.indexOf("'admin-level-names'"), -1, 'the level names live in the access levels (issue #21)')
	assert.equal(template.indexOf("'admin-mail-grants'"), -1, 'the Mail warnings live in src/admin/access/levels/mail.js')
	assert.ok(require('./AdminManifest.cjs').loadsBefore('admin/admin-levels.js', 'admin/admin-grants.js'), 'Mail tooltips load with the levels, before the grants')
})

test('granting a Mail write asks first, and only a Mail write does', () => {
	const asked = []
	const context = { t: (app, text) => text, confirm: (text) => { asked.push(text); return false } }
	vm.runInNewContext(require('./AdminManifest.cjs').script('admin-levels.js'), context)
	const Grants = context.McpAdminMailGrants
	assert.deepEqual(plain(Grants.WRITE_OPERATIONS), ['flags', 'move', 'draft', 'send'])
	assert.equal(Grants.writeWarning([{ module: 'mail', operation: 'messages' }, { module: 'files', operation: 'edit' }]), null)
	assert.match(Grants.writeWarning([{ module: 'mail', operation: 'flags' }]), /hidden instructions/)
	assert.match(Grants.writeWarning([{ module: 'mail', operation: 'move' }]), /move them to other folders/)
	assert.match(Grants.writeWarning([{ module: 'mail', operation: 'draft' }]), /save new drafts/)
	assert.match(Grants.writeWarning([{ module: 'mail', operation: 'send' }]), /send messages, which cannot be undone/)
	assert.equal(Grants.writeWarning([{ module: 'files', operation: 'move' }]), null, 'only the Mail move is a Mail write')
	assert.equal(Grants.confirmWrites([{ module: 'mail', operation: 'read' }]), true)
	assert.deepEqual(asked, [], 'no question for a read')
	assert.equal(Grants.confirmWrites([{ module: 'mail', operation: 'flags' }]), false, 'refused when the administrator says no')
	assert.equal(Grants.confirmWrites([{ module: 'mail', operation: 'move' }]), false)
	assert.equal(Grants.confirmWrites([{ module: 'mail', operation: 'draft' }]), false)
	assert.equal(Grants.confirmWrites([{ module: 'mail', operation: 'send' }]), false)
	assert.equal(asked.length, 4)
})

test('every way of granting asks before a Mail write: matrix cell, bulk, popover and group policy', () => {
	const read = (file) => require('./AdminManifest.cjs').script(file)
	assert.match(read('admin-grants.js'), /McpAdminMailGrants\.confirmWrites\(\[\{ module, operation: body\.operation \}\]\)/, 'matrix cell')
	assert.match(read('admin-grants.js'), /McpAdminMailGrants\.writeWarning\(plan\.changes\.filter/, 'bulk change')
	assert.match(read('admin-level-popover.js'), /McpAdminMailGrants\.confirmWrites\(commands\.filter\(\(command\) => command\.state === 'allow'\)\)/, 'popover')
	assert.match(read('admin-groups.js'), /action === 'save' && window\.McpAdminMailGrants && !window\.McpAdminMailGrants\.confirmWrites\(/, 'group policy')
})
