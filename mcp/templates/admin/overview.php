<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** Overview panel of the administrator settings.
 * @var array<string, mixed> $_ settings parameters
 * @var \OCP\IL10N $l app translator
 */
?>
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
        <?php include dirname(__DIR__) . '/admin-file-write-policy-overview.php'; ?>
        <div id="mcp-tags-invalid-overview" class="mcp-tags-warning" role="alert" hidden>
            <p><?php p($l->t('The saved exclusion tags setting cannot be read, so the MCP tools treat every file and folder as not found. Choose the exclusion tags and save them again.')); ?> <a href="#protection"><?php p($l->t('Open Data protection')); ?></a></p>
        </div>
    </div>
    </div>

