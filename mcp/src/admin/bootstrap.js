// SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
// SPDX-License-Identifier: AGPL-3.0-or-later
import { createPolicyChannel } from '../platform/policy-state.js'
import { mountTabs } from './tabs.js'
import { createPicker } from './picker.js'
import { mountMatrix } from './access/controller.js'
import { mountService } from './overview/service.js'
import { mountCheckoutLimit } from './overview/checkout-limit.js'
import { mountEndpointCopy } from './overview/endpoint-copy.js'
import { mountOauthClients } from './clients/oauth-clients.js'
import { mountExcludedTags } from './protection/excluded-tags.js'
import { mountLogAccess } from './logs/access-groups.js'
import { mountLogPrivacy } from './logs/privacy.js'
import { mountConnections } from '../settings/connections.js'

import { mountExceptions } from './access/exceptions.js'
import { mountOverrides } from './access/overrides.js'
import { mountRowActions } from './access/row-actions.js'
import { mountLevelPopover } from './access/level-popover.js'
import { mountGroups } from './groups/controller.js'
import { mountInspector } from './inspection/controller.js'
import { mountResourceScope } from './scope/controller.js'

/** Compose independent settings; keep state and failure recovery in their owners. */
export function mountAdmin({ root, ...dependencies }) {
    const policyChannel = createPolicyChannel()
    const section = id => ({ ...dependencies, policyChannel, root: root.querySelector('#' + id) })
    const picker = spec => createPicker({ ...spec, t: dependencies.t })
    const tabs = mountTabs({ root })
    const events = new EventTarget()
    let matrix, overrides, exceptions, rowActions, levelPopover, resourceScope, inspector
    const access = { ...dependencies, root, events, policyChannel,
        getMatrix: () => matrix, getOverrides: () => overrides, getExceptions: () => exceptions,
        getRowActions: () => rowActions, getLevelPopover: () => levelPopover,
        getResourceScope: () => resourceScope, getInspector: () => inspector }
    matrix = mountMatrix({ ...access, root: root.querySelector('#mcp-block-matrix') })
    overrides = mountOverrides(access)
    exceptions = mountExceptions(access)
    rowActions = mountRowActions(access)
    levelPopover = mountLevelPopover(access)
    resourceScope = mountResourceScope(access)
    inspector = mountInspector(access)
    const groups = mountGroups(access)
    const owners = [tabs, matrix, overrides, exceptions, rowActions, levelPopover, resourceScope, inspector, groups,
        mountService(section('mcp-block-status')),
        mountCheckoutLimit(section('mcp-block-status')),
        mountEndpointCopy({ ...dependencies, root }),
        mountOauthClients(section('mcp-block-oauth')),
        mountExcludedTags({ ...section('mcp-block-tags'), createPicker: picker,
            overviewAlert: root.querySelector('#mcp-tags-invalid-overview'),
            tabAlert: root.querySelector('#mcp-tab-protection-alert') }),
        mountLogAccess({ ...section('mcp-block-logs'), createPicker: picker, reloadMatrix: matrix.reload }),
        mountLogPrivacy(section('mcp-block-logs-privacy')),
        mountConnections({ ...section('mcp-connections'), mode: 'admin', onCounts(result) {
            if (Number.isInteger(result?.counts?.connections)) {
                root.querySelector('#mcp-count-connections').textContent = String(result.counts.connections)
            }
        } }),
    ]
    return { ready: Promise.all(owners.map(owner => owner.ready)), destroy() { for (const owner of owners.slice().reverse()) owner.destroy() } }
}
