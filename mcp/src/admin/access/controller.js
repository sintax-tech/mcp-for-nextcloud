// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createPolicyState, mustReloadPolicy } from '../../platform/policy-state.js'
import { createScope } from '../../platform/lifecycle.js'
import { createPresentation } from './presentation.js'
import { mountMatrixFilters } from './filters.js'
import { createMatrixView } from './view.js'
import { createBulkChanges } from './bulk-change.js'

/** Own the current server snapshot and compose matrix responsibilities explicitly. */
export function mountMatrix({ root, request, t, n, notifyError, policyChannel, events = new EventTarget(), getOverrides = () => null, getExceptions = () => null, getRowActions = () => null, getResourceScope = () => null, getLevelPopover = () => null }) {
    const scope = createScope()
    const api = scope.request(request)
    const policy = createPolicyState(policyChannel)
    scope.use(policy.destroy)
    let saving = false
    const presentation = createPresentation({ t })
    let data = null
    let loading = null
    let view
    let bulk
    const getData = () => data
    const filters = mountMatrixFilters({ root, t, getData, moduleLabels: presentation.moduleLabels, reload, onPresentationChange: () => { if (data) view?.render() } })
    view = createMatrixView({ root, t, presentation, getData, getFilters: () => filters.values, savePermission, onBulk: (...args) => bulk.apply(...args), isBulkBusy: () => bulk.busy || saving || policy.revision === null, reload, notifyError, events, getOverrides, getExceptions, getRowActions, getResourceScope, getLevelPopover })
    bulk = createBulkChanges({ root, request: api, t, n, getData, unavailableReason: presentation.unavailableReason, reload, onBusy: view.setBulkBusy, notifyError, policy, policyError })
    async function reload() {
        if (scope.disposed) return
        const ticket = policy.read()
        const pending = { revision: policy.revision }
        loading = pending
        view.setLoading(true)
        const { search, group, filter, page } = filters.values
        const query = new URLSearchParams({ search, group, filter, page: String(page) })
        try {
            const fresh = await api('GET', '/api/grants?' + query)
            if (!policy.accept(ticket, fresh.revision)) return
            data = fresh
            filters.renderOptions()
            view.render()
        } catch (error) {
            if (scope.disposed) return
            view.renderError()
            notifyError(t('mcp', 'Could not load the users.'))
        } finally {
            if (loading === pending) { loading = null; if (!scope.disposed) view.setLoading(false) }
        }
    }
    async function policyError(error) {
        if (!mustReloadPolicy(error)) return false
        policy.invalidate()
        notifyError(error.message === '503'
            ? t('mcp', 'The server could not confirm whether the change was saved. The table was reloaded with the saved values.')
            : t('mcp', 'The permissions were changed in another window or by another administrator. The table was reloaded with the saved values; nothing was overwritten.'))
        await reload()
        return true
    }
    async function savePermission(user, payload) {
        if (saving || bulk.busy || policy.revision === null) throw new Error('Policy snapshot unavailable')
        saving = true
        const sent = policy.revision
        try {
            const updated = await api('PUT', '/api/grants/' + encodeURIComponent(user.uid), { ...payload, expectedRevision: sent }, { uncertainWrite: true })
            if (!policy.saved(sent, updated.revision)) {
                await reload()
                throw new Error('Policy snapshot changed')
            }
            data.revision = updated.revision
            const index = data.users.findIndex(row => row.uid === user.uid)
            if (index !== -1) data.users[index] = updated
            return updated
        } catch (error) {
            await policyError(error)
            throw error
        } finally { saving = false }
    }
    // Other feature writes invalidate the rendered matrix; their drafts live in their own controllers.
    scope.on(events, 'mcp-group-policy-saved', reload)
    scope.on(events, 'mcp-scope-saved', reload)
    scope.use(policyChannel?.follow((from, to) => scope.timeout(() => { if (data?.revision !== to && loading?.revision !== to) reload() }, 0)) || (() => {}))
    const ready = reload()
    return { ready, reload, data: getData, api, policyError, notifyError,
        labels: () => ({ modules: presentation.moduleLabels(), operations: presentation.operationLabels() }),
        levelLabels: presentation.levelLabels, bulk: bulk.apply,
        isBusy: () => saving || bulk.busy || policy.revision === null,
        setBusy(on) { saving = on; view.setBulkBusy(on) },
        refresh() { if (data && !scope.disposed) view.render() },
        announce(from, to) { if (!policy.saved(from, to)) throw new Error('Policy snapshot changed'); if (data) data.revision = to },
        offerUndo: bulk.offerUndo, focusUndo: bulk.focusUndo,
        destroy() { scope.destroy(); bulk.destroy(); filters.destroy(); view.destroy() } }
}
