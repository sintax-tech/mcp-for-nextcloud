<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Tools\Files\Deletion\Sharing;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Tools\Results\ToolFailure;
use OCA\Mcp\Mcp\Tools\Results\ToolResult;

/** A separate explicit shared-impact confirmation, independent of UPDATE and with no recipient disclosure. */
final class DeleteSharedConfirmation {
	/**
	 * Describes shared impact and the limits of native recovery.
	 *
	 * @return string Honest shared impact warning for preview and a missing confirmation.
	 */
	public static function message(): string {
		return Translator::t('This deletion affects shared content. Ask for explicit confirmation and retry with confirm_shared: true. Other people may lose access. Native recovery is not guaranteed for either person.');
	}

	/**
	 * Requests the missing shared confirmation without attempting deletion.
	 *
	 * @param bool $shared Current admitted shared impact.
	 * @param bool $confirmed Explicit shared confirmation.
	 * @return array<string,mixed>|null Non-error confirmation request, without calling the native sink.
	 */
	public static function request(bool $shared, bool $confirmed): ?array {
		return $shared && !$confirmed ? ToolResult::json(['requiresConfirmation' => true, 'requiresSharedConfirmation' => true,
			'shared' => true, 'message' => self::message() . ' ' . \OCA\Mcp\Tools\Files\Deletion\DeleteMessages::risk()]) : null;
	}

	/**
	 * Refuses newly shared content when explicit shared confirmation is missing.
	 *
	 * @param bool $shared Fresh shared impact at the final frontier.
	 * @param bool $confirmed Explicit shared confirmation.
	 * @return void Refuse newly shared content without an attempt or automatic retry.
	 */
	public static function assertConfirmed(bool $shared, bool $confirmed): void {
		if ($shared && !$confirmed) { throw new ToolFailure(self::message()); }
	}
}
