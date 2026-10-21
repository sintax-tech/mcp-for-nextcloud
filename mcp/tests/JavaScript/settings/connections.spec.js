// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

for (const scope of ['admin', 'personal']) {
    test(`${scope} connection revoke uses its scope and preserves plain text confirmation`, async ({ page, app }) => {
        app.state.connections[0].client.host = "D'Avila <client>"
        await app.open(scope === 'admin' ? '/admin#clients' : '/personal')
        const base = scope === 'admin' ? '/api/connections' : '/api/my/connections'
        expect(app.calls.find(call => call.path === base).query).toEqual(scope === 'admin' ? { page: '1', search: '' } : { page: '1' })
        page.once('dialog', async dialog => {
            expect(dialog.message()).toContain("D'Avila <client>")
            await dialog.accept()
        })
        await page.getByRole('button', { name: /^Revoke (the )?connection|^Revoke D'Avila/ }).first().click()
        await expect(page.locator('#mcp-connections-status')).toHaveText('Connection revoked.')
        expect(app.calls.find(call => call.method === 'DELETE').path).toBe(base + '/1')
        expect(app.calls.find(call => call.method === 'DELETE').headers.requesttoken).toBe('synthetic-request-token')
        expect(app.calls.find(call => call.method === 'DELETE').headers['content-type']).toBeUndefined()
        if (scope === 'admin') await expect(page.locator('#mcp-count-connections')).toHaveText('1')
        else {
            expect(app.calls.some(call => call.path.startsWith('/api/connections'))).toBe(false)
            await expect(page.getByRole('button', { name: /Revoke all/ })).toHaveCount(0)
        }
    })
}

test('connection cancel sends nothing and failed revoke reaches status without Toast', async ({ page, app }) => {
    await app.open('/personal')
    await page.evaluate(() => { delete window.OCP })
    page.once('dialog', dialog => dialog.dismiss())
    await page.getByRole('button', { name: 'Revoke client.example', exact: true }).first().click()
    expect(app.calls.filter(call => call.method === 'DELETE')).toHaveLength(0)
    app.on('DELETE', '/api/my/connections/1', () => ({ status: 500 }))
    page.once('dialog', dialog => dialog.accept())
    await page.getByRole('button', { name: 'Revoke client.example', exact: true }).first().click()
    await expect(page.locator('#mcp-connections-status')).toHaveText('Could not revoke the connection.')
})

test('admin revoke-user encodes UID, resets the page and refreshes counts', async ({ page, app }) => {
    app.state.connections[0].uid = 'team/one'
    await app.open('/admin#clients')
    page.once('dialog', dialog => dialog.accept())
    await page.getByRole('button', { name: 'Revoke all connections of Alice' }).first().click()
    await expect(page.locator('#mcp-connections-status')).toHaveText('Connections of Alice revoked.')
    expect(app.calls.find(call => call.method === 'DELETE').path).toBe('/api/connections/users/team%2Fone')
    await expect(page.locator('#mcp-count-connections')).toHaveText('0')
    await expect(page.locator('#mcp-connections-table')).toContainText('No active connections.')
})

test('connection load retries and removing the final row returns to the previous page', async ({ page, app }) => {
    let failed = true
    let removed = false
    app.on('GET', '/api/my/connections', ({ query, state }) => {
        if (failed) return { status: 500 }
        const pageNumber = Number(query.get('page'))
        return { body: { connections: removed && pageNumber === 2 ? [] : state.connections.slice(0, 1), page: pageNumber, hasMore: pageNumber === 1 } }
    })
    app.on('DELETE', '/api/my/connections/1', () => { removed = true; return { body: {} } })
    await app.open('/personal')
    failed = false
    await page.getByRole('button', { name: 'Retry', exact: true }).click()
    await page.locator('#mcp-connections-next').click()
    await expect(page.locator('#mcp-connections-page-info')).toHaveText('Page 2')
    page.once('dialog', dialog => dialog.accept())
    await page.getByRole('button', { name: 'Revoke client.example', exact: true }).click()
    await expect(page.locator('#mcp-connections-page-info')).toHaveText('Page 1')
})
