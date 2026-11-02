<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** Protection panel of the administrator settings.
 * @var array<string, mixed> $_ settings parameters
 * @var \OCP\IL10N $l app translator
 */
?>
    <div role="tabpanel" id="mcp-panel-protection" aria-labelledby="mcp-tab-protection" class="mcp-tab-panel" tabindex="0">
    <?php include dirname(__DIR__) . '/admin-backups.php'; ?>
    <?php include dirname(__DIR__) . '/admin-file-write-policy.php'; ?>
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

