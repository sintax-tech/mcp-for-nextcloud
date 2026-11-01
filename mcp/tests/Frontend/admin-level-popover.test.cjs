/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Behavioral tests of src/admin/admin-level-popover.js (S3 of the admin redesign) with Node's built-in runner and a minimal DOM.
 * The script runs unchanged against a stub of window.McpAdminMatrix: a level becomes one all-or-nothing command of the
 * operations that change, "inherit" where the preview says that is what the person inherits, from the revision of the
 * table on screen, with the receipt offered as the undo; taking "Can connect" away is confirmed first.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('node:vm')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const plain = (value) => JSON.parse(JSON.stringify(value))
const tick = async () => { for (let i = 0; i < 12; i++) await new Promise(setImmediate) }
const REVISION = '9007199254740993'
const CATALOG = { files: ['read', 'edit', 'create', 'move', 'restore', 'share', 'link'], logs: ['read'] }

class Element {
	constructor(tag, document) {
		this.tag = tag
		this.document = document
		this.children = []
		this.attributes = {}
		this.listeners = {}
		this.classes = new Set()
		this.dataset = {}
		this.style = {}
		this.hidden = false
		this.disabled = false
		this.checked = false
		this.open = false
		this.parent = null
		this.isConnected = true
		this._text = ''
	}
	set className(value) { this.classes = new Set(String(value).split(/\s+/).filter(Boolean)) }
	get className() { return [...this.classes].join(' ') }
	get classList() { return { add: (name) => this.classes.add(name), contains: (name) => this.classes.has(name) } }
	append(...children) {
		for (const child of children) {
			if (typeof child !== 'string') child.parent = this
			this.children.push(child)
		}
	}
	replaceChildren(...children) { this.children = []; this._text = ''; this.append(...children) }
	setAttribute(key, value) { this.attributes[key] = String(value) }
	getAttribute(key) { return this.attributes[key] ?? null }
	addEventListener(name, action) { (this.listeners[name] ||= []).push(action) }
	async fire(name, event = {}) { for (const action of this.listeners[name] || []) await action(event) }
	focus() { this.document.activeElement = this }
	contains(node) { for (let at = node; at; at = at.parent) if (at === this) return true; return false }
	getBoundingClientRect() { return { top: 0, left: 0, bottom: 20, width: 800 } }
	get offsetParent() { return this.parent }
	get offsetWidth() { return 380 }
	set textContent(value) { this._text = String(value); this.children = [] }
	get textContent() { return this._text + this.children.map((child) => typeof child === 'string' ? child : child.textContent).join('') }
	all() { return this.children.flatMap((child) => typeof child === 'string' ? [] : [child, ...child.all()]) }
	querySelector(selector) {
		if (selector === 'input:checked') return this.all().find((node) => node.tag === 'input' && node.checked) ?? null
		if (selector === 'input' || selector === 'button') return this.all().find((node) => node.tag === selector) ?? null
		throw new Error('unsupported selector ' + selector)
	}
	async click() {
		if (this.disabled) return
		if (this.tag === 'input' && this.type === 'radio') {
			this.checked = true
			await this.fire('change')
			return
		}
		await this.onclick?.()
	}
}

/** One user row of the API; `sources` maps an operation ("eligible" for Can connect) to its origin. */
function user(uid, grants, sources = {}, eligible = true) {
	const origin = (key) => sources[key] || { source: 'default', groupIds: [] }
	return {
		uid,
		displayName: uid === 'ana' ? 'Ana Souza' : uid,
		eligible,
		grants: { files: Object.fromEntries(CATALOG.files.map((operation) => [operation, grants.includes(operation)])), logs: { read: false } },
		origins: {
			eligible: origin('eligible'),
			grants: { files: Object.fromEntries(CATALOG.files.map((operation) => [operation, origin(operation)])), logs: { read: origin('logs') } },
		},
	}
}

const individualAllow = { source: 'individual_allow', groupIds: [] }
const individualDeny = { source: 'individual_deny', groupIds: [] }

