<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** Advanced panel of the administrator settings.
 * @var array<string, mixed> $_ settings parameters
 * @var \OCP\IL10N $l app translator
 */
?>
    <div role="tabpanel" id="mcp-panel-advanced" aria-labelledby="mcp-tab-advanced" class="mcp-tab-panel" tabindex="0">
    <div class="section mcp-block" id="mcp-block-logs">
        <h2><?php p($l->t('Server log')); ?></h2>
        <?php if (!$_['logsAvailable']) { ?>
            <p class="mcp-unsupported-app" id="mcp-logs-unavailable"><?php p($l->t('This server writes its log to %s, not to a file, so the server log tools stay hidden. They need the file log, the default of Nextcloud.', [$_['logType']])); ?></p>
        <?php } ?>
        <p class="settings-hint mcp-hint"><?php p($l->t('The server log shows file names, addresses and accounts of every user. Choose below which groups, besides administrators, may read it through MCP.')); ?></p>
        <div id="mcp-logs-groups" class="mcp-logs-groups" aria-busy="true">
            <span class="mcp-tags-loading"><?php p($l->t('Loading groups…')); ?></span>
        </div>
        <p class="mcp-picker-summary mcp-hint" id="mcp-logs-summary"></p>
        <span class="mcp-status" id="mcp-logs-status" aria-live="polite"></span>
    </div>

    <div class="section mcp-block" id="mcp-block-logs-privacy">
        <h2><?php p($l->t('Log privacy')); ?></h2>
        <p>
            <input type="checkbox" class="checkbox" id="mcp-logs-hide-file-names">
            <label for="mcp-logs-hide-file-names"><?php p($l->t('Hide the text of log messages')); ?></label>
            <span class="mcp-status" id="mcp-logs-hide-file-names-status" aria-live="polite"></span>
        </p>
        <p>
            <button type="button" class="mcp-learn-more" id="mcp-logs-privacy-more" aria-expanded="false" aria-controls="mcp-logs-privacy-detail" hidden><?php p($l->t('Learn more')); ?></button>
        </p>
        <div id="mcp-logs-privacy-detail" class="mcp-learn-more-box">
            <p class="settings-hint mcp-hint"><?php p($l->t('On (default): while exclusion tags are configured, the text of log messages and the identification of the client (user agent) are hidden; the date, level, app, user, error type and route stay. Off: the full messages and clients are shown for diagnosis, and the exclusion by tags is not respected. The option exists because some IT teams need the names to track problems.')); ?></p>
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
    </div>
