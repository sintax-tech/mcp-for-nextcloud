<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Presentation;

use OCA\Mcp\L10n\Translator;
use OCA\Mcp\Mcp\Confirmation\PlanText;

/**
 * Localized Talk refusals and argument validation messages.
 *
 * Each method translates its unchanged English source text for the authenticated user through Translator.
 */
final class Messages {
	/* Tool descriptions, exposed to MCP clients in tools/list. */

	/**
	 * Prevents construction of this static presentation utility.
	 */
	private function __construct() {
	}

	/**
	 * The conversation does not exist for this account, or it is not readable.
	 * @return string The message in the language of the current user.
	 */
	public static function conversationNotFound(): string {
		return Translator::t('conversation not found or not accessible');
	}

	/**
	 * The conversation exists but this account may not write in it.
	 * @return string The message in the language of the current user.
	 */
	public static function conversationNotWritable(): string {
		return Translator::t('no permission to write in this conversation');
	}

	/**
	 * The file is missing, outside the user folder or not readable.
	 * @return string The message in the language of the current user.
	 */
	public static function fileNotFound(): string {
		return Translator::t('file not found or not accessible');
	}

	/**
	 * The attachment id is not a share of this conversation.
	 * @return string The message in the language of the current user.
	 */
	public static function attachmentNotFound(): string {
		return Translator::t('attachment not found in this conversation');
	}

	/**
	 * The account does not exist or may not be messaged by this account.
	 * @return string The message in the language of the current user.
	 */
	public static function userNotReachable(): string {
		return Translator::t('user not found or no permission to message them');
	}

	/**
	 * Refusal of one guest of a group.
	 *
	 * @param string $accountId Account id the caller sent
	 * @return string The message in the language of the current user
	 */
	public static function participantNotReachable(string $accountId): string {
		return Translator::t('participant \'%s\' not found or no permission to message them', [$accountId]);
	}

	/**
	 * Reported per guest in invitations_failed, after the group already exists.
	 * @return string The message in the language of the current user.
	 */
	public static function invitationFailed(): string {
		return Translator::t('Talk did not accept the invitation of this participant');
	}

	/**
	 * The referenced Deck card or calendar event does not exist or is not readable.
	 * @return string The message in the language of the current user.
	 */
	public static function referenceNotFound(): string {
		return Translator::t('referenced item not found or not accessible');
	}

	/**
	 * The Deck app is off for this account, or the MCP has no read grant in Deck.
	 * @return string The message in the language of the current user.
	 */
	public static function referenceDeckOff(): string {
		return Translator::t('Deck reference unavailable: the Deck app is turned off for you or the MCP has no read permission in Deck');
	}

	/**
	 * The Calendar app is off for this account, or the MCP has no read grant in Calendar.
	 * @return string The message in the language of the current user.
	 */
	public static function referenceCalendarOff(): string {
		return Translator::t('Calendar reference unavailable: the Calendar app is turned off for you or the MCP has no read permission in Calendar');
	}

	/**
	 * Talk did not create the room.
	 * @return string The message in the language of the current user.
	 */
	public static function conversationNotCreated(): string {
		return Translator::t('conversation not created by Talk');
	}

	/**
	 * Talk refused the message.
	 * @return string The message in the language of the current user.
	 */
	public static function messageNotSent(): string {
		return Translator::t('the message could not be sent');
	}

	/**
	 * The share could not be created.
	 * @return string The message in the language of the current user.
	 */
	public static function fileNotShared(): string {
		return Translator::t('the file could not be shared');
	}

	/**
	 * The file already carries a card in this conversation, so nothing new is published.
	 * @return string The message in the language of the current user.
	 */
	public static function fileAlreadyShared(): string {
		return Translator::t('the file is already shared in this conversation');
	}

	/**
	 * The share was created but the caption failed, so the caption has to be sent as its own message.
	 * @return string The message in the language of the current user.
	 */
	public static function captionNotSent(): string {
		return Translator::t('The attachment was shared in this conversation, but the caption was not sent. Do not repeat talk_attach_file: the file is already shared and a new card would not be created. If the user still wants the caption, send it as a new message with talk_reply, following the same approval.');
	}

