// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { createPolicyChannel, createPolicyState, mustReloadPolicy } from '../../../src/platform/policy-state.js'

test('a confirmed local commit advances only blocks built from its exact predecessor', () => {
    const channel = createPolicyChannel()
    const matrix = createPolicyState(channel)
    const logs = createPolicyState(channel)
    const stale = createPolicyState(channel)
    matrix.accept(matrix.read(), '9007199254740992')
    logs.accept(logs.read(), '9007199254740992')
    stale.accept(stale.read(), '9007199254740991')
    matrix.saved('9007199254740992', '9007199254740993')
    expect(logs.revision).toBe('9007199254740993')
    expect(stale.revision).toBe('9007199254740991')
    expect(matrix.revision).toBe('9007199254740993')
    logs.destroy()
    matrix.saved('9007199254740993', '9007199254740994')
    expect(logs.revision).toBe('9007199254740993')
})

test('late reads and uncertain writes cannot restore a usable stale revision', () => {
    const state = createPolicyState(createPolicyChannel())
    const old = state.read()
    const fresh = state.read()
    expect(state.accept(fresh, '10')).toBe(true)
    expect(state.accept(old, '9')).toBe(false)
    const beforeWrite = state.read()
    state.saved('10', '11')
    expect(state.accept(beforeWrite, '10')).toBe(false)
    state.invalidate()
    expect(state.revision).toBeNull()
    expect(state.accept(beforeWrite, '11')).toBe(false)
    expect(mustReloadPolicy({ message: '503', data: { uncertain: true, revision: '99' } })).toBe(true)
    expect(state.revision).toBeNull()
    expect(mustReloadPolicy({ message: '409' })).toBe(true)
    expect(mustReloadPolicy({ message: '428' })).toBe(true)
    expect(mustReloadPolicy({ message: '503', data: { uncertain: false } })).toBe(false)
})

test('a post-commit read spanning another commit never advances unrelated drafts', () => {
    const channel = createPolicyChannel()
    const first = createPolicyState(channel)
    const other = createPolicyState(channel)
    first.accept(first.read(), '1')
    other.accept(other.read(), '1')
    first.saved('1', '3')
    expect(first.revision).toBe('3')
    expect(other.revision).toBe('1')
    expect(() => first.accept(first.read(), 4)).toThrow()
})

test('a delayed save response cannot overwrite a newer read', () => {
    const state = createPolicyState(createPolicyChannel())
    state.accept(state.read(), '1')
    state.accept(state.read(), '3')
    expect(state.saved('1', '2')).toBe(false)
    expect(state.revision).toBe('3')
})

test('a no-op save does not announce another administrators intervening commit', () => {
    const channel = createPolicyChannel()
    const first = createPolicyState(channel)
    const other = createPolicyState(channel)
    first.accept(first.read(), '1')
    other.accept(other.read(), '1')
    first.saved('1', '2', { announce: false })
    expect(first.revision).toBe('2')
    expect(other.revision).toBe('1')
})
