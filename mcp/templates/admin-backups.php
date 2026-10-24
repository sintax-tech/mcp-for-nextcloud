<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/** @var \OCP\IL10N $l */
?>
<div class="section mcp-block" id="mcp-backup-settings">
	<h2><?php p($l->t('MCP backup copies')); ?></h2>
	<form id="mcp-backup-form" aria-busy="true">
		<p><label for="mcp-backup-folder"><?php p($l->t('Backup folder name')); ?></label></p>
		<input type="text" id="mcp-backup-folder" required maxlength="255" aria-describedby="mcp-backup-help" disabled>
		<p id="mcp-backup-help" class="settings-hint"><?php p($l->t('One folder name directly in each acting user’s personal root, for example MCP backups or .mcp-backups. The folder is created with the first copy. Changing this name does not move or delete anything; all previous names remain protected against MCP writes.')); ?></p>
		<p><input type="checkbox" id="mcp-backup-copies" disabled> <label for="mcp-backup-copies"><?php p($l->t('Create MCP copies before overwriting Files (default: on)')); ?></label></p>
		<p class="settings-hint"><?php p($l->t('Copies can be suspended only while the Versions app is enabled. Availability is checked again for each acting user. Existing operations still require the Versions app; this setting does not enable writes otherwise refused. Native versioning follows its own policy and may not keep every file or backend.')); ?></p>
		<p class="settings-hint"><?php p($l->t('Contact backups before deletion are always required and use the configured folder. Existing backups remain preserved and protected even when Files copies are suspended.')); ?></p>
		<p id="mcp-backup-effective" aria-live="polite"></p>
		<p><button type="submit" id="mcp-backup-save" disabled><?php p($l->t('Save backup settings')); ?></button> <button type="button" id="mcp-backup-reload"><?php p($l->t('Reload backup settings')); ?></button></p>
		<p id="mcp-backup-status" class="mcp-status" role="status" aria-live="polite"></p>
	</form>
</div>
