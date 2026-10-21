// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'
import { mountModule } from '../support/mount-module.js'

test('isolated tags repair uses the supplied alerts and disposes picker controls', async ({ page, app }) => {
    app.state.tags = { tags: [], selected: [], invalid: true }
    await mountModule(page, 'admin/protection/excluded-tags.js', 'mountExcludedTags', 'mcp-block-tags')
    await expect(page.locator('#mcp-tags-invalid-overview')).not.toHaveAttribute('hidden')
    page.once('dialog', dialog => dialog.accept())
    await page.locator('#mcp-tags-repair').click()
    await expect(page.locator('#mcp-tags-invalid-overview')).toHaveAttribute('hidden')
    expect(app.calls.find(call => call.method === 'PUT').body).toEqual({ tagIds: [] })
    await page.evaluate(() => window.mounted.destroy())
    await expect(page.locator('#mcp-tags-list')).toBeEmpty()
})

test('isolated log access resynchronizes conflict before the next versioned save', async ({ page, app }) => {
    await mountModule(page, 'admin/logs/access-groups.js', 'mountLogAccess', 'mcp-block-logs')
    let writes = 0
    app.on('PUT', '/api/logs-access', ({ body, state }) => {
        if (!writes++) {
            Object.assign(state.logAccess, { groups: ['ops'], version: 10 })
            state.revision = '1'
            return { status: 409 }
        }
        expect(body).toEqual({ groups: ['ops', 'staff'], previous: ['ops'], version: 10, expectedRevision: '1' })
        return { body: { ...state.logAccess, groups: body.groups, version: 11, revision: '2' } }
    })
    await page.getByRole('button', { name: 'Remove Staff' }).click()
    await expect(page.getByRole('button', { name: 'Remove Operations' })).toBeVisible()
    await page.getByRole('combobox', { name: 'Search groups' }).click()
    await page.getByRole('option', { name: 'Staff', exact: true }).click()
    await expect(page.locator('#mcp-logs-status')).toHaveText('Saved')
    expect(await page.evaluate(() => window.__reloads)).toBe(2)
})

test('isolated picker rolls back before resync and ignores late save after destroy', async ({ page, app }) => {
    await mountModule(page, 'admin/protection/excluded-tags.js', 'mountExcludedTags', 'mcp-block-tags')
    let release
    app.on('PUT', '/api/admin/tags', async () => {
        await new Promise(resolve => { release = resolve })
        return { status: 503 }
    })
    await page.getByRole('combobox', { name: 'Search tags' }).fill('Shared')
    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('Enter')
    await expect.poll(() => typeof release).toBe('function')
    await page.evaluate(() => window.mounted.destroy())
    release()
    await expect(page.locator('#mcp-tags-list')).toBeEmpty()
    expect(await page.evaluate(() => window.__toasts)).toEqual([])
})

for (const personal of [false, true]) {
    test(`isolated ${personal ? 'personal' : 'admin'} connections retain scoped requests and status fallback`, async ({ page, app }) => {
        const base = personal ? '/api/my/connections' : '/api/connections'
        await mountModule(page, 'settings/connections.js', 'mountConnections', 'mcp-connections', personal)
        app.on('DELETE', base + '/1', () => ({ status: 500 }))
        await page.evaluate(() => { delete window.OCP })
        page.once('dialog', dialog => dialog.accept())
        await page.getByRole('button', { name: personal ? 'Revoke client.example' : 'Revoke client.example for Alice', exact: true }).first().click()
        await expect(page.locator('#mcp-connections-status')).toHaveText('Could not revoke the connection.')
        expect(app.calls.find(call => call.method === 'DELETE').headers['content-type']).toBeUndefined()
        await page.evaluate(() => window.mounted.destroy())
        await expect(page.locator('#mcp-connections-table tbody')).toBeEmpty()
    })
}

test('isolated small settings use original payloads and server replies', async ({ page, app }) => {
    await mountModule(page, 'admin/clients/oauth-clients.js', 'mountOauthClients', 'mcp-block-oauth')
    await page.locator('#mcp-oauth-hosts').fill(' ONE.EXAMPLE ')
    await page.locator('#mcp-oauth-hosts-save').click()
    await expect(page.locator('#mcp-oauth-hosts-status')).toHaveText('Saved')
    expect(app.calls.find(call => call.method === 'PUT').body).toEqual({ hosts: ['one.example'], expectedRevision: '0' })
    await mountModule(page, 'admin/overview/checkout-limit.js', 'mountCheckoutLimit', 'mcp-block-status')
    await page.locator('#mcp-checkout-limit').fill('3')
    await page.locator('#mcp-checkout-limit-save').click()
    await expect(page.locator('#mcp-checkout-limit-status')).toHaveText('Saved')
    expect(app.calls.find(call => call.path === '/api/checkout-limit' && call.method === 'PUT').body).toEqual({ mib: 3 })
    await mountModule(page, 'admin/logs/privacy.js', 'mountLogPrivacy', 'mcp-block-logs-privacy')
    await page.locator('#mcp-logs-hide-file-names').press('Space')
    await expect(page.locator('#mcp-logs-hide-file-names-status')).toHaveText('Saved')
})

test('isolated tabs clean up navigation and copy handles rejection', async ({ page, app }) => {
    await mountModule(page, 'admin/overview/endpoint-copy.js', 'mountEndpointCopy', 'mcp-admin')
    await page.evaluate(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async () => { throw new Error('Denied') } } }))
    await page.locator('[data-copy=mcp-endpoint]').click()
    await expect.poll(() => page.evaluate(() => window.__toasts)).toContain('Could not copy the endpoint.')
    await mountModule(page, 'admin/tabs.js', 'mountTabs', 'mcp-admin')
    await page.getByRole('tab', { name: 'Advanced' }).click()
    await expect(page).toHaveURL(/#advanced$/)
    await page.evaluate(() => window.mounted.destroy())
    await expect(page.locator('[role=tabpanel]:visible')).toHaveCount(5)
})
