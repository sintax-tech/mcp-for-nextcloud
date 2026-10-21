// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect, user } from '../support/app-fixture.js'
const userWith = source => user('alice', { grants: { files: { read: true, create: false }, logs: { read: false } }, origins: { grants: { files: { read: { source, groupIds: [] } } } } })
const cell = page => page.locator('[data-uid=alice] [data-level-key=files]')
for (const [source, after, mark] of [['individual_allow', true, 'redundant'], ['individual_allow', false, 'exception'], ['invalid', true, 'exception'], ['individual_allow', null, 'exception']]) {
    test(`classification ${source} after ${after} is ${mark} and never writes`, async ({ page, app }) => {
        app.state.users = [userWith(source)]
        app.on('POST', '/api/grants/commands/preview', ({ body }) => {
            expect(body).toEqual({ commands: [{ uid: 'alice', module: 'files', operation: 'read', state: 'inherit' }] })
            return { body: { revision: '0', cells: body.commands.map(c => ({ ...c, after: { allowed: after, source: 'group', groupIds: ['staff'] } })) } }
        })
        await app.open()
        await expect(cell(page)).toHaveAttribute('data-mark', mark)
        expect(app.calls.filter(c => c.path === '/api/grants/commands')).toEqual([])
        expect(app.calls.filter(c => c.path === '/api/grants/commands/preview')).toHaveLength(1)
    })
}
test('classification is renewed after a same-revision read, and a new failure keeps the exception', async ({ page, app }) => {
    app.state.users = [userWith('individual_allow')]
    await app.open(); await expect(cell(page)).toHaveAttribute('data-mark', 'redundant')
    app.on('POST', '/api/grants/commands/preview', () => ({ status: 503 }))
    await page.locator('#mcp-group').selectOption('staff')
    await expect(cell(page)).toHaveAttribute('data-mark', 'exception')
    expect(app.calls.filter(c => c.path === '/api/grants/commands/preview')).toHaveLength(2)
})
for (const owner of ['menu', 'popover', 'inspector']) {
    test(`classification waits for the ${owner} to close before redrawing`, async ({ page, app }) => {
        app.state.users = [userWith('individual_allow')]
        const answers = []
        app.on('POST', '/api/grants/commands/preview', ({ body }) => new Promise(resolve => { answers.push(() => resolve({ body: { revision: '0', cells: body.commands.map(c => ({ ...c, after: { allowed: true, source: 'default', groupIds: [] } })) } })) }))
        await app.open()
        if (owner === 'menu') await page.locator('.mcp-row-actions-button').click()
        if (owner === 'popover') await cell(page).locator('button.mcp-level').click()
        if (owner === 'inspector') await page.locator('#mcp-inspector-entry').click()
        await expect.poll(() => answers.length).toBe(owner === 'popover' ? 2 : 1)
        answers.forEach(answer => answer())
        if (owner === 'menu') await expect(page.locator('#mcp-row-menu')).toBeVisible()
        if (owner === 'popover') await expect(page.locator('#mcp-level-popover')).toBeVisible()
        if (owner === 'inspector') await expect(page.locator('#mcp-inspector')).toHaveClass(/mcp-drawer-open/)
        await page.keyboard.press('Escape')
        await expect(cell(page)).toHaveAttribute('data-mark', 'redundant')
    })
}
