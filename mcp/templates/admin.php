<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/**
 * Admin page of the "MCP for Cloud" settings section: five WAI-ARIA tabs, each with one
 * `role="tabpanel"` holding the core `.section` blocks of its area — overview (status), access and
 * permissions (the users × permissions matrix), clients and connections (OAuth clients and active
 * connections), data protection (exclusion tags) and advanced (server log, log privacy, OCR).
 * The resource scope panel is src/admin/scope/start.js.
 * src/admin/admin-tabs.js provides the tab behavior and the URL hash mapping; src/admin/picker/start.js is the
 * readable-search multi-select of the log groups and the exclusion tags; the matrix, OAuth clients,
 * tags and log groups are filled by src/admin/matrix/start.js, the connections by src/shared/connections.js.
 * The page loads them as one script, js/mcp-admin.js, built from src/admin.manifest by scripts/build_assets.php. Without
 * JavaScript every panel stays visible, the tab bar stays hidden and the log privacy detail stays open.
 *
 * @var array{renderAdminPanel:callable, endpoint:string, serviceEnabled:bool, ocrActive:bool, ocrUrl:string, version:string, eligibleUsers:int|null, connectedUsers:int|null, activeConnections:int, unsupportedApps:list<array{name:string, installed:string, required:string}>, logsAvailable:bool, logType:string} $_
 * @var \OCP\IL10N $l
 */
\OCP\Util::addScript('mcp', 'mcp-admin');
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

<?php print_unescaped($_['renderAdminPanel']('overview', $_)); ?><?php print_unescaped($_['renderAdminPanel']('access', $_)); ?><?php print_unescaped($_['renderAdminPanel']('clients', $_)); ?><?php print_unescaped($_['renderAdminPanel']('protection', $_)); ?><?php print_unescaped($_['renderAdminPanel']('advanced', $_)); ?>

    <footer class="mcp-attribution">
        <?php p($l->t('Developed by Sintax')); ?> · <a href="https://sintax.tech" target="_blank" rel="noopener noreferrer">sintax.tech</a>
    </footer>
</div>
