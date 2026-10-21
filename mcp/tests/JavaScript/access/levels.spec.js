// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import * as levels from '../../../src/admin/access/levels.js'

test('levels preserve cumulative catalog operations and unknown states', () => {
    const operations = ['read', 'edit', 'create', 'move', 'restore', 'share', 'link']
    expect(levels.levels('files', operations)).toEqual(['none', 'read', 'edit', 'full'])
    expect(levels.operationsFor('files', operations, 'edit')).toEqual(['read', 'edit', 'create', 'move', 'restore'])
    expect(levels.operationsFor('files', [...operations, 'future'], 'full')).toContain('future')
    expect(levels.operationsFor('files', [...operations, 'future'], 'edit')).not.toContain('future')
    expect(levels.levelOf('files', operations, { read: true })).toBe('unknown')
    expect(levels.levelOf('files', ['read', 'edit'], { read: false, edit: true })).toBe('custom')
    expect(() => levels.operationsFor('logs', ['read'], 'edit')).toThrow('Unknown level')
})
