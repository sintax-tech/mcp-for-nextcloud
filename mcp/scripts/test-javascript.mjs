// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

if (!process.versions.node.startsWith('24.21.')) {
    throw new Error('JavaScript verification requires Node 24.21.x; see docs/en/development/setup.md.')
}
const app = fileURLToPath(new URL('../', import.meta.url))
const env = { ...process.env }
// Playwright sets FORCE_COLOR for workers; do not propagate a conflicting NO_COLOR.
delete env.NO_COLOR
const result = spawnSync(process.execPath, ['node_modules/@playwright/test/cli.js', 'test', ...process.argv.slice(2)], {
    cwd: app, stdio: 'inherit', env,
})
if (result.error) throw result.error
process.exitCode = result.status ?? 1
