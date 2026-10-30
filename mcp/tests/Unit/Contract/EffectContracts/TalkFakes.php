<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/**
 * The Talk (spreed) app of the effect contract, which `nextcloud/ocp` does not ship: a chat that keeps its rooms and
 * the messages sent to them as plain data, and the services TalkServices resolves by class name. {@see TalkChat::install()}
 * aliases them to the `OCA\Talk\*` names, so the contract must run in a process of its own: the other Talk tests need
 * those classes absent. The constants carry the values of the Talk releases MCP supports.
 */

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

/** The state of the chat: its rooms and every message sent, in order. */
final class TalkChat {
	/** Id of the first message sent. */
	private const FIRST_MESSAGE = 55;

	/** @var array<string, TalkFakeRoom> rooms by token */
	public array $rooms = [];
	/** @var list<array{id:int, room:string, actorType:string, actor:string, message:string, at:string, replyTo:int|null}> */
	public array $messages = [];

	/** @param array<string, string> $rooms display name by token of the rooms alice takes part in */
	public function __construct(array $rooms) {
		foreach ($rooms as $token => $name) {
			$this->rooms[$token] = new TalkFakeRoom($token, $name);
		}
	}

	/** @return void makes the fake services resolvable under the Talk class names TalkServices checks */
	public static function install(): void {
		foreach ([
			'OCA\\Talk\\Model\\Attendee' => TalkFakeAttendee::class, 'OCA\\Talk\\Room' => TalkFakeRoom::class,
			'OCA\\Talk\\Webinary' => TalkFakeWebinary::class, 'OCA\\Talk\\Manager' => TalkFakeManager::class,
			'OCA\\Talk\\Chat\\ChatManager' => TalkFakeChatManager::class,
			'OCA\\Talk\\Service\\ParticipantService' => TalkFakeParticipantService::class,
			'OCA\\Talk\\Service\\RoomService' => TalkFakeRoomService::class,
		] as $talk => $fake) {
			if (!class_exists($talk)) {
				class_alias($fake, $talk);
			}
		}
	}

	/** @return array<string, object> the Talk services by class name, as the server container answers them */
	public function services(): array {
		return [
			'OCA\\Talk\\Manager' => new TalkFakeManager($this), 'OCA\\Talk\\Chat\\ChatManager' => new TalkFakeChatManager($this),
			'OCA\\Talk\\Service\\ParticipantService' => new TalkFakeParticipantService(),
			'OCA\\Talk\\Service\\RoomService' => new TalkFakeRoomService(),
		];
	}

	/**
	 * @param array{room:string, actorType:string, actor:string, message:string, at:string, replyTo:int|null} $message
	 * @return TalkFakeComment the comment Talk answers for the message stored
	 */
	public function store(array $message): TalkFakeComment {
		$id = self::FIRST_MESSAGE + count($this->messages);
		$this->messages[] = ['id' => $id] + $message;
		return new TalkFakeComment($id);
	}
}

/** The participant constants of `OCA\Talk\Model\Attendee`. */
final class TalkFakeAttendee {
	public const PERMISSIONS_CHAT = 128;
	public const PERMISSIONS_LOBBY_IGNORE = 64;
	public const ACTOR_USERS = 'users';
}

/** The lobby constants of `OCA\Talk\Webinary`. */
final class TalkFakeWebinary {
	public const LOBBY_NONE = 0;
}

/** A group room alice can write to, with the constants of `OCA\Talk\Room`. */
final class TalkFakeRoom {
	public const READ_ONLY = 1;
	public const TYPE_GROUP = 2;
	public const TYPE_CHANGELOG = 4;

	/**
	 * @param string $token room token
	 * @param string $name display name
	 */
	public function __construct(private string $token, private string $name) {
	}

	/** @return string room token */
	public function getToken(): string {
		return $this->token;
	}

	/**
	 * @param string $userId viewer
	 * @return string display name of the room
	 */
	public function getDisplayName(string $userId): string {
		return $this->name;
	}

	/** @return bool whether the room lives on another server */
	public function isFederatedConversation(): bool {
		return false;
	}

