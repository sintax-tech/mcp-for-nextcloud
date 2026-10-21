// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
export default class RequiredTestsReporter {
    onBegin(config, suite) {
        this.count = suite.allTests().length
        this.skipped = false
    }
    onTestEnd(test, result) {
        if (result.status === 'skipped') this.skipped = true
    }
    onEnd() {
        if (!this.count || this.skipped) {
            console.error('The JavaScript gate requires a nonempty suite with no skipped scenarios.')
            return { status: 'failed' }
        }
    }
}
