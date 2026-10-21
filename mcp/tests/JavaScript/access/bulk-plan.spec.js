// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { planBulkChange } from '../../../src/admin/access/bulk-plan.js'

test('bulk planning excludes unavailable rows and retains immutable before-images', () => {
    const users = [
        { uid: 'change', grants: { files: { read: false, create: true } } },
        { uid: 'same', grants: { files: { read: true, create: true } } },
        { uid: 'disabled', grants: { files: { read: false, create: false } } },
    ]
    const before = structuredClone(users)
    const plan = planBulkChange({ users, module: 'files', operations: ['read', 'create'], granted: true, unavailableReason: row => row.uid === 'disabled' ? 'Disabled' : null })
    expect(plan).toEqual({ users: 1, unavailable: 1, unchanged: 1, changes: [{ operation: 'read', granted: true, previous: new Map([['change', false]]) }] })
    expect(users).toEqual(before)
})

test('eligibility uses the same planner', () => {
    const plan = planBulkChange({ users: [{ uid: 'on', eligible: true }, { uid: 'off', eligible: false }], module: null, operations: ['eligible'], granted: false, unavailableReason: () => null })
    expect(plan.changes).toEqual([{ operation: 'eligible', granted: false, previous: new Map([['on', true]]) }])

})
