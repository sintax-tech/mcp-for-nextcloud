<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Routines\MailTasks;

/**
 * What each step of routines_create_mail_card receives, from the arguments the user confirmed. The plan and the
 * confirmed call read the same values here, so the plan shows exactly what the steps get. The save never carries
 * `replace` or `etag`, and the card never carries `labels`: one free name, one card, nothing else.
 */
final class MailCardRequest {
	/** Longest body excerpt the card may copy. */
	public const EXCERPT_MAX = 2000;
	/** Arguments of the routine that deck_create_card takes under the same name. */
	private const CARD_FIELDS = ['stackId', 'title', 'duedate', 'assignees', 'confirm_shared'];

	/** Static mapping only. */
	private function __construct() {
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @return int the message
	 */
	public static function messageId(array $arguments): int {
		return (int)$arguments['message_id'];
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @return string the attachment
	 */
	public static function attachmentId(array $arguments): string {
		return (string)$arguments['attachment_id'];
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @return string the path of the new file in the actor's Files
	 */
	public static function path(array $arguments): string {
		return (string)$arguments['path'];
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @return int characters of the body the card copies; 0 copies none
	 */
	public static function excerptChars(array $arguments): int {
		return (int)($arguments['excerpt_chars'] ?? 0);
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @return bool whether the user confirmed a folder or a board other people can reach
	 */
	public static function confirmShared(array $arguments): bool {
		return ($arguments['confirm_shared'] ?? false) === true;
	}

	/**
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @return string the user's own text at the top of the card, '' for none
	 */
	public static function note(array $arguments): string {
		return (string)($arguments['note'] ?? '');
	}

	/**
	 * The arguments of deck_create_card, built field by field: `confirm`, `plan_state` and `labels` never reach the
	 * card step, because the single confirmation is this tool's and the card creates nothing on the board but itself.
	 *
	 * @param array<string, mixed> $arguments validated arguments of routines_create_mail_card
	 * @param string $description the exact text of the card
	 * @return array<string, mixed> arguments of deck_create_card
	 */
	public static function card(array $arguments, string $description): array {
		return array_intersect_key($arguments, array_flip(self::CARD_FIELDS)) + ['description' => $description];
	}
}
