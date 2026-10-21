// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { createLevelPlan } from '../../../src/admin/access/level-plan.js'
import { createOverridePlan } from '../../../src/admin/access/override-plan.js'
const catalog = () => ({ files: ['read', 'create', 'edit', 'delete'] })
const levels = createLevelPlan({ catalog })
const resets = createOverridePlan({ catalog })
const person = (source = 'individual_allow', allowed = true) => ({ uid: 'alice', enabled: true, eligible: allowed,
    grants: { files: { read: allowed, create: allowed, edit: allowed, delete: allowed } },
    origins: { eligible: { source }, grants: { files: Object.fromEntries(catalog().files.map(op => [op, { source }])) } } })
for (const inherited of [null, {}, { create: { allowed: true }, edit: { allowed: true }, delete: { allowed: true } }]) {
    test(`removing a level is always explicit deny even if membership changes: ${JSON.stringify(inherited)}`, () => {
        expect(levels.planLevel(person(), 'files', 'read', inherited)).toEqual(['create', 'edit', 'delete'].map(operation => ({ uid: 'alice', module: 'files', operation, state: 'deny' })))
    })
}
for (const allowed of [true, false, null, undefined]) {
    test(`an individual denial returns to inheritance only with known inherited allow: ${allowed}`, () => {
        const commands = levels.planLevel(person('individual_deny', false), 'files', 'read', { read: { allowed } })
        expect(commands).toEqual([{ uid: 'alice', module: 'files', operation: 'read', state: allowed === true ? 'inherit' : 'allow' }])
    })
}
test('inherit resets exactly individual and invalid cells, and no-op operation sends nothing', () => {
    const user = person('group')
    user.origins.grants.files.create.source = 'invalid'
    user.origins.grants.files.edit.source = 'individual_deny'
    expect(levels.planInherit(user, 'files')).toEqual(['create', 'edit'].map(operation => ({ uid: 'alice', module: 'files', operation, state: 'inherit' })))
    expect(levels.planOperation(user, 'files', 'read', 'inherit')).toEqual([])
    expect(levels.planOperation(user, 'files', 'create', 'deny')).toEqual([])
    expect(levels.planLevel(person(), 'files', 'full', null)).toEqual([])
})
test('eligibility confirmation distinguishes known kept, unknown and already denied', () => {
    const cmd = [{ module: null, state: 'inherit' }]
    expect(levels.losesEligibility(person(), cmd, null)).toBe(true)
    expect(levels.losesEligibility(person(), cmd, { eligible: { allowed: true } })).toBe(false)
    expect(levels.losesEligibility(person('individual_deny', false), cmd, null)).toBe(false)
    expect(levels.planTarget(person(), null, { eligible: false }, { eligible: { allowed: false } })[0].state).toBe('deny')
})
test('reset includes hidden individual cells but never inherited cells, and counts sign-out uncertainty', () => {
    const user = person(); user.enabled = false
    user.origins.grants.files.read.source = 'group'
    user.origins.grants.files.edit.source = 'invalid'
    const plan = resets.plan([user, person('default')], null)
    expect(plan).toMatchObject({ users: 1, cells: 4, allow: 3, invalid: 1, eligibleAllow: 1, unseen: 4, without: 1, tooMany: false })
    expect(plan.targets[0].cells.map(cell => cell.operation)).toEqual(['eligible', 'create', 'edit', 'delete'])
})
test('reset limits reject more than fifty users or 2500 cells without partial planning', () => {
    expect(resets.plan(Array.from({ length: 51 }, (_, i) => ({ ...person(), uid: String(i) })), null).tooMany).toBe(true)
    const user = person(); user.origins.grants.files = Object.fromEntries(Array.from({ length: 2501 }, (_, i) => [String(i), { source: 'individual_allow' }]))
    expect(resets.plan([user], null).tooMany).toBe(true)
    expect(resets.plan([person()], [{ module: null, operation: 'eligible' }])).toMatchObject({ cells: 1, eligibleAllow: 1 })
})
