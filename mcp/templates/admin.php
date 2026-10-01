<?php
/**
 * Admin section: endpoint, service switch, OCR status, OAuth clients (filled by js/admin-grants.js), the users × permissions matrix rendered by
 * js/admin-grants.js, and the hidden system tags picker.
 *
 * @var array{endpoint:string, serviceEnabled:bool, ocrActive:bool, ocrUrl:string} $_
 * @var \OCP\IL10N $l
 */
\OCP\Util::addScript('mcp', 'admin-grants');
\OCP\Util::addStyle('mcp', 'admin');
?>
<div class="section" id="mcp-admin" data-service-enabled="<?php p($_['serviceEnabled'] ? '1' : '0'); ?>">
    <h2><?php p($l->t('MCP')); ?></h2>
    <p class="mcp-endpoint">
        <?php p($l->t('Endpoint')); ?>: <code id="mcp-endpoint"><?php p($_['endpoint']); ?></code>
        <button type="button" class="mcp-copy" data-copy="mcp-endpoint"><?php p($l->t('Copy')); ?></button>
    </p>
    <p>
        <input type="checkbox" class="checkbox" id="mcp-service" <?php if ($_['serviceEnabled']) { print_unescaped('checked'); } ?>>
        <label for="mcp-service"><?php p($l->t('MCP service enabled')); ?></label>
        <span class="mcp-status" id="mcp-service-status" aria-live="polite"></span>
    </p>
    <p class="mcp-hint"><?php p($l->t('Users need “Can connect” and must activate the connection in their personal settings. Read permissions start allowed; write, delete and transfer start denied. Nextcloud permissions and shares still apply.')); ?></p>

    <h3><?php p($l->t('OCR')); ?></h3>
    <?php if ($_['ocrActive']) { ?>
        <p class="mcp-ocr" id="mcp-ocr" data-active="1"><?php p($l->t('Workflow OCR is active. PDFs it processes gain a text layer that files_read can read.')); ?></p>
    <?php } else { ?>
        <p class="mcp-ocr mcp-hint" id="mcp-ocr" data-active="0">
            <?php p($l->t('Workflow OCR is not active. Scanned PDFs and images have no text for the AI; it is told to ask you or to view the page as an image.')); ?>
            <a href="<?php p($_['ocrUrl']); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('Get Workflow OCR in the App Store')); ?></a>.
            <?php p($l->t('It needs ocrmypdf installed on the server. Nothing else in MCP depends on it.')); ?>
        </p>
    <?php } ?>

    <h3><?php p($l->t('OAuth clients')); ?></h3>
    <p>
        <label for="mcp-oauth-hosts"><?php p($l->t('Allowed client hosts')); ?></label>
        <input type="text" id="mcp-oauth-hosts" class="mcp-oauth-hosts" placeholder="claude.ai, chatgpt.com" spellcheck="false" autocomplete="off">
        <button type="button" id="mcp-oauth-hosts-save"><?php p($l->t('Save')); ?></button>
        <span class="mcp-status" id="mcp-oauth-hosts-status" aria-live="polite"></span>
    </p>
    <p class="mcp-hint"><?php p($l->t('Hosts separated by commas. claude.ai and chatgpt.com are the default. The host is the one in the URL the client uses as its client_id.')); ?></p>
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
        <p class="mcp-hint"><?php p($l->t('Paste this client_id in the client settings, for example Gemini CLI settings.json → mcpServers.<name>.oauth.clientId.')); ?></p>
    </div>

    <div class="mcp-toolbar">
        <input type="search" id="mcp-search" placeholder="<?php p($l->t('Search user (name, user ID or e-mail)')); ?>" aria-label="<?php p($l->t('Search user')); ?>">
        <select id="mcp-group" aria-label="<?php p($l->t('Group')); ?>">
            <option value=""><?php p($l->t('All users')); ?></option>
        </select>
        <span class="mcp-pager">
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

    <h3><?php p($l->t('Hidden files & tags')); ?></h3>
    <p class="mcp-hint"><?php p($l->t('Files and folders tagged with the selected system tags will be completely hidden from MCP tools. Recommended: use restricted or invisible tags so users cannot remove them.')); ?></p>
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
