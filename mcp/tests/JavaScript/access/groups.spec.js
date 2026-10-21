// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '../support/app-fixture.js'

const detail = page => page.locator('#mcp-group-detail')
function groupFixture(app, extra = {}) {
    const group = { groupId: 'staff', displayName: 'Staff', trusted: true, exists: true, state: 'active', grants: [], revision: '0', catalog: { _system: ['eligible'], notes: ['read', 'edit'] }, counts: { members: null, overrides: null, permissions: null }, ...extra }
    app.on('GET', '/api/group-directory', () => ({ body: { groups: [group], total: 1, hasMore: false, counts: { all: 1, granted: 0, blocked: null } } }))
    app.on('GET', '/api/group-directory/detail', ({ state }) => ({ body: { ...group, revision: state.revision } }))
    app.on('POST', '/api/group-grants/preview', ({ body, state }) => ({ body: { previewId: 'group-preview', revision: state.revision, members: 51, revokes: 2, withdraws: true, current: group.grants, grants: body.grants } }))
    app.on('PUT', '/api/group-grants', ({ body, state }) => {
        expect(body.previewId).toBe('group-preview')
        expect(body.expectedRevision).toBe(state.revision)
        group.grants = body.grants
        state.revision = String(BigInt(state.revision) + 1n)
        return { body: { revision: state.revision } }
    })
    return group
}
async function open(page, app) {
    await app.open()
    await page.locator('#mcp-group-list button').first().click()
    await expect(detail(page).locator('h3')).toHaveText('Staff')
}
async function edit(page) {
    await detail(page).locator('button[name=mcp-group-level-notes][value=read]').click()
    await detail(page).getByRole('button', { name: 'Review and save', exact: true }).click()
    await expect(detail(page).getByRole('button', { name: 'Save group policy', exact: true })).toBeEnabled()
    await expect(detail(page).getByRole('button', { name: 'Save group policy', exact: true })).toBeFocused()
}
const saves = app => app.calls.filter(c => c.path === '/api/group-grants' && c.method === 'PUT')

test('unknown counts stay distinct from zero and the heading receives focus', async ({ page, app }) => {
    groupFixture(app)
    await open(page, app)
    await expect(detail(page)).toContainText('Unknown number of members')
    await expect(detail(page)).toContainText('could not be counted')
    await expect(detail(page).locator('h3')).toBeFocused()
    await expect(detail(page).getByRole('button', { name: 'Save group policy' })).toHaveCount(0)
})

test('empty PHP grants become an object draft and save requires its preview receipt', async ({ page, app }) => {
    groupFixture(app)
    await open(page, app)
    await edit(page)
    expect(saves(app)).toEqual([])
    await expect(detail(page)).toContainText('51 current members; 2 credential holders')
    await detail(page).getByRole('button', { name: 'Save group policy', exact: true }).click()
    await expect.poll(() => saves(app).length).toBe(1)
    expect(saves(app)[0].body).toEqual({ gid: 'staff', grants: { notes: ['read'] }, expectedRevision: '0', previewId: 'group-preview' })
})

for (const status of [409, 428, 503, 422, 405, 412, 500]) {
    test(`group ${status} preserves the draft and requires an explicit new preview`, async ({ page, app }) => {
        groupFixture(app)
        app.on('PUT', '/api/group-grants', () => ({ status, body: { revision: '9999' } }))
        await open(page, app)
        await edit(page)
        await detail(page).getByRole('button', { name: 'Save group policy', exact: true }).click()
        await expect(page.locator('#mcp-group-alert')).not.toHaveText('')
        await expect(detail(page).locator('button[name=mcp-group-level-notes][value=read]')).toHaveAttribute('aria-pressed', 'true')
        await expect(detail(page).getByRole('button', { name: 'Save group policy', exact: true })).toHaveCount(0)
        expect(saves(app)).toHaveLength(1)
    })
}

for (const extra of [{ trusted: false }, { trusted: null }, { exists: false, trusted: false }]) {
    test(`untrusted or missing groups explain their diagnosis and keep grants disabled: ${JSON.stringify(extra)}`, async ({ page, app }) => {
        groupFixture(app, { grants: { notes: ['read'] }, ...extra })
        await open(page, app)
        await expect(detail(page).locator('#mcp-group-diagnosis')).not.toHaveText('')
        await expect(detail(page).locator('button[name=mcp-group-level-notes][value=read]')).toBeDisabled()
        await expect(detail(page).getByRole('button', { name: 'Preview clearing group policy' })).toBeEnabled()
    })
}

test('group search and pagination keep their own filter, with native keyboard list navigation', async ({ page, app }) => {
    const group = groupFixture(app)
    app.on('GET', '/api/group-directory', ({ query }) => ({ body: { groups: [group, { ...group, groupId: 'ops', displayName: 'Operations' }], hasMore: true, total: 52, counts: { all: 52, granted: 3, blocked: null } } }))
    await app.open()
    const buttons = page.locator('#mcp-group-list button')
    await buttons.first().focus()
    await page.keyboard.press('End')
    await expect(buttons.last()).toBeFocused()
    await page.keyboard.press('Home')
    await expect(buttons.first()).toBeFocused()
    await page.locator('#mcp-group-next').click()
    await expect.poll(() => app.calls.filter(c => c.path === '/api/group-directory').at(-1).query.page).toBe('2')
    await page.locator('#mcp-group-filter-granted').click()
    await expect.poll(() => app.calls.filter(c => c.path === '/api/group-directory').at(-1).query).toEqual({ search: '', page: '1', filter: 'granted' })
    await page.locator('#mcp-group-search').fill(' op ')
    await expect.poll(() => app.calls.filter(c => c.path === '/api/group-directory').at(-1).query.search).toBe('op')
    expect(app.calls.filter(c => c.path === '/api/grants').at(-1).query.group ?? '').toBe('')
})

