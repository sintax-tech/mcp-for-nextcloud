// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect, user } from '../support/app-fixture.js'
const panel = page => page.locator('.mcp-reset-preview')
const individual = () => user('alice', { origins: { eligible: { source: 'individual_allow', groupIds: [] }, grants: { files: { read: { source: 'individual_allow', groupIds: [] }, create: { source: 'individual_deny', groupIds: [] } } } } })
const writes = app => app.calls.filter(c => c.path === '/api/grants/commands')
async function review(page, app) {
    app.state.users = [individual()]
    await app.open()
    await page.locator('[data-uid=alice] .mcp-row-actions-button').click()
    await page.getByRole('menuitem', { name: 'Give everything back to inheritance…' }).click()
    await expect(panel(page).getByRole('button', { name: 'Remove overrides' })).toBeEnabled()
    await expect(panel(page).locator('h3')).toBeFocused()
}
test('row reset previews exact inherited cells and sends one revisioned atomic command', async ({ page, app }) => {
    await review(page, app)
    expect(writes(app)).toEqual([])
    await expect(panel(page)).toContainText('Can connect')
    await panel(page).getByRole('button', { name: 'Remove overrides' }).click()
    await expect.poll(() => writes(app).length).toBe(1)
    expect(writes(app)[0].body).toEqual({ expectedRevision: '0', commands: [
        { uid: 'alice', module: null, operation: 'eligible', state: 'inherit' },
        { uid: 'alice', module: 'files', operation: 'read', state: 'inherit' },
        { uid: 'alice', module: 'files', operation: 'create', state: 'inherit' },
    ] })
    await expect(page.locator('#mcp-bulk-undo')).toBeVisible()
})
for (const status of [409, 428, 503, 500]) {
    test(`reset ${status} never replays or reports success`, async ({ page, app }) => {
        app.on('POST', '/api/grants/commands', () => ({ status, body: {} }))
        await review(page, app)
        await panel(page).getByRole('button', { name: 'Remove overrides' }).click()
        await expect.poll(() => writes(app).length).toBe(1)
        await expect(page.locator('#mcp-bulk-undo')).toBeHidden()
        if (status === 503) {
            await expect(panel(page).getByRole('button', { name: 'Try again' })).toBeEnabled()
            await expect(page.locator('#mcp-reset-alert')).not.toHaveText('')
        }
        expect(app.state.revision).toBe('0')
    })
}
for (const failure of ['error', 'stale']) {
    test(`unreadable ${failure} inheritance stays an exception and reset warns of unknown`, async ({ page, app }) => {
        app.state.users = [individual()]
        app.on('POST', '/api/grants/commands/preview', () => failure === 'error' ? { status: 503 } : { body: { revision: '9999', cells: [] } })
        await app.open()
        await expect(page.locator('[data-uid=alice] [data-level-key=files]')).toHaveAttribute('data-mark', 'exception')
        await page.locator('[data-uid=alice] .mcp-row-actions-button').click()
        await page.getByRole('menuitem', { name: 'Give everything back to inheritance…' }).click()
        await expect(panel(page)).toContainText(failure === 'error' ? 'shown as unknown' : 'older than the preview')
        if (failure === 'stale') await expect(panel(page).getByRole('button', { name: 'Remove overrides' })).toBeDisabled()
        expect(writes(app)).toEqual([])
    })
}
test('row menu keyboard navigation, Escape and reset cancellation restore focus without writes', async ({ page, app }) => {
    app.state.users = [individual()]; await app.open()
    const trigger = page.locator('[data-uid=alice] .mcp-row-actions-button')
    await trigger.focus(); await page.keyboard.press('ArrowDown')
    await expect(page.getByRole('menuitem', { name: 'Inspect access' })).toBeFocused()
    await page.keyboard.press('End'); await expect(page.getByRole('menuitem', { name: 'Copy user ID' })).toBeFocused()
    await page.keyboard.press('Escape'); await expect(trigger).toBeFocused()
    await trigger.click()
    await page.getByRole('menuitem', { name: 'Give everything back to inheritance…' }).click()
    await panel(page).getByRole('button', { name: 'Cancel' }).click()
    await expect(panel(page)).toBeHidden()
    await expect(trigger).toBeFocused()
    expect(writes(app)).toEqual([])
})

for (const [allowed, source, phrase] of [[true, 'group', 'nobody is signed out'], [false, 'default', 'revoked'], [null, 'unknown', 'Unknown']]) {
    test(`reset preview explains inherited eligibility ${allowed} without guessing`, async ({ page, app }) => {
        app.on('POST', '/api/grants/commands/preview', ({ body }) => ({ body: { revision: '0', cells: body.commands.map(c => ({ ...c, before: { allowed: true, source: 'individual_allow', groupIds: [] }, after: { allowed, source, groupIds: source === 'group' ? ['staff'] : [] } })) } }))
        await review(page, app)
        await expect(panel(page)).toContainText(phrase)
        await expect(panel(page).locator('.mcp-reset-targets')).toHaveAttribute('tabindex', '0')
        expect(writes(app)).toEqual([])
    })
}

