// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

function scopes(app) {
    const saved = Object.fromEntries(['files', 'calendar', 'tasks'].map(module => [module, { uid: 'alice', module, active: true, mode: 'unrestricted', items: [], limit: 200 }]))
    for (const module of Object.keys(saved)) {
        app.on('GET', `/api/scope/alice/${module}`, ({ state }) => ({ body: { ...saved[module], revision: state.revision } }))
        app.on('GET', `/api/scope/alice/${module}/candidates`, () => ({ body: { items: [{ id: '11', name: 'A & B', path: module === 'files' ? '/A & B' : null, excluded: true }, { id: '12', name: 'Team', path: null }], total: 2, page: 1, pageSize: 50, hasMore: false } }))
        app.on('PUT', `/api/scope/alice/${module}`, ({ body, state }) => {
            expect(body.expectedRevision).toBe(state.revision)
            saved[module] = { ...saved[module], mode: body.items === null ? 'unrestricted' : 'restricted', items: (body.items || []).map(id => ({ id, name: 'A & B', path: null, found: true })) }
            state.revision = String(BigInt(state.revision) + 1n)
            return { body: { revision: state.revision, changed: true, receiptId: 'scope-receipt' } }
        })
    }
    return saved
}
async function open(page, app) {
    await app.open()
    await page.locator('#mcp-scope-entry').click()
    await page.locator('#mcp-scope-user').fill('alice')
    await page.locator('#mcp-scope-form').getByRole('button').click()
    await expect(page.locator('#mcp-scope-module-files')).toBeVisible()
}
const writes = app => app.calls.filter(call => call.method === 'PUT' && call.path.startsWith('/api/scope/'))

test('scope drafts stay local until save; selections are plain text and save offers a server receipt', async ({ page, app }) => {
    scopes(app)
    await open(page, app)
    await page.locator('#mcp-scope-mode-files-restricted').check()
    await page.locator('#mcp-scope-module-files').getByRole('button', { name: 'Search', exact: true }).click()
    await expect(page.locator('.mcp-scope-results')).toContainText('/A & B')
    await expect(page.locator('.mcp-scope-results')).toContainText('excluded by a tag')
    await page.getByRole('button', { name: 'Add /A & B', exact: true }).click()
    expect(writes(app)).toEqual([])
    await page.locator('#mcp-scope-save-files').click()
    await expect(page.locator('#mcp-scope-undo-files')).toBeVisible()
    expect(writes(app)[0].body).toEqual({ expectedRevision: '0', mode: 'restricted', items: ['11'] })
    await expect(page.locator('#mcp-scope-save-files')).toBeDisabled()
})

for (const status of [409, 503]) {
    test(`scope ${status} reloads without retrying the mutation`, async ({ page, app }) => {
        scopes(app)
        app.on('PUT', '/api/scope/alice/files', () => ({ status, body: { uncertain: true } }))
        await open(page, app)
        await page.locator('#mcp-scope-mode-files-restricted').check()
        await page.locator('#mcp-scope-save-files').click()
        await expect.poll(() => writes(app).length).toBe(1)
        await expect(page.locator('#mcp-scope-alert')).not.toHaveText('')
        await expect(page.locator('#mcp-scope-undo-files')).toHaveCount(0)
        expect(writes(app)[0].body.expectedRevision).toBe('0')
    })
}

test('a draft prevents closing or replacing its drawer until discard is confirmed', async ({ page, app }) => {
    scopes(app)
    await open(page, app)
    await page.locator('#mcp-scope-mode-files-restricted').check()
    page.once('dialog', dialog => dialog.dismiss())
    await page.locator('#mcp-scope-close').click()
    await expect(page.locator('#mcp-scope')).toHaveClass(/mcp-drawer-open/)
    page.once('dialog', dialog => dialog.dismiss())
    await page.locator('#mcp-inspector-entry').click()
    await expect(page.locator('#mcp-inspector')).not.toHaveClass(/mcp-drawer-open/)
    page.once('dialog', dialog => dialog.accept())
    await page.locator('#mcp-scope-close').click()
    await expect(page.locator('#mcp-scope')).not.toHaveClass(/mcp-drawer-open/)
    expect(writes(app)).toEqual([])
})