test('editing after preview invalidates its receipt; discard restores the saved grant', async ({ page, app }) => {
    groupFixture(app); await open(page, app); await edit(page)
    await detail(page).getByRole('button', { name: 'Back', exact: true }).click()
    await detail(page).locator('button[name=mcp-group-level-notes][value=full]').click()
    await expect(detail(page).getByRole('button', { name: 'Save group policy' })).toHaveCount(0)
    await detail(page).getByRole('button', { name: 'Discard', exact: true }).click()
    await expect(detail(page).locator('button[name=mcp-group-level-notes][value=none]')).toHaveAttribute('aria-pressed', 'true')
    expect(saves(app)).toEqual([])
})

test('group operations show custom policy and changing level preserves keyboard focus', async ({ page, app }) => {
    groupFixture(app, { grants: { notes: ['edit'] } }); await open(page, app)
    await expect(detail(page)).toContainText('Custom')
    const read = detail(page).locator('button[name=mcp-group-level-notes][value=read]')
    await read.click(); await expect(read).toBeFocused()
    await expect(read).toHaveAttribute('aria-pressed', 'true')
    await expect(read).toContainText('✓')
    expect(saves(app)).toEqual([])
})

test('abandoning group changes requires confirmation and selecting another group cancels no write', async ({ page, app }) => {
    const group = groupFixture(app)
    app.on('GET', '/api/group-directory', () => ({ body: { groups: [group, { ...group, groupId: 'ops', displayName: 'Operations' }], hasMore: false, counts: {} } }))
    await open(page, app)
    await detail(page).locator('button[name=mcp-group-level-notes][value=read]').click()
    page.once('dialog', dialog => dialog.dismiss())
    await page.locator('#mcp-group-list button').last().click()
    await expect(detail(page).locator('h3')).toHaveText('Staff')
    expect(app.calls.filter(c => c.path === '/api/group-directory/detail')).toHaveLength(1)
    expect(saves(app)).toEqual([])
})

test('phone group picker folds after selection and can reopen without losing its draft', async ({ page, app }) => {
    groupFixture(app)
    await page.setViewportSize({ width: 390, height: 844 })
    await open(page, app)
    await expect(page.locator('#mcp-group-picker')).toBeVisible()
    await detail(page).locator('button[name=mcp-group-level-notes][value=read]').click()
    await page.locator('#mcp-group-picker').click()
    await expect(page.locator('#mcp-group-list button')).toBeVisible()
    await expect(detail(page).locator('button[name=mcp-group-level-notes][value=read]')).toHaveAttribute('aria-pressed', 'true')
    expect(saves(app)).toEqual([])
})

test('the delayed group announcement cannot move confirmation during a pointer click', async ({ page, app }) => {
    await page.clock.install()
    await page.clock.pauseAt(new Date())
    groupFixture(app)
    await open(page, app)
    await edit(page)
    const button = detail(page).getByRole('button', { name: 'Save group policy', exact: true })
    const before = await button.boundingBox()
    await page.mouse.move(before.x + before.width / 2, before.y + before.height / 2)
    await page.mouse.down()
    await page.clock.runFor(61)
    await expect(page.locator('#mcp-group-status')).toMatchAriaSnapshot('- status: Review the impact, then save the group policy.')
    expect(await button.boundingBox()).toEqual(before)
    await page.mouse.up()
    await expect.poll(() => saves(app).length).toBe(1)
})

for (const action of ['save', 'preview', 'read']) {
    test(`unreadable group ${action} never claims success or replays`, async ({ page, app }) => {
        groupFixture(app)
        await open(page, app)
        const before = app.calls.filter(c => c.path === '/api/group-directory/detail').length
        if (action === 'save') {
            await edit(page)
            app.on('PUT', '/api/group-grants', ({ state }) => { state.revision = '9'; return { raw: '<html>Proxy</html>' } })
            await detail(page).getByRole('button', { name: 'Save group policy', exact: true }).click()
            await expect(page.locator('#mcp-group-alert')).toContainText('could not be confirmed')
            await expect.poll(() => app.calls.filter(c => c.path === '/api/group-directory/detail').length).toBe(before + 1)
            expect(saves(app)).toHaveLength(1)
            await expect(detail(page).locator('button[name=mcp-group-level-notes][value=read]')).toHaveAttribute('aria-pressed', 'true')
        } else if (action === 'preview') {
            app.on('POST', '/api/group-grants/preview', () => ({ raw: '<html>Proxy</html>' }))
            await detail(page).locator('button[name=mcp-group-level-notes][value=read]').click()
            await detail(page).getByRole('button', { name: 'Review and save', exact: true }).click()
            await expect(page.locator('#mcp-group-alert')).not.toHaveText('')
            expect(saves(app)).toHaveLength(0)
            expect(app.calls.filter(c => c.path === '/api/group-directory/detail')).toHaveLength(before)
        } else {
            app.on('GET', '/api/group-directory/detail', () => ({ raw: '<html>Proxy</html>' }))
            await page.locator('#mcp-group-list button').first().click()
            await expect(page.locator('#mcp-group-alert')).not.toHaveText('')
            expect(saves(app)).toHaveLength(0)
        }
        await expect(page.locator('#mcp-group-status')).not.toHaveText('Saved')
        await expect(detail(page).getByRole('button', { name: 'Save group policy', exact: true })).toHaveCount(0)
    })
}
