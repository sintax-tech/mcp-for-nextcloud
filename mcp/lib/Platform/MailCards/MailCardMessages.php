<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\MailCards;

use OCA\Mcp\L10n\Translator;

/** The sentences a mail card step answers with when the original tool has none of its own. */
final class MailCardMessages {
	/** Static messages only; never instantiated. */
	private function __construct() {
	}

	/**
	 * @param string $tool the published name of the tool whose step was refused, e.g. mail_save_attachment
	 * @return string the refusal of a step the actor may not run through MCP
	 */
	public static function notAllowed(string $tool): string {
		return Translator::t('Using %s through MCP is not allowed for your account.', [$tool]);
	}

	/**
	 * @return string the outcome of a step whose effect may have been persisted
	 */
	public static function uncertain(): string {
		return Translator::t('This step could not be confirmed and may have been applied. Check it in Nextcloud before trying again.');
	}
}
