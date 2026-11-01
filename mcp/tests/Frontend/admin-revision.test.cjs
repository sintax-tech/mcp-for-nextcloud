/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Behavioral tests of src/admin/admin-grants.js found in the browser check of ticket 14 (T14): the server-log block follows the
 * revision moves of the other blocks of the page and announces its own (D1, D2), and every operation of the catalog has
 * a translated column label (D5). The pieces are cut out of the source and run against stubs, as in
 * admin-oauth-clients.test.cjs.
 */
const { test } = require('node:test')
const assert = require('node:assert/strict')
const vm = require('./AdminContext.cjs')
const fs = require('node:fs')
const path = require('node:path')

const root = path.join(__dirname, '../..')
const source = require('./AdminManifest.cjs').script('admin-grants.js')

/** The source of one function of the admin script, from its declaration to its closing brace. */
function cut(declaration) {
	const start = source.indexOf('\t' + declaration)
	assert.ok(start > 0, declaration + ' was found in src/admin/admin-grants.js')
	return source.slice(start, source.indexOf('\n\t}\n', start) + 4)
}
const helpersStart = source.indexOf('\t/** Page event of a write this page committed')
const helpers = source.slice(helpersStart, source.indexOf('\t/**\n\t * @param {HTMLElement} cell cell that shows the save result', helpersStart))
assert.ok(helpersStart > 0 && helpers.includes('function followRevision('), 'the revision helpers were found in src/admin/admin-grants.js')

/**
 * Runs the server-log block against a fake server. `serve({method, body})` answers [status, body]. The picker is a stub
 * that keeps its configuration, so a test calls its save() as the picker does.
 */
async function logsBlock(serve) {
	const log = { calls: [], errors: [], matrixReloads: 0, announced: [] }
	const window = new EventTarget()
	window.addEventListener('mcp-revision-moved', (event) => log.announced.push([event.detail.from, event.detail.to]))
	let config = null
	window.McpAdminPicker = { create: (options) => { config = options; return { setOptions() {}, showFallback() {} } } }
	const context = {
		window, CustomEvent,
		document: { getElementById: () => ({ setAttribute() {}, replaceChildren() {}, textContent: '' }) },
		t: (app, text) => text,
		n: (app, singular) => singular,
		el: () => ({}),
		notifyError: (message) => log.errors.push(message),
		load: () => { log.matrixReloads++ },
		api: async (method, apiPath, body) => {
			log.calls.push({ method, path: apiPath, body: body === undefined ? undefined : JSON.parse(JSON.stringify(body)) })
			const [status, data] = await serve({ method, body })
			if (status >= 400) throw Object.assign(new Error(String(status)), { data })
			return data
		},
	}
	vm.createContext(context)
	vm.runInContext(helpers + cut('async function initLogsAccess() {') + '\nthis.run = initLogsAccess', context)
	await context.run()
	const moved = (from, to) => window.dispatchEvent(new CustomEvent('mcp-revision-moved', { detail: { from, to } }))
	return { log, picker: () => config, moved }
}

const logsState = (revision, version, groups) => ({ revision, version, groups, missing: [], allGroups: [{ id: 'staff', displayName: 'Staff' }, { id: 'ops', displayName: 'Ops' }], available: true, logType: 'file' })

test('T14 D1/D2: a save of another block of this page is adopted, so the server-log groups are saved instead of refused', async () => {
	const { log, picker, moved } = await logsBlock(({ method, body }) => method === 'GET'
		? [200, logsState('5', 1, [])]
		: body.expectedRevision === '6' ? [200, logsState('7', 2, ['staff'])] : [409, { error: 'Conflict' }])
	moved('4', '5') // not from this block's revision: ignored
	moved('5', '6') // the matrix of this page saved a cell
	await picker().save(['staff'], [])
	assert.equal(log.calls.at(-1).body.expectedRevision, '6')
	assert.deepEqual(log.errors, [], 'no "changed in another window" for a change of this window')
	assert.deepEqual(log.announced, [['4', '5'], ['5', '6'], ['6', '7']], 'its own save is announced to the page')
})

test('T14 D1: a server-log save is announced only when it alone lies between the two revisions', async () => {
	for (const [next, previous, revision, announced] of [
		[['staff'], [], '6', true],
		[['staff'], [], '7', false], // another write landed before the state was read back
		[['ops', 'staff'], ['staff', 'ops'], '6', false], // the same set: nothing committed, the step is someone else's
	]) {
		const { log, picker } = await logsBlock(({ method }) => method === 'GET' ? [200, logsState('5', 1, previous)] : [200, logsState(revision, 2, ['staff'])])
		await picker().save(next, previous)
		assert.deepEqual(log.announced, announced ? [['5', '6']] : [], `${next} from ${previous}, answer ${revision}`)
	}
})

test('T14 D1 review F1: a save that changes nothing, followed by another administrator\'s change before the read-back, is never announced', async () => {
	// The no-op is accepted at 5 without a commit; another administrator then saves the list (revision 6, version 2)
	// before the server reads the state back, so the answer shows one step and a changed list.
	const { log, picker } = await logsBlock(({ method }) => method === 'GET' ? [200, logsState('5', 1, ['staff'])] : [200, logsState('6', 2, ['staff', 'ops'])])
	await picker().save(['staff'], ['staff'])
	assert.deepEqual(log.announced, [])
})

test('T14 D1: a move made elsewhere is still refused by the server, and the block says so', async () => {
	const { log, picker } = await logsBlock(({ method }) => method === 'GET' ? [200, logsState('5', 1, [])] : [409, { error: 'Conflict' }])
	await assert.rejects(picker().save(['staff'], []))
	await picker().onError(new Error('409'))
	assert.equal(log.calls.filter((call) => call.method === 'PUT')[0].body.expectedRevision, '5')
	assert.match(log.errors[0], /another window or by another administrator/)
	assert.deepEqual(log.announced, [])
})

test('T14 D5: every operation of the catalog has a translated column label, restore included', () => {
	require('./PollsAdminLabels.cjs').check(root, cut)
})
