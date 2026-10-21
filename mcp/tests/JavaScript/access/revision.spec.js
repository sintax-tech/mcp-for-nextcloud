// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

for (const status of [409, 428, 503]) {
    test(`matrix reloads ${status} without replaying the stale save or adopting its error revision`, async ({ page, app }) => {
        app.on('PUT', '/api/grants/alice', ({ state }) => {
            state.revision = '9007199254740993'
            return { status, body: { error: 'Unavailable', uncertain: true, revision: '99999999999999999' } }
        })
        await app.open('/admin#access?view=operations')
        await page.locator('input[aria-label^="alice — Files: read"]').check()
        await expect.poll(() => app.calls.filter(c => c.path === '/api/grants' && c.method === 'GET').length).toBe(3)
        await expect(page.locator('input[aria-label^="alice — Files: read"]')).not.toBeChecked()
        expect(app.calls.filter(c => c.method === 'PUT')).toHaveLength(1)
        await page.locator('input[aria-label^="bob — Files: create"]').check()
        await expect(page.locator('input[aria-label^="bob — Files: create"]')).toBeEnabled()
        const calls = app.calls.filter(c => c.method === 'PUT')
        expect(calls[1].body.expectedRevision).toBe('9007199254740993')
    })
}

async function allowFilesRead(page) {
    await page.locator('#mcp-matrix thead input[type=checkbox]').check()
    await page.locator('.mcp-selection-module').selectOption('files')
    await page.locator('.mcp-selection-level').selectOption('read')
    page.once('dialog', dialog => dialog.accept())
    await page.locator('.mcp-selection-apply').click()
    await expect(page.locator('#mcp-bulk-undo')).toBeVisible()
}

test('undo uses its receipt revision even after a later policy edit', async ({ page, app }) => {
    await app.open('/admin#access?view=operations')
    await allowFilesRead(page)
    await page.locator('input[aria-label^="bob — Files: create"]').check()
    await expect(page.locator('input[aria-label^="bob — Files: create"]')).toBeEnabled()
    await page.locator('#mcp-bulk-undo').click()
    await expect(page.locator('#mcp-bulk-undo')).toBeHidden()
    const undo = app.calls.filter(c => c.path === '/api/grants/undo')
    expect(undo).toHaveLength(1)
    expect(undo[0].body).toEqual({ receiptId: 'receipt-1', expectedRevision: '1' })
    expect(app.state.users[0].grants.files.read).toBe(true)
    expect(app.state.users[1].grants.files.create).toBe(true)
})

test('expired receipt removes undo without an inverse write', async ({ page, app }) => {
    app.on('POST', '/api/grants/undo', () => ({ status: 410, body: { error: 'Expired' } }))
    await app.open('/admin#access?view=operations')
    await allowFilesRead(page)
    await page.locator('#mcp-bulk-undo').click()
    await expect(page.locator('#mcp-bulk-notice-text')).toContainText('the time to undo it has passed')
    await expect(page.locator('#mcp-bulk-undo')).toBeHidden()
    expect(app.calls.filter(c => c.method === 'POST').map(c => c.path)).toEqual(['/api/grants/commands', '/api/grants/undo'])
})