for (const status of [403, 503]) {
    test(`unreadable scope ${status} shows an alert and no resource names`, async ({ page, app }) => {
        scopes(app)
        app.on('GET', '/api/scope/alice/files', () => ({ status }))
        await app.open()
        await page.locator('#mcp-scope-entry').click()
        await page.locator('#mcp-scope-user').fill('alice')
        await page.locator('#mcp-scope-form').getByRole('button').click()
        await expect(page.locator('#mcp-scope-alert')).not.toHaveText('')
        await expect(page.locator('#mcp-scope-modules')).toBeEmpty()
    })
}

test('personal scope reads only the session route and reports failure instead of unrestricted', async ({ page, app }) => {
    app.on('GET', '/api/my/scope', () => ({ status: 503 }))
    await app.open('/personal')
    await expect(page.locator('#mcp-my-scope-status')).toContainText('cannot be read')
    await expect(page.locator('#mcp-my-scope-list')).toBeEmpty()
    expect(app.calls.filter(call => call.path.includes('/scope')).map(call => [call.method, call.path, call.query])).toEqual([['GET', '/api/my/scope', {}]])
})

test('scope undo uses only the acknowledged receipt and original committed revision', async ({ page, app }) => {
    const saved = scopes(app)
    app.on('POST', '/api/grants/undo', ({ body, state }) => {
        expect(body).toEqual({ receiptId: 'scope-receipt', expectedRevision: '1' })
        saved.files.mode = 'unrestricted'; saved.files.items = []; state.revision = '2'
        return { body: { revision: '2', changed: true } }
    })
    await open(page, app)
    await page.locator('#mcp-scope-mode-files-restricted').check()
    await page.locator('#mcp-scope-save-files').click()
    await page.locator('#mcp-scope-undo-files').click()
    await expect(page.locator('#mcp-scope-mode-files-unrestricted')).toBeChecked()
    expect(app.calls.filter(c => c.path === '/api/grants/undo')).toHaveLength(1)
})

test('another module save advances a same-page draft without replacing its selection', async ({ page, app }) => {
    scopes(app); await open(page, app)
    await page.locator('#mcp-scope-mode-files-restricted').check()
    await page.locator('#mcp-scope-tab-calendar').click()
    await page.locator('#mcp-scope-mode-calendar-restricted').check()
    await page.locator('#mcp-scope-save-calendar').click()
    await expect(page.locator('#mcp-scope-undo-calendar')).toBeVisible()
    await page.locator('#mcp-scope-tab-files').click()
    await expect(page.locator('#mcp-scope-mode-files-restricted')).toBeChecked()
    await page.locator('#mcp-scope-save-files').click()
    await expect(page.locator('#mcp-scope-undo-files')).toBeVisible()
    expect(writes(app).map(c => c.body.expectedRevision)).toEqual(['0', '1'])
})

test('scope tabs use arrows and retain unsaved status in their accessible names', async ({ page, app }) => {
    scopes(app); await open(page, app)
    await page.locator('#mcp-scope-mode-files-restricted').check()
    const tab = page.locator('#mcp-scope-tab-files')
    await expect(tab).toContainText('not saved')
    await tab.focus(); await page.keyboard.press('ArrowRight')
    await expect(page.locator('#mcp-scope-tab-calendar')).toBeFocused()
    await expect(page.locator('#mcp-scope-tab-calendar')).toHaveAttribute('aria-selected', 'true')
    await page.keyboard.press('End'); await expect(page.locator('#mcp-scope-tab-tasks')).toBeFocused()
    await page.keyboard.press('Home'); await expect(tab).toBeFocused()
    expect(writes(app)).toEqual([])
})

for (const [error, text] of [['ScopeInactive', 'do not apply'], ['Inaccessible', 'cannot reach'], ['TooManyResources', 'at most 200']]) {
    test(`scope refusal ${error} preserves the draft and explains the cause`, async ({ page, app }) => {
        const saved = scopes(app)
        saved.files.mode = 'restricted'; saved.files.items = [{ id: '11', path: '/A & B', found: true }]
        app.on('PUT', '/api/scope/alice/files', () => ({ status: 422, body: { error, items: ['11'], limit: 200 } }))
        await open(page, app)
        await page.locator('#mcp-scope-mode-files-unrestricted').check()
        await page.locator('#mcp-scope-save-files').click()
        await expect(page.locator('#mcp-scope-line-files')).toContainText(text)
        expect(writes(app)).toHaveLength(1)
        await expect(page.locator('#mcp-scope-undo-files')).toHaveCount(0)
    })
}

