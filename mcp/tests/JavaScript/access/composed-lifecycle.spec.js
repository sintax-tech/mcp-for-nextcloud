// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'
for (const reject of [false, true]) {
    test(`admin remount removes previous listeners and ignores late scope ${reject ? 'failure' : 'success'}`, async ({ page, app }) => {
        const replies = []
        for (const module of ['files', 'calendar', 'tasks']) app.on('GET', `/api/scope/alice/${module}`, () => new Promise(resolve => replies.push(() => resolve(reject ? { status: 503 } : { body: { uid: 'alice', module, revision: '0', active: true, mode: 'restricted', limit: 200, items: [{ id: '11', name: 'Old secret' }] } }))))
        await page.goto('/admin?bare=1#access')
        await page.evaluate(async () => {
            const { mountAdmin } = await import('/modules/admin/bootstrap.js')
            const { createNextcloud } = await import('/modules/platform/nextcloud.js')
            const { createRequest } = await import('/modules/platform/http.js')
            const host = createNextcloud()
            window.mountAdminFixture = () => mountAdmin({ root: document.querySelector('#mcp-admin'), ...host, request: createRequest(host) })
            window.adminOwner = window.mountAdminFixture(); await window.adminOwner.ready
        })
        await page.locator('#mcp-scope-entry').click()
        await page.locator('#mcp-scope-user').fill('alice')
        await page.locator('#mcp-scope-form button').click()
        await expect.poll(() => replies.length).toBe(3)
        await page.evaluate(async () => { window.adminOwner.destroy(); window.adminOwner.destroy(); window.adminOwner = window.mountAdminFixture(); await window.adminOwner.ready })
        replies.forEach(reply => reply())
        await expect(page.locator('#mcp-scope-modules')).toBeEmpty()
        await expect(page.locator('#mcp-scope-alert')).toBeEmpty()
        await expect(page.locator('#mcp-scope')).not.toHaveAttribute('role', 'dialog')
        await expect(page.locator('.mcp-reset-toolbar')).toHaveCount(1)
        const before = app.calls.filter(c => c.path === '/api/group-directory').length
        await page.locator('#mcp-group-search').fill('fresh')
        await expect.poll(() => app.calls.filter(c => c.path === '/api/group-directory').length).toBe(before + 1)
        expect(app.calls.filter(c => c.path === '/api/group-directory').at(-1).query.search).toBe('fresh')
    })
}
