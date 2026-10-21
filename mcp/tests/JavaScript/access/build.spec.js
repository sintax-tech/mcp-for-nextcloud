// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { mkdtemp, mkdir, writeFile, readFile, rm, access } from 'node:fs/promises'
import { join } from 'node:path'
import { buildAssets } from '../../../scripts/build-assets.mjs'

test('build produces both self-contained entries and preserves unrelated assets', async () => {
    const root = await mkdtemp(join(process.cwd(), 'build/assets-probe-'))
    try {
        await mkdir(join(root, 'src/entries'), { recursive: true })
        await mkdir(join(root, 'js'))
        await writeFile(join(root, 'src/entries/admin.js'), 'document.body.dataset.page = "admin"; document.body.textContent = "Saving…"')
        await writeFile(join(root, 'src/entries/personal.js'), 'document.body.dataset.page = "personal"')
        await writeFile(join(root, 'js/unrelated.js'), 'keep')
        await buildAssets(root)
        for (const entry of ['admin', 'personal']) {
            const output = await readFile(join(root, `js/${entry}.js`), 'utf8')
            expect(output).toContain(['SPDX-License', 'Identifier: AGPL-3.0-or-later'].join('-'))
            expect(output).toContain(`"${entry}"`)
        }
        expect(await readFile(join(root, 'js/admin.js'), 'utf8')).toContain('Saving…')
        await writeFile(join(root, 'src/entries/admin.js'), 'import "./missing.js"')
        await expect(buildAssets(root)).rejects.toThrow()
        for (const entry of ['admin', 'personal']) await expect(access(join(root, `js/${entry}.js`))).rejects.toThrow()
        expect(await readFile(join(root, 'js/unrelated.js'), 'utf8')).toBe('keep')
    } finally { await rm(root, { recursive: true, force: true }) }
})

test('build rejects unreachable production sources and cyclic imports', async () => {
    const root = await mkdtemp(join(process.cwd(), 'build/assets-probe-'))
    try {
        await mkdir(join(root, 'src/entries'), { recursive: true })
        for (const entry of ['admin', 'personal']) await writeFile(join(root, `src/entries/${entry}.js`), 'console.log("entry")')
        await writeFile(join(root, 'src/orphan.js'), 'export const orphan = true')
        await expect(buildAssets(root)).rejects.toThrow(/Unreachable/)
        await rm(join(root, 'src/orphan.js'))
        await writeFile(join(root, 'src/entries/admin.js'), 'import "../cycle.js"')
        await writeFile(join(root, 'src/cycle.js'), 'import "./entries/admin.js"')
        await expect(buildAssets(root)).rejects.toThrow(/Cyclic/)
    } finally { await rm(root, { recursive: true, force: true }) }
})