function setup({ users, preview, commands, confirm = true } = {}) {
	const document = {
		activeElement: null,
		listeners: {},
		createElement: (tag) => new Element(tag, document),
		getElementById: (id) => id === 'mcp-level-popover' ? popover : null,
		addEventListener: (name, action) => { (document.listeners[name] ||= []).push(action) },
	}
	const popover = new Element('div', document)
	popover.hidden = true
	popover.parent = new Element('div', document)
	const calls = []
	const notices = []
	const errors = []
	const confirms = []
	const busy = []
	let reloads = 0
	const data = { revision: REVISION, catalog: CATALOG, users: users || [user('ana', ['read', 'edit'], { edit: individualAllow })] }
	const matrix = {
		data: () => data,
		labels: () => ({ modules: { files: 'Files', logs: 'Server log' }, operations: { read: 'read', edit: 'edit', create: 'create', move: 'move', restore: 'restore', share: 'share', link: 'public link' } }),
		api: async (method, url, body) => {
			calls.push({ method, url, body: plain(body) })
			if (url === '/api/grants/commands/preview') {
				if (preview instanceof Error) throw preview
				return preview ? preview(body) : { revision: REVISION, cells: [] }
			}
			if (commands instanceof Error) throw commands
			return commands || { revision: '9007199254740994', changed: body.commands.length, receiptId: 'r-1' }
		},
		announce: (from, to) => calls.push({ announce: [from, to] }),
		isBusy: () => busy.at(-1) === true,
		setBusy: (on) => busy.push(on),
		offerUndo: (message, saved) => notices.push({ message, saved: plain(saved) }),
		policyError: async (e) => e.message === '409',
		notifyError: (message) => errors.push(message),
		reload: async () => { reloads++ },
	}
	const window = {
		McpAdminMatrix: matrix,
		McpAdminLevels: undefined,
		addEventListener: () => {},
		confirm: (text) => { confirms.push(text); return confirm },
	}
	const context = { window, document, t: (app, text, vars) => text.replace(/\{(\w+)\}/g, (_, key) => vars?.[key] ?? '{' + key + '}') }
	vm.runInNewContext(require('./AdminManifest.cjs').levelsSource(), context)
	vm.runInNewContext(require('./AdminManifest.cjs').script('admin-level-popover.js'), context)
	const trigger = new Element('button', document)
	return { api: window.McpLevelPopover, popover, trigger, document, calls, notices, errors, confirms, busy, data, reloads: () => reloads }
}

/** The radio of one level in the open popover. */
const radio = (kit, level) => kit.popover.all().find((node) => node.tag === 'input' && node.value === level)
const posts = (kit) => kit.calls.filter((call) => call.url === '/api/grants/commands')

test('a level sends only the operations that change, and an operation it takes away is always an explicit deny', () => {
	const kit = setup()
	const ana = kit.data.users[0]
	// Ana: read by default, edit by an individual Allow. What she inherits without it: read allowed, edit denied.
	const inherited = kit.api.inheritedValues(ana, 'files', [{ uid: 'ana', module: 'files', operation: 'edit', after: { allowed: false, source: 'default', groupIds: [] } }])
	// Even though she would inherit edit denied, taking it away is a deny: never an inherit a group could turn into an Allow.
	assert.deepEqual(plain(kit.api.planLevel(ana, 'files', 'read', inherited)), [{ uid: 'ana', module: 'files', operation: 'edit', state: 'deny' }])
	assert.deepEqual(plain(kit.api.planLevel(ana, 'files', 'none', inherited)), [
		{ uid: 'ana', module: 'files', operation: 'read', state: 'deny' },
		{ uid: 'ana', module: 'files', operation: 'edit', state: 'deny' },
	])
	assert.deepEqual(plain(kit.api.planLevel(ana, 'files', 'edit', inherited).map((c) => c.operation + ':' + c.state)), ['create:allow', 'move:allow', 'restore:allow'])
	assert.deepEqual(plain(kit.api.planLevel(ana, 'files', 'read', null)), [{ uid: 'ana', module: 'files', operation: 'edit', state: 'deny' }])
})

