<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Reading;

use InvalidArgumentException;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCA\Mcp\Modules\Talk\Failures\ConversationAccessException;
use OCA\Mcp\Modules\Talk\Failures\TalkUnavailableException;
use OCA\Mcp\Modules\Talk\Integration\TalkServices;
use OCA\Mcp\Modules\Talk\Integration\UnreadCounter;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\Presentation\Messages;
use OCA\Mcp\Modules\Talk\Presentation\PageMessages;
use OCA\Mcp\Modules\Talk\TalkModule;

/**
 * Read-only pages of the messages a conversation has after the reader's read marker, oldest first.
 *
 * The first page snapshots the marker and returns it as the starting point. Later pages continue from the last
 * message already returned, carried by a signed cursor, and never read the marker again: another client that marks
 * the conversation as read meanwhile cannot make this read skip what it had not returned yet.
 *
 * Messages come from Talk's own forward read, `ChatManager::waitForNewMessages()`, called with no wait and with the
 * mention notifications left alone, the way Talk's chat summary reads unread messages. Nothing is marked as read,
 * no session or status is touched and the lobby is never opened. Access, lobby included, is checked again on every
 * page. Messages that arrive while the reader pages show up in later pages; a message deleted or expired by
 * retention simply leaves no entry.
 */
final class UnreadMessages {
	/** Largest page, the same bound talk_read_messages has. */
	public const MAX_MESSAGES = ConversationReader::MAX_MESSAGES;

	/** Page size when the caller omits limit. */
	public const DEFAULT_MESSAGES = ConversationReader::DEFAULT_MESSAGES;

	/** Longest opaque cursor the schema and the decoder accept. */
	public const MAX_CURSOR_LENGTH = CursorCodec::MAX_LENGTH;

	/**
	 * @param TalkServices $talkServices lazy native Talk services
	 * @param ConversationResolver $resolver existing read access, participation and lobby checks
	 * @param ConversationReader $reader existing message projection and attachment filter
	 * @param UnreadCounter $counter read marker as Talk reports it, read without saving
	 * @param MessageCursor $cursor continuation bound to this tool, this reader and this conversation
	 */
	public function __construct(
		private TalkServices $talkServices,
		private ConversationResolver $resolver,
		private ConversationReader $reader,
		private UnreadCounter $counter,
		private MessageCursor $cursor,
	) {
	}

	/**
	 * One page of the messages after the read marker the read started from.
	 *
	 * @param string $userId authenticated reader
	 * @param string $token conversation token
	 * @param int $limit page size, between 1 and self::MAX_MESSAGES
	 * @param string|null $cursor signed nextCursor of the previous page of this read, or null to start at the marker
	 * @return array{conversation: array{token: string, displayName: string}, lastReadMessage: int, messages: list<array<string, mixed>>, nextCursor: string|null}
	 * @throws InvalidArgumentException when the limit, the token or the cursor is not usable
	 * @throws PolicyUnavailable when the Files scope of the reader is unreadable
	 * @throws ConversationAccessException when the conversation cannot be read here or has no readable marker
	 * @throws TalkUnavailableException when spreed is unavailable
	 */
	public function read(string $userId, string $token, int $limit, ?string $cursor): array {
		if ($limit < 1 || $limit > self::MAX_MESSAGES) {
			throw new InvalidArgumentException(Messages::invalidLimit());
		}
		$position = $this->cursor->position($cursor, TalkModule::TOOL_READ_UNREAD, $userId, $token);
		$this->reader->assertReferencesAvailable($userId);

		$conversation = $this->resolver->resolveForReading($userId, $token);
		$room = $conversation->room;
		if ($room->isFederatedConversation()) {
			// The messages live on the host server; the local chat holds none of them.
			throw new ConversationAccessException(PageMessages::federatedUnread());
		}
		$chatManager = $this->talkServices->chatManager($userId);

		if ($position === null) {
			$marker = $this->counter->marker(
				$room,
				$conversation->participant->getAttendee(),
				$chatManager,
				$userId,
				$this->talkServices->unreadMarkers($userId),
			);
			if ($marker === null) {
				throw new ConversationAccessException(PageMessages::unreadableMarker());
			}
			// 0 and Talk's "from the first message" marker both mean no read is known: start at the beginning.
			$baseline = max(0, $marker);
			$offset = $baseline;
		} else {
			$baseline = $position['baseline'];
			$offset = $position['message'];
		}

		// One extra message tells whether a next page exists. No wait, no IUser and markNotificationsAsRead false:
		// Talk then only selects the comments after the offset.
		$comments = $chatManager->waitForNewMessages($room, $offset, $limit + 1, 0, null, false, false, 0);
		$more = count($comments) > $limit;
		$messages = $this->reader->project($userId, $conversation->token(), array_slice($comments, 0, $limit));
		$last = $messages === [] ? null : (int)$messages[count($messages) - 1]['id'];

		return [
			'conversation' => ['token' => $conversation->token(), 'displayName' => $conversation->displayName()],
			'lastReadMessage' => $baseline,
			'messages' => $messages,
			'nextCursor' => $more && $last !== null
				? $this->cursor->next($conversation->token(), $last, $baseline, TalkModule::TOOL_READ_UNREAD, $userId)
				: null,
		];
	}
}
