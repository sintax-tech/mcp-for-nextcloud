<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Contacts;

/** Fixed tool wording: Contacts backups remain mandatory regardless of the Files switch. */
final class ContactBackupText {
	/** @return string safe description fragment without a hard-coded destination */
	public static function destination(): string {
		return 'confirmation, a verified full .vcf copy is saved in the configured MCP backup folder under Contacts before ';
	}
}