test('F1: group membership that changes within the same revision never turns a removal into kept access', async () => {
	// The preview, read at revision R, says Ana inherits edit denied. Before the command lands she joins a group that grants
	// edit, which does not have to move R. An "inherit" would then remove her Allow and the group would keep edit; a deny
	// takes it away whatever the groups say by then.
	const kit = setup({ preview: (body) => ({ revision: REVISION, cells: body.commands.map((c) => ({ ...c, before: { allowed: true }, after: { allowed: false, source: 'default', groupIds: [] } })) }) })
	kit.api.open(kit.trigger, 'ana', 'files')
	await tick()
	assert.match(kit.popover.textContent, /Inherits: Read \(Default\)/, 'the preview said edit is not inherited')
	await radio(kit, 'read').click()
	await tick()
	const sent = posts(kit)[0].body
	assert.equal(sent.expectedRevision, REVISION)
	assert.deepEqual(sent.commands, [{ uid: 'ana', module: 'files', operation: 'edit', state: 'deny' }])
	assert.ok(sent.commands.every((command) => command.state !== 'inherit'), 'a lower level never sends inherit')

	const none = setup({ preview: (body) => ({ revision: REVISION, cells: body.commands.map((c) => ({ ...c, after: { allowed: false, source: 'default', groupIds: [] } })) }) })
	none.api.open(none.trigger, 'ana', 'files')
	await tick()
	await radio(none, 'none').click()
	await tick()
	assert.deepEqual(posts(none)[0].body.commands.map((c) => c.operation + ':' + c.state), ['read:deny', 'edit:deny'])
})

test('giving an operation back to inheritance only happens when the preview says it is inherited allowed', () => {
	const kit = setup()
	// Ana denied edit individually; the groups would give it.
	const ana = user('ana', ['read'], { edit: individualDeny })
	const allowedByGroup = kit.api.inheritedValues(ana, 'files', [{ uid: 'ana', module: 'files', operation: 'edit', after: { allowed: true, source: 'group', groupIds: ['comercial'] } }])
	const edit = kit.api.planLevel(ana, 'files', 'edit', allowedByGroup)
	assert.deepEqual(plain(edit.find((c) => c.operation === 'edit')), { uid: 'ana', module: 'files', operation: 'edit', state: 'inherit' })
	assert.ok(edit.filter((c) => c.operation !== 'edit').every((c) => c.state === 'allow'), 'cells without an individual value are explicit')
	const unknown = kit.api.inheritedValues(ana, 'files', [{ uid: 'ana', module: 'files', operation: 'edit', after: { allowed: null, source: 'unknown', groupIds: [] } }])
	assert.equal(kit.api.planLevel(ana, 'files', 'edit', unknown).find((c) => c.operation === 'edit').state, 'allow')
	assert.equal(kit.api.planLevel(ana, 'files', 'edit', null).find((c) => c.operation === 'edit').state, 'allow')
})

test('inherit, one operation and the eligibility check', () => {
	const kit = setup()
	const ana = user('ana', ['read', 'edit'], { edit: individualAllow, share: { source: 'invalid', groupIds: [] }, eligible: individualAllow })
	assert.deepEqual(plain(kit.api.planInherit(ana, 'files').map((c) => c.operation)), ['edit', 'share'])
	assert.deepEqual(plain(kit.api.planOperation(ana, 'files', 'edit', 'allow')), [], 'already an individual Allow')
	assert.deepEqual(plain(kit.api.planOperation(ana, 'files', 'read', 'deny')), [{ uid: 'ana', module: 'files', operation: 'read', state: 'deny' }])
	assert.deepEqual(plain(kit.api.planOperation(ana, 'files', 'share', 'deny')), [], 'an invalid value already reads as Deny')
	const deny = [{ uid: 'ana', module: null, operation: 'eligible', state: 'deny' }]
	const inherit = [{ uid: 'ana', module: null, operation: 'eligible', state: 'inherit' }]
	assert.equal(kit.api.losesEligibility(ana, deny, null), true)
	assert.equal(kit.api.losesEligibility(ana, inherit, { eligible: { allowed: false } }), true)
	assert.equal(kit.api.losesEligibility(ana, inherit, null), true, 'an unknown result counts as taken away')
	assert.equal(kit.api.losesEligibility(ana, inherit, { eligible: { allowed: true } }), false, 'a group keeps it')
	assert.equal(kit.api.losesEligibility({ ...ana, eligible: false }, deny, null), false)
	assert.deepEqual(plain(kit.api.inheritedValues(ana, 'files', [])).edit, { allowed: null, source: 'unknown', groupIds: [] }, 'no answer for a cell is unknown')
})

