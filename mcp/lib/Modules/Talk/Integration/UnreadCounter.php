<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Integration;

use OCP\IUser;
use OCP\IUserManager;
use Throwable;

/**
 * Unread count and read marker of one conversation for a local account, the same numbers Talk shows in its list.
 *
 * Talk stores the counter only for a federated conversation. For a local one it counts the chat comments after the
 * read marker, a read served from its distributed cache. A marker still in the legacy format is converted from the
 * comment read mark without saving the conversion, which Talk does when it formats the room itself.
 */
final class UnreadCounter {
	/**
	 * @param IUserManager $users resolves the reader for the legacy read mark, a read
	 */
	public function __construct(
		private IUserManager $users,
	) {
	}

	/**
	 * The unread count and the read marker Talk would report for this reader.
	 *
	 * @param object $room OCA\Talk\Room, unread
	 * @param object $attendee OCA\Talk\Model\Attendee of the reader
	 * @param object $chatManager OCA\Talk\Chat\ChatManager
	 * @param string $userId authenticated reader
	 * @param array{unreadMigration: int, unreadFirstMessage: int} $markers Talk's special read markers
	 * @return array{unread: int, lastRead: int}|null null when Talk would have to write or fail to answer
	 */
	public function count(object $room, object $attendee, object $chatManager, string $userId, array $markers): ?array {
		try {
			if ($room->isFederatedConversation()) {
				// Only here does Talk keep the counter on the attendee: the host server pushes it.
				$unread = $attendee->getUnreadMessages();
				$lastRead = $attendee->getLastReadMessage();
			} else {
				$lastRead = $this->localLastRead($room, $attendee, $chatManager, $userId, $markers['unreadMigration']);
				if ($lastRead === null) {
					return null;
				}
				$lastMessage = $room->getLastMessageId();
				$unread = is_int($lastMessage) && $lastMessage !== 0 && $lastRead === $lastMessage
					? 0
					: $chatManager->getUnreadCount($room, $lastRead);
			}
		} catch (Throwable) {
			return null;
		}
		if (!is_int($unread) || !is_int($lastRead)) {
			return null;
		}
		if ($lastRead === 0) {
			// A chat without readable history starts from the first message.
			$lastRead = $markers['unreadFirstMessage'];
		}
		if ($lastRead === $markers['unreadFirstMessage'] && $unread === 0) {
			// Marked unread from the first message: Talk shows one unread message.
			$unread = 1;
		}
		return ['unread' => $unread, 'lastRead' => $lastRead];
	}

	/**
	 * The read marker of a local conversation as Talk would report it, read without counting and without saving.
	 *
	 * @param object $room OCA\Talk\Room, unread
	 * @param object $attendee OCA\Talk\Model\Attendee of the reader
	 * @param object $chatManager OCA\Talk\Chat\ChatManager
	 * @param string $userId authenticated reader
	 * @param array{unreadMigration: int, unreadFirstMessage: int} $markers Talk's special read markers
	 * @return int|null the stored marker, a legacy one converted in memory, or null when it cannot be read
	 */
	public function marker(object $room, object $attendee, object $chatManager, string $userId, array $markers): ?int {
		try {
			return $this->localLastRead($room, $attendee, $chatManager, $userId, $markers['unreadMigration']);
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * The read marker of a local conversation, converting the legacy marker in memory only.
	 *
	 * @param object $room OCA\Talk\Room
	 * @param object $attendee OCA\Talk\Model\Attendee
	 * @param object $chatManager OCA\Talk\Chat\ChatManager
	 * @param string $userId authenticated reader
	 * @param int $migration Talk's marker of a read state not yet converted
	 * @return int|null null when the legacy read mark cannot be read
	 */
	private function localLastRead(object $room, object $attendee, object $chatManager, string $userId, int $migration): ?int {
		$lastRead = $attendee->getLastReadMessage();
		if ($lastRead !== $migration) {
			return is_int($lastRead) ? $lastRead : null;
		}
		$user = $this->users->get($userId);
		if (!$user instanceof IUser) {
			return null;
		}
		$legacy = $chatManager->getLastReadMessageFromLegacy($room, $user);
		return is_int($legacy) ? $legacy : null;
	}
}
