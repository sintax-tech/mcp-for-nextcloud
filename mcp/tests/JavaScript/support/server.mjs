// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createServer } from 'node:http'
import { readFileSync } from 'node:fs'
import { spawnSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

// An explicit archive asset directory lets closure checks execute the packaged bytes.
const archiveAssets = process.env.MCP_TEST_ASSET_ROOT
const app = fileURLToPath(new URL('../../../', import.meta.url))
createServer((request, response) => {
    const url = new URL(request.url, 'http://127.0.0.1')
    const path = url.pathname
    if (path === '/health') return response.end('ok')
    if (path === '/admin' || path === '/personal') {
        const render = spawnSync('php', [app + 'tests/JavaScript/support/render-page.php', path.slice(1)], { encoding: 'utf8' })
        if (render.error || render.status !== 0) {
            response.writeHead(500)
            return response.end(String(render.error || render.stderr))
        }
        response.setHeader('Content-Type', 'text/html; charset=utf-8')
        const html = url.searchParams.has('bare') ? render.stdout.replace(/<script src="[^"]+"><\/script>/g, '') : render.stdout
        return response.end(html)
    }
    if (/^\/modules\/(?:[a-z-]+\/)*[a-z-]+\.js$/.test(path)) {
        try {
            response.setHeader('Content-Type', 'text/javascript')
            return response.end(readFileSync(app + 'src/' + path.slice('/modules/'.length)))
        } catch { response.writeHead(404); return response.end('Missing module') }
    }
    const asset = path.match(/^\/apps\/mcp\/(js|css)\/([a-z-]+)\.(js|css)$/)
    if (asset && asset[1] === asset[3]) {
        try {
            const body = readFileSync(`${archiveAssets || app}/${asset[1]}/${asset[2]}.${asset[3]}`)
            response.setHeader('Content-Type', asset[3] === 'js' ? 'text/javascript' : 'text/css')
            return response.end(body)
        } catch { /* A missing registered asset must return an observable failure. */ }
    }
    response.writeHead(404)
    response.end('Unknown fixture route')
}).listen(18461, '127.0.0.1')
