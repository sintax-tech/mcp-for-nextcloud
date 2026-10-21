// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createScope } from '../../platform/lifecycle.js'
import { createPolicyState } from '../../platform/policy-state.js'
import { flashStatus as flash } from '../status-feedback.js'
import { changes } from './oauth-changes.js'
import { createClientCards } from './custom-client-cards.js'
function el(tag, props, children = []) {
    const node = Object.assign(document.createElement(tag), props || {})
    node.append(...children)
    return node
}

export function mountOauthClients({ root, request, t, notifyError, policyChannel, reloadMatrix = () => {} }) {
    const scope = createScope(root)
    const api = scope.request(request)
    const policy = createPolicyState(policyChannel)
    scope.use(policy.destroy)
    async function start() {
        const hostsInput = root.querySelector('#mcp-oauth-hosts')
        const hostsSave = root.querySelector('#mcp-oauth-hosts-save')
        const hostsStatus = root.querySelector('#mcp-oauth-hosts-status')
        const nativeBox = root.querySelector('#mcp-native-client')
        const nativeStatus = root.querySelector('#mcp-native-client-status')
        const details = root.querySelector('#mcp-native-client-details')
        const clientId = root.querySelector('#mcp-native-client-id')
        const redirects = root.querySelector('#mcp-native-redirects')
        const customList = root.querySelector('#mcp-custom-clients')
        const customAdd = root.querySelector('#mcp-custom-add')
        /** Self-hosted clients as the server last returned them; each card edits one of them (or a new one). */
        let customClients = []
        // The block's own revision, from its own GET or save; never the matrix's, never an error body's.
        let queue = Promise.resolve()
        // Advances when the page is reloaded after a refused save: intents queued before that are dropped.
        let epoch = 0
        /** Per card: the client it was built from, and what is typed in it now. */
        /** The server's last answer: what is stored at this block's revision. */
        let confirmed = null
        const { customCard, keepUnsaved, unsaved } = createClientCards({ scope, t, notifyError, report, flash, saveCustom, getClients: () => customClients, recover })
        async function read() {
            const ticket = policy.read()
            const data = await api('GET', '/api/oauth-clients')
            if (!policy.accept(ticket, data.revision)) throw new Error('stale')
            return data
        }

        /** @param {object} data settings returned by the API */
        function show(data) {
            confirmed = data
            hostsInput.value = data.hosts.join(', ')
            nativeBox.checked = data.nativeClientEnabled
            details.hidden = !data.nativeClientEnabled
            clientId.textContent = data.nativeClientId
            redirects.replaceChildren(...data.nativeRedirectUris.map((uri) => el('li', {}, [el('code', { textContent: uri })])))
            customClients = data.customClients
        }

        /**
         * @param {HTMLElement} status status span next to the control
         * @param {boolean} ok whether the save worked
         * @param {string} [message] text shown on failure
         */
        function report(status, ok, message) {
            status.textContent = ok ? t('mcp', 'Saved') : (message || t('mcp', 'Not saved'))
            flash(status, ok)
        }

        /**
         * Runs one task of the block after the previous one has finished.
         *
         * @param {Function} task async work
         * @return {Promise<*>} what the task returned
         */
        function enqueue(task) {
            const run = queue.then(task)
            queue = run.catch(() => {})
            return run
        }

        /**
         * Sends one change when its turn comes: the body is built then by `build`, from the clients the server confirmed
         * last, with the revision of that same answer, which is then replaced by the revision and clients the server
         * answers with. The policy revision moved, so the matrix is reloaded to keep its own saves valid. An intent
         * queued before a reload is dropped (error "stale"); a refused save drops the intents queued behind it.
         *
         * @param {Function} build (clients) => the changed settings
         * @return {Promise<object>} the settings the server saved
         */
        function put(build) {
            const queuedAt = epoch
            return enqueue(async () => {
                if (queuedAt !== epoch) {
                    throw new Error('stale')
                }
                try {
                    const sent = policy.revision
                    if (sent === null) throw new Error('428')
                    const change = build(customClients)
                    const committed = changes(change, confirmed)
                    const data = await api('PUT', '/api/oauth-clients', { ...change, expectedRevision: sent })
                    if (!policy.saved(sent, data.revision, { announce: committed })) throw new Error('409')
                    customClients = data.customClients
                    confirmed = data
                    reloadMatrix()
                    return data
                } catch (e) {
                    if (scope.disposed) throw e
                    if (e.message === '409' || e.message === '428' || (e.message === '503' && e.data && e.data.uncertain)) {
                        epoch++
                        policy.invalidate()
                    }
                    throw e
                }
            })
        }

        /**
         * When a save failed because the page was out of date (409, or 428 without a revision) or the server could not
         * confirm it (503 uncertain), shows what is stored now, in the queue's order, and tells the admin nothing was
         * overwritten. The cards with unsaved typed changes are kept (see keepUnsaved()). An intent dropped behind such a
         * save is handled by that reload.
         *
         * @param {Error} e error thrown by put()
         * @return {Promise<boolean>} whether the error was handled here (the settings are then reloaded)
         */
        async function recover(e) {
            if (e.message === 'stale') {
                return true
            }
            if (e.message === '409' || e.message === '428') {
                notifyError(t('mcp', 'The OAuth client settings were changed in another window or by another administrator. The current settings were loaded; nothing was overwritten.'))
            } else if (e.message === '503' && e.data && e.data.uncertain) {
                notifyError(t('mcp', 'The server could not confirm whether the change was saved. The current settings were loaded.'))
            } else {
                return false
            }
            try {
                const pending = unsaved(customList)
                show(await enqueue(read))
                customList.replaceChildren(...keepUnsaved(pending))
            } catch (reload) {
                if (scope.disposed) return
                notifyError(t('mcp', 'Could not load the OAuth client settings.'))
            }
            return true
        }

        /**
         * Saves one change to the list of self-hosted clients.
         *
         * @param {Function} change (clients) => every client to keep, a new one without id; applied to the clients the
         *     server confirmed last, when the save's turn comes
         * @return {Promise<object|undefined>} the client the server added, if any
         */
        async function saveCustom(change) {
            let before = new Set()
            await put((clients) => {
                before = new Set(clients.map((client) => client.id))
                return { customClients: change(clients).map(({ id, name, enabled, redirectUris }) => ({ id, name, enabled, redirectUris })) }
            })
            return customClients.find((client) => !before.has(client.id))
        }

        try {
            show(await read())
        } catch (e) {
            if (scope.disposed) return
            notifyError(t('mcp', 'Could not load the OAuth client settings.'))
            return
        }
        customList.replaceChildren(...customClients.map(customCard))
        scope.on(customAdd, 'click', () => {
            const card = customCard({ id: null, name: '', enabled: true, redirectUris: [] })
            customList.append(card)
            card.querySelector('input[type="text"]').focus()
        })

        scope.on(hostsSave, 'click', async () => {
            const hosts = hostsInput.value.split(',').map((host) => host.trim().toLowerCase()).filter((host) => host !== '')
            hostsSave.disabled = true
            try {
                show(await put(() => ({ hosts })))
                report(hostsStatus, true)
            } catch (e) {
            if (scope.disposed) return
                if (!(await recover(e))) {
                    report(hostsStatus, false, t('mcp', 'Invalid host list: use lowercase host names only, without https://, port, path or *, and keep at least one.'))
                }
            } finally {
                if (!scope.disposed) hostsSave.disabled = false
            }
        })

        scope.on(nativeBox, 'change', async () => {
            const value = nativeBox.checked
            nativeBox.disabled = true
            try {
                show(await put(() => ({ nativeClientEnabled: value })))
                report(nativeStatus, true)
            } catch (e) {
            if (scope.disposed) return
                nativeBox.checked = !value
                if (!(await recover(e))) {
                    report(nativeStatus, false)
                    notifyError(t('mcp', 'Could not change the native client.'))
                }
            } finally {
                if (!scope.disposed) nativeBox.disabled = false
            }
        })
    }

    return { ready: start(), destroy: scope.destroy }
}
