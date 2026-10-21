// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect, user } from '../support/app-fixture.js'

const cell = page => page.locator('[data-uid=alice] [data-level-key=files]')
const popup = page => page.locator('#mcp-level-popover')
const commands = app => app.calls.filter(call => call.method === 'POST' && call.path === '/api/grants/commands')
const individual = (allowed = true) => user('alice', {
    grants: { files: { read: true, create: allowed }, logs: { read: false } },
    origins: { eligible: { source: 'default', groupIds: [] }, grants: { files: { read: { source: 'default', groupIds: [] }, create: { source: allowed ? 'individual_allow' : 'individual_deny', groupIds: [] } } } },
})

test('levels are the default, display textual levels, and open a keyboard-accessible popover', async ({ page, app }) => {
    app.state.users = [individual()]
    await app.open()
    await expect(page.locator('#mcp-view-levels')).toHaveAttribute('aria-pressed', 'true')
    await expect(cell(page)).toHaveAttribute('data-level', 'full')
    await expect(cell(page).locator('.mcp-level-word')).toHaveText('Full')
    await expect(cell(page)).toHaveAttribute('data-mark', 'exception')
    const trigger = cell(page).locator('button.mcp-level')
    await trigger.click()
    await expect(popup(page)).toBeVisible()
    await expect(trigger).toHaveAttribute('aria-expanded', 'true')
    await page.keyboard.press('Escape')
    await expect(popup(page)).toBeHidden()
    await expect(trigger).toBeFocused()
})

test('a lower level sends explicit deny, one revisioned command and one server receipt', async ({ page, app }) => {
    app.state.users = [individual()]
    await app.open()
    await cell(page).locator('button.mcp-level').click()
    await popup(page).getByRole('radio', { name: /^Read/ }).click()
    await expect.poll(() => commands(app).length).toBe(1)
    expect(commands(app)[0].body).toEqual({ expectedRevision: '0', commands: [{ uid: 'alice', module: 'files', operation: 'create', state: 'deny' }] })
    await expect(page.locator('#mcp-bulk-undo')).toBeVisible()
})

for (const status of [409, 428, 503, 500]) {
    test(`a level save rejected with ${status} never replays or offers an undo`, async ({ page, app }) => {
        app.state.users = [individual()]
        app.on('POST', '/api/grants/commands', () => ({ status, body: { uncertain: true, revision: '9999' } }))
        await app.open()
        await cell(page).locator('button.mcp-level').click()
        await popup(page).getByRole('radio', { name: /^Read/ }).click()
        await expect.poll(() => commands(app).length).toBe(1)
        await expect(popup(page)).toBeHidden()
        await expect(page.locator('#mcp-bulk-undo')).toBeHidden()
        expect(app.state.users[0].grants.files.create).toBe(true)
        expect(app.state.revision).toBe('0')
    })
}

test('unavailable and unknown levels have distinct text and controls, and scope stays visible', async ({ page, app }) => {
    app.state.users = [user('alice', { appsEnabled: { files: false, logs: true }, scope: { files: { mode: 'restricted', count: 2 } } }), user('unknown', { grants: { files: { read: null, create: false }, logs: { read: false } } })]
    await app.open()
    await expect(cell(page)).toContainText('Unavailable')
    await expect(cell(page).locator('.mcp-level')).toHaveCount(0)
    await expect(cell(page).locator('.mcp-scope-indicator')).toHaveText('◩ Restricted to 2')
    const unknown = page.locator('[data-uid=unknown] [data-level-key=files]')
    await expect(unknown).toContainText('Unknown')
    await expect(unknown.getByRole('button', { name: 'Retry' })).toBeVisible()
})

test('a redundant individual value uses its inherited origin and is reviewed before reset', async ({ page, app }) => {
    app.state.users = [individual(false)]
    await app.open()
    await expect(cell(page)).toHaveAttribute('data-mark', 'redundant')
    await expect(cell(page).locator('.icon-user')).toHaveCount(0)
    await expect(page.locator('#mcp-redundant-notice')).toBeVisible()
    await page.locator('#mcp-redundant-review').click()
    await expect(page.locator('.mcp-reset-preview')).toBeVisible()
    expect(commands(app)).toEqual([])
})

for (const accept of [false, true]) {
    test(`turning off eligibility requires explicit confirmation: ${accept}`, async ({ page, app }) => {
        app.state.users = [individual()]; await app.open()
        await page.locator('[data-level-key=eligible] button.mcp-level').click()
        page.once('dialog', async dialog => {
            expect(dialog.message()).toContain('signs the person out')
            if (accept) await dialog.accept(); else await dialog.dismiss()
        })
        await popup(page).getByRole('radio', { name: /^No/ }).click()
        if (accept) {
            await expect.poll(() => commands(app).length).toBe(1)
            expect(commands(app)[0].body.commands).toEqual([{ uid: 'alice', module: null, operation: 'eligible', state: 'deny' }])
        } else {
            await expect(popup(page)).toBeVisible()
            expect(commands(app)).toEqual([])
        }
    })
}

test('advanced operation controls identify their current state and explicit inherit uses only individual cells', async ({ page, app }) => {
    app.state.users = [individual()]; await app.open()
    await cell(page).locator('button.mcp-level').click()
    await popup(page).locator('summary').click()
    await expect(popup(page).locator('[data-focus-key="operation:create:allow"]')).toHaveAttribute('aria-pressed', 'true')
    await expect(popup(page).locator('[data-focus-key="operation:create:allow"]')).toContainText('✓')
    await popup(page).getByRole('button', { name: 'Inherit from groups and defaults' }).click()
    await expect.poll(() => commands(app).length).toBe(1)
    expect(commands(app)[0].body.commands).toEqual([{ uid: 'alice', module: 'files', operation: 'create', state: 'inherit' }])
})
