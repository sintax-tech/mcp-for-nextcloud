// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

test('service disposal ignores a late save and remount attaches exactly one listener', async ({ page, app }) => {
    let release
    app.on('PUT', '/api/service', async () => {
        await new Promise(resolve => { release = resolve })
        return { body: { enabled: true, revision: '1' } }
    })
    await page.goto('/admin?bare=1')
    await page.evaluate(async () => {
        const { mountService } = await import('/modules/admin/overview/service.js')
        const { createRequest } = await import('/modules/platform/http.js')
        const { createNextcloud } = await import('/modules/platform/nextcloud.js')
        const host = createNextcloud()
        window.mountServiceFixture = () => mountService({ root: document.getElementById('mcp-block-status'), request: createRequest(host), ...host })
        window.mountedService = window.mountServiceFixture()
    })
    await expect(page.locator('#mcp-service')).toBeEnabled()
    await page.locator('#mcp-service').press('Space')
    await expect.poll(() => typeof release).toBe('function')
    await page.evaluate(() => { window.mountedService.destroy(); window.mountedService.destroy() })
    release()
    await expect(page.locator('#mcp-service-status')).toHaveText('')
    await page.evaluate(() => { window.mountedService = window.mountServiceFixture() })
    await expect(page.locator('#mcp-service')).toBeEnabled()
    await page.locator('#mcp-service').press('Space')
    await expect.poll(() => app.calls.filter(call => call.path === '/api/service').length).toBe(2)
    release()
    await expect(page.locator('#mcp-service-status')).toHaveText('Saved')
})

test('importing a feature without a page or host has no effects', async ({ page, app }) => {
    await page.goto('/health')
    await page.evaluate(async () => {
        delete window.OC
        delete window.OCP
        delete window.t
        delete window.n
        await import('/modules/admin/picker.js')
        await import('/modules/settings/connections.js')
        await import('/modules/admin/logs/access-groups.js')
        await import('/modules/admin/tabs.js')
    })
    expect(app.calls).toEqual([])
})
