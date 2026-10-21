// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'
const row = extra => ({ module: 'files', operation: 'read', allowed: true, blockingGate: null, grant: { allowed: true, source: 'default', groupIds: [] }, scope: { mode: 'unrestricted', count: 0 }, ...extra })
function fixture(app, operations) {
    app.on('GET', '/api/inspector/access', () => ({ body: { revision: '0', operations } }))
    app.on('GET', '/api/scope/alice/calendar/candidates', () => ({ body: { items: [{ id: '11', name: 'Team' }] } }))
}
async function inspect(page, app) {
    await app.open()
    await page.locator('#mcp-inspector-entry').click()
    await page.locator('#mcp-inspector-user').fill('alice')
    await page.locator('#mcp-inspector-form button').click()
}
for (const [gate, text] of [[null, 'can use the MCP tools'], ['service', 'service is switched off'], ['connection', 'personal settings'], ['eligibility_unknown', 'cannot be confirmed'], ['grant', 'nothing is granted'], ['resource_scope_unknown', 'cannot be confirmed']]) {
    test(`effective access explains ${gate} without inventing access`, async ({ page, app }) => {
        fixture(app, [row({ allowed: gate === null ? true : gate.endsWith('unknown') ? null : false, blockingGate: gate })])
        await inspect(page, app)
        await expect(page.locator('#mcp-inspector-access')).toContainText(text)
        await expect(page.locator('#mcp-inspector-alert')).toBeEmpty()
        expect(app.calls.filter(c => c.method !== 'GET' && c.path !== '/api/grants/commands/preview')).toEqual([])
    })
}
for (const [reason, extra, text] of [
    ['allowed', {}, 'every check passed'], ['not_found', {}, 'does not exist'], ['no_native_access', {}, 'no access'], ['read_only', {}, 'only read'],
    ['excluded', { exclusion: 'tag_direct' }, 'exclusion tag'], ['excluded', { exclusion: 'tag_folder', folder: '/<private>' }, '/<private>'],
    ['not_evaluated', { gate: 'native_acl' }, 'not known'], ['out_of_scope', {}, 'outside the resource scope'],
]) {
    test(`path explanation ${reason} ${extra.exclusion || ""} renders server codes as text`, async ({ page, app }) => {
        fixture(app, [row()])
        app.on('GET', '/api/inspector/path', ({ query }) => {
            expect(query.get('uid')).toBe('alice'); expect(query.get('path')).toBe('/Report')
            return { body: { reason, ...extra } }
        })
        await inspect(page, app)
        await expect(page.locator('#mcp-inspector-resources')).toBeVisible()
        await page.locator('#mcp-inspector-resources summary').click()
        await page.locator('#mcp-inspector-path').fill('Report')
        await page.locator('#mcp-inspector-path-form button').click()
        await expect(page.locator('#mcp-inspector-path-result')).toContainText(text)
        await expect(page.locator('#mcp-inspector-path-result private')).toHaveCount(0)
    })
}
for (const status of [403, 503]) {
    test(`inspection ${status} removes prior results and announces failure`, async ({ page, app }) => {
        fixture(app, [row()]); await inspect(page, app)
        await expect(page.locator('#mcp-inspector-access')).toContainText('can use')
        app.on('GET', '/api/inspector/access', () => ({ status }))
        await page.locator('#mcp-inspector-form button').click()
        await expect(page.locator('#mcp-inspector-alert')).not.toHaveText('')
        await expect(page.locator('#mcp-inspector-access')).toBeEmpty()
        await expect(page.locator('#mcp-inspector-resources')).toBeHidden()
    })
}
test('drawer Escape restores entry focus and an empty answer confirms nothing', async ({ page, app }) => {
    fixture(app, []); await inspect(page, app)
    await expect(page.locator('#mcp-inspector-access')).toContainText('nothing can be said')
    await page.locator('#mcp-inspector-user').focus()
    await page.keyboard.press('Escape')
    await expect(page.locator('#mcp-inspector')).not.toHaveClass(/mcp-drawer-open/)
    await expect(page.locator('#mcp-inspector-entry')).toBeFocused()
})

