<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Admin page of the "MCP for Cloud" settings section: five WAI-ARIA tabs, each with one
 * `role="tabpanel"` holding the core `.section` blocks of its area — overview (status), access and
 * permissions (the users × permissions matrix), clients and connections (OAuth clients and active
 * connections), data protection (exclusion tags) and advanced (server log, log privacy, OCR).
 * The resource scope panel is js/admin-scope.js.
 * js/admin-tabs.js provides the tab behavior and the URL hash mapping; js/admin-picker.js is the
 * readable-search multi-select of the log groups and the exclusion tags; the matrix, OAuth clients,
 * tags and log groups are filled by js/admin-grants.js, the connections by js/connections.js. Without
 * JavaScript every panel stays visible, the tab bar stays hidden and the log privacy detail stays open.
 *
 * @var array{endpoint:string, serviceEnabled:bool, ocrActive:bool, ocrUrl:string, version:string, eligibleUsers:int|null, connectedUsers:int|null, activeConnections:int, unsupportedApps:list<array{name:string, installed:string, required:string}>, logsAvailable:bool, logType:string} $_
 * @var \OCP\IL10N $l
 */
\OCP\Util::addScript('mcp', 'admin-picker');
\OCP\Util::addScript('mcp', 'admin-levels');
\OCP\Util::addScript('mcp', 'admin-grants');
\OCP\Util::addScript('mcp', 'admin-level-popover');
\OCP\Util::addScript('mcp', 'admin-exceptions');
\OCP\Util::addScript('mcp', 'admin-row-actions');
\OCP\Util::addScript('mcp', 'admin-overrides');
\OCP\Util::addScript('mcp', 'admin-groups');
\OCP\Util::addScript('mcp', 'admin-scope');
\OCP\Util::addScript('mcp', 'admin-inspector');
\OCP\Util::addScript('mcp', 'connections');
\OCP\Util::addScript('mcp', 'admin-tabs');
\OCP\Util::addScript('mcp', 'admin-file-write-policy');
\OCP\Util::addScript('mcp', 'admin-backups');
\OCP\Util::addStyle('mcp', 'admin');
?>
<div id="mcp-admin" class="mcp-settings" data-service-enabled="<?php p($_['serviceEnabled'] ? '1' : '0'); ?>">
    <div class="mcp-tab-bar" role="tablist" aria-label="<?php p($l->t('Sections')); ?>">
        <button type="button" role="tab" id="mcp-tab-overview" data-tab-hash="overview" aria-controls="mcp-panel-overview" aria-selected="true"><?php p($l->t('Overview')); ?></button>
        <button type="button" role="tab" id="mcp-tab-access" data-tab-hash="access" aria-controls="mcp-panel-access" aria-selected="false"><?php p($l->t('Access and permissions')); ?></button>
        <button type="button" role="tab" id="mcp-tab-clients" data-tab-hash="clients" aria-controls="mcp-panel-clients" aria-selected="false"><?php p($l->t('Clients and connections')); ?></button>
        <button type="button" role="tab" id="mcp-tab-protection" data-tab-hash="protection" aria-controls="mcp-panel-protection" aria-selected="false"><?php p($l->t('Data protection')); ?><span id="mcp-tab-protection-alert" class="mcp-tab-alert" hidden> <?php p($l->t('(action needed)')); ?></span></button>
        <button type="button" role="tab" id="mcp-tab-advanced" data-tab-hash="advanced" aria-controls="mcp-panel-advanced" aria-selected="false"><?php p($l->t('Advanced')); ?></button>
    </div>

    <div role="tabpanel" id="mcp-panel-overview" aria-labelledby="mcp-tab-overview" class="mcp-tab-panel" tabindex="0">
    <div class="section mcp-block" id="mcp-block-status">
        <h2><?php p($l->t('Status')); ?></h2>
        <div class="mcp-cards">
            <div class="mcp-card mcp-card-wide">
                <span class="mcp-card-label"><?php p($l->t('Endpoint')); ?></span>
                <span class="mcp-endpoint">
                    <code id="mcp-endpoint"><?php p($_['endpoint']); ?></code>
                    <button type="button" class="mcp-copy" data-copy="mcp-endpoint"><?php p($l->t('Copy')); ?></button>
                </span>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('Service')); ?></span>
                <span>
                    <input type="checkbox" class="checkbox" id="mcp-service" <?php if ($_['serviceEnabled']) { print_unescaped('checked'); } ?>>
                    <label for="mcp-service"><?php p($l->t('MCP service enabled')); ?></label>
                    <span class="mcp-status" id="mcp-service-status" aria-live="polite"></span>
                </span>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('App version')); ?></span>
                <span class="mcp-card-value"><?php p($_['version']); ?></span>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('Eligible users')); ?></span>
                <span class="mcp-card-value" id="mcp-count-eligible"><?php p($_['eligibleUsers'] === null ? '—' : (string)$_['eligibleUsers']); ?></span>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('Connected users')); ?></span>
                <span class="mcp-card-value" id="mcp-count-connected"><?php p($_['connectedUsers'] === null ? '—' : (string)$_['connectedUsers']); ?></span>
                <span class="mcp-hint"><?php p($l->t('Eligible users who activated their connection.')); ?></span>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('Active connections')); ?></span>
                <span class="mcp-card-value" id="mcp-count-connections"><?php p((string)$_['activeConnections']); ?></span>
            </div>
            <div class="mcp-card mcp-card-wide">
                <label class="mcp-card-label" for="mcp-checkout-limit"><?php p($l->t('Checkout upload limit')); ?></label>
                <span>
                    <input type="number" id="mcp-checkout-limit" class="mcp-checkout-limit" min="1" max="102400" step="1" inputmode="numeric" aria-describedby="mcp-checkout-limit-info">
                    <span><?php p($l->t('MiB')); ?></span>
                    <button type="button" id="mcp-checkout-limit-save"><?php p($l->t('Save')); ?></button>
                    <span class="mcp-status" id="mcp-checkout-limit-status" aria-live="polite"></span>
                </span>
                <span class="mcp-hint" id="mcp-checkout-limit-info"></span>
            </div>
        </div>
        <p class="settings-hint mcp-hint"><?php p($l->t('Paste the endpoint in the MCP client. Turning the service off signs every client out at once.')); ?></p>
        <?php include __DIR__ . '/admin-file-write-policy-overview.php'; ?>
        <div id="mcp-tags-invalid-overview" class="mcp-tags-warning" role="alert" hidden>
            <p><?php p($l->t('The saved exclusion tags setting cannot be read, so the MCP tools treat every file and folder as not found. Choose the exclusion tags and save them again.')); ?> <a href="#protection"><?php p($l->t('Open Data protection')); ?></a></p>
        </div>
    </div>
    </div>

    <div role="tabpanel" id="mcp-panel-access" aria-labelledby="mcp-tab-access" class="mcp-tab-panel" tabindex="0">
    <div class="section mcp-block" id="mcp-group-editor">
        <h2><?php p($l->t('Group grants')); ?></h2>
        <details class="mcp-group-rules">
            <summary><?php p($l->t('How group grants work')); ?></summary>
            <p class="mcp-hint"><?php p($l->t('Group grants apply to the live group, including future members. Individual overrides take precedence. Nextcloud permissions still apply.')); ?></p>
            <p class="mcp-hint"><?php p($l->t('Only native Nextcloud groups can grant access. If a group is deleted and that event is lost, a new group with the same ID may inherit the old policy.')); ?></p>
            <p class="mcp-hint"><?php p($l->t('Taking “Can connect” away from a group is refused while it is unknown what a signed-in member would keep. Check the sources and try again.')); ?></p>
        </details>
        <div class="mcp-groups" id="mcp-groups" data-list="open">
            <button type="button" id="mcp-group-picker" class="mcp-group-picker" aria-expanded="true" aria-controls="mcp-groups-nav"><?php p($l->t('Choose a group')); ?></button>
            <nav class="mcp-groups-nav" id="mcp-groups-nav" aria-label="<?php p($l->t('Groups')); ?>">
                <div class="mcp-groups-top">
                    <label for="mcp-group-search" class="mcp-sr-only"><?php p($l->t('Search groups by name or ID')); ?></label>
                    <input type="search" id="mcp-group-search" placeholder="<?php p($l->t('Search groups by name or ID')); ?>">
                    <div class="mcp-view mcp-group-filter" role="group" aria-label="<?php p($l->t('Show groups')); ?>">
                        <button type="button" id="mcp-group-filter-all" aria-pressed="true"><?php p($l->t('All')); ?></button>
                        <button type="button" id="mcp-group-filter-granted" aria-pressed="false"><?php p($l->t('With grants')); ?></button>
                        <button type="button" id="mcp-group-filter-blocked" aria-pressed="false"><?php p($l->t('Blocked groups')); ?></button>
                    </div>
                    <p id="mcp-group-count" class="mcp-hint" aria-live="polite"></p>
                </div>
                <ul id="mcp-group-list" class="mcp-group-list" aria-busy="true" aria-label="<?php p($l->t('Groups')); ?>"></ul>
                <div class="mcp-groups-pager">
                    <button type="button" id="mcp-group-prev"><?php p($l->t('Previous')); ?></button>
                    <button type="button" id="mcp-group-next"><?php p($l->t('Next')); ?></button>
                </div>
            </nav>
            <div class="mcp-groups-main">
                <p id="mcp-group-status" role="status" aria-live="polite"></p>
                <p id="mcp-group-alert" role="alert" aria-live="assertive"></p>
                <div id="mcp-group-detail"></div>
            </div>
        </div>
    </div>
    <div class="section mcp-block mcp-matrix-block" id="mcp-block-matrix">
        <h2><?php p($l->t('Permissions')); ?></h2>
        <?php foreach ($_['unsupportedApps'] as $app) { ?>
            <p class="mcp-unsupported-app"><?php p($l->t('%1$s %2$s is installed, but MCP for Cloud supports it from version %3$s on. Its tools stay hidden until the app is updated; the saved permissions are kept.', [$app['name'], $app['installed'], $app['required']])); ?></p>
        <?php } ?>
        <div class="mcp-toolbar">
            <input type="search" id="mcp-search" placeholder="<?php p($l->t('Search user (name, user ID or e-mail)')); ?>" aria-label="<?php p($l->t('Search user')); ?>">
            <select id="mcp-group" aria-label="<?php p($l->t('Group')); ?>">
                <option value=""><?php p($l->t('All users')); ?></option>
            </select>
            <select id="mcp-filter" aria-label="<?php p($l->t('Show')); ?>">
                <option value=""><?php p($l->t('Everyone')); ?></option>
                <option value="eligible" selected><?php p($l->t('Only users who can connect')); ?></option>
                <option value="connected"><?php p($l->t('Only connected users')); ?></option>
            </select>
            <span class="mcp-view" role="group" aria-label="<?php p($l->t('Show permissions as')); ?>">
                <button type="button" id="mcp-view-levels" aria-pressed="true"><?php p($l->t('Levels')); ?></button>
                <button type="button" id="mcp-view-operations" aria-pressed="false"><?php p($l->t('Operations')); ?></button>
            </span>
            <span id="mcp-app-filter" hidden>
                <label for="mcp-app"><?php p($l->t('Show apps')); ?></label>
                <select id="mcp-app" disabled>
                    <option value=""><?php p($l->t('All apps')); ?></option>
                </select>
            </span>
            <span class="mcp-view" role="group" aria-label="<?php p($l->t('Show people')); ?>">
                <button type="button" id="mcp-only-all" aria-pressed="true"><?php p($l->t('All')); ?></button>
                <button type="button" id="mcp-only-exceptions" aria-pressed="false"><?php p($l->t('With exceptions')); ?></button>
            </span>
            <span id="mcp-count" class="mcp-count" aria-live="polite"></span>
        </div>
        <div id="mcp-redundant-notice" class="mcp-note mcp-note-info" role="status" hidden>
            <p id="mcp-redundant-notice-text"></p>
            <button type="button" id="mcp-redundant-review"><?php p($l->t('Review')); ?></button>
        </div>
        <div class="mcp-matrix-wrap">
            <table class="mcp-matrix" id="mcp-matrix" aria-busy="true">
                <thead></thead>
                <tbody><tr><td class="mcp-empty"><?php p($l->t('Loading…')); ?></td></tr></tbody>
            </table>
        </div>
        <div id="mcp-row-menu" class="mcp-row-menu" role="menu" hidden></div>
        <div id="mcp-level-popover" class="mcp-popover" role="dialog" aria-labelledby="mcp-level-popover-title" hidden></div>
        <div class="mcp-bulk-notice" id="mcp-bulk-notice" role="status" aria-live="polite">
            <span id="mcp-bulk-notice-text" tabindex="-1"></span>
            <button type="button" id="mcp-bulk-undo" hidden><?php p($l->t('Undo')); ?></button>
        </div>
        <div class="mcp-toolbar mcp-toolbar-bottom">
            <p class="mcp-legend mcp-legend-levels" id="mcp-legend-levels">
                <span class="mcp-legend-item"><span class="mcp-origin-icon icon-user" aria-hidden="true"></span> <?php p($l->t('Exception: differs from what the person inherits')); ?></span>
                <span class="mcp-legend-item"><span class="mcp-origin-icon icon-group" aria-hidden="true"></span> <?php p($l->t('From a group')); ?></span>
                <span class="mcp-legend-item"><?php p($l->t('No icon: default')); ?></span>
                <span class="mcp-legend-item"><span class="mcp-state-mark" aria-hidden="true">⊘</span> <?php p($l->t('Unavailable')); ?></span>
                <span><?php p($l->t('Choose a cell to change its level or its operations.')); ?></span>
                <span><?php p($l->t('Reading starts allowed, writing denied. Nextcloud permissions still apply.')); ?></span>
            </p>
            <p class="mcp-legend" id="mcp-legend-operations" hidden>
                <span class="mcp-legend-item"><input type="checkbox" checked disabled aria-hidden="true" tabindex="-1"> <?php p($l->t('Allowed')); ?></span>
                <span class="mcp-legend-item"><input type="checkbox" disabled aria-hidden="true" tabindex="-1"> <?php p($l->t('Denied')); ?></span>
                <span class="mcp-legend-item"><span class="mcp-state-mark" aria-hidden="true">⊘</span> <?php p($l->t('Unavailable')); ?></span>
                <span><?php p($l->t('Point at ⊘ for the reason.')); ?></span>
            </p>
            <span class="mcp-pager">
                <button type="button" id="mcp-prev-bottom"><?php p($l->t('Previous')); ?></button>
                <span id="mcp-page-info-bottom"></span>
                <button type="button" id="mcp-next-bottom"><?php p($l->t('Next')); ?></button>
            </span>
        </div>
    </div>
    <div class="section mcp-block mcp-drawer" id="mcp-scope" aria-labelledby="mcp-scope-title">
        <div class="mcp-drawer-head">
            <h2 id="mcp-scope-title" tabindex="-1"><?php p($l->t('Resource scope')); ?></h2>
            <button type="button" id="mcp-scope-close" class="mcp-drawer-close" aria-label="<?php p($l->t('Close')); ?>">✕</button>
        </div>
        <div class="mcp-drawer-entry">
            <p class="mcp-hint"><?php p($l->t('Limit the MCP tools of one person to chosen folders, calendars and task lists. It only narrows what the person already has.')); ?></p>
            <button type="button" id="mcp-scope-entry"><?php p($l->t('Restrict the resources of a person…')); ?></button>
        </div>
        <div class="mcp-drawer-body">
            <form id="mcp-scope-form" class="mcp-inspector-form">
                <label for="mcp-scope-user"><?php p($l->t('User ID')); ?></label>
                <input type="text" id="mcp-scope-user" autocomplete="off" spellcheck="false">
                <button type="submit"><?php p($l->t('Open')); ?></button>
            </form>
            <p id="mcp-scope-status" role="status" aria-live="polite"></p>
            <p id="mcp-scope-alert" role="alert" aria-live="assertive"></p>
            <div id="mcp-scope-tabs" class="mcp-drawer-tabs" role="tablist" aria-label="<?php p($l->t('Modules')); ?>"></div>
            <div id="mcp-scope-modules"></div>
            <p class="mcp-hint"><?php p($l->t('Restricting does not end the sessions of the user: it applies from their next MCP call.')); ?></p>
        </div>
    </div>
    <div class="section mcp-block mcp-drawer" id="mcp-inspector" aria-labelledby="mcp-inspector-title">
        <div class="mcp-drawer-head">
            <h2 id="mcp-inspector-title" tabindex="-1"><?php p($l->t('Access inspector')); ?></h2>
            <button type="button" id="mcp-inspector-close" class="mcp-drawer-close" aria-label="<?php p($l->t('Close')); ?>">✕</button>
        </div>
        <div class="mcp-drawer-entry">
            <p class="mcp-hint"><?php p($l->t('See what the MCP tools of one person can do now and what blocks the rest. It only reads.')); ?></p>
            <button type="button" id="mcp-inspector-entry"><?php p($l->t('Inspect a person…')); ?></button>
        </div>
        <div class="mcp-drawer-body">
        <form id="mcp-inspector-form" class="mcp-inspector-form">
            <label for="mcp-inspector-user"><?php p($l->t('User ID')); ?></label>
            <input type="text" id="mcp-inspector-user" autocomplete="off" spellcheck="false">
            <button type="submit"><?php p($l->t('Inspect')); ?></button>
        </form>
        <p id="mcp-inspector-status" role="status" aria-live="polite"></p>
        <p id="mcp-inspector-alert" role="alert" aria-live="assertive"></p>
        <div id="mcp-inspector-access"></div>
        <details id="mcp-inspector-resources" class="mcp-inspector-why" hidden>
            <summary><?php p($l->t('Why a file or calendar is reached or not')); ?></summary>
            <p class="mcp-hint"><?php p($l->t('Names appear only here, for administrators. To the MCP tools, anything they cannot reach is simply not found.')); ?></p>
            <form id="mcp-inspector-path-form" class="mcp-inspector-form">
                <label for="mcp-inspector-path"><?php p($l->t('Path in the files of the user')); ?></label>
                <input type="text" id="mcp-inspector-path" placeholder="/Documents/report.pdf" autocomplete="off" spellcheck="false">
                <button type="submit"><?php p($l->t('Explain')); ?></button>
            </form>
            <p id="mcp-inspector-path-result" aria-live="polite"></p>
            <form id="mcp-inspector-calendar-form" class="mcp-inspector-form">
                <label for="mcp-inspector-calendar-module"><?php p($l->t('Module')); ?></label>
                <select id="mcp-inspector-calendar-module">
                    <option value="calendar"><?php p($l->t('Calendar')); ?></option>
                    <option value="tasks"><?php p($l->t('Tasks')); ?></option>
                </select>
                <label for="mcp-inspector-calendar"><?php p($l->t('Calendar or task list')); ?></label>
                <select id="mcp-inspector-calendar"></select>
                <label for="mcp-inspector-calendar-operation"><?php p($l->t('Operation')); ?></label>
                <select id="mcp-inspector-calendar-operation"></select>
                <button type="submit"><?php p($l->t('Explain')); ?></button>
            </form>
            <p id="mcp-inspector-calendar-result" aria-live="polite"></p>
        </details>
        </div>
    </div>
    </div>

    <div role="tabpanel" id="mcp-panel-clients" aria-labelledby="mcp-tab-clients" class="mcp-tab-panel" tabindex="0">
    <div class="section mcp-block" id="mcp-block-oauth">
        <h2><?php p($l->t('OAuth clients')); ?></h2>
        <p>
            <label for="mcp-oauth-hosts"><?php p($l->t('Allowed client hosts')); ?></label>
            <input type="text" id="mcp-oauth-hosts" class="mcp-oauth-hosts" placeholder="claude.ai, chatgpt.com" spellcheck="false" autocomplete="off">
            <button type="button" id="mcp-oauth-hosts-save"><?php p($l->t('Save')); ?></button>
            <span class="mcp-status" id="mcp-oauth-hosts-status" aria-live="polite"></span>
        </p>
        <p class="settings-hint mcp-hint"><?php p($l->t('Hosts separated by commas. claude.ai and chatgpt.com are the default. The host is the one in the URL the client uses as its client_id.')); ?></p>
        <p>
            <input type="checkbox" class="checkbox" id="mcp-native-client">
            <label for="mcp-native-client"><?php p($l->t('Allow local programs (native client)')); ?></label>
            <span class="mcp-status" id="mcp-native-client-status" aria-live="polite"></span>
        </p>
        <div id="mcp-native-client-details" class="mcp-native-client-details" hidden>
            <p>
                client_id: <code id="mcp-native-client-id"></code>
                <button type="button" class="mcp-copy" data-copy="mcp-native-client-id"><?php p($l->t('Copy')); ?></button>
            </p>
            <p><?php p($l->t('Accepted redirect URIs')); ?>:</p>
            <ul id="mcp-native-redirects" class="mcp-native-redirects"></ul>
            <p class="settings-hint mcp-hint"><?php p($l->t('Paste this client_id in the client settings, for example Gemini CLI settings.json → mcpServers.<name>.oauth.clientId.')); ?></p>
        </div>
        <h3 class="mcp-custom-heading"><?php p($l->t('Self-hosted clients (custom redirect URIs)')); ?></h3>
        <p class="settings-hint mcp-hint"><?php p($l->t('For self-hosted programs that take a client_id in their settings and return to their own domain. Add one client per program: its name is shown to people on the consent screen and in their connections. Turning a client off, deleting it or removing one of its redirect URIs signs out its connections.')); ?></p>
        <div id="mcp-custom-clients" class="mcp-custom-clients" aria-live="polite"></div>
        <p><button type="button" id="mcp-custom-add"><?php p($l->t('Add client')); ?></button></p>
    </div>

    <div class="section mcp-block" id="mcp-connections" data-scope="admin">
        <h2><?php p($l->t('Active connections')); ?></h2>
        <p class="settings-hint mcp-hint"><?php p($l->t('Each row is one MCP client signed in with OAuth. Revoking signs that client out at once; the user can sign in again while they may connect. App passwords are managed in each user’s security settings.')); ?></p>
        <div class="mcp-toolbar">
            <input type="search" id="mcp-connections-search" placeholder="<?php p($l->t('Search by user ID or client')); ?>" aria-label="<?php p($l->t('Search connections')); ?>">
            <span class="mcp-pager">
                <button type="button" id="mcp-connections-prev"><?php p($l->t('Previous')); ?></button>
                <span id="mcp-connections-page-info"></span>
                <button type="button" id="mcp-connections-next"><?php p($l->t('Next')); ?></button>
            </span>
            <span class="mcp-status" id="mcp-connections-status" aria-live="polite"></span>
        </div>
        <table class="mcp-connections-table" id="mcp-connections-table" aria-busy="true">
            <thead>
                <tr>
                    <th scope="col"><?php p($l->t('User')); ?></th>
                    <th scope="col"><?php p($l->t('Client')); ?></th>
                    <th scope="col"><?php p($l->t('Signed in')); ?></th>
                    <th scope="col"><?php p($l->t('Expires')); ?></th>
                    <th scope="col"><span class="hidden-visually"><?php p($l->t('Actions')); ?></span></th>
                </tr>
            </thead>
            <tbody><tr><td colspan="5" class="mcp-empty"><?php p($l->t('Loading…')); ?></td></tr></tbody>
        </table>
    </div>
    </div>

    <div role="tabpanel" id="mcp-panel-protection" aria-labelledby="mcp-tab-protection" class="mcp-tab-panel" tabindex="0">
    <?php include __DIR__ . '/admin-backups.php'; ?>
    <?php include __DIR__ . '/admin-file-write-policy.php'; ?>
    <div class="section mcp-block" id="mcp-block-tags">
        <h2><?php p($l->t('Excluded from MCP access')); ?></h2>
        <p class="settings-hint mcp-hint"><?php p($l->t('Files and folders tagged with a selected tag, and everything inside those folders, are completely hidden from the tools of this MCP connector. The server log tools respect this only while “Hide the text of log messages” is on.')); ?></p>
        <p class="settings-hint mcp-hint"><?php p($l->t('The protection applies only to this connector: it does not cover other AI tools or connectors, and nothing stops someone from sending a file to an AI by hand. Only the tags selected here exclude files; any other tag is an ordinary label.')); ?></p>
        <p class="settings-hint mcp-hint"><?php p($l->t('Recommended: invisible tags, which only administrators can assign or remove, or restricted tags, which only administrators and the groups they allow can assign or remove; users with edit access can remove collaborative tags.')); ?></p>
        <div id="mcp-hidden-tags-section" class="mcp-hidden-tags">
            <div id="mcp-tags-list" class="mcp-tags-container" aria-busy="true">
                <span class="mcp-tags-loading"><?php p($l->t('Loading tags…')); ?></span>
            </div>
            <div id="mcp-tags-invalid" class="mcp-tags-warning" role="alert" style="display: none;">
                <p><?php p($l->t('The saved exclusion tags setting cannot be read, so the MCP tools treat every file and folder as not found. Choose the exclusion tags and save them again.')); ?></p>
                <button type="button" id="mcp-tags-repair"><?php p($l->t('Save exclusion tags')); ?></button>
            </div>
            <div id="mcp-tags-warning" class="mcp-tags-warning" style="display: none;">
                <?php p($l->t('Warning: one or more selected tags are collaborative. Users with edit access can remove collaborative tags, which may expose excluded files.')); ?>
            </div>
            <span class="mcp-status" id="mcp-tags-status" aria-live="polite"></span>
        </div>
    </div>
    </div>

    <div role="tabpanel" id="mcp-panel-advanced" aria-labelledby="mcp-tab-advanced" class="mcp-tab-panel" tabindex="0">
    <div class="section mcp-block" id="mcp-block-logs">
        <h2><?php p($l->t('Server log')); ?></h2>
        <?php if (!$_['logsAvailable']) { ?>
            <p class="mcp-unsupported-app" id="mcp-logs-unavailable"><?php p($l->t('This server writes its log to %s, not to a file, so the server log tools stay hidden. They need the file log, the default of Nextcloud.', [$_['logType']])); ?></p>
        <?php } ?>
        <p class="settings-hint mcp-hint"><?php p($l->t('The server log shows file names, addresses and accounts of every user. Choose below which groups, besides administrators, may read it through MCP.')); ?></p>
        <div id="mcp-logs-groups" class="mcp-logs-groups" aria-busy="true">
            <span class="mcp-tags-loading"><?php p($l->t('Loading groups…')); ?></span>
        </div>
        <p class="mcp-picker-summary mcp-hint" id="mcp-logs-summary"></p>
        <span class="mcp-status" id="mcp-logs-status" aria-live="polite"></span>
    </div>

    <div class="section mcp-block" id="mcp-block-logs-privacy">
        <h2><?php p($l->t('Log privacy')); ?></h2>
        <p>
            <input type="checkbox" class="checkbox" id="mcp-logs-hide-file-names">
            <label for="mcp-logs-hide-file-names"><?php p($l->t('Hide the text of log messages')); ?></label>
            <span class="mcp-status" id="mcp-logs-hide-file-names-status" aria-live="polite"></span>
        </p>
        <p>
            <button type="button" class="mcp-learn-more" id="mcp-logs-privacy-more" aria-expanded="false" aria-controls="mcp-logs-privacy-detail" hidden><?php p($l->t('Learn more')); ?></button>
        </p>
        <div id="mcp-logs-privacy-detail" class="mcp-learn-more-box">
            <p class="settings-hint mcp-hint"><?php p($l->t('On (default): while exclusion tags are configured, the text of log messages and the identification of the client (user agent) are hidden; the date, level, app, user, error type and route stay. Off: the full messages and clients are shown for diagnosis, and the exclusion by tags is not respected. The option exists because some IT teams need the names to track problems.')); ?></p>
        </div>
    </div>

    <div class="section mcp-block" id="mcp-block-ocr">
        <h2><?php p($l->t('OCR')); ?></h2>
        <?php if ($_['ocrActive']) { ?>
            <p class="mcp-ocr" id="mcp-ocr" data-active="1"><?php p($l->t('Workflow OCR is active. PDFs it processes gain a text layer that files_read can read.')); ?></p>
        <?php } else { ?>
            <p class="mcp-ocr settings-hint mcp-hint" id="mcp-ocr" data-active="0">
                <?php p($l->t('Workflow OCR is not active. Scanned PDFs and images have no text for the AI; it is told to ask you or to view the page as an image.')); ?>
                <a href="<?php p($_['ocrUrl']); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('Get Workflow OCR in the App Store')); ?></a>.
                <?php p($l->t('It needs ocrmypdf installed on the server. Nothing else in MCP depends on it.')); ?>
            </p>
        <?php } ?>
    </div>
    </div>

    <footer class="mcp-attribution">
        <?php p($l->t('Developed by Sintax')); ?> · <a href="https://sintax.tech" target="_blank" rel="noopener noreferrer">sintax.tech</a>
    </footer>
</div>
