// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

function twoUsers(app) {
    const saved = {}
    for (const uid of ['alice', 'bob']) {
        saved[uid] = {}
        for (const module of ['files', 'calendar', 'tasks']) {
            saved[uid][module] = { uid, module, active: true, mode: 'restricted', limit: 200,
                items: [{ id: uid === 'alice' ? '11' : '22', name: `${uid} folder`, path: null, found: true }] }
            app.on('GET', `/api/scope/${uid}/${module}`, ({ state }) => ({ body: { ...saved[uid][module], revision: state.revision } }))
            app.on('GET', `/api/scope/${uid}/${module}/candidates`, () => ({ body: {
                items: [{ id: '23', name: `${uid} extra`, path: null }], total: 1, page: 1, hasMore: false,
            } }))
            app.on('PUT', `/api/scope/${uid}/${module}`, ({ body, state }) => save(uid, module, body, state))
        }
    }
    function save(uid, module, body, state) {
        expect(body.expectedRevision).toBe(state.revision)
        saved[uid][module] = { ...saved[uid][module], mode: body.mode,
            items: (body.items || []).map(id => ({ id, name: `${uid} folder`, path: null, found: true })) }
        state.revision = String(BigInt(state.revision) + 1n)
        return { body: { revision: state.revision, changed: true, receiptId: `${uid}-receipt` } }
    }
    return { saved, save }
}
async function select(page, uid) {
    await page.locator('#mcp-scope-user').fill(uid)
    await page.locator('#mcp-scope-form').getByRole('button').click()
    await expect(page.locator('#mcp-scope-module-files')).toContainText(`${uid} folder`)
}
async function start(page, app) {
    page.on('dialog', dialog => dialog.accept())
    await app.open()
    await page.locator('#mcp-scope-entry').click()
    await select(page, 'alice')
}

for (const operation of ['browse', 'reload', 'save', 'undo']) {
    for (const rejected of [false, true]) {
        test(`late ${operation} ${rejected ? 'failure' : 'success'} stays with its original scope owner`, async ({ page, app }) => {
            const { saved, save } = twoUsers(app)
            let release, began = false
            const gate = new Promise(resolve => { release = resolve })
            const finish = async result => { began = true; await gate; return rejected ? { status: 503, body: { uncertain: true } } : result() }
            await start(page, app)
            if (operation === 'browse') {
                app.on('GET', '/api/scope/alice/files/candidates', () => finish(() => ({ body: { items: [], page: 1, hasMore: false } })))
                await page.locator('#mcp-scope-module-files').getByRole('button', { name: 'Search', exact: true }).click()
            } else {
                if (operation === 'save') app.on('PUT', '/api/scope/alice/files', ({ body, state }) => finish(() => save('alice', 'files', body, state)))
                if (operation === 'reload') app.on('GET', '/api/scope/alice/files', ({ state }) => finish(() => ({ body: { ...saved.alice.files, revision: state.revision } })))
                await page.locator('#mcp-scope-mode-files-unrestricted').check()
                await page.locator('#mcp-scope-save-files').click()
                if (operation === 'undo') {
                    await expect(page.locator('#mcp-scope-undo-files')).toBeVisible()
                    app.on('POST', '/api/grants/undo', ({ state }) => finish(() => {
                        state.revision = String(BigInt(state.revision) + 1n)
                        return { body: { revision: state.revision, changed: true } }
                    }))
                    await page.locator('#mcp-scope-undo-files').click()
                }
            }
            await expect.poll(() => began).toBe(true)
            await select(page, 'bob')
            const response = page.waitForResponse(r => operation === 'undo' ? r.url().endsWith('/api/grants/undo')
                : r.url().includes('/api/scope/alice/files') && (operation !== 'save' || r.request().method() === 'PUT'))
            release()
            await response
            // Wait for the actual controller continuations, not a mocked controller.
            await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))))
            await expect(page.locator('#mcp-scope-module-files')).toContainText('bob folder')
            await expect(page.locator('#mcp-scope-module-files')).not.toContainText('alice folder')
            await expect(page.locator('#mcp-scope-undo-files')).toHaveCount(0)
            await expect(page.locator('#mcp-scope-alert')).toHaveText('')
            await page.locator('#mcp-scope-module-files').getByRole('button', { name: 'Search', exact: true }).click()
            await page.getByRole('button', { name: 'Add bob extra', exact: true }).click()
            await page.locator('#mcp-scope-save-files').click()
            await expect(page.locator('#mcp-scope-undo-files')).toBeVisible()
            const bobWrites = app.calls.filter(c => c.method === 'PUT' && c.path === '/api/scope/bob/files')
            expect(bobWrites).toHaveLength(1)
            expect(bobWrites[0].body.items).toEqual(['22', '23'])
        })
    }
}

test('detached scope controls cannot write after another user opens', async ({ page, app }) => {
    twoUsers(app)
    await start(page, app)
    await page.locator('#mcp-scope-mode-files-unrestricted').check()
    await page.locator('#mcp-scope-save-files').evaluate(node => { window.detachedSave = node })
    await select(page, 'bob')
    await page.evaluate(() => window.detachedSave.click())
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))))
    expect(app.calls.filter(c => c.method === 'PUT' && c.path.startsWith('/api/scope/'))).toEqual([])
    await expect(page.locator('#mcp-scope-module-files')).toContainText('bob folder')
})
