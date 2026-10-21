// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { changes } from '../../../src/admin/clients/oauth-changes.js'

const saved = { hosts: ['one.example', 'two.example'], hostsDefault: true, nativeClientEnabled: false, customClients: [{ id: 'a', name: 'A', enabled: true, redirectUris: ['https://one.example/cb'] }] }
for (const [label, change, expected] of [
    ['same native switch', { nativeClientEnabled: false }, false],
    ['changed native switch', { nativeClientEnabled: true }, true],
    ['default host set with order and duplicates', { hosts: ['two.example', 'ONE.EXAMPLE', 'one.example'] }, false],
    ['PHP trimming and duplicates', { customClients: [{ id: 'a', name: '\0\tA\v\r', enabled: true, redirectUris: [' https://one.example/cb\0', 'https://one.example/cb'] }] }, false],
    ['real trimmed change', { customClients: [{ id: 'a', name: '\0B\0', enabled: true, redirectUris: ['https://one.example/cb'] }] }, true],
    ['control character inside value is uncertain', { customClients: [{ id: 'a', name: 'A\0B', enabled: true, redirectUris: ['https://one.example/cb'] }] }, false],
    ['nonbreaking spaces survive PHP trimming', { customClients: [{ id: 'a', name: '\u00a0A\u00a0', enabled: true, redirectUris: ['https://one.example/cb'] }] }, true],
]) {
    test(`OAuth commit announcement comparison: ${label}`, () => expect(changes(change, saved)).toBe(expected))
}