	/**
	 * spreed is not installed or not enabled for this account.
	 * @return string The message in the language of the current user.
	 */
	public static function talkUnavailable(): string {
		return Translator::t('the conversations app is not available');
	}

	/**
	 * Fallback for anything unexpected.
	 * @return string The message in the language of the current user.
	 */
	public static function unexpected(): string {
		return Translator::t('unexpected error while accessing Talk');
	}

	/**
	 * The conversation token is missing or is not a string.
	 * @return string The message in the language of the current user.
	 */
	public static function invalidToken(): string {
		return Translator::t('invalid conversation_token');
	}

	/**
	 * The message body is missing or blank.
	 * @return string The message in the language of the current user.
	 */
	public static function emptyMessage(): string {
		return Translator::t('message cannot be empty');
	}

	/**
	 * The batch carries no message at all.
	 * @return string The message in the language of the current user.
	 */
	public static function emptyBatch(): string {
		return Translator::t('send at least one message in messages');
	}

	/**
	 * The batch is longer than the module accepts.
	 *
	 * @param int $max Largest batch accepted
	 * @return string The message in the language of the current user
	 */
	public static function tooManyMessages(int $max): string {
		return Translator::t('batch with more than %d messages', [$max]);
	}

	/**
	 * The message body is over the module character limit.
	 * @return string The message in the language of the current user.
	 */
	public static function messageTooLong(): string {
		return Translator::t('message exceeds the character limit');
	}

	/**
	 * The path is missing, absolute or traverses outside the user folder.
	 * @return string The message in the language of the current user.
	 */
	public static function invalidPath(): string {
		return Translator::t('invalid path');
	}

	/**
	 * The account id is missing or is not a string.
	 * @return string The message in the language of the current user.
	 */
	public static function invalidUser(): string {
		return Translator::t('invalid user');
	}

	/**
	 * The reference object does not follow {type: deck_card|calendar_event, ...}.
	 * @return string The message in the language of the current user.
	 */
	public static function invalidReference(): string {
		return Translator::t('invalid reference: use {type: deck_card, card_id, board_id?} or {type: calendar_event, calendar, uid}');
	}

	/**
	 * The group name is blank once normalized.
	 * @return string The message in the language of the current user.
	 */
	public static function invalidGroupName(): string {
		return Translator::t('the group name cannot be empty');
	}

	/**
	 * The group name is over the module character limit.
	 * @return string The message in the language of the current user.
	 */
	public static function groupNameTooLong(): string {
		return Translator::t('the group name exceeds the character limit');
	}

	/**
	 * The group would invite more people than the module accepts.
	 *
	 * @param int $max Largest number of guests accepted
	 * @return string The message in the language of the current user
	 */
	public static function tooManyParticipants(int $max): string {
		return Translator::t('group with more than %d participants', [$max]);
	}

	/**
	 * A reply or attachment id is not a positive integer.
	 * @return string The message in the language of the current user.
	 */
	public static function invalidIdentifier(): string {
		return Translator::t('invalid identifier');
	}

	/**
	 * The limit argument is outside the accepted range.
	 * @return string The message in the language of the current user.
	 */
	public static function invalidLimit(): string {
		return Translator::t('limit must be a number between 1 and 200');
	}

	/** Page size for talk_list_unread, between 1 and 50. */
	public static function invalidPageLimit(): string {
		return Translator::t('limit must be a number between 1 and 50');
	}
	/** Opaque cursor for talk_list_unread that this tool did not issue. */
	public static function invalidCursor(): string {
		return Translator::t('invalid cursor');
	}

	/**
	 * The quoted message does not exist in this conversation.
	 * @return string The message in the language of the current user.
	 */
	public static function replyTargetNotFound(): string {
		return Translator::t('quoted message not found in this conversation');
	}

	/**
	 * Raised for a tool name this module does not serve.
	 * @return string The message in the language of the current user.
	 */
	public static function unknownTool(): string {
		return Translator::t('unknown tool');
	}

	/* Texts of the human plan, the body a person reads before approving a write. */

}
