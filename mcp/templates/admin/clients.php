<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** Clients panel of the administrator settings.
 * @var array<string, mixed> $_ settings parameters
 * @var \OCP\IL10N $l app translator
 */
?>
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