for (const reject of [false, true]) {
    test(`destroyed inspector ignores a late ${reject ? 'failure' : 'success'} and remount starts clean`, async ({ page, app }) => {
        await page.goto('/admin?bare=1#access')
        await page.evaluate(async () => {
            const { mountInspector } = await import('/modules/admin/inspection/controller.js')
            const { createNextcloud } = await import('/modules/platform/nextcloud.js')
            const dependencies = { root: document.querySelector('#mcp-admin'), ...createNextcloud(), events: new EventTarget(), getExceptions: () => null, getResourceScope: () => null }
            window.inspectorFactory = request => mountInspector({ ...dependencies, request })
            window.inspector = window.inspectorFactory(() => new Promise((resolve, reject) => { window.inspectorReply = { resolve, reject } }))
            window.inspector.open('alice')
        })
        await expect(page.locator('#mcp-inspector-status')).toHaveText('Inspecting…')
        await page.evaluate(async reject => {
            window.inspector.destroy()
            const before = document.querySelector('#mcp-inspector').innerHTML
            window.inspectorReply[reject ? 'reject' : 'resolve'](reject ? new Error('503') : { revision: '0', operations: [] })
            await new Promise(resolve => setTimeout(resolve, 0))
            window.inspectorAfterDestroy = { before, after: document.querySelector('#mcp-inspector').innerHTML }
        }, reject)
        const snapshots = await page.evaluate(() => window.inspectorAfterDestroy)
        expect(snapshots.after).toBe(snapshots.before)
        await expect(page.locator('#mcp-inspector')).not.toHaveAttribute('role', 'dialog')
        await page.evaluate(() => { window.inspector = window.inspectorFactory(async () => ({ revision: '0', operations: [] })); window.inspector.open('bob') })
        await expect(page.locator('#mcp-inspector-access')).toContainText('nothing can be said about bob')
        await expect(page.locator('#mcp-inspector-alert')).toBeEmpty()
    })
}

test('late access and resource explanations never move from one inspected user to another', async ({ page, app }) => {
    fixture(app, [row()])
    app.on('GET', '/api/scope/bob/calendar/candidates', () => ({ body: { items: [] } }))
    let release
    app.on('GET', '/api/inspector/path', () => new Promise(resolve => { release = () => resolve({ body: { reason: 'excluded', exclusion: 'tag_folder', folder: '/Alice secret' } }) }))
    await inspect(page, app)
    await page.locator('#mcp-inspector-resources summary').click()
    await page.locator('#mcp-inspector-path').fill('/Secret')
    await page.locator('#mcp-inspector-path-form button').click()
    await expect.poll(() => typeof release).toBe('function')
    await page.locator('#mcp-inspector-user').fill('bob')
    await page.locator('#mcp-inspector-form button').click()
    await expect(page.locator('#mcp-inspector-access')).toContainText('bob can use')
    release()
    await expect(page.locator('#mcp-inspector-path-result')).toBeEmpty()
    let releaseAccess
    app.on('GET', '/api/inspector/access', ({ query }) => query.get('uid') === 'alice'
        ? new Promise(resolve => { releaseAccess = () => resolve({ body: { revision: '0', operations: [row({ allowed: false, blockingGate: 'service' })] } }) })
        : { body: { revision: '0', operations: [row()] } })
    await page.locator('#mcp-inspector-user').fill('alice'); await page.locator('#mcp-inspector-form button').click()
    await expect.poll(() => typeof releaseAccess).toBe('function')
    await page.locator('#mcp-inspector-user').fill('bob'); await page.locator('#mcp-inspector-form button').click()
    await expect(page.locator('#mcp-inspector-status')).toContainText('Access of bob')
    releaseAccess()
    await expect(page.locator('#mcp-inspector-access')).toContainText('bob can use')
    await expect(page.locator('#mcp-inspector-access')).not.toContainText('switched off')
})