test('opening asks the preview for the individual cells only, then a level is one command from the table revision with an undo', async () => {
	const kit = setup({ preview: (body) => ({ revision: REVISION, cells: body.commands.map((c) => ({ ...c, before: { allowed: true }, after: { allowed: false, source: 'default', groupIds: [] } })) }) })
	kit.api.open(kit.trigger, 'ana', 'files')
	assert.equal(kit.popover.hidden, false)
	assert.equal(kit.trigger.getAttribute('aria-expanded'), 'true')
	assert.equal(kit.document.activeElement, radio(kit, 'custom') ?? kit.popover.querySelector('input'), 'the focus is in the popover')
	await tick()
	assert.deepEqual(kit.calls[0], { method: 'POST', url: '/api/grants/commands/preview', body: { commands: [{ uid: 'ana', module: 'files', operation: 'edit', state: 'inherit' }] } })
	assert.match(kit.popover.textContent, /Inherits: Read \(Default\)/)
	assert.match(kit.popover.textContent, /Files of Ana Souza/)
	await radio(kit, 'read').click()
	await tick()
	assert.deepEqual(posts(kit).map((call) => call.body), [{ expectedRevision: REVISION, commands: [{ uid: 'ana', module: 'files', operation: 'edit', state: 'deny' }] }])
	assert.ok(kit.calls.some((call) => call.announce && call.announce[0] === REVISION && call.announce[1] === '9007199254740994'))
	assert.deepEqual(kit.notices, [{ message: 'Files of Ana Souza: Read.', saved: { revision: '9007199254740994', changed: 1, receiptId: 'r-1' } }])
	assert.equal(kit.reloads(), 1)
	assert.deepEqual(kit.busy, [true, false], 'the matrix is locked while saving and released after')
	assert.equal(kit.popover.hidden, true)
})

test('a preview read at another revision is not used: the level is sent as allow or deny', async () => {
	const kit = setup({ preview: () => ({ revision: '1', cells: [{ uid: 'ana', module: 'files', operation: 'edit', after: { allowed: false } }] }) })
	kit.api.open(kit.trigger, 'ana', 'files')
	await tick()
	assert.match(kit.popover.textContent, /What this person inherits could not be read\./)
	await radio(kit, 'read').click()
	await tick()
	assert.deepEqual(posts(kit)[0].body.commands, [{ uid: 'ana', module: 'files', operation: 'edit', state: 'deny' }])
})

test('a conflict saves nothing, offers no undo and leaves the reload to the conflict handler', async () => {
	const kit = setup({ commands: new Error('409') })
	kit.api.open(kit.trigger, 'ana', 'files')
	await tick()
	await radio(kit, 'none').click()
	await tick()
	assert.equal(posts(kit).length, 1)
	assert.deepEqual(kit.notices, [])
	assert.equal(kit.reloads(), 0, 'policyError() reloads by itself')
	assert.deepEqual(kit.errors, [])
	assert.deepEqual(kit.busy, [true, false])
})

test('another failure is reported and the table reloaded with the saved values', async () => {
	const kit = setup({ commands: new Error('500') })
	kit.api.open(kit.trigger, 'ana', 'files')
	await tick()
	await radio(kit, 'none').click()
	await tick()
	assert.deepEqual(kit.errors, ['Could not save the permission for Ana Souza.'])
	assert.equal(kit.reloads(), 1)
})

