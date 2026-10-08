<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * OAuth consent page (guest layout). Shows the client name from its metadata document next to the client_id host
 * and the redirect host; the form posts with the Nextcloud CSRF token.
 *
 * @var \OCP\IL10N $l
 * @var array $_
 */
?>
<style>
    .mcp-consent { max-width: 460px; margin: 0 auto; padding: 24px; border-radius: var(--border-radius-large, 12px);
        background: var(--color-main-background); color: var(--color-main-text); text-align: left; }
    .mcp-consent h2 { margin: 0 0 12px; font-size: 20px; }
    .mcp-consent p { margin: 8px 0; }
    .mcp-consent .mcp-hosts { color: var(--color-text-maxcontrast); font-size: 14px; }
    .mcp-consent .mcp-warning { color: var(--color-warning-text, var(--color-warning)); }
    .mcp-consent .mcp-error { color: var(--color-error-text, var(--color-error)); }
    .mcp-consent .mcp-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; }
    .mcp-consent button.primary { background: var(--color-primary-element); color: var(--color-primary-element-text); }
</style>
<div class="mcp-consent">
<?php if ($_['error'] !== null): ?>
    <h2><?php p($l->t('Connection not possible')); ?></h2>
    <p class="mcp-error"><?php p($l->t('The authorization request is invalid.')); ?></p>
    <p class="mcp-hosts"><?php p($_['error']); ?></p>
<?php elseif ($_['blocked']): ?>
    <h2><?php p($l->t('MCP is not available for your account')); ?></h2>
    <p><?php p($l->t('%s cannot be connected because an administrator has not enabled MCP for your account.', [$_['client']])); ?></p>
<?php else: ?>
    <h2><?php p($l->t('Allow %s to access MCP with your account?', [$_['client']])); ?></h2>
    <p><?php p($l->t('Signed in as %s.', [$_['account']])); ?></p>
    <p><?php p($l->t('The app will be able to use the MCP tools your administrator enabled for you. You can disconnect it at any time in your personal settings.')); ?></p>
    <p class="mcp-hosts">
        <?php p($l->t('Client: %s', [$_['nativeClient'] ? $l->t('Local program (native client)') : $_['clientHost']])); ?><br>
        <?php p($l->t('Returns to: %s', [$_['redirectHost']])); ?>
    </p>
    <?php if ($_['loopback']): ?>
        <p class="mcp-warning"><?php p($l->t('This client returns to a program on your own computer. Only continue if you started this connection yourself.')); ?></p>
    <?php endif; ?>
    <form method="post" action="<?php p($_['action']); ?>">
        <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
        <input type="hidden" name="pending" value="<?php p($_['pending']); ?>">
        <div class="mcp-actions">
            <button type="submit" name="decision" value="deny"><?php p($l->t('Cancel')); ?></button>
            <button type="submit" name="decision" value="allow" class="primary"><?php p($l->t('Allow')); ?></button>
        </div>
    </form>
<?php endif; ?>
</div>
