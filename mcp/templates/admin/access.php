<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** Access panel of the administrator settings.
 * @var array<string, mixed> $_ settings parameters
 * @var \OCP\IL10N $l app translator
 */
?>
    <div role="tabpanel" id="mcp-panel-access" aria-labelledby="mcp-tab-access" class="mcp-tab-panel" tabindex="0">
    <?php require dirname(__DIR__) . '/files-delete-warning.php'; ?>
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

