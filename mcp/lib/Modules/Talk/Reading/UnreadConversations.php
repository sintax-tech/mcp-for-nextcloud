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
use OCA\Mcp\Modules\Talk\Locating\Conversation;
use OCA\Mcp\Modules\Talk\Locating\ConversationResolver;
use OCA\Mcp\Modules\Talk\Presentation\Messages;
use Throwable;

/**
 * Read-only page of the conversations that still have unread messages.
 *
 * The scan is a snapshot of the rooms Talk returns now. It does not mark anything as read and it does not call
 * the chat manager, so lastReadMessage, lastPing, the session and notifications stay as Talk left them. A lobby
 * that bars the caller drops the room before any of its counters are copied into the answer.
 */
final class UnreadConversations {
	/** Largest page the tool returns, the bound shared with the published schema. */
	public const MAX_PAGE = 50;

	/** Page size when the caller omits limit. */
	public const DEFAULT_PAGE = 50;

	/** Longest opaque cursor the schema and the decoder accept. */
	public const MAX_CURSOR_LENGTH = 200;

	/** Longest room token Talk publishes, the same bound as a conversation token. */
	private const MAX_TOKEN_LENGTH = 30;

	/**
	 * @param TalkServices $talkServices lazy native Talk services
	 * @param ConversationResolver $resolver existing lobby check
	 */
	public function __construct(
		private TalkServices $talkServices,
		private ConversationResolver $resolver,
	) {
	}

	/**
	 * Conversations with at least one unread message, most recently active first.
	 *
	 * @param string $userId authenticated reader
	 * @param int $limit page size, between 1 and self::MAX_PAGE
	 * @param string|null $cursor opaque nextCursor from the previous page, or null to start
	 * @return array{conversations: list<array{token: string, displayName: string, type: int, participantType: int, unreadMessages: int, lastReadMessage: int, archived: bool, lastActivity: int|null}>, nextCursor: string|null}
	 * @throws InvalidArgumentException when the limit or the cursor is not usable
	 * @throws TalkUnavailableException when spreed is unavailable
	 */
	public function list(string $userId, int $limit, ?string $cursor): array {
		if ($limit < 1 || $limit > self::MAX_PAGE) {
			throw new InvalidArgumentException(Messages::invalidPageLimit());
		}
		$position = $cursor === null ? null : $this->decodeCursor($cursor);

		$manager = $this->talkServices->manager($userId);
		$participantService = $this->talkServices->participantService($userId);
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
			if ($this->resolver->keepsOutOfLobby($userId, new Conversation($room, $participant))) {
				continue;
			}
			$lastRead = $attendee->getLastReadMessage();
			if (!is_int($lastRead)) {
				continue;
			}
			$lastActivity = $room->getLastActivity();
			$rows[] = [
				'token' => (string)$room->getToken(),
				'displayName' => (string)$room->getDisplayName($userId, false),
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

		return [
			'conversations' => $page,
			'nextCursor' => count($visible) > $limit ? $this->encodeCursor($page[count($page) - 1]) : null,
		];
	}

	/**
	 * Whether this row sorts strictly after the cursor in the page order.
	 *
	 * @param array{token: string, lastActivity: int|null} $row one visible conversation
	 */
	private function isAfter(array $row, int $activity, string $token): bool {
		$rowActivity = $row['lastActivity'] ?? 0;
		if ($rowActivity !== $activity) {
			return $rowActivity < $activity;
		}
		return strcmp($row['token'], $token) > 0;
	}

	/**
	 * @param array{token: string, lastActivity: int|null} $row last conversation of this page
	 */
	private function encodeCursor(array $row): string {
		$json = json_encode(['a' => $row['lastActivity'] ?? 0, 't' => $row['token']], JSON_THROW_ON_ERROR);
		return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
	}

	/**
	 * @return array{activity: int, token: string}
	 * @throws InvalidArgumentException when the cursor is not a position this tool issued
	 */
	private function decodeCursor(string $cursor): array {
		if ($cursor === '' || strlen($cursor) > self::MAX_CURSOR_LENGTH || preg_match('/^[A-Za-z0-9_-]+$/', $cursor) !== 1) {
			throw new InvalidArgumentException(Messages::invalidCursor());
		}
		$remainder = strlen($cursor) % 4;
		$padded = strtr($cursor, '-_', '+/') . ($remainder === 0 ? '' : str_repeat('=', 4 - $remainder));
		$raw = base64_decode($padded, true);
		$data = is_string($raw) ? json_decode($raw, true) : null;
		if (!is_array($data) || count($data) !== 2 || !array_key_exists('a', $data) || !array_key_exists('t', $data)) {
			throw new InvalidArgumentException(Messages::invalidCursor());
		}
		$token = $data['t'];
		if (!is_int($data['a']) || $data['a'] < 0 || !is_string($token) || $token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
			throw new InvalidArgumentException(Messages::invalidCursor());
		}
		return ['activity' => $data['a'], 'token' => $token];
	}
}
