// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test, expect } from '@playwright/test'
import { mkdtemp, mkdir, writeFile, copyFile, access, rm, readdir } from 'node:fs/promises'
import { join } from 'node:path'
import { spawnSync } from 'node:child_process'

for (const failure of ['docs', 'build', 'stage', 'check', 'verify']) {
    test(`package ${failure} failure removes only the current version result`, async () => {
        const root = await mkdtemp(join(process.cwd(), 'build/package-probe-'))
        try {
            for (const dir of ['appinfo', 'lib', 'templates', 'LICENSES', 'scripts', 'build', 'bin']) await mkdir(join(root, dir))
            await copyFile('scripts/package.sh', join(root, 'scripts/package.sh'))
            await writeFile(join(root, 'appinfo/info.xml'), '<version>1.2.3</version>')
            for (const file of ['README.md', 'README.pt-BR.md', 'LICENSE', 'NOTICE', 'THIRD-PARTY-NOTICES.md', 'composer.json', 'composer.lock']) await writeFile(join(root, file), '')
            for (const version of ['1.2.3', 'older']) await writeFile(join(root, `build/mcp-${version}.tar.gz`), 'previous result')
            // Pipeline probes isolate shell failure propagation; the actual checker has PHP tar fixtures.
            await writeFile(join(root, 'bin/npm'), `#!/bin/sh\nexit ${failure === 'build' ? 42 : 0}\n`, { mode: 0o755 })
            await writeFile(join(root, 'bin/composer'), '#!/bin/sh\nexit 0\n', { mode: 0o755 })
            // The standalone package script must propagate each documentation boundary failure.
            await writeFile(join(root, 'bin/node'), `#!/bin/sh\ncase "$2" in\n  check) exit ${failure === 'docs' ? 42 : 0};;\n  stage) exit ${failure === 'stage' ? 42 : 0};;\n  verify) exit ${failure === 'verify' ? 42 : 0};;\n  *) exit 0;;\nesac\n`, { mode: 0o755 })
            await writeFile(join(root, 'scripts/check_package.php'), failure === 'check' ? '<?php fwrite(STDERR, "Intentional archive rejection\\n"); exit(23);' : '<?php exit(0);')
            const result = spawnSync('sh', ['scripts/package.sh'], { cwd: root, encoding: 'utf8', env: { ...process.env, PATH: join(root, 'bin') + ':' + process.env.PATH } })
            expect(result.error).toBeUndefined()
            expect(result.status).toBe(failure === 'check' ? 1 : 42)
            if (failure === 'check') expect(result.stderr).toContain('Intentional archive rejection')
            await expect(access(join(root, 'build/mcp-1.2.3.tar.gz'))).rejects.toThrow()
            await access(join(root, 'build/mcp-older.tar.gz'))
            expect((await readdir(join(root, 'build'))).filter(name => name.startsWith('package-stage-'))).toEqual([])
        } finally { await rm(root, { recursive: true, force: true }) }
    })
}
