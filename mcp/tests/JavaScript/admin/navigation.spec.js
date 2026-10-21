// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

test('tabs keep app queries, keyboard focus and history', async ({ page, app }) => {
    await app.open('/admin#access?view=operations&app=files')
    await expect(page.locator('#mcp-app')).toHaveValue('files')
    await page.getByRole('tab', { name: 'Access and permissions' }).focus()
    await page.keyboard.press('ArrowRight')
    await expect(page.getByRole('tab', { name: 'Clients and connections' })).toBeFocused()
    await expect(page).toHaveURL(/#clients$/)
    await page.keyboard.press('ArrowLeft')
    await expect(page).toHaveURL(/#access\?view=operations&app=files$/)
    await page.goBack()
    await expect(page.locator('#mcp-panel-clients')).toBeVisible()
    await page.getByRole('tab', { name: 'Clients and connections' }).focus()
    await page.keyboard.press('Home')
    await expect(page.getByRole('tab', { name: 'Overview', exact: true })).toBeFocused()
    await page.keyboard.press('End')
    await expect(page.getByRole('tab', { name: 'Advanced' })).toBeFocused()
})

test('legacy anchors select their panel and unknown hashes fall back', async ({ page, app }) => {
    await app.open('/admin#mcp-block-tags')
    await expect(page.locator('#mcp-panel-protection')).toBeVisible()
    await page.evaluate(() => { location.hash = 'unknown' })
    await expect(page.locator('#mcp-panel-overview')).toBeVisible()
})

test('without JavaScript every section and the privacy explanation remain readable', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false })
    try {
        const page = await context.newPage()
        await page.goto('http://127.0.0.1:18461/admin')
        await expect(page.locator('[role=tabpanel]:visible')).toHaveCount(5)
        await expect(page.locator('.mcp-block:visible')).toHaveCount(11)
        await expect(page.locator('.mcp-tab-bar')).toBeHidden()
        await expect(page.locator('#mcp-logs-privacy-detail')).toBeVisible()
    } finally { await context.close() }
})

test('unknown app is replaced without adding history or an API filter', async ({ page, app }) => {
    await app.open('/admin#access?view=operations&app=unknown&keep=yes')
    await expect(page).toHaveURL(/#access\?view=operations&keep=yes$/)
    expect(app.calls.find(call => call.path === '/api/grants').query).toEqual({ search: '', group: '', filter: 'eligible', page: '1' })
    const before = app.calls.filter(call => call.path === '/api/grants').length
    await page.locator('#mcp-app').selectOption('files')
    await expect(page.locator('#mcp-matrix [data-module=logs]:visible')).toHaveCount(0)
    expect(app.calls.filter(call => call.path === '/api/grants')).toHaveLength(before)
})
