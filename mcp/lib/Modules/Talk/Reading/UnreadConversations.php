<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Reading;

use DateTimeInterface;
use InvalidArgumentException;
use OCA\Mcp\Modules\Talk\Failures\TalkUnavailableException;
use OCA\Mcp\Modules\Talk\Integration\TalkServices;
use OCA\Mcp\Modules\Talk\Integration\UnreadRoomView;
use OCA\Mcp\Modules\Talk\Presentation\Messages;
use OCA\Mcp\Modules\Talk\TalkModule;
use Throwable;

/**
 * Read-only page of the conversations that still have unread messages.
 *
 * The scan reads the rooms Talk already has. It does not mark anything as read, does not open a lobby and does not
 * rename a conversation. A lobby that bars the caller drops the room before any of its fields are copied. The page
 * is not a frozen mailbox: a conversation that receives a message can change position, and the cursor only
 * continues after the last row of this page for this same reader.
 */
final class UnreadConversations {
	/** Largest page the tool returns, the bound shared with the published schema. */
	public const MAX_PAGE = 50;

	/** Page size when the caller omits limit. */
	public const DEFAULT_PAGE = 50;

	/** Longest opaque cursor the schema and the decoder accept. */
	public const MAX_CURSOR_LENGTH = UnreadCursor::MAX_LENGTH;

	/**
	 * @param TalkServices $talkServices lazy native Talk services
	 * @param UnreadRoomView $rooms lobby and name, both read from values Talk already stored
	 * @param UnreadCursor $cursor continuation bound to this tool and this reader
	 */
	public function __construct(
		private TalkServices $talkServices,
		private UnreadRoomView $rooms,
		private UnreadCursor $cursor,
	) {
	}

	/**
	 * Conversations with at least one unread message, most recently active first.
	 *
	 * @param string $userId authenticated reader
	 * @param int $limit page size, between 1 and self::MAX_PAGE
	 * @param string|null $cursor signed nextCursor from the previous page of this same reader, or null to start
	 * @return array{conversations: list<array{token: string, displayName: string, type: int, participantType: int, unreadMessages: int, lastReadMessage: int, archived: bool, lastActivity: int|null}>, nextCursor: string|null}
	 * @throws InvalidArgumentException when the limit or the cursor is not usable
	 * @throws TalkUnavailableException when spreed is unavailable
	 */
	public function list(string $userId, int $limit, ?string $cursor): array {
		if ($limit < 1 || $limit > self::MAX_PAGE) {
			throw new InvalidArgumentException(Messages::invalidPageLimit());
		}
		$position = $this->cursor->position($cursor, TalkModule::TOOL_LIST_UNREAD, $userId);

		$manager = $this->talkServices->manager($userId);
		$participantService = $this->talkServices->participantService($userId);
		$constants = $this->talkServices->conversationConstants($userId);
		$lobbyNone = $constants['lobbyNone'] ?? null;
		$ignore = $constants['lobbyIgnorePermission'] ?? null;
		if (!is_int($lobbyNone) || !is_int($ignore)) {
			throw new TalkUnavailableException();
		}
		$rows = [];
		foreach ($manager->getRoomsForUser($userId) as $room) {
			try {
				$participant = $participantService->getParticipant($room, $userId, false);
			} catch (TalkUnavailableException $e) {
				throw $e;
			} catch (Throwable) {
				// A room without a readable participant is one this user cannot describe.
				continue;
			}
			$attendee = $participant->getAttendee();
			$unread = $attendee->getUnreadMessages();
			if (!is_int($unread) || $unread < 1) {
				continue;
			}
			// The lobby decision comes before any field is copied: a barred room contributes nothing.
			if ($this->rooms->barred($room, $participant, $lobbyNone, $ignore)) {
				continue;
			}
			$lastRead = $attendee->getLastReadMessage();
			if (!is_int($lastRead)) {
				continue;
			}
			$lastActivity = $room->getLastActivity();
			$rows[] = [
				'token' => (string)$room->getToken(),
				'displayName' => $this->rooms->displayName($room, $userId),
				'type' => (int)$room->getType(),
				'participantType' => (int)$attendee->getParticipantType(),
				'unreadMessages' => $unread,
				'lastReadMessage' => $lastRead,
				'archived' => (bool)$attendee->isArchived(),
				'lastActivity' => $lastActivity instanceof DateTimeInterface ? $lastActivity->getTimestamp() : null,
			];
		}

		usort($rows, static function (array $a, array $b): int {
			$byActivity = ($b['lastActivity'] ?? 0) <=> ($a['lastActivity'] ?? 0);
			return $byActivity !== 0 ? $byActivity : strcmp($a['token'], $b['token']);
		});

		$visible = [];
		foreach ($rows as $row) {
			if ($position !== null && !$this->isAfter($row, $position['activity'], $position['token'])) {
				continue;
			}
			$visible[] = $row;
		}
		$page = array_slice($visible, 0, $limit);
		$last = $page === [] ? null : $page[count($page) - 1];

		return [
			'conversations' => $page,
			'nextCursor' => count($visible) > $limit && $last !== null
				? $this->cursor->next($last['lastActivity'] ?? 0, $last['token'], TalkModule::TOOL_LIST_UNREAD, $userId)
				: null,
		];
	}

	/**
	 * Whether this row sorts strictly after the cursor in the page order.
	 *
	 * @param array{token: string, lastActivity: int|null} $row one visible conversation
	 * @param int $activity activity the cursor continues after
	 * @param string $token token the cursor continues after
	 */
	private function isAfter(array $row, int $activity, string $token): bool {
		$rowActivity = $row['lastActivity'] ?? 0;
		if ($rowActivity !== $activity) {
			return $rowActivity < $activity;
		}
		return strcmp($row['token'], $token) > 0;
	}
}
