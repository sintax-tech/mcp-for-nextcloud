// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
// Upstream issue #11, exercised through rendered modules and the HTTP boundary.
import { test, expect } from '../support/app-fixture.js'
const uncertain = 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.'
const reads = app => app.calls.filter(c => c.method === 'GET' && c.path === '/api/grants').length
const writes = app => app.calls.filter(c => c.method !== 'GET')
const html = { raw: '<html>Session or proxy response</html>' }
async function bulk(page) {
    await page.locator('#mcp-matrix thead input[type=checkbox]').check()
    await page.locator('.mcp-selection-module').selectOption('files')
    await page.locator('.mcp-selection-level').selectOption('read')
    page.once('dialog', dialog => dialog.accept())
    await page.locator('.mcp-selection-apply').click()
}
for (const action of ['cell', 'bulk', 'undo', 'service']) {
    test(`unreadable ${action} write reloads once, never replays or claims success`, async ({ page, app }) => {
        await app.open('/admin#access?view=operations')
        if (action === 'undo') {
            await bulk(page)
            await expect(page.locator('#mcp-bulk-undo')).toBeVisible()
            await expect(page.locator('#mcp-matrix')).toHaveAttribute('aria-busy', 'false')
        }
        const path = { cell: '/api/grants/alice', bulk: '/api/grants/commands', undo: '/api/grants/undo', service: '/api/service' }[action]
        const before = reads(app), sent = writes(app).length
        app.on(['cell', 'service'].includes(action) ? 'PUT' : 'POST', path, ({ state }) => {
            state.revision = '9'
            state.users[0].grants.files.read = true
            state.serviceEnabled = false
            return html
        })
        if (action === 'cell') await page.locator('input[aria-label^="alice — Files: read"]').check()
        if (action === 'bulk') await bulk(page)
        if (action === 'undo') await page.locator('#mcp-bulk-undo').click()
        if (action === 'service') {
            await page.locator('#mcp-tab-overview').click()
            await page.locator('#mcp-service').uncheck()
        }
        await expect.poll(() => page.evaluate(() => window.__toasts)).toContain(uncertain)
        await expect.poll(() => reads(app)).toBe(before + 1)
        expect(writes(app)).toHaveLength(sent + 1)
        await expect(page.locator('#mcp-matrix')).toHaveAttribute('aria-busy', 'false')
        if (action === 'service') {
            await expect(page.locator('#mcp-service')).not.toBeChecked()
            await expect(page.locator('#mcp-service')).toBeEnabled()
            await expect(page.locator('#mcp-service-status')).not.toHaveText('Saved')
            await expect(page.locator('#mcp-service-status')).not.toHaveText('Not saved')
        } else {
            await expect(page.locator('input[aria-label^="alice — Files: read"]')).toBeChecked()
            await expect(page.locator('#mcp-bulk-undo')).toBeHidden()
            await expect(page.locator('#mcp-bulk-notice-text')).not.toHaveText('Change undone.')
            if (action === 'undo') await expect(page.locator('#mcp-bulk-notice-text')).toHaveText(uncertain)
        }
    })
}
test('unreadable write followed by unreadable recovery exposes Retry and releases busy state', async ({ page, app }) => {
    await app.open('/admin#access?view=operations')
    const before = reads(app)
    app.on('PUT', '/api/grants/alice', () => html)
    app.on('GET', '/api/grants', () => html)
    await page.locator('input[aria-label^="alice — Files: read"]').check()
    await expect(page.locator('#mcp-matrix').getByRole('button', { name: 'Retry' })).toBeVisible()
    await expect(page.locator('#mcp-matrix')).toHaveAttribute('aria-busy', 'false')
    expect(reads(app)).toBe(before + 1)
    expect(writes(app)).toHaveLength(1)
    expect(await page.evaluate(() => window.__toasts)).toContain(uncertain)
})
test('unreadable initial GET is a load error, not an uncertain write', async ({ page, app }) => {
    app.on('GET', '/api/grants', () => html)
    await app.open('/admin#access')
    await expect(page.locator('#mcp-matrix').getByRole('button', { name: 'Retry' })).toBeVisible()
    expect(await page.evaluate(() => window.__toasts)).not.toContain(uncertain)
    expect(writes(app)).toHaveLength(0)
})
test('transport preserves HTTP errors and ordinary parse failures, with explicit uncertain-write opt-in', async ({ page, app }) => {
    await page.goto('/health')
    const result = await page.evaluate(async () => {
        const { createRequest } = await import('/modules/platform/http.js')
        async function failure(status, options) {
            const api = createRequest({ generateUrl: p => p, requestToken: () => 'synthetic', fetch: async () => new Response('<html>', { status }) })
            try { await api('POST', '/synthetic', {}, options); return null }
            catch (e) { return { message: e.message, name: e.name, data: e.data ?? null, cause: e.cause?.name ?? null } }
        }
        return [await failure(412, { uncertainWrite: true }), await failure(200), await failure(200, { uncertainWrite: true })]
    })
    expect(result[0]).toMatchObject({ message: '412', data: null })
    expect(result[1]).toMatchObject({ name: 'SyntaxError', data: null })
    expect(result[2]).toMatchObject({ message: '503', data: { uncertain: true }, cause: 'SyntaxError' })
})
