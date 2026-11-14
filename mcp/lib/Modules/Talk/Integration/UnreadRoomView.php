<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Integration;

use DateTimeInterface;
use OCA\Mcp\Modules\Talk\Presentation\RoomNames;
use OCP\IUserManager;
use Throwable;

/**
 * Read-only lobby decision and display name for one Talk room.
 *
 * Neither answer is written back. An active lobby whose timer is already in the past is treated as open for this
 * answer only, the same moment Talk would open it, without calling the maintenance that persists that opening.
 * A direct conversation names the other participant from the two account ids already stored, or from the participants
 * already in the room when the stored name is empty or still a single legacy account id. That pair is rebuilt in
 * memory and never written back. The reader must be one of the ids; the name is the other account, and it is empty
 * when the read fails or that other account cannot be told apart, including when the only id left is the reader.
 * A group with no stored name stays empty: Talk's own fallback builds that name from display names and saves it,
 * which this read does not do. The release notes conversation and a password request get the names Talk gives them.
 */
final class UnreadRoomView {
	/** Room object type of a conversation opened to request the password of a shared file. */
	private const PASSWORD_REQUEST = 'share:password';

	/** Talk room class, named here because Integration is the only place that may name it. */
	private const ROOM_CLASS = 'OCA\\Talk\\Room';

	/**
	 * @param IUserManager $users display names of accounts, a read
	 * @param int|null $oneToOneType Talk's one-to-one room type when the caller already resolved it; null reads it
	 *                                from the loaded Talk room class, and leaves the stored name untouched when Talk
	 *                                is absent
	 */
	public function __construct(
		private IUserManager $users,
		private ?int $oneToOneType = null,
	) {
	}

	/**
	 * Whether the lobby bars this participant, decided from values already loaded on the room.
	 *
	 * @param object $room OCA\Talk\Room, unread
	 * @param object $participant OCA\Talk\Participant of the reader
	 * @param int $lobbyNone the native value of a room that has no lobby
	 * @param int $ignorePermission the native permission that lets a participant pass a lobby
	 * @return bool true when the room must be omitted
	 */
	public function barred(object $room, object $participant, int $lobbyNone, int $ignorePermission): bool {
		try {
			$state = $room->getLobbyState();
			// A state that cannot be read stays barred. Only the native open value lets the room through.
			if (!is_int($state)) {
				return true;
			}
			if ($state === $lobbyNone) {
				return false;
			}
			$permissions = $participant->getPermissions();
			if (!is_int($permissions)) {
				return true;
			}
			if (($permissions & $ignorePermission) !== 0) {
				return false;
			}
			$timer = $room->getLobbyTimer();
			// Strictly before now, matching the comparison Talk uses when it opens an elapsed lobby.
			return !$timer instanceof DateTimeInterface || $timer->getTimestamp() >= time();
		} catch (Throwable) {
			// A lobby that cannot be read stays barred, so a counter never leaks from an unknown room.
			return true;
		}
	}

	/**
	 * The name to show, taken from the value Talk already stored.
	 *
	 * The same order as Talk's Manager::resolveRoomDisplayName: password request, then release notes, then the rest.
	 *
	 * @param object $room OCA\Talk\Room, unread
	 * @param string $userId authenticated reader
	 * @param int $changelogType Talk's type of the release notes conversation
	 * @param object|null $participants OCA\Talk\Service\ParticipantService, read only when a direct name is not the stored pair
	 * @return string stored name, Talk's name of a special room, the other participant's display name, or ''
	 */
	public function displayName(object $room, string $userId, int $changelogType, ?object $participants = null): string {
		$stored = $this->storedName($room);
		try {
			$type = $room->getType();
			$objectType = $room->getObjectType();
		} catch (Throwable) {
			return '';
		}
		if ($objectType === self::PASSWORD_REQUEST) {
			return RoomNames::passwordRequest($stored);
		}
		if (is_int($type) && $type === $changelogType) {
			return RoomNames::changelog();
		}
		$oneToOne = $this->oneToOneType();
		if ($oneToOne !== null && is_int($type) && $type === $oneToOne) {
			return $this->oneToOneName($stored, $userId, $room, $participants);
		}
		return $stored;
	}

