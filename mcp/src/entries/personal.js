// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createNextcloud } from '../platform/nextcloud.js'
import { createRequest } from '../platform/http.js'
import { mountPersonalScope } from '../settings/resource-scope.js'
import { mountConnections } from '../settings/connections.js'

const root = document.getElementById('mcp-personal')
if (root) {
    const host = createNextcloud()
    mountPersonalScope({ root, ...host, request: createRequest(host) })
    mountConnections({ root: root.querySelector('#mcp-connections'), mode: 'personal', ...host, request: createRequest(host) })
}
