// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect, user } from '../support/app-fixture.js'

async function bulk(page, module, index, action) {
    const select = page.locator('#mcp-matrix thead input[type=checkbox]')
    if (!await select.isChecked()) await select.check()
    await page.locator('.mcp-selection-module').selectOption(module ?? '')
    const level = action === 'Deny' ? 'none' : module === null || index === 0 ? 'read' : 'full'
    await page.locator('.mcp-selection-level').selectOption(level)
    await page.locator('.mcp-selection-apply').click()
}

test('single grants encode UID, use authoritative rows and restore focus after rollback', async ({ page, app }) => {
    app.state.users = [user('name/with space')]
    await app.open('/admin#access?view=operations')
    const box = page.locator('input[aria-label^="name/with space — Files: read"]')
    await box.focus()
    await box.check()
    await expect(box).toBeEnabled()
    await expect(box).toBeChecked()
    await expect(box).toBeFocused()
    const call = app.calls.find(call => call.method === 'PUT')
    expect(call.path).toBe('/api/grants/name%2Fwith%20space')
    expect(call.body).toEqual({ module: 'files', operation: 'read', granted: true, expectedRevision: '0' })
    expect(call.headers['content-type']).toBe('application/json')
    app.on('PUT', call.path, () => ({ status: 500 }))
    await box.press('Space')
    await expect(box).toBeChecked()
    await expect(box).toBeFocused()
})

test('unavailable is not denied and app filtering preserves collapsed rows', async ({ page, app }) => {
    app.state.users = [user('disabled', { enabled: false }), user('ineligible', { eligible: false }), user('no-app', { appsEnabled: { files: false, logs: false } })]
    await app.open('/admin#access?view=operations')
    await expect(page.locator('#mcp-matrix .mcp-row-unavailable')).toHaveCount(2)
    await expect(page.locator('#mcp-matrix .mcp-state-unavailable')).toHaveCount(4)
    await page.locator('#mcp-app').selectOption('files')
    await expect(page.locator('.mcp-row-unavailable').first()).toHaveAttribute('colspan', '2')
    await expect(page.locator('[data-uid=no-app] [data-module=files] input')).toHaveCount(0)
})

test('a failed atomic bulk command offers no undo and does not retry its cells', async ({ page, app }) => {
    const before = structuredClone(app.state.users)
    app.on('POST', '/api/grants/commands', () => ({ status: 503, body: { error: 'Unavailable', uncertain: false } }))
    await app.open('/admin#access?view=operations')
    page.once('dialog', dialog => dialog.accept())
    await bulk(page, 'files', 2, 'Allow')
    await expect(page.locator('#mcp-bulk-notice-text')).toHaveText('Could not update the selected people.')
    await expect(page.locator('#mcp-bulk-undo')).toBeHidden()
    const requests = app.calls.filter(call => call.method === 'POST')
    expect(requests).toHaveLength(1)
    expect(requests[0].body).toEqual({ expectedRevision: '0', commands: [
        { uid: 'alice', module: 'files', operation: 'read', state: 'allow' },
        { uid: 'bob', module: 'files', operation: 'create', state: 'allow' },
    ] })
    expect(app.state.users).toEqual(before)
})

test('bulk skips unavailable, cancel sends nothing and undo restores saved values', async ({ page, app }) => {
    app.state.users.push(user('disabled', { enabled: false }))
    await app.open('/admin#access?view=operations')
    page.once('dialog', async dialog => {
        expect(dialog.message()).toContain('left out')
        expect(dialog.message()).toContain('already has it')
        await dialog.dismiss()
    })
    await bulk(page, 'files', 0, 'Allow')
    expect(app.calls.filter(call => call.method === 'POST')).toHaveLength(0)
    page.once('dialog', dialog => dialog.accept())
    await bulk(page, 'files', 0, 'Allow')
    await expect(page.locator('#mcp-bulk-undo')).toBeVisible()
    await page.locator('#mcp-bulk-undo').click()
    await expect(page.locator('#mcp-bulk-notice-text')).toHaveText('Change undone.')
    expect(app.state.users[0].grants.files.read).toBe(false)
    expect(app.state.users[1].grants.files.read).toBe(true)
})

