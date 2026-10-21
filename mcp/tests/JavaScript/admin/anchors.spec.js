// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'
import { mountModule } from '../support/mount-module.js'

test('tab hashes remain literal IDs, never CSS selectors', async ({ page, app }) => {
    await mountModule(page, 'admin/tabs.js', 'mountTabs', 'mcp-admin')
    await page.evaluate(() => { location.hash = '[not-a-selector' })
    await expect(page.locator('#mcp-panel-overview')).toBeVisible()
    await page.evaluate(() => {
        const anchor = document.createElement('p')
        anchor.id = 'anchor:with[brackets]'
        document.getElementById('mcp-panel-protection').append(anchor)
        location.hash = anchor.id
    })
    await expect(page.locator('#mcp-panel-protection')).toBeVisible()
})
