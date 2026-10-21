// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'
import { mountModule } from '../support/mount-module.js'

const client = (id, name) => ({ id, clientId: 'nextcloud-mcp-custom-' + id, name, enabled: true, redirectUris: ['https://client.example/cb'] })
const cards = page => page.locator('.mcp-custom-client')
const writes = app => app.calls.filter(c => c.path === '/api/oauth-clients' && c.method === 'PUT')
async function draft(page, name = 'New client') {
    await page.locator('#mcp-custom-add').click()
    const card = cards(page).last()
    await card.getByLabel('Name', { exact: true }).fill(name)
    await card.getByLabel('Accepted redirect URIs', { exact: true }).fill('https://new.example/cb')
    return card
}

test('custom clients save, toggle and delete with successive revisions and literal labels', async ({ page, app }) => {
    await app.open('/admin#clients')
    const card = await draft(page, '<img src=x onerror=alert(1)>')
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(cards(page).first().locator('.mcp-status')).toHaveText('Saved')
    await expect(cards(page).locator('img')).toHaveCount(0)
    await cards(page).first().getByLabel('Enabled', { exact: true }).uncheck()
    await expect(cards(page).first().getByLabel('Enabled', { exact: true })).toBeEnabled()
    page.once('dialog', dialog => dialog.accept())
    await cards(page).first().getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(cards(page)).toHaveCount(0)
    expect(writes(app).map(c => c.body.expectedRevision)).toEqual(['0', '1', '2'])
    expect(writes(app)[2].body.customClients).toEqual([])
})

for (const status of [409, 428, 503]) {
    test(`OAuth ${status} reload preserves the new draft and requires an explicit second save`, async ({ page, app }) => {
        let count = 0
        app.on('PUT', '/api/oauth-clients', ({ state, body }) => {
            if (!count++) {
                state.revision = '7'
                return { status, body: { uncertain: true, revision: '99' } }
            }
            expect(body.expectedRevision).toBe('7')
            state.oauth.customClients = [{ ...body.customClients[0], id: 'new', clientId: 'nextcloud-mcp-custom-new' }]
            state.revision = '8'
            return { body: { ...state.oauth, revision: '8' } }
        })
        await app.open('/admin#clients')
        const card = await draft(page)
        await card.getByRole('button', { name: 'Save', exact: true }).click()
        await expect(cards(page).first().locator('.mcp-status')).toContainText('Your changes are kept here')
        expect(writes(app)).toHaveLength(1)
        await expect(cards(page).first().getByLabel('Name', { exact: true })).toHaveValue('New client')
        await cards(page).first().getByRole('button', { name: 'Save', exact: true }).click()
        await expect(cards(page).first().locator('.mcp-status')).toHaveText('Saved')
        expect(writes(app)).toHaveLength(2)
    })
}

for (const refuse of [false, true]) {
    test(`queued client toggles ${refuse ? 'are discarded behind a conflict' : 'use the preceding result and revision'}`, async ({ page, app }) => {
        app.state.oauth.customClients = [client('a', 'A'), client('b', 'B')]
        let release
        let count = 0
        app.on('PUT', '/api/oauth-clients', async ({ body, state }) => {
            if (!count++) await new Promise(resolve => { release = resolve })
            if (refuse) { state.revision = '4'; return { status: 409 } }
            state.oauth.customClients = body.customClients.map(c => ({ ...c, clientId: 'nextcloud-mcp-custom-' + c.id }))
            state.revision = String(BigInt(state.revision) + 1n)
            return { body: { ...state.oauth, revision: state.revision } }
        })
        await app.open('/admin#clients')
        await cards(page).nth(0).getByLabel('Enabled', { exact: true }).uncheck()
        await expect.poll(() => typeof release).toBe('function')
        await cards(page).nth(1).getByLabel('Enabled', { exact: true }).uncheck()
        expect(writes(app)).toHaveLength(1)
        release()
        await expect(cards(page).nth(1).getByLabel('Enabled', { exact: true })).toBeEnabled()
        if (refuse) {
            await expect(cards(page).nth(0).getByLabel('Enabled', { exact: true })).toBeChecked()
            await expect(cards(page).nth(1).getByLabel('Enabled', { exact: true })).toBeChecked()
            expect(writes(app)).toHaveLength(1)
        } else {
            await expect.poll(() => writes(app).length).toBe(2)
            expect(writes(app)[1].body.expectedRevision).toBe('1')
            expect(writes(app)[1].body.customClients.map(c => c.enabled)).toEqual([false, false])
        }
    })
}

