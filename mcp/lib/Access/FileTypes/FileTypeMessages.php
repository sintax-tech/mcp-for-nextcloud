<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Access\FileTypes;

use OCA\Mcp\L10n\Translator;

/** Localized refusals for the MCP file-name policy, without names or configuration. */
final class FileTypeMessages {
	/** Static messages only. */
	private function __construct() {
	}

	/** @return string Public type refusal, with no list of allowed extensions. */
	public static function denied(): string {
		return Translator::t('This file type cannot be written through MCP. Ask an administrator to review the file type policy.');
	}

	/** @return string Public configuration failure without backend details. */
	public static function unavailable(): string {
		return Translator::t('The MCP file type policy is unavailable. Ask an administrator to review its settings.');
	}

	/** @return string Admin validation error without reflecting the submitted value. */
	public static function invalid(): string {
		return Translator::t('Invalid file type policy settings.');
	}
}
