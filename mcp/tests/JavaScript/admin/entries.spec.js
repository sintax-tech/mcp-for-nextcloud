// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

for (const mode of ['admin', 'personal']) {
    test(`${mode} loads one final classic entry and ignores absent roots`, async ({ page, app }) => {
        await app.open('/' + mode)
        await expect(page.locator('script[src]')).toHaveAttribute('src', `/apps/mcp/js/${mode}.js`)
        const ids = await page.locator('[id]').evaluateAll(nodes => nodes.map(node => node.id))
        expect(new Set(ids).size).toBe(ids.length)
        const source = await (await page.request.get(`/apps/mcp/js/${mode}.js`)).text()
        expect(source).not.toContain('window.McpAdminPicker')
        const calls = app.calls.length
        await page.goto('/' + mode + '?bare=1')
        await page.evaluate(() => { document.body.replaceChildren(); delete window.OC; delete window.t; delete window.n })
        await page.addScriptTag({ content: source })
        expect(app.calls).toHaveLength(calls)
    })
}
