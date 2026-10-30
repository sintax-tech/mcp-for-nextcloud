<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Contract\EffectContracts;

use OCP\IUser;

/** Native RoomService and ParticipantService calls, recording room and invitation effects without MCP logic. */
final class TalkCreationNative {
	/** @var list<array<string, mixed>> created rooms */
	public array $created = [];
	/** @var list<array<string, mixed>> native participant additions */
	public array $invited = [];

	/** @param TalkChat $chat rooms and messages */
	public function __construct(private TalkChat $chat) {
	}

	/**
	 * @param IUser $actor sender
	 * @param IUser $target recipient
	 * @return TalkFakeRoom stable direct room
	 */
	public function createOneToOneConversation(IUser $actor, IUser $target): TalkFakeRoom {
		$token = 'direct01';
		if (!isset($this->chat->rooms[$token])) {
			$this->chat->rooms[$token] = new TalkFakeRoom($token, $target->getDisplayName());
			$this->created[] = ['kind' => 'direct', 'owner' => $actor->getUID(), 'target' => $target->getUID(), 'token' => $token];
		}
		return $this->chat->rooms[$token];
	}

	/**
	 * @param int $type room type
	 * @param string $name room name
	 * @param IUser $owner creator
	 * @return TalkFakeRoom room
	 */
	public function createConversation(int $type, string $name, IUser $owner): TalkFakeRoom {
		$token = 'group00' . count($this->created);
		$this->created[] = ['kind' => $type, 'name' => $name, 'owner' => $owner->getUID(), 'token' => $token];
		return $this->chat->rooms[$token] = new TalkFakeRoom($token, $name);
	}

	/**
	 * @param TalkFakeRoom $room room
	 * @param array $users invited accounts
	 * @param IUser $owner inviter
	 * @return void
	 */
	public function addUsers(TalkFakeRoom $room, array $users, IUser $owner): void {
		$this->invited[] = ['room' => $room->getToken(), 'users' => $users, 'by' => $owner->getUID()];
	}

	/**
	 * @param TalkFakeRoom $room room
	 * @param string $uid account
	 * @param bool|string $session session
	 * @return TalkFakeParticipant member
	 */
	public function getParticipant(TalkFakeRoom $room, string $uid, bool|string $session = false): TalkFakeParticipant {
		return new TalkFakeParticipant();
	}

	/**
	 * @param TalkFakeRoom $room room
	 * @return void all members already present
	 */
	public function ensureOneToOneRoomIsFilled(TalkFakeRoom $room): void {
	}
}