	/**
	 * The name column already loaded on the room, without asking Talk to resolve or persist one.
	 *
	 * @param object $room OCA\Talk\Room
	 */
	private function storedName(object $room): string {
		if (!property_exists($room, 'name')) {
			return '';
		}
		try {
			$value = (new \ReflectionProperty($room, 'name'))->getValue($room);
		} catch (Throwable) {
			return '';
		}
		return is_string($value) ? $value : '';
	}

	/**
	 * The other participant's display name, from the stored pair or from the accounts already in the room.
	 *
	 * A stored value that already starts with the JSON pair is used as Talk left it. An empty name takes the account
	 * ids of the room. A legacy name, the other account id as plain text, is appended only when the room no longer
	 * holds both accounts, which is the complement Talk applies before it would save the pair. The legacy text is
	 * never itself the name: the other id is the one that is not the reader.
	 *
	 * @param string $stored the name column
	 * @param string $userId authenticated reader
	 * @param object $room OCA\Talk\Room
	 * @param object|null $participants OCA\Talk\Service\ParticipantService
	 */
	private function oneToOneName(string $stored, string $userId, object $room, ?object $participants): string {
		if (str_starts_with($stored, '["')) {
			return $this->displayOfOther($this->decodedIds($stored), $userId);
		}
		$ids = $this->participantIds($participants, $room);
		if ($ids === null) {
			return '';
		}
		if ($stored !== '' && count($ids) !== 2) {
			$ids[] = $stored;
		}
		return $this->displayOfOther($ids, $userId);
	}

	/**
	 * Account ids Talk stored as a JSON list, or null when that value is not a list of account ids.
	 *
	 * @return list<string>|null
	 */
	private function decodedIds(string $stored): ?array {
		try {
			$ids = json_decode($stored, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException) {
			return null;
		}
		if (!is_array($ids)) {
			return null;
		}
		$clean = [];
		foreach ($ids as $id) {
			if (!is_string($id) || $id === '') {
				return null;
			}
			$clean[] = $id;
		}
		return $clean;
	}

	/**
	 * Account ids of the room, read and not saved, or null when that read fails.
	 *
	 * @return list<string>|null
	 */
	private function participantIds(?object $participants, object $room): ?array {
		if ($participants === null) {
			return null;
		}
		try {
			$ids = $participants->getParticipantUserIds($room);
		} catch (Throwable) {
			return null;
		}
		if (!is_array($ids)) {
			return null;
		}
		$clean = [];
		foreach ($ids as $id) {
			if (!is_string($id) || $id === '') {
				return null;
			}
			$clean[] = $id;
		}
		return $clean;
	}

	/**
	 * The display name of the single account id that is not the reader.
	 *
	 * @param list<string>|null $ids account ids of the direct conversation
	 * @param string $userId authenticated reader, who must be one of them
	 */
	private function displayOfOther(?array $ids, string $userId): string {
		if ($ids === null) {
			return '';
		}
		$other = null;
		$caller = false;
		foreach ($ids as $id) {
			if ($id === $userId) {
				$caller = true;
				continue;
			}
			if ($other !== null) {
				return '';
			}
			$other = $id;
		}
		if (!$caller || !is_string($other)) {
			return '';
		}
		try {
			$display = $this->users->getDisplayName($other);
		} catch (Throwable) {
			return '';
		}
		return $display ?? $other;
	}

	/** @return int|null Talk's one-to-one type, or null when this installation has not loaded it */
	private function oneToOneType(): ?int {
		if ($this->oneToOneType !== null) {
			return $this->oneToOneType;
		}
		if (!class_exists(self::ROOM_CLASS)) {
			return null;
		}
		$value = constant(self::ROOM_CLASS . '::TYPE_ONE_TO_ONE');
		return is_int($value) ? $value : null;
	}
}