test('taking "Can connect" away asks first, in words, and sends nothing without a yes', async () => {
	const users = [user('ana', ['read'], { eligible: { source: 'group', groupIds: ['comercial'] } })]
	const kit = setup({ users, confirm: false })
	kit.api.open(kit.trigger, 'ana', null)
	await tick()
	assert.equal(kit.calls.length, 0, 'nothing individual to preview')
	assert.match(kit.popover.textContent, /“Can connect” for Ana Souza/)
	assert.deepEqual(plain(kit.popover.all().filter((node) => node.tag === 'input').map((node) => node.value)), ['read', 'none'])
	await radio(kit, 'none').click()
	await tick()
	assert.deepEqual(kit.confirms, ['Turn off “Can connect” for Ana Souza?\nThis signs the person out of every MCP client, and undoing it does not sign them back in.'])
	assert.equal(posts(kit).length, 0)
	assert.equal(kit.popover.hidden, false, 'a no keeps the popover open')

	const yes = setup({ users })
	yes.api.open(yes.trigger, 'ana', null)
	await tick()
	await radio(yes, 'none').click()
	await tick()
	assert.equal(yes.confirms.length, 1)
	assert.deepEqual(posts(yes)[0].body.commands, [{ uid: 'ana', module: null, operation: 'eligible', state: 'deny' }])
	assert.deepEqual(yes.notices.map((notice) => notice.message), ['Can connect of Ana Souza: No.'])
})

test('turning "Can connect" on never asks', async () => {
	const kit = setup({ users: [user('ana', ['read'], {}, false)], confirm: false })
	kit.api.open(kit.trigger, 'ana', null)
	await tick()
	await radio(kit, 'read').click()
	await tick()
	assert.deepEqual(kit.confirms, [])
	assert.deepEqual(posts(kit)[0].body.commands, [{ uid: 'ana', module: null, operation: 'eligible', state: 'allow' }])
})

test('each operation shows Inherit, Allow and Deny; the pressed one has a check mark besides aria-pressed', async () => {
	const kit = setup({ users: [user('ana', ['read', 'edit'], { edit: individualAllow, read: individualDeny })] })
	kit.api.open(kit.trigger, 'ana', 'files')
	await tick()
	const groups = kit.popover.all().filter((node) => node.classes.has('mcp-tri-group'))
	assert.equal(groups.length, CATALOG.files.length)
	const edit = groups.find((group) => group.attributes['aria-label'] === 'edit')
	const [inherit, allow, deny] = edit.children
	assert.equal(allow.getAttribute('aria-pressed'), 'true')
	assert.equal(inherit.getAttribute('aria-pressed'), 'false')
	assert.equal(deny.getAttribute('aria-pressed'), 'false')
	const mark = allow.all().find((node) => node.classes.has('mcp-pressed-mark'))
	assert.equal(mark.textContent, '✓ ')
	assert.equal(mark.getAttribute('aria-hidden'), 'true')
	assert.equal(inherit.all().some((node) => node.classes.has('mcp-pressed-mark')), false)
	await deny.click()
	await tick()
	assert.deepEqual(posts(kit)[0].body.commands, [{ uid: 'ana', module: 'files', operation: 'edit', state: 'deny' }])
	assert.deepEqual(kit.notices.map((notice) => notice.message), ['Files of Ana Souza: edit set to Deny.'])
})

test('the inherit button gives back exactly the individual values, and is off without any', async () => {
	const kit = setup()
	kit.api.open(kit.trigger, 'ana', 'files')
	await tick()
	const button = kit.popover.all().find((node) => node.classes.has('mcp-popover-inherit-all'))
	assert.equal(button.disabled, false)
	await button.click()
	await tick()
	assert.deepEqual(posts(kit)[0].body.commands, [{ uid: 'ana', module: 'files', operation: 'edit', state: 'inherit' }])
	assert.deepEqual(kit.notices.map((notice) => notice.message), ['Files of Ana Souza inherits again.'])

	const none = setup({ users: [user('ana', ['read'])] })
	none.api.open(none.trigger, 'ana', 'files')
	await tick()
	assert.equal(none.popover.all().find((node) => node.classes.has('mcp-popover-inherit-all')).disabled, true)
})

test('Escape closes the popover and gives the focus back to the cell', async () => {
	const kit = setup()
	kit.api.open(kit.trigger, 'ana', 'files')
	await tick()
	let prevented = false
	await kit.popover.fire('keydown', { key: 'Escape', preventDefault: () => { prevented = true } })
	assert.ok(prevented)
	assert.equal(kit.popover.hidden, true)
	assert.equal(kit.trigger.getAttribute('aria-expanded'), 'false')
	assert.equal(kit.document.activeElement, kit.trigger)
	// Opening the same cell again while it is open closes it.
	kit.api.open(kit.trigger, 'ana', 'files')
	kit.api.open(kit.trigger, 'ana', 'files')
	assert.equal(kit.popover.hidden, true)
})