test('removing a selected resource preserves focus and undo expiry waits for keyboard focus', async ({ page, app }) => {
    const saved = scopes(app)
    saved.files.mode = 'restricted'; saved.files.items = [{ id: '11', path: '/One', found: true }, { id: '12', path: '/Two', found: true }]
    await open(page, app)
    await page.locator('#mcp-scope-remove-files-11').click()
    await expect(page.locator('#mcp-scope-remove-files-12')).toBeFocused()
    await page.clock.install()
    await page.clock.pauseAt(new Date())
    await page.locator('#mcp-scope-save-files').click()
    const undo = page.locator('#mcp-scope-undo-files')
    await undo.focus()
    await page.clock.runFor(10001)
    await expect(undo).toBeFocused()
    await page.locator('#mcp-scope-tab-files').focus()
    await expect(undo).toHaveCount(0)
    await expect(page.locator('#mcp-scope-tab-files')).toBeFocused()
})

test('a committed scope whose read-back fails keeps its receipt but announces the unreadable state', async ({ page, app }) => {
    scopes(app)
    app.on('PUT', '/api/scope/alice/files', ({ state }) => {
        state.revision = '1'
        app.on('GET', '/api/scope/alice/files', () => ({ status: 503 }))
        return { body: { revision: '1', changed: true, receiptId: 'scope-receipt' } }
    })
    await open(page, app)
    await page.locator('#mcp-scope-mode-files-restricted').check()
    await page.locator('#mcp-scope-save-files').click()
    await expect(page.locator('#mcp-scope-alert')).not.toHaveText('')
    await expect(page.locator('#mcp-scope-undo-files')).toBeVisible()
    expect(writes(app)).toHaveLength(1)
})

test('the delayed scope announcement cannot move a radio during a pointer click', async ({ page, app }) => {
    await page.clock.install()
    await page.clock.pauseAt(new Date())
    scopes(app)
    await open(page, app)
    const choice = page.locator('#mcp-scope-mode-files-restricted')
    const before = await choice.boundingBox()
    await page.mouse.move(before.x + before.width / 2, before.y + before.height / 2)
    await page.mouse.down()
    // Force the polite live-region announcement between pointerdown and pointerup.
    await page.clock.runFor(61)
    await page.mouse.up()
    await expect(page.locator('#mcp-scope-status')).toMatchAriaSnapshot('- status: Resource scope of alice.')
    await expect(choice).toBeChecked()
    await expect(page.locator('#mcp-scope-save-files')).toBeEnabled()
    expect(writes(app)).toEqual([])
})

for (const action of ['save', 'undo']) {
    test(`unreadable scope ${action} reports uncertainty and reloads stored scope without replay`, async ({ page, app }) => {
        const saved = scopes(app)
        await open(page, app)
        await page.locator('#mcp-scope-mode-files-restricted').check()
        if (action === 'undo') {
            await page.locator('#mcp-scope-save-files').click()
            await expect(page.locator('#mcp-scope-undo-files')).toBeVisible()
        }
        const before = app.calls.filter(c => c.method === 'GET' && c.path === '/api/scope/alice/files').length
        const writeCount = app.calls.filter(c => c.method !== 'GET').length
        app.on(action === 'save' ? 'PUT' : 'POST', action === 'save' ? '/api/scope/alice/files' : '/api/grants/undo', ({ state }) => {
            saved.files.mode = 'unrestricted'; saved.files.items = []; state.revision = '9'
            return { raw: '<html>Proxy</html>' }
        })
        await page.locator(action === 'save' ? '#mcp-scope-save-files' : '#mcp-scope-undo-files').click()
        await expect(page.locator('#mcp-scope-line-files')).toContainText('could not be confirmed')
        await expect(page.locator('#mcp-scope-mode-files-unrestricted')).toBeChecked()
        await expect(page.locator('#mcp-scope-mode-files-unrestricted')).toBeEnabled()
        expect(app.calls.filter(c => c.method === 'GET' && c.path === '/api/scope/alice/files')).toHaveLength(before + 1)
        expect(app.calls.filter(c => c.method !== 'GET')).toHaveLength(writeCount + 1)
        await expect(page.locator('#mcp-scope-undo-files')).toHaveCount(0)
    })
}
