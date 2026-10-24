<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

use OCA\Mcp\Service\BackupLocation;

/** Shares the configured destination without applying the optional Files-copy switch to Contacts. */
final class ContactBackupDirectory {
	/**
	 * @param BackupLocation|null $location current configured location
	 * @param string $bookSegment already sanitized book name
	 * @return string actor-relative mandatory contact backup directory
	 */
	public static function path(?BackupLocation $location, string $bookSegment): string {
		return '/' . ($location?->current()['folder'] ?? BackupLocation::LEGACY) . '/Contacts/' . $bookSegment;
	}
}
