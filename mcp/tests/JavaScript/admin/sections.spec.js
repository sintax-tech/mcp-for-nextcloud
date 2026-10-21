// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

test('invalid empty tags require confirmation and repair every alert', async ({ page, app }) => {
    app.state.tags = { tags: [], selected: [], invalid: true }
    await app.open('/admin#protection')
    await expect(page.locator('#mcp-tags-invalid')).toBeVisible()
    await expect(page.locator('#mcp-tab-protection-alert')).not.toHaveAttribute('hidden')
    page.once('dialog', dialog => dialog.dismiss())
    await page.locator('#mcp-tags-repair').click()
    expect(app.calls.filter(call => call.method === 'PUT')).toHaveLength(0)
    page.once('dialog', dialog => dialog.accept())
    await page.locator('#mcp-tags-repair').click()
    await expect(page.locator('#mcp-tags-invalid')).toBeHidden()
    await expect(page.locator('#mcp-tags-invalid-overview')).toHaveAttribute('hidden')
    await expect(page.locator('#mcp-tab-protection-alert')).toHaveAttribute('hidden')
    expect(app.calls.find(call => call.method === 'PUT').body).toEqual({ tagIds: [] })
})

test('picker searches known tags, rolls back 503 and keeps selection/focus', async ({ page, app }) => {
    app.on('PUT', '/api/admin/tags', () => ({ status: 503 }))
    await app.open('/admin#protection')
    const search = page.getByRole('combobox', { name: 'Search tags' })
    await search.fill('not a tag')
    await expect(page.locator('#mcp-tags-list [role=option]:not([hidden])')).toHaveCount(0)
    await search.fill('shared')
    await page.keyboard.press('ArrowDown')
    await page.keyboard.press('Enter')
    await expect(page.getByRole('button', { name: 'Remove Private' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Remove Shared' })).toHaveCount(0)
    await expect(search).toBeFocused()
    await expect.poll(() => page.evaluate(() => window.__toasts)).toContain('Could not check the system tags, so the exclusion tags were not changed. Try again later.')
    expect(app.calls.find(call => call.method === 'PUT').body).toEqual({ tagIds: ['1', '2'] })
    await page.keyboard.press('Escape')
    await expect(search).toHaveValue('')
    await page.keyboard.press('Escape')
    await expect(search).toHaveAttribute('aria-expanded', 'false')
})

test('tag success shows collaborative warning and saved status expires', async ({ page, app }) => {
    await app.open('/admin#protection')
    await page.clock.install()
    await page.getByRole('combobox', { name: 'Search tags' }).click()
    await page.getByRole('option', { name: /^Shared/ }).click()
    await expect(page.locator('#mcp-tags-status')).toHaveText('Saved')
    await expect(page.locator('#mcp-tags-warning')).toBeVisible()
    await page.clock.fastForward(2001)
    await expect(page.locator('#mcp-tags-status')).toHaveText('')
})

test('log groups carry previous/version and a conflict resynchronizes before the next save', async ({ page, app }) => {
    app.state.logAccess.missing = ['removed']
    app.state.logAccess.groups.push('removed')
    let saves = 0
    app.on('PUT', '/api/logs-access', ({ body, state }) => {
        if (++saves === 1) {
            state.logAccess.groups = ['ops']
            state.logAccess.version = 8
            state.revision = '1'
            return { status: 409 }
        }
        expect(body.previous).toEqual(['ops'])
        expect(body.version).toBe(8)
        state.revision = '2'
        state.logAccess = { ...state.logAccess, groups: body.groups, version: 9 }
        return { body: { ...state.logAccess, groups: body.groups, version: 9, revision: '2' } }
    })
    await app.open('/admin#advanced')
    await page.getByRole('button', { name: 'Remove removed (group removed)' }).click()
    await expect(page.getByRole('button', { name: 'Remove Operations' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Remove Staff' })).toHaveCount(0)
    expect(app.calls.find(call => call.method === 'PUT').body).toEqual({ groups: ['staff'], previous: ['staff', 'removed'], version: 1, expectedRevision: '0' })
    await page.getByRole('combobox', { name: 'Search groups' }).click()
    await page.getByRole('option', { name: 'Staff', exact: true }).click()
    await expect(page.locator('#mcp-logs-status')).toHaveText('Saved')
    await expect.poll(() => app.calls.filter(call => call.path === '/api/grants').length).toBe(4)
})

test('failed log group save and reload expose the fallback without restoring stale choices', async ({ page, app }) => {
    await app.open('/admin#advanced')
    app.on('PUT', '/api/logs-access', () => ({ status: 500 }))
    app.on('GET', '/api/logs-access', () => ({ status: 503 }))
    await page.getByRole('combobox', { name: 'Search groups' }).click()
    await page.getByRole('option', { name: 'Operations' }).click()
    await expect(page.locator('#mcp-logs-groups')).toContainText('Could not load the groups.')
    await expect(page.locator('#mcp-logs-groups .mcp-picker-box')).toHaveAttribute('hidden')
})

test('privacy load failure disables the switch; details remain keyboard operable', async ({ page, app }) => {
    app.on('GET', '/api/logs-privacy', () => ({ status: 500 }))
    await app.open('/admin#advanced')
    await expect(page.locator('#mcp-logs-hide-file-names')).toBeDisabled()
    await expect(page.locator('#mcp-logs-privacy-detail')).toBeHidden()
    await page.locator('#mcp-logs-privacy-more').press('Enter')
    await expect(page.locator('#mcp-logs-privacy-detail')).toBeVisible()
    await expect(page.locator('#mcp-logs-privacy-more')).toHaveAttribute('aria-expanded', 'true')
})

test('privacy and service mutations roll back and admin fallback alerts remain visible', async ({ page, app }) => {
    app.on('PUT', '/api/logs-privacy', () => ({ status: 500 }))
    app.on('PUT', '/api/service', () => ({ status: 500 }))
    await app.open('/admin#advanced')
    await page.locator('#mcp-logs-hide-file-names').press('Space')
    await expect(page.locator('#mcp-logs-hide-file-names')).toBeChecked()
    await expect(page.locator('#mcp-logs-hide-file-names-status')).toHaveText('Not saved')
    await page.getByRole('tab', { name: 'Overview', exact: true }).click()
    await page.evaluate(() => { delete window.OCP })
    let message
    page.once('dialog', async dialog => { message = dialog.message(); await dialog.accept() })
    await page.locator('#mcp-service').press('Space')
    await expect.poll(() => message).toBe('Could not change the MCP service.')
    await expect(page.locator('#mcp-service')).toBeChecked()
    await expect(page.locator('#mcp-service')).toBeEnabled()
})

test('OAuth settings normalize hosts, separate native payload and render response text safely', async ({ page, app }) => {
    app.state.oauth.nativeClientId = '<img src=x onerror=alert(1)>'
    await app.open('/admin#clients')
    await page.locator('#mcp-oauth-hosts').fill(' ONE.EXAMPLE , Two.example ')
    await page.locator('#mcp-oauth-hosts-save').click()
    await expect(page.locator('#mcp-oauth-hosts-status')).toHaveText('Saved')
    expect(app.calls.find(call => call.method === 'PUT').body).toEqual({ hosts: ['one.example', 'two.example'], expectedRevision: '0' })
    await page.locator('#mcp-native-client').check()
    await expect(page.locator('#mcp-native-client-details')).toBeVisible()
    await expect(page.locator('#mcp-native-client-id')).toHaveText(app.state.oauth.nativeClientId)
    await expect(page.locator('#mcp-native-client-id img')).toHaveCount(0)
    expect(app.calls.filter(call => call.method === 'PUT')[1].body).toEqual({ nativeClientEnabled: true, expectedRevision: '1' })
})

test('checkout preserves rounding, invalid input and server limits', async ({ page, app }) => {
    app.state.limit.configuredBytes = 1
    await app.open('/admin#overview')
    await expect(page.locator('#mcp-checkout-limit')).toHaveValue('1')
    await expect(page.locator('#mcp-checkout-limit')).toHaveAttribute('max', '102400')
    await expect(page.locator('#mcp-checkout-limit-info')).toContainText('PHP allows up to 8 MiB')
    await page.locator('#mcp-checkout-limit').fill('1.5')
    await page.locator('#mcp-checkout-limit-save').click()
    await expect(page.locator('#mcp-checkout-limit-status')).toHaveText('Enter a whole number of MiB, at least 1.')
    expect(app.calls.filter(call => call.method === 'PUT')).toHaveLength(0)
    await page.locator('#mcp-checkout-limit').fill('2')
    await page.locator('#mcp-checkout-limit-save').click()
    await expect(page.locator('#mcp-checkout-limit-status')).toHaveText('Saved')
    expect(app.calls.find(call => call.method === 'PUT').body).toEqual({ mib: 2 })
})

test('independent settings failures do not prevent other sections loading', async ({ page, app }) => {
    for (const path of ['/api/admin/tags', '/api/oauth-clients', '/api/checkout-limit', '/api/logs-access']) app.on('GET', path, () => ({ status: 500 }))
    await app.open('/admin#access')
    await expect(page.locator('#mcp-matrix tbody tr')).toHaveCount(2)
    await expect(page.locator('#mcp-tags-list')).toContainText('Could not load system tags.')
    await expect(page.locator('#mcp-checkout-limit-info')).toContainText('Could not load the checkout upload limit.')
    await expect(page.locator('#mcp-logs-groups')).toContainText('Could not load the groups.')
})

test('OAuth failures retain native state and show host validation feedback', async ({ page, app }) => {
    app.on('PUT', '/api/oauth-clients', () => ({ status: 400 }))
    await app.open('/admin#clients')
    await page.locator('#mcp-oauth-hosts').fill('bad/host')
    await page.locator('#mcp-oauth-hosts-save').click()
    await expect(page.locator('#mcp-oauth-hosts-status')).toContainText('Invalid host list')
    await page.locator('#mcp-native-client').press('Space')
    await expect(page.locator('#mcp-native-client-status')).toHaveText('Not saved')
    await expect(page.locator('#mcp-native-client')).not.toBeChecked()
})

test('service/privacy accept authoritative responses and checkout failure reenables save', async ({ page, app }) => {
    await app.open('/admin#overview')
    await page.locator('#mcp-service').press('Space')
    await expect(page.locator('#mcp-service-status')).toHaveText('Saved')
    expect(app.calls.find(call => call.path === '/api/service').body).toEqual({ enabled: false, expectedRevision: '0' })
    app.on('PUT', '/api/checkout-limit', () => ({ status: 400 }))
    await page.locator('#mcp-checkout-limit-save').click()
    await expect(page.locator('#mcp-checkout-limit-status')).toHaveText('Not saved')
    await expect(page.locator('#mcp-checkout-limit-save')).toBeEnabled()
    await page.getByRole('tab', { name: 'Advanced' }).click()
    await page.locator('#mcp-logs-hide-file-names').press('Space')
    await expect(page.locator('#mcp-logs-hide-file-names-status')).toHaveText('Saved')
    expect(app.calls.find(call => call.path === '/api/logs-privacy' && call.method === 'PUT').body).toEqual({ hideFileNames: false })
})

test('copy success and rejected clipboard writes give feedback', async ({ page, app }) => {
    await page.addInitScript(() => Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async text => { window.__copied = text } } }))
    await app.open('/admin#overview')
    const button = page.locator('[data-copy=mcp-endpoint]')
    await button.click()
    await expect(button).toHaveText('Copied')
    expect(await page.evaluate(() => window.__copied)).toBe(await page.locator('#mcp-endpoint').textContent())
    await page.evaluate(() => { navigator.clipboard.writeText = async () => { throw new Error('Denied') } })
    await button.click()
    await expect.poll(() => page.evaluate(() => window.__toasts)).toContain('Could not copy the endpoint.')
})

test('picker locks in-flight saves, gives unique IDs and preserves keyboard removal', async ({ page, app }) => {
    let release
    app.on('PUT', '/api/logs-access', async ({ body, state }) => {
        await new Promise(resolve => { release = resolve })
        return { body: { ...state.logAccess, groups: body.groups, version: 2, revision: '1' } }
    })
    await app.open('/admin#advanced')
    const ids = await page.locator('.mcp-picker-list').evaluateAll(nodes => nodes.map(node => node.id))
    expect(new Set(ids).size).toBe(ids.length)
    const search = page.getByRole('combobox', { name: 'Search groups' })
    await search.focus()
    await page.keyboard.press('Backspace')
    await expect(search).toBeDisabled()
    await expect.poll(() => typeof release).toBe('function')
    expect(app.calls.filter(call => call.path === '/api/logs-access' && call.method === 'PUT')).toHaveLength(1)
    release()
    await expect(search).toBeEnabled()
    await expect(page.getByRole('button', { name: 'Remove Staff' })).toHaveCount(0)
})
