<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Presentation;

use OCA\Mcp\L10n\Translator;

/** Tool description, parameter description and user messages of files_add_comment. */
final class CommentingMessages {
	/** Static messages only. */
	private function __construct() {
	}

	/** @return string description of files_add_comment */
	public static function addCommentTool(): string {
		return 'Add a comment to a file or folder, as you, through Nextcloud\'s own comments service. Without confirm: true '
			. 'it only shows the plan. Mentions of existing accounts notify them; any other mention is refused. Adding a '
			. 'comment marks the file\'s comments as read for you. Comments are never edited or deleted.';
	}

	/** @return string description of the text parameter */
	public static function text(): string {
		return 'Exact text of the comment, up to 1000 characters; leading and trailing spaces are removed, as Nextcloud does.';
	}

	/** @return string refusal of a comment with no text */
	public static function emptyText(): string {
		return Translator::t('The comment has no text.');
	}

	/**
	 * @param int $max native character limit
	 * @return string refusal of a comment longer than the native limit
	 */
	public static function tooLong(int $max): string {
		return Translator::t('The comment is longer than %d characters.', [$max]);
	}

	/**
	 * @param string $mention the mention as written
	 * @return string refusal of a mention of anything but one account
	 */
	public static function unsupportedMention(string $mention): string {
		return Translator::t('The comment mentions %s, which is not a single account. Only mentions of existing accounts are allowed.', [$mention]);
	}

	/**
	 * @param string $mention the mention as written
	 * @return string refusal of a mention of an account that does not exist
	 */
	public static function unknownMention(string $mention): string {
		return Translator::t('The comment mentions %s, but there is no such account. Remove the mention or use the account ID.', [$mention]);
	}

	/** @return string refusal when the files.comments grant is gone */
	public static function notAllowed(): string {
		return Translator::t('Adding comments through MCP is not allowed for you.');
	}

	/** @return string warning of a plan that no longer holds */
	public static function planChanged(): string {
		return Translator::t('The file or the comment changed since the plan. Review the new plan and confirm again.');
	}

	/** @return string answer when the native write did not end as expected */
	public static function writeFailed(): string {
		return Translator::t('The comment may not have been saved. Check the comments of the file before trying again.');
	}
}