test('failed deletion keeps its card and edited client deleted elsewhere becomes an unsaved new card', async ({ page, app }) => {
    app.state.oauth.customClients = [client('a', 'A')]
    app.on('PUT', '/api/oauth-clients', () => ({ status: 500 }))
    await app.open('/admin#clients')
    page.once('dialog', dialog => dialog.accept())
    await cards(page).first().getByRole('button', { name: 'Delete', exact: true }).click()
    await expect(cards(page).first().locator('.mcp-status')).toHaveText('Not saved')
    await cards(page).first().getByLabel('Name', { exact: true }).fill('Edited')
    app.on('PUT', '/api/oauth-clients', ({ state }) => {
        state.oauth.customClients = []
        state.revision = '3'
        return { status: 409 }
    })
    await cards(page).first().getByRole('button', { name: 'Save', exact: true }).click()
    await expect(cards(page).first().locator('.mcp-status')).toContainText('deleted in the meantime')
    await expect(cards(page).first().getByLabel('Name', { exact: true })).toHaveValue('Edited')
    await expect(cards(page).first().locator('code')).toHaveCount(0)
})

test('custom connection labels remain plain text and use a fallback after client deletion', async ({ page, app }) => {
    app.state.connections[0].client = { kind: 'custom', name: '<img src=x onerror=alert(1)>', id: 'nextcloud-mcp-custom-a', host: '' }
    app.state.connections[1].client = { kind: 'custom', name: '', id: 'nextcloud-mcp-custom-b', host: '' }
    await app.open('/admin#clients')
    await expect(page.locator('#mcp-connections-table')).toContainText('<img src=x onerror=alert(1)>')
    await expect(page.locator('#mcp-connections-table')).toContainText('Self-hosted client')
    await expect(page.locator('#mcp-connections-table img')).toHaveCount(0)
})

test('a local matrix commit advances the OAuth block and its next commit advances the matrix', async ({ page, app }) => {
    await app.open('/admin#access?view=operations')
    await page.locator('input[aria-label^="alice — Files: read"]').check()
    await expect(page.locator('input[aria-label^="alice — Files: read"]')).toBeEnabled()
    await page.getByRole('tab', { name: 'Clients and connections' }).click()
    const card = await draft(page)
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    await expect(cards(page).first().locator('.mcp-status')).toHaveText('Saved')
    expect(writes(app)[0].body.expectedRevision).toBe('1')
    await page.getByRole('tab', { name: 'Access and permissions' }).click()
    await page.locator('#mcp-view-operations').click()
    await page.locator('input[aria-label^="bob — Files: create"]').check()
    await expect(page.locator('input[aria-label^="bob — Files: create"]')).toBeEnabled()
    expect(app.calls.filter(c => c.path === '/api/grants/bob')[0].body.expectedRevision).toBe('2')
})

test('an unchanged OAuth save cannot announce a foreign commit from its readback', async ({ page, app }) => {
    app.on('PUT', '/api/oauth-clients', ({ state }) => {
        state.revision = '1'
        return { body: { ...state.oauth, revision: '1' } }
    })
    await app.open('/admin#clients')
    await page.locator('#mcp-oauth-hosts-save').click()
    await expect(page.locator('#mcp-oauth-hosts-status')).toHaveText('Saved')
    await page.getByRole('tab', { name: 'Access and permissions' }).click()
    await page.locator('#mcp-view-operations').click()
    await page.locator('input[aria-label^="alice — Files: read"]').check()
    await expect(page.locator('input[aria-label^="alice — Files: read"]')).not.toBeChecked()
    expect(app.calls.filter(c => c.path === '/api/grants/alice')[0].body.expectedRevision).toBe('0')
})

test('disposing the client editor ignores an accepted late response', async ({ page, app }) => {
    let release
    app.on('PUT', '/api/oauth-clients', async ({ state, body }) => {
        await new Promise(resolve => { release = resolve })
        return { body: { ...state.oauth, customClients: [{ ...body.customClients[0], id: 'new', clientId: 'nextcloud-mcp-custom-new' }], revision: '1' } }
    })
    await mountModule(page, 'admin/clients/oauth-clients.js', 'mountOauthClients', 'mcp-block-oauth')
    const card = await draft(page)
    await card.getByRole('button', { name: 'Save', exact: true }).click()
    await expect.poll(() => typeof release).toBe('function')
    await page.evaluate(() => window.mounted.destroy())
    release()
    await expect(cards(page).first().locator('code')).toHaveCount(0)
    await expect(cards(page).first().locator('.mcp-status')).toHaveText('')
    expect(await page.evaluate(() => window.__toasts)).toEqual([])
})