test('bulk lock prevents overlap; notice pauses on focus and expires after leaving', async ({ page, app }) => {
    let release
    app.on('POST', '/api/grants/commands', async () => {
        await new Promise(resolve => { release = resolve })
        return { body: { revision: '1', receiptId: 'receipt-delayed' } }
    })
    await app.open('/admin#access?view=operations')
    await page.clock.install()
    page.once('dialog', dialog => dialog.accept())
    await bulk(page, 'files', 0, 'Allow')
    await expect(page.locator('.mcp-bulk:enabled')).toHaveCount(0)
    expect(app.calls.filter(call => call.method === 'POST')).toHaveLength(1)
    await expect.poll(() => typeof release).toBe('function')
    release()
    await expect(page.locator('#mcp-bulk-undo')).toBeFocused()
    await page.clock.fastForward(11000)
    await expect(page.locator('#mcp-bulk-undo')).toBeVisible()
    await page.locator('#mcp-search').focus()
    await page.mouse.move(0, 0)
    await page.clock.fastForward(10001)
    await expect(page.locator('#mcp-bulk-undo')).toBeHidden()
})

test('matrix retry and debounce preserve query and both pagination controls', async ({ page, app }) => {
    let fail = true
    app.state.hasMore = true
    app.state.total = null
    app.on('GET', '/api/grants', ({ query, state }) => fail ? { status: 500 } : { body: { revision: state.revision, serviceEnabled: state.serviceEnabled, users: state.users, groups: state.groups, catalog: state.catalog, page: Number(query.get('page')), pageSize: 50, hasMore: true, total: null } })
    await app.open('/admin#access?view=operations')
    fail = false
    await page.getByRole('button', { name: 'Retry', exact: true }).click()
    await expect(page.locator('#mcp-matrix tbody tr')).toHaveCount(2)
    await page.clock.install()
    await page.clock.pauseAt(new Date(Date.now() + 1000))
    await page.locator('#mcp-search').fill(' ali ')
    const count = app.calls.filter(call => call.path === '/api/grants').length
    await page.clock.fastForward(299)
    expect(app.calls.filter(call => call.path === '/api/grants')).toHaveLength(count)
    await page.clock.fastForward(1)
    await expect.poll(() => app.calls.at(-1).query.search).toBe('ali')
    await page.locator('#mcp-next-bottom').click()
    await expect.poll(() => app.calls.at(-1).query.page).toBe('2')
    await page.locator('#mcp-group').selectOption('staff')
    await expect.poll(() => app.calls.at(-1).query).toEqual({ search: 'ali', group: 'staff', filter: 'eligible', page: '1' })
})

test('eligibility revocation warns about sign-out and a new bulk notice replaces undo', async ({ page, app }) => {
    await app.open('/admin#access?view=operations')
    page.once('dialog', async dialog => {
        expect(dialog.message()).toContain('undoing this does not sign them back in')
        await dialog.accept()
    })
    await bulk(page, null, 0, 'Deny')
    await expect(page.locator('#mcp-bulk-undo')).toBeVisible()
    page.once('dialog', dialog => dialog.accept())
    await bulk(page, null, 0, 'Allow')
    await expect(page.locator('#mcp-bulk-notice-text')).toContainText('The previous undo is no longer available.')
    expect(app.calls.filter(call => call.method === 'POST').map(call => call.body.commands[0].module)).toEqual([null, null])
    await bulk(page, null, 0, 'Allow')
    await expect(page.locator('#mcp-bulk-notice-text')).toContainText('Nothing to change for the selected people')
    expect(app.calls.filter(call => call.method === 'POST')).toHaveLength(2)
})

test('view selection tolerates denied storage and empty matrix keeps its column span', async ({ page, app }) => {
    await page.addInitScript(() => {
        Object.defineProperty(window, 'localStorage', { get() { throw new Error('Storage denied') } })
        window.ResizeObserver = undefined
    })
    app.state.users = []
    await app.open('/admin#access?view=operations')
    await expect(page.locator('#mcp-matrix tbody')).toHaveText('No users found.')
    await page.locator('#mcp-app').selectOption('logs')
    await expect(page.locator('#mcp-matrix tbody td')).toHaveAttribute('colspan', '5')
})
