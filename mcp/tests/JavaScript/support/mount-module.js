// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
/** Isolate a feature on the real template, with explicit host and sibling hooks. */
export async function mountModule(page, path, name, root, personal = false) {
    await page.goto(personal ? '/personal?bare=1' : '/admin?bare=1')
    await page.evaluate(async ({ path, name, root, personal }) => {
        const { createNextcloud } = await import('/modules/platform/nextcloud.js')
        const { createRequest } = await import('/modules/platform/http.js')
        const { createPicker } = await import('/modules/admin/picker.js')
        const host = createNextcloud()
        window.__reloads = 0
        const component = await import('/modules/' + path)
        window.mounted = component[name]({
            ...host,
            root: document.getElementById(root),
            request: createRequest(host),
            createPicker: spec => createPicker({ ...spec, t: host.t }),
            reloadMatrix: () => { window.__reloads++ },
            overviewAlert: document.getElementById('mcp-tags-invalid-overview'),
            tabAlert: document.getElementById('mcp-tab-protection-alert'),
            mode: personal ? 'personal' : 'admin',
            onCounts: result => { window.__counts = result.counts },
        })
        await window.mounted.ready
    }, { path, name, root, personal })
}
