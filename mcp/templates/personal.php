<?php
/**
 * Personal page of the "MCP for Nextcloud" settings section: a status card (service, administrator eligibility,
 * own connection) with the Connect/Disconnect switch, the user's own OAuth connections (filled by
 * js/connections.js) and the app-password guidance. Every visible string goes through the IL10N that
 * \OC\Template\Base injects as $l, so the page follows the user language.
 *
 * @var array $_ template parameters provided by \OCA\Mcp\Settings\PersonalSettings
 * @var \OCP\IL10N $l translations for app "mcp", injected by the template engine
 */
\OCP\Util::addScript('mcp', 'connections');
\OCP\Util::addStyle('mcp', 'admin');
$escape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$service = $_['global'] ? $l->t('enabled') : $l->t('disabled');
$adminEligibility = $_['eligible'] ? $l->t('allowed') : $l->t('not allowed');
$connection = $_['connected'] ? $l->t('active') : $l->t('disconnected');
$canConnect = $_['connected'] || ($_['eligible'] && $_['global']);
?>
<div id="mcp-personal" class="mcp-settings">
    <div class="section mcp-block" id="mcp-personal-status">
        <h2><?= $l->t('MCP connection') ?></h2>
        <p class="mcp-endpoint"><?= $l->t('Endpoint: %s', ['<code>' . $escape($_['endpoint']) . '</code>']) ?></p>
        <div class="mcp-cards">
            <div class="mcp-card">
                <span class="mcp-card-label"><?= $l->t('Service') ?></span>
                <strong><?= $service ?></strong>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?= $l->t('Administrator eligibility') ?></span>
                <strong><?= $adminEligibility ?></strong>
            </div>
            <div class="mcp-card">
                <span class="mcp-card-label"><?= $l->t('Your connection') ?></span>
                <strong><?= $connection ?></strong>
            </div>
        </div>
        <form method="post" action="<?= $escape($_['action']) ?>">
            <input type="hidden" name="requesttoken" value="<?= $escape($_['token']) ?>">
            <input type="hidden" name="enabled" value="<?= $_['connected'] ? '0' : '1' ?>">
            <button type="submit" class="<?= $_['connected'] ? '' : 'primary' ?>" <?= $canConnect ? '' : 'disabled' ?>><?= $_['connected'] ? $l->t('Disconnect') : $l->t('Connect') ?></button>
        </form>
        <?php if (!$canConnect) { ?>
            <p class="settings-hint mcp-hint"><?= $l->t('An administrator must turn the service on and allow your account before you can connect.') ?></p>
        <?php } ?>
        <p class="settings-hint mcp-hint"><?= $l->t('Disconnecting blocks access to this app on the next request. It does not revoke the app password; revoke that separately in Nextcloud Settings → Security.') ?></p>
    </div>

    <div class="section mcp-block" id="mcp-connections" data-scope="personal">
        <h2><?= $l->t('Your connected clients') ?></h2>
        <p class="settings-hint mcp-hint"><?= $l->t('MCP clients you signed in with OAuth. Revoking signs a client out at once; you can sign in again later.') ?></p>
        <div class="mcp-toolbar">
            <span class="mcp-pager">
                <button type="button" id="mcp-connections-prev"><?= $l->t('Previous') ?></button>
                <span id="mcp-connections-page-info"></span>
                <button type="button" id="mcp-connections-next"><?= $l->t('Next') ?></button>
            </span>
            <span class="mcp-status" id="mcp-connections-status" aria-live="polite"></span>
        </div>
        <table class="mcp-connections-table" id="mcp-connections-table" aria-busy="true">
            <thead>
                <tr>
                    <th scope="col"><?= $l->t('Client') ?></th>
                    <th scope="col"><?= $l->t('Signed in') ?></th>
                    <th scope="col"><?= $l->t('Expires') ?></th>
                    <th scope="col"><span class="hidden-visually"><?= $l->t('Actions') ?></span></th>
                </tr>
            </thead>
            <tbody><tr><td colspan="4" class="mcp-empty"><?= $l->t('Loading…') ?></td></tr></tbody>
        </table>
    </div>

    <div class="section mcp-block" id="mcp-personal-app-password">
        <h2><?= $l->t('App password') ?></h2>
        <p class="settings-hint mcp-hint"><?= $l->t('Create an individual app password in Nextcloud Settings → Security, then use it with your Nextcloud user ID in a client that supports HTTP Basic authentication and Streamable HTTP MCP 2025-06-18. This app never asks for your Nextcloud password.') ?></p>
    </div>
</div>
