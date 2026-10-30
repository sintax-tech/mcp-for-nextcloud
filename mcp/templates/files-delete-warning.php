<?php
/* SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later */
declare(strict_types=1);
/** @var \OCP\IL10N $l Translation service of the authenticated admin page. */
?>
<div class="section mcp-block" id="mcp-delete-warning">
	<h2><?php p($l->t('File deletion: optional permission')); ?></h2>
	<p><?php p($l->t('Deletion is denied by default. Existing Files permissions do not enable it. Selecting Files Full now includes Delete; grant it only deliberately.')); ?></p>
	<p><?php p($l->t('Deletion uses the native Nextcloud mechanism after the available preventive checks. Failures or changes can cause permanent deletion even while the trash bin is enabled. Grant this permission only if you accept this risk and maintain backups.')); ?></p>
	<p class="mcp-hint"><?php p($l->t('Deletion supports files and completely inspected folders in Local personal home storage, including shared originals and descendants of local received shares with native DELETE permission. Shared impact requires confirm_shared. Received share roots, other mounts, personal and selected scope roots, and current or historical backup folders are refused. Folders are refused while exclusion tags are configured. Folder limits are 2,000 nodes, 1 GiB and 64 levels.')); ?></p>
</div>