test('uncertain reset reloads once without retry and definite undo refusal retains its receipt', async ({ page, app }) => {
    await review(page, app)
    await panel(page).getByRole('button', { name: 'Remove overrides' }).click()
    await expect(page.locator('#mcp-bulk-undo')).toBeVisible()
    const before = app.calls.filter(c => c.path === '/api/grants').length
    app.on('POST', '/api/grants/undo', () => ({ status: 503, body: {} }))
    await page.locator('#mcp-bulk-undo').click()
    await expect(page.locator('#mcp-bulk-undo')).toBeVisible()
    await expect(page.locator('#mcp-bulk-notice')).toContainText('Nothing was changed')
    expect(app.calls.filter(c => c.path === '/api/grants')).toHaveLength(before)
    const first = app.calls.filter(c => c.path === '/api/grants/undo')[0].body
    await page.locator('#mcp-bulk-undo').click()
    await expect.poll(() => app.calls.filter(c => c.path === '/api/grants/undo').length).toBe(2)
    expect(app.calls.filter(c => c.path === '/api/grants/undo')[1].body).toEqual(first)
})

test('selected reset names users without overrides and prunes the selection after a page change', async ({ page, app }) => {
    app.state.users = [individual(), user('bob')]
    await app.open(); await page.locator('#mcp-select-all').check()
    await page.getByRole('button', { name: 'Give back to inheritance… (2)' }).click()
    await expect(panel(page)).toContainText('1 selected user has no individual overrides and is left out')
    await panel(page).getByRole('button', { name: 'Cancel' }).click()
    app.state.users = [user('bob')]
    await page.locator('#mcp-group').selectOption('staff')
    await expect(page.getByRole('button', { name: 'Give back to inheritance… (1)' })).toBeVisible()
    await page.getByRole('button', { name: 'Clear selection' }).click()
    await expect(page.locator('.mcp-selection-bar')).toBeHidden()
    expect(writes(app)).toEqual([])
})

test('preview cannot be confirmed in flight and a cancelled answer never reopens it', async ({ page, app }) => {
    app.state.users = [individual()]
    await app.open()
    let release
    app.on('POST', '/api/grants/commands/preview', ({ body }) => new Promise(resolve => { release = () => resolve({ body: { revision: '0', cells: body.commands.map(c => ({ ...c, after: { allowed: true, source: 'default', groupIds: [] } })) } }) }))
    await page.locator('[data-uid=alice] .mcp-row-actions-button').click()
    await page.getByRole('menuitem', { name: 'Give everything back to inheritance…' }).click()
    await expect(panel(page).getByRole('button', { name: 'Remove overrides' })).toBeDisabled()
    await expect(panel(page).locator('h3')).toBeFocused()
    await page.keyboard.press('Escape')
    await expect.poll(() => typeof release).toBe('function'); release()
    await expect(panel(page)).toBeHidden()
    expect(writes(app)).toEqual([])
})

test('the delayed reset announcement cannot move confirmation during a pointer click', async ({ page, app }) => {
    await page.clock.install()
    await page.clock.pauseAt(new Date())
    await review(page, app)
    const button = panel(page).getByRole('button', { name: 'Remove overrides' })
    const before = await button.boundingBox()
    await page.mouse.move(before.x + before.width / 2, before.y + before.height / 2)
    await page.mouse.down()
    await page.clock.runFor(61)
    await expect(page.locator('#mcp-reset-status')).toMatchAriaSnapshot('- status: The result after the reset is shown.')
    expect(await button.boundingBox()).toEqual(before)
    await page.mouse.up()
    await expect.poll(() => writes(app).length).toBe(1)
    await expect(page.locator('#mcp-bulk-undo')).toBeVisible()
})

test('unreadable inheritance reset reloads and closes preview without a retry or success offer', async ({ page, app }) => {
    app.on('POST', '/api/grants/commands', () => ({ raw: '<html>Proxy</html>' }))
    await review(page, app)
    const before = app.calls.filter(c => c.method === 'GET' && c.path === '/api/grants').length
    await panel(page).getByRole('button', { name: 'Remove overrides' }).click()
    await expect(panel(page)).toBeHidden()
    await expect.poll(() => page.evaluate(() => window.__toasts)).toContain('The server could not confirm whether the change was saved. The table was reloaded with the saved values.')
    expect(app.calls.filter(c => c.method === 'GET' && c.path === '/api/grants')).toHaveLength(before + 1)
    expect(writes(app)).toHaveLength(1)
    await expect(page.locator('#mcp-bulk-undo')).toBeHidden()
})
