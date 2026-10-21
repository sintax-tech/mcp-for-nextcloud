// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

test('matrix disposal removes debounce and header observation; remount has one query listener', async ({ page, app }) => {
    await page.goto('/admin?bare=1#access?view=operations')
    await page.evaluate(async () => {
        const NativeObserver = window.ResizeObserver
        window.__observers = []
        window.ResizeObserver = class {
            constructor(callback) { this.native = new NativeObserver(callback); this.rows = []; this.disconnected = false; window.__observers.push(this) }
            observe(row) { this.rows.push(row); this.disconnected = false; this.native.observe(row) }
            disconnect() { this.disconnected = true; this.native.disconnect() }
        }
        const { mountMatrix } = await import('/modules/admin/access/controller.js')
        const { createNextcloud } = await import('/modules/platform/nextcloud.js')
        const { createRequest } = await import('/modules/platform/http.js')
        const host = createNextcloud()
        window.mountMatrixFixture = () => mountMatrix({ root: document.getElementById('mcp-block-matrix'), ...host, request: createRequest(host) })
        window.matrix = window.mountMatrixFixture()
        await window.matrix.ready
        await window.matrix.reload()
    })
    expect(await page.evaluate(() => window.__observers[0].rows.length)).toBe(2)
    await page.clock.install()
    await page.clock.pauseAt(new Date())
    await page.locator('#mcp-search').fill('cancelled')
    await page.evaluate(() => { window.matrix.destroy(); window.matrix.destroy() })
    expect(await page.evaluate(() => window.__observers[0].disconnected)).toBe(true)
    await page.clock.runFor(400)
    expect(app.calls.filter(call => call.path === '/api/grants')).toHaveLength(2)
    await page.evaluate(async () => { window.matrix = window.mountMatrixFixture(); await window.matrix.ready })
    await page.locator('#mcp-search').fill('alice')
    await page.clock.runFor(300)
    await expect.poll(() => app.calls.filter(call => call.path === '/api/grants').length).toBe(4)
    await expect(page.locator('#mcp-matrix')).toHaveAttribute('aria-busy', 'false')
    const sticky = await page.locator('#mcp-matrix').evaluate(table => ({ height: table.tHead.rows[0].getBoundingClientRect().height, offset: parseFloat(table.tHead.rows[1].cells[0].style.top) }))
    expect(sticky.offset).toBe(sticky.height)
})

for (const matrix of [true, false]) {
    test(`${matrix ? 'matrix' : 'connections'} remount keeps visible filters consistent with its fresh query`, async ({ page, app }) => {
        await page.goto('/admin?bare=1')
        await page.evaluate(async matrix => {
            const { createNextcloud } = await import('/modules/platform/nextcloud.js')
            const { createRequest } = await import('/modules/platform/http.js')
            const host = createNextcloud()
            const component = await import(matrix ? '/modules/admin/access/controller.js' : '/modules/settings/connections.js')
            window.remount = async () => {
                window.owner?.destroy()
                window.owner = component[matrix ? 'mountMatrix' : 'mountConnections']({
                    root: document.getElementById(matrix ? 'mcp-block-matrix' : 'mcp-connections'),
                    ...host, request: createRequest(host), mode: 'admin',
                })
                await window.owner.ready
            }
            await window.remount()
        }, matrix)
        await page.clock.install()
        await page.clock.pauseAt(new Date())
        const search = page.locator(matrix ? '#mcp-search' : '#mcp-connections-search')
        await search.fill('alice')
        await page.clock.runFor(300)
        const endpoint = matrix ? '/api/grants' : '/api/connections'
        await expect.poll(() => app.calls.filter(call => call.path === endpoint).at(-1)?.query.search).toBe('alice')
        if (matrix) await page.locator('#mcp-group').selectOption('staff')
        await expect(page.locator(matrix ? '#mcp-matrix' : '#mcp-connections-table')).toHaveAttribute('aria-busy', 'false')
        await page.evaluate(() => window.remount())
        await expect(search).toHaveValue('')
        expect(app.calls.filter(call => call.path === endpoint).at(-1).query.search).toBe('')
        if (matrix) {
            await expect(page.locator('#mcp-group')).toHaveValue('')
            await expect(page.locator('#mcp-group option[value="staff"]')).toHaveCount(1)
            expect(app.calls.filter(call => call.path === endpoint).at(-1).query.group).toBe('')
        }
    })
}
