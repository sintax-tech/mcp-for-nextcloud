<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
/** @var \OCP\IL10N $l */
?>
<div class="section mcp-block" id="mcp-file-write-policy">
	<h2><?php p($l->t('File types writable through MCP')); ?></h2>
	<form id="mcp-file-policy-form" aria-busy="true" novalidate>
		<p><label for="mcp-file-policy-mode"><?php p($l->t('File type policy')); ?></label></p>
		<select id="mcp-file-policy-mode" aria-describedby="mcp-file-policy-mode-error" disabled>
			<option value=""><?php p($l->t('Choose a policy')); ?></option>
			<option value="off"><?php p($l->t('No restriction (default)')); ?></option>
			<option value="allowlist"><?php p($l->t('Only listed extensions')); ?></option>
		</select>
		<p id="mcp-file-policy-mode-error" role="alert" hidden></p>
		<p><label for="mcp-file-policy-extensions"><?php p($l->t('Allowed extensions')); ?></label></p>
		<p id="mcp-file-policy-format" class="settings-hint"><?php p($l->t('Separate extensions with commas or new lines. An initial dot and uppercase letters are accepted. Up to 128 distinct extensions; each has 1–32 ASCII letters, digits, underscores or hyphens and starts with a letter or digit.')); ?></p>
		<textarea id="mcp-file-policy-extensions" rows="5" aria-describedby="mcp-file-policy-format mcp-file-policy-extensions-error" disabled></textarea>
		<p id="mcp-file-policy-extensions-error" role="alert" hidden></p>
		<p><input type="checkbox" id="mcp-file-policy-extensionless" aria-describedby="mcp-file-policy-allowExtensionless-error" disabled> <label for="mcp-file-policy-extensionless"><?php p($l->t('Allow files without an extension')); ?></label></p>
		<p id="mcp-file-policy-allowExtensionless-error" role="alert" hidden></p>
		<p id="mcp-file-policy-empty" class="settings-hint" hidden><?php p($l->t('With an empty list, only files without an extension can be allowed. If that option is off, all applicable writes are refused.')); ?></p>
		<div id="mcp-file-policy-invalid" class="mcp-tags-warning" role="alert" hidden><?php p($l->t('The file type policy could not be loaded. Applicable MCP writes remain refused if the saved configuration is invalid. Choose a complete policy and save it to recover; no restriction is a deliberate choice.')); ?></div>
		<p><button type="submit" id="mcp-file-policy-save" disabled><?php p($l->t('Save file type policy')); ?></button></p>
		<p id="mcp-file-policy-status" class="mcp-status" aria-live="polite"><?php p($l->t('Loading file type policy…')); ?></p>
	</form>
	<p class="settings-hint"><?php p($l->t('This policy applies only to writes through this MCP connector. It checks the final file name and its last extension, not the file content. Other clients and WebDAV are outside its scope. With the policy active, a trailing dot or whitespace, a backslash, a colon in the name or a non-ASCII extension is refused. Names such as .env and .gitignore count as having no extension: listing env does not allow .env; use the option for files without an extension.')); ?></p>
	<p class="settings-hint"><?php p($l->t('Notes needs md/txt in the list. Mandatory internal backups are exempt; Notes, existing files, administrators, version restore and undo follow the current policy.')); ?></p>
	<p class="settings-hint"><?php p($l->t('With the policy active, moving or copying a folder checks every file even when names stay the same, up to 2,000 nodes. This costs more work for large folders and can refuse a move containing a blocked jpg or pdf.')); ?></p>
	<p class="settings-hint"><?php p($l->t('With a file type policy active, copying a folder checks every file inside it first and refuses the whole copy if any file is not allowed. The copy is then made file by file from that checked list: files another client adds or renames while the copy runs are not copied, and a failure partway leaves a partial copy that is reported and never removed. Moving a folder also checks every file inside it first, but the move itself is a single Nextcloud operation: a file another client adds to the folder in the short time between that check and the move is moved with it.')); ?></p>
</div>