	/** @return int read-only state: writable */
	public function getReadOnly(): int {
		return 0;
	}

	/** @return int room type: group */
	public function getType(): int {
		return self::TYPE_GROUP;
	}

	/** @return int lobby state: no lobby */
	public function getLobbyState(): int {
		return TalkFakeWebinary::LOBBY_NONE;
	}
}

/** alice as a participant of a room, with the chat permission. */
final class TalkFakeParticipant {
	/** @return int permission bits */
	public function getPermissions(): int {
		return TalkFakeAttendee::PERMISSIONS_CHAT;
	}
}

/** A message Talk answers after storing it. */
final class TalkFakeComment {
	/** @param int $id message id */
	public function __construct(private int $id) {
	}

	/** @return string message id */
	public function getId(): string {
		return (string)$this->id;
	}
}

/** `OCA\Talk\Manager`: finds the rooms of the chat. */
final class TalkFakeManager {
	/** @param TalkChat $chat the chat */
	public function __construct(private TalkChat $chat) {
	}

	/**
	 * @param string $token room token
	 * @param string $userId viewer
	 * @return TalkFakeRoom the room
	 */
	public function getRoomForUserByToken(string $token, string $userId): TalkFakeRoom {
		return $this->chat->rooms[$token] ?? throw new \RuntimeException('room not found');
	}
}

/** `OCA\Talk\Service\ParticipantService`: alice takes part in every room. */
final class TalkFakeParticipantService {
	/**
	 * @param TalkFakeRoom $room room
	 * @param string $userId account
	 * @param bool|string $sessionId session filter
	 * @return TalkFakeParticipant the participant
	 */
	public function getParticipant(TalkFakeRoom $room, string $userId, bool|string $sessionId = false): TalkFakeParticipant {
		return new TalkFakeParticipant();
	}

	/**
	 * @param TalkFakeRoom $room room about to receive a message
	 * @return void a group room needs nothing
	 */
	public function ensureOneToOneRoomIsFilled(TalkFakeRoom $room): void {
	}
}

/** `OCA\Talk\Service\RoomService`: no lobby timer to expire. */
final class TalkFakeRoomService {
}

/** `OCA\Talk\Chat\ChatManager`: stores every message sent in the chat. */
final class TalkFakeChatManager {
	/** @param TalkChat $chat the chat */
	public function __construct(private TalkChat $chat) {
	}

	/**
	 * @param TalkFakeRoom $room target room
	 * @param TalkFakeParticipant $participant sender
	 * @param string $actorType actor kind
	 * @param string $actorId sender uid
	 * @param string $message native system envelope
	 * @param \DateTime $creation native timestamp
	 * @param bool $notify notification flag
	 * @return TalkFakeComment persisted system message
	 */
	public function addSystemMessage(TalkFakeRoom $room, TalkFakeParticipant $participant, string $actorType, string $actorId,
		string $message, \DateTime $creation, bool $notify): TalkFakeComment {
		return $this->chat->store(['room' => $room->getToken(), 'actorType' => $actorType, 'actor' => $actorId,
			'message' => $message, 'at' => $creation->format(\DATE_ATOM), 'replyTo' => null]);
	}

	/**
	 * @param TalkFakeRoom $room room
	 * @param TalkFakeParticipant $participant sender
	 * @param string $actorType actor type
	 * @param string $actorId actor
	 * @param string $message text
	 * @param \DateTime $creation time of the message
	 * @param object|null $replyTo quoted message
	 * @return TalkFakeComment the stored message
	 */
	public function sendMessage(TalkFakeRoom $room, TalkFakeParticipant $participant, string $actorType, string $actorId,
		string $message, \DateTime $creation, ?object $replyTo = null): TalkFakeComment {
		return $this->chat->store(['room' => $room->getToken(), 'actorType' => $actorType, 'actor' => $actorId,
			'message' => $message, 'at' => $creation->format(\DATE_ATOM), 'replyTo' => $replyTo === null ? null : (int)$replyTo->getId()]);
	}
}
