// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createNextcloud } from '../platform/nextcloud.js'
import { createRequest } from '../platform/http.js'
import { mountAdmin } from '../admin/bootstrap.js'

const root = document.getElementById('mcp-admin')
if (root) {
    const host = createNextcloud()
    mountAdmin({ root, ...host, request: createRequest(host) })
}
