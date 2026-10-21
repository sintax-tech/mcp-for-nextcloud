// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect, user } from '../support/app-fixture.js'
for (const width of [390, 1280]) for (const direction of ['ltr', 'rtl']) {
    test(`focused operations remain visible between sticky columns at ${width}px ${direction}`, async ({ page, app }) => {
        const ops = ['read', 'create', 'edit', 'delete', 'move', 'share', 'link', 'restore']
        app.state.catalog = { files: ops, calendar: ops, deck: ops }
        app.state.users = [user('alice', { appsEnabled: { files: true, calendar: true, deck: true }, grants: Object.fromEntries(Object.keys(app.state.catalog).map(key => [key, Object.fromEntries(ops.map(op => [op, true]))])) })]
        await page.setViewportSize({ width, height: 844 })
        await app.open('/admin#access?view=operations')
        await page.locator('#mcp-admin').evaluate((root, dir) => { root.dir = dir }, direction)
        const boxes = page.locator('[data-uid=alice] input[type=checkbox]').filter({ visible: true })
        for (const index of [await boxes.count() - 1, 2, Math.floor(await boxes.count() / 2)]) {
            await boxes.nth(index).focus()
            const bounds = await boxes.nth(index).evaluate(box => {
                const wrap = box.closest('.mcp-matrix-wrap'), cell = box.closest('td'), row = cell.parentElement
                const rect = cell.getBoundingClientRect(), bounds = wrap.getBoundingClientRect()
                let left = bounds.left, right = bounds.right
                for (const other of row.cells) {
                    if (other === cell || getComputedStyle(other).position !== 'sticky') continue
                    const r = other.getBoundingClientRect()
                    if (r.left < rect.left) left = Math.max(left, r.right)
                    else right = Math.min(right, r.left)
                }
                const control = box.getBoundingClientRect()
                return { x: control.left, end: control.right, left, right }
            })
            expect(bounds.x).toBeGreaterThanOrEqual(bounds.left - 2)
            expect(bounds.end).toBeLessThanOrEqual(bounds.right + 2)
        }
    })
}
