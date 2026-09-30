<?php
/**
 * Personal settings section: endpoint, service and eligibility state, own connection
 * switch and app-password guidance. Every visible string goes through the IL10N that
 * \OC\Template\Base injects as $l, so the page follows the user language.
 *
 * @var array $_ template parameters provided by \OCA\Mcp\Settings\PersonalSettings
 * @var \OCP\IL10N $l translations for app "mcp", injected by the template engine
 */
$escape = static fn ($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$service = $_['global'] ? $l->t('enabled') : $l->t('disabled');
$adminEligibility = $_['eligible'] ? $l->t('allowed') : $l->t('not allowed');
$connection = $_['connected'] ? $l->t('active') : $l->t('disconnected');
?>
<div class="section" id="mcp-personal">
    <h2><?= $l->t('MCP connection') ?></h2>
    <p><?= $l->t('Endpoint: %s', ['<code>' . $escape($_['endpoint']) . '</code>']) ?></p>
    <p><?= $l->t('Service: %s. Administrator eligibility: %s.', ['<strong>' . $service . '</strong>', '<strong>' . $adminEligibility . '</strong>']) ?></p>
    <p><?= $l->t('Your connection: %s.', ['<strong>' . $connection . '</strong>']) ?></p>
    <form method="post" action="<?= $escape($_['action']) ?>">
        <input type="hidden" name="requesttoken" value="<?= $escape($_['token']) ?>">
        <input type="hidden" name="enabled" value="<?= $_['connected'] ? '0' : '1' ?>">
        <button type="submit" <?= !$_['connected'] && (!$_['eligible'] || !$_['global']) ? 'disabled' : '' ?>><?= $_['connected'] ? $l->t('Disconnect') : $l->t('Connect') ?></button>
    </form>
    <p><?= $l->t('Create an individual app password in Nextcloud Settings → Security, then use it with your Nextcloud user ID in a client that supports HTTP Basic authentication and Streamable HTTP MCP 2025-06-18. This app never asks for your Nextcloud password.') ?></p>
    <p><?= $l->t('Disconnecting blocks access to this app on the next request. It does not revoke the app password; revoke that separately in Nextcloud Settings → Security.') ?></p>
</div>
