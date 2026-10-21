/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createScope } from '../../platform/lifecycle.js'
import { createPolicyState, mustReloadPolicy } from '../../platform/policy-state.js'
import { flashStatus as flash } from '../status-feedback.js'

export function mountService({ root, request, t, notifyError, policyChannel }) {
    const scope = createScope(root)
    const api = scope.request(request)
    const policy = createPolicyState(policyChannel)
    scope.use(policy.destroy)
    const serviceBox = root.querySelector('#mcp-service')
    const serviceStatus = root.querySelector('#mcp-service-status')
    async function reload() {
        serviceBox.disabled = true
        const ticket = policy.read()
        try {
            const data = await api('GET', '/api/grants?page=1')
            if (!policy.accept(ticket, data.revision)) return
            serviceBox.checked = data.serviceEnabled
        } catch {
            if (!scope.disposed) {
                policy.invalidate()
                notifyError(t('mcp', 'Could not load the users.'))
            }
        } finally { if (!scope.disposed) serviceBox.disabled = policy.revision === null }
    }
    scope.on(serviceBox, 'change', async () => {
        if (policy.revision === null) return
        const value = serviceBox.checked
        const sent = policy.revision
        serviceBox.disabled = true
        try {
            const result = await api('PUT', '/api/service', { enabled: value, expectedRevision: sent }, { uncertainWrite: true })
            if (!policy.saved(sent, result.revision)) { await reload(); return }
            serviceBox.checked = result.enabled
            serviceStatus.textContent = t('mcp', 'Saved')
            flash(serviceStatus, true)
        } catch (error) {
            if (scope.disposed) return
            if (mustReloadPolicy(error)) {
                policy.invalidate()
                await reload()
            } else serviceBox.checked = !value
            const message = error.message === '503' && error.data?.uncertain
                ? t('mcp', 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.')
                : t('mcp', 'Could not change the MCP service.')
            serviceStatus.textContent = error.data?.uncertain ? message : t('mcp', 'Not saved')
            flash(serviceStatus, false)
            notifyError(message)
        } finally { if (!scope.disposed) serviceBox.disabled = policy.revision === null }
    })
    return { ready: reload(), destroy: scope.destroy }
}