test('choosing the level the person already has sends nothing', async () => {
	const kit = setup({ users: [user('ana', ['read'])] })
	kit.api.open(kit.trigger, 'ana', 'files')
	await tick()
	await radio(kit, 'read').click()
	await tick()
	assert.equal(posts(kit).length, 0)
	assert.deepEqual(kit.notices.map((notice) => notice.message), ['Nothing changed.'])
})

test('the focus stays on the same control when the preview answer draws the popover again', async () => {
	// Nothing individual: the preview is skipped and the popover is drawn again at once, while the focus is on its radio.
	const kit = setup({ users: [user('ana', ['read'])] })
	kit.api.open(kit.trigger, 'ana', 'files')
	await tick()
	const focused = kit.document.activeElement
	assert.ok(kit.popover.contains(focused), 'the focus is inside the popover after the redraw')
	assert.equal(focused.value, 'read', 'on the radio of the current level')
	assert.equal(focused, radio(kit, 'read'), 'the control drawn last, not a removed one')

	// With a preview: the focus moved to an operation button before the answer comes back stays there.
	let answer
	const slow = setup({ preview: () => new Promise((resolve) => { answer = resolve }) })
	slow.api.open(slow.trigger, 'ana', 'files')
	const deny = slow.popover.all().find((node) => node.dataset.focusKey === 'operation:edit:deny')
	deny.focus()
	answer({ revision: REVISION, cells: [] })
	await tick()
	assert.equal(slow.document.activeElement.dataset.focusKey, 'operation:edit:deny')
	assert.ok(slow.popover.contains(slow.document.activeElement))
})

test('F1: "Can connect" No is always a deny, asked first; Yes goes back to inheritance only when it is inherited Yes', async () => {
	const users = [user('ana', ['read'], { eligible: individualAllow })]
	const no = setup({ users, preview: (body) => ({ revision: REVISION, cells: body.commands.map((c) => ({ ...c, after: { allowed: false, source: 'default', groupIds: [] } })) }) })
	no.api.open(no.trigger, 'ana', null)
	await tick()
	await radio(no, 'none').click()
	await tick()
	assert.equal(no.confirms.length, 1, 'taking it away is still asked')
	assert.deepEqual(posts(no)[0].body.commands, [{ uid: 'ana', module: null, operation: 'eligible', state: 'deny' }], 'never inherit, though No is what she inherits')

	const denied = [user('ana', ['read'], { eligible: individualDeny }, false)]
	const yes = setup({ users: denied, preview: (body) => ({ revision: REVISION, cells: body.commands.map((c) => ({ ...c, after: { allowed: true, source: 'group', groupIds: ['comercial'] } })) }) })
	yes.api.open(yes.trigger, 'ana', null)
	await tick()
	await radio(yes, 'read').click()
	await tick()
	assert.deepEqual(posts(yes)[0].body.commands, [{ uid: 'ana', module: null, operation: 'eligible', state: 'inherit' }])
	assert.deepEqual(yes.confirms, [])
})

test('P2 focus: the popover is brought into view whole when it opens; its height is capped and it scrolls inside', async () => {
	const kit = setup({ users: [user('ana', ['read'])] })
	const asked = []
	kit.popover.scrollIntoView = (options) => asked.push(options)
	kit.api.open(kit.trigger, 'ana', 'files')
	await new Promise(setImmediate)
	assert.ok(asked.length >= 1, 'the page moves only as far as showing it takes')
	assert.deepEqual(JSON.parse(JSON.stringify(asked[0])), { block: 'nearest', inline: 'nearest' })
	const opened = asked.length
	await kit.popover.fire('toggle')
	assert.equal(asked.length, opened + 1, 'opening "Operations" brings the taller popover back into view')
	const css = fs.readFileSync(path.join(root, 'css/admin.css'), 'utf8')
	assert.match(css, /\.mcp-popover \{[^}]*max-height: calc\(100vh - var\(--header-height, 50px\) - var\(--default-clickable-area, 34px\) - 24px\);[^}]*overflow-y: auto;[^}]*scroll-margin-block: calc\(var\(--default-clickable-area, 34px\) \+ 12px\) 8px;/s)
})
