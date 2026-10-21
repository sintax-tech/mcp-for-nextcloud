// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { test as base, expect } from '@playwright/test'

export const user = (uid, changes = {}) => ({
    uid, displayName: uid, enabled: true, eligible: true, connected: false,
    appsEnabled: { files: true, logs: true },
    grants: { files: { read: false, create: true }, logs: { read: false } },
    ...changes,
})

export const test = base.extend({
    app: async ({ page }, use) => {
        const errors = []
        page.on('pageerror', error => errors.push(error.message))
        const calls = []
        const overrides = new Map()
        const receipts = new Map()
        const state = {
            revision: '0', serviceEnabled: true,
            users: [user('alice'), user('bob', { grants: { files: { read: true, create: false }, logs: { read: true } } })],
            catalog: { files: ['read', 'create'], logs: ['read'] },
            groups: [{ id: 'staff', displayName: 'Staff' }],
            tags: { tags: [{ id: 1, name: 'Private', type: 'restricted' }, { id: 2, name: 'Shared', type: 'collaborative' }], selected: ['1'], invalid: false },
            logAccess: { groups: ['staff'], allGroups: [{ id: 'staff', displayName: 'Staff' }, { id: 'ops', displayName: 'Operations' }], missing: [], version: 1 },
            oauth: { customClients: [], hostsDefault: true, hosts: ['cloud.example'], nativeClientEnabled: false, nativeClientId: 'native', nativeRedirectUris: ['http://127.0.0.1/callback'] },
            limit: { configuredBytes: 1048576, effectiveBytes: 1048576, phpBytes: 8388608 },
            privacy: { hideFileNames: true },
            connections: [1, 2].map(id => ({ id, uid: 'alice', displayName: 'Alice', client: { id: 'https://client.example/' + id, host: 'client.example', native: false }, createdAt: 1700000000, expiresAt: 1800000000 })),
            hasMore: false, total: 2,
        }
        await page.addInitScript(() => {
            const interpolate = (text, vars = {}, escape = true) => text.replace(/\{([^}]+)\}/g, (_, key) => {
                const value = String(vars[key] ?? `{${key}}`)
                return escape ? value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll("'", '&#039;').replaceAll('"', '&quot;') : value
            })
            window.t = (app, text, vars, count, options) => interpolate(text, vars, options?.escape !== false)
            window.n = (app, one, many, count, vars, options) => interpolate((count === 1 ? one : many).replaceAll('%n', String(count)), vars, options?.escape !== false)
            window.OC = { generateUrl: path => path, requestToken: 'synthetic-request-token' }
            window.__toasts = []
            window.OCP = { Toast: { error: text => window.__toasts.push(text) } }
        })
        function defaultResponse(method, path, query, body) {
            const advance = () => { state.revision = String(BigInt(state.revision) + 1n); return state.revision }
            if (path === '/api/grants/commands/preview' && method === 'POST') return { revision: state.revision, cells: body.commands.map(command => {
                const row = state.users.find(user => user.uid === command.uid)
                const before = command.module === null ? row.eligible : row.grants[command.module][command.operation]
                const allowed = command.module !== null && command.module !== 'logs' && command.operation === 'read'
                return { ...command, before: { allowed: before, source: 'individual_allow', groupIds: [] }, after: { allowed, source: 'default', groupIds: [] } }
            }) }
            if (path === '/api/group-directory' && method === 'GET') return { revision: state.revision, groups: [], total: 0, page: 1, hasMore: false, counts: { all: 0, granted: 0, blocked: 0 } }
            if (path === '/api/my/scope' && method === 'GET') return { modules: Object.fromEntries(['files', 'calendar', 'tasks'].map(module => [module, { mode: 'unrestricted', items: [] }])) }
            if (path === '/api/grants/commands' && method === 'POST') {
                const before = structuredClone(state.users)
                for (const command of body.commands) {
                    const row = state.users.find(row => row.uid === command.uid)
                    if (command.module === null) row.eligible = command.state === 'allow'
                    else row.grants[command.module][command.operation] = command.state === 'allow'
                }
                const revision = advance()
                const receiptId = 'receipt-' + revision
                receipts.set(receiptId, before)
                return { revision, receiptId, changed: body.commands.length }
            }
            if (path === '/api/grants/undo' && method === 'POST') {
                if (!receipts.has(body.receiptId)) throw new Error('Unknown synthetic receipt')
                state.users = receipts.get(body.receiptId)
                receipts.delete(body.receiptId)
                return { revision: advance(), changed: 1 }
            }
            if (path === '/api/grants' && method === 'GET') return { revision: state.revision, serviceEnabled: state.serviceEnabled, users: state.users, catalog: state.catalog, groups: state.groups, page: Number(query.get('page')), pageSize: 50, total: state.total, hasMore: state.hasMore }
            if (path === '/api/grants/bulk' && method === 'POST') {
                for (const row of state.users.filter(row => body.uids.includes(row.uid))) {
                    if (body.module === null) row.eligible = body.granted
                    else row.grants[body.module][body.operation] = body.granted
                }
                return { updated: body.uids.length }
            }
            if (path.startsWith('/api/grants/') && method === 'PUT') {
                const row = state.users.find(row => row.uid === decodeURIComponent(path.slice('/api/grants/'.length)))
                if (Object.hasOwn(body, 'eligible')) row.eligible = body.eligible
                else row.grants[body.module][body.operation] = body.granted
                return { ...row, revision: advance(), receiptId: null }
            }
            if (path === '/api/admin/tags') {
                if (method === 'PUT') Object.assign(state.tags, { selected: body.tagIds, invalid: false })
                return state.tags
            }
            if (path === '/api/logs-access') {
                if (method === 'PUT') { Object.assign(state.logAccess, { groups: body.groups, version: state.logAccess.version + 1 }); advance() }
                return { ...state.logAccess, revision: state.revision }
            }
            if (path === '/api/logs-privacy') {
                if (method === 'PUT') Object.assign(state.privacy, body)
                return state.privacy
            }
            if (path === '/api/oauth-clients') {
                if (method === 'PUT') {
                    const { expectedRevision, ...change } = body
                    if (change.customClients) change.customClients = change.customClients.map((client, index) => {
                        const id = client.id || 'client' + index + '-' + state.revision
                        return { ...client, id, clientId: 'nextcloud-mcp-custom-' + id }
                    })
                    Object.assign(state.oauth, change)
                    advance()
                }
                return { ...state.oauth, revision: state.revision }
            }
            if (path === '/api/checkout-limit') {
                if (method === 'PUT') state.limit.configuredBytes = body.mib * 1048576
                return state.limit
            }
            if (path === '/api/service' && method === 'PUT') { state.serviceEnabled = body.enabled; return { enabled: body.enabled, revision: advance() } }
            if (['/api/connections', '/api/my/connections'].includes(path) && method === 'GET') return { connections: state.connections, page: Number(query.get('page')), hasMore: false }
            if (method === 'DELETE' && /^\/api\/(my\/)?connections\//.test(path)) {
                state.connections = path.includes('/users/') ? [] : state.connections.filter(row => String(row.id) !== decodeURIComponent(path.split('/').at(-1)))
                return { revoked: true, counts: { connections: state.connections.length } }
            }
            throw new Error(`Unexpected API request: ${method} ${path}`)
        }
        await page.route('**/*', async route => {
            const request = route.request()
            const url = new URL(request.url())
            if (url.origin !== 'http://127.0.0.1:18461') {
                errors.push('Unexpected external request: ' + url.origin)
                return route.abort()
            }
            if (!url.pathname.startsWith('/apps/mcp/api/')) return route.continue()
            const path = url.pathname.slice('/apps/mcp'.length)
            const method = request.method()
            const body = request.postDataJSON()
            calls.push({ method, path, query: Object.fromEntries(url.searchParams), body, headers: request.headers() })
            try {
                const handler = overrides.get(method + ' ' + path)
                const revisioned = method !== 'GET' && path !== '/api/grants/commands/preview' && (path.startsWith('/api/grants/') || ['/api/service', '/api/logs-access', '/api/oauth-clients'].includes(path))
                const conflict = revisioned && body?.expectedRevision !== state.revision
                const result = handler ? await handler({ body, query: url.searchParams, state, calls })
                    : conflict ? { status: body?.expectedRevision === undefined ? 428 : 409, body: { error: 'Conflict', revision: state.revision } }
                    : { body: defaultResponse(method, path, url.searchParams, body) }
                await route.fulfill({ status: result.status ?? 200, ...(result.raw === undefined
                    ? { json: result.body ?? {} } : { contentType: 'text/html', body: result.raw }) })
            } catch (error) {
                errors.push(error.message)
                await route.abort()
            }
        })
        await use({
            state, calls,
            on: (method, path, handler) => overrides.set(method + ' ' + path, handler),
            open: async (path = '/admin#access') => {
                const response = await page.goto(path)
                expect(response.status(), await response.text()).toBe(200)
                await expect(page.locator('#mcp-connections-table')).toHaveAttribute('aria-busy', 'false')
                if (path.startsWith('/admin')) await expect(page.locator('#mcp-matrix')).toHaveAttribute('aria-busy', 'false')
            },
        })
        expect(errors, 'Uncaught browser errors or unexpected requests').toEqual([])
    },
})
export { expect }
