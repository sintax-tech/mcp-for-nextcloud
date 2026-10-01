<?php
/**
 * Admin page of the "MCP for Nextcloud" settings section, one core `.section` per block:
 * status, OAuth clients, hidden files & tags, OCR, the users × permissions matrix and the active connections.
 * The matrix, OAuth clients and tags are filled by js/admin-grants.js, the connections by js/connections.js.
 *
 * @var array{endpoint:string, serviceEnabled:bool, ocrActive:bool, ocrUrl:string, version:string, eligibleUsers:int, connectedUsers:int, activeConnections:int} $_
 * @var \OCP\IL10N $l
 */
\OCP\Util::addScript('mcp', 'admin-grants');
\OCP\Util::addScript('mcp', 'connections');
\OCP\Util::addStyle('mcp', 'admin');
?>
<div id="mcp-admin" class="mcp-settings" data-service-enabled="<?php p($_['serviceEnabled'] ? '1' : '0'); ?>">
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
                <span class="mcp-card-value" id="mcp-count-eligible"><?php p((string)$_['eligibleUsers']); ?></span>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('Connected users')); ?></span>
                <span class="mcp-card-value" id="mcp-count-connected"><?php p((string)$_['connectedUsers']); ?></span>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?php p($l->t('Active connections')); ?></span>
                <span class="mcp-card-value" id="mcp-count-connections"><?php p((string)$_['activeConnections']); ?></span>
            </div>
        </div>
        <p class="settings-hint mcp-hint"><?php p($l->t('Paste the endpoint in the MCP client. Turning the service off signs every client out at once.')); ?></p>
    </div>

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
    </div>

    <div class="section mcp-block" id="mcp-block-tags">
        <h2><?php p($l->t('Hidden files & tags')); ?></h2>
        <p class="settings-hint mcp-hint"><?php p($l->t('Files and folders tagged with the selected system tags will be completely hidden from MCP tools. Recommended: use restricted or invisible tags so users cannot remove them.')); ?></p>
        <div id="mcp-hidden-tags-section" class="mcp-hidden-tags">
            <div id="mcp-tags-list" class="mcp-tags-container" aria-busy="true">
                <span class="mcp-tags-loading"><?php p($l->t('Loading tags…')); ?></span>
            </div>
            <div id="mcp-tags-warning" class="mcp-tags-warning" style="display: none;">
                <?php p($l->t('Warning: one or more selected tags are collaborative. Users with edit access can remove collaborative tags, which may expose hidden files.')); ?>
            </div>
            <span class="mcp-status" id="mcp-tags-status" aria-live="polite"></span>
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

    <div class="section mcp-block" id="mcp-block-matrix">
        <h2><?php p($l->t('Permissions')); ?></h2>
        <p class="settings-hint mcp-hint"><?php p($l->t('Users need “Can connect” and must activate the connection in their personal settings. Read permissions start allowed; write, delete and transfer start denied. Nextcloud permissions and shares still apply.')); ?></p>
        <p class="settings-hint mcp-hint"><?php p($l->t('Each change is saved at once. The “All” menus in the header allow or deny a permission for every user on the current page.')); ?></p>
        <div class="mcp-toolbar">
            <input type="search" id="mcp-search" placeholder="<?php p($l->t('Search user (name, user ID or e-mail)')); ?>" aria-label="<?php p($l->t('Search user')); ?>">
            <select id="mcp-group" aria-label="<?php p($l->t('Group')); ?>">
                <option value=""><?php p($l->t('All users')); ?></option>
            </select>
            <select id="mcp-filter" aria-label="<?php p($l->t('Show')); ?>">
                <option value=""><?php p($l->t('Everyone')); ?></option>
                <option value="eligible"><?php p($l->t('Only users who can connect')); ?></option>
                <option value="connected"><?php p($l->t('Only connected users')); ?></option>
            </select>
            <span>
                <input type="checkbox" class="checkbox" id="mcp-compact">
                <label for="mcp-compact"><?php p($l->t('Compact rows')); ?></label>
            </span>
            <span class="mcp-pager">
                <span id="mcp-count" class="mcp-count" aria-live="polite"></span>
                <button type="button" id="mcp-prev"><?php p($l->t('Previous')); ?></button>
                <span id="mcp-page-info"></span>
                <button type="button" id="mcp-next"><?php p($l->t('Next')); ?></button>
            </span>
        </div>
        <div class="mcp-matrix-wrap">
            <table class="mcp-matrix" id="mcp-matrix" aria-busy="true">
                <thead></thead>
                <tbody><tr><td class="mcp-empty"><?php p($l->t('Loading…')); ?></td></tr></tbody>
            </table>
        </div>
        <div class="mcp-toolbar mcp-toolbar-bottom">
            <span class="mcp-pager">
                <button type="button" id="mcp-prev-bottom"><?php p($l->t('Previous')); ?></button>
                <span id="mcp-page-info-bottom"></span>
                <button type="button" id="mcp-next-bottom"><?php p($l->t('Next')); ?></button>
            </span>
        </div>
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
