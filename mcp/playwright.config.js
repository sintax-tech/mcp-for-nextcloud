// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { defineConfig } from '@playwright/test'

export default defineConfig({
    testDir: './tests/JavaScript',
    outputDir: './build/browser-results',
    forbidOnly: true,
    retries: 0,
    workers: 2,
    timeout: 15000,
    expect: { timeout: 5000 },
    reporter: [['line'], ['./tests/JavaScript/support/required-tests-reporter.js']],
    use: { baseURL: 'http://127.0.0.1:18461', trace: 'retain-on-failure' },
    projects: ['chromium', 'firefox', 'webkit'].map(browserName => ({ name: browserName, use: { browserName } })),
    webServer: {
        command: 'node tests/JavaScript/support/server.mjs',
        url: 'http://127.0.0.1:18461/health',
        reuseExistingServer: false,
        timeout: 10000,
    },
})
