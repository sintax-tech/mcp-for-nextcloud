<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Files\Deletion;

use OCA\Mcp\L10n\Translator;

/** Honest descriptions of the opt-in deletion policy and its bounded observations. */
final class DeleteMessages {
	/** @return string Warning shared by the plan, confirmed receipt and administrator documentation. */
	public static function risk(): string {
		return Translator::t('Deletion uses the native Nextcloud mechanism after the available preventive checks. Failures or changes can cause permanent deletion even while the trash bin is enabled. Grant this permission only if you accept this risk and maintain backups.');
	}

	/** @return string Supported initial scope, without implying support for other backends. */
	public static function supported(): string {
		return Translator::t('Deletion supports files and completely inspected folders in Local personal home storage, including shared originals and descendants of local received shares with native DELETE permission. Shared impact requires confirm_shared. Received share roots, other mounts, personal and selected scope roots, and current or historical backup folders are refused. Folders are refused while exclusion tags are configured. Folder limits are 2,000 nodes, 1 GiB and 64 levels.');
	}

	/** @return string A gate failed without exposing implementation details. */
	public static function unavailable(): string {
		return Translator::t('The preventive deletion checks could not establish eligibility. Nothing was deleted by this call.');
	}

	/** @return string The native operation left the same observed source. */
	public static function preserved(): string {
		return Translator::t('Deletion was refused or did not complete; the original file is still present with the same identity and ETag. No automatic retry was made.');
	}

	/** @return string Removal observed without claiming a recoverable trash destination. */
	public static function removed(): string {
		return Translator::t('The file is no longer at its original path. Its trash destination and recoverability have not been confirmed. No recovery copy was created by MCP.');
	}

	/** @return string Partial failure or observation failure cannot be reported as an untouched source. */
	public static function uncertain(): string {
		return Translator::t('The deletion outcome is uncertain. Do not retry automatically. Check the original location and the native trash bin before deciding what to do next.');
	}

	/** @return string Description published in the tool guide. */
	public static function tool(): string {
		return 'Delete one own file or completely admitted folder after preventive checks, with a separate opt-in files.delete permission. Only unencrypted Local home originals or descendants of local received shares are supported; DELETE, not UPDATE, is required. Shared content requires confirm_shared, including shared descendants in a folder. Received share roots are always refused. Folders are refused with configured exclusion tags or incomplete inspection (2,000 nodes, 1 GiB, 64 levels). Preview returns the exact source, inspected folder counts, ID, ETag and a permanent-deletion risk warning; echo id and etag when confirming. Native trash recovery is not guaranteed, and no mandatory recovery copy is made. Personal, mounted and selected scope roots, other backends and reserved backup names (including canonical owner origins behind aliases) are refused. Plans disclose shared impact without recipient names; native restoration destinations can differ by actor/owner.';
	}
}
