<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Meetings;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The Talk steps of a meeting: a new private group room and its agenda. Implemented by the Talk module through the
 * work classes of talk_create_group and talk_reply; without a binding the container fails.
 *
 * Every method checks again, for the actor, everything the original tool is checked for: the grant of its operation,
 * Guests, the Talk app, the arguments and the native ACL. A plan writes nothing; a step runs at most once.
 */
interface MeetingTalk {
	/**
	 * The plan of talk_create_group: the room name and the invited accounts, resolved without creating anything.
	 *
	 * @param string $userId the actor, owner of the new room
	 * @param string $name the room name
	 * @param list<string> $participants local accounts to invite besides the actor
	 * @return array<string, mixed> the plan of talk_create_group, with nothing written
	 * @throws ToolFailure when the step is not allowed for the actor or would be refused
	 * @throws \InvalidArgumentException on arguments talk_create_group refuses
	 */
	public function planRoom(string $userId, string $name, array $participants): array;

	/**
	 * Creates the room as talk_create_group does, once, and reports the room token and its URL on this instance.
	 * Once the room exists the step always carries its token; a URL this instance cannot build is null, with a note.
	 *
	 * @param string $userId the actor, owner of the new room
	 * @param string $name the room name
	 * @param list<string> $participants local accounts to invite besides the actor
	 * @return MeetingStep applied, partial (invitations failed, the room exists) or failed (no room)
	 * @throws \InvalidArgumentException on arguments talk_create_group refuses
	 */
	public function createRoom(string $userId, string $name, array $participants): MeetingStep;

	/**
	 * The agenda as it will be posted, before the room exists: the text talk_reply would store.
	 *
	 * @param string $userId the actor, author of the agenda
	 * @param string $message the agenda text
	 * @return array<string, mixed> the agenda draft, with nothing written
	 * @throws ToolFailure when the step is not allowed for the actor
	 * @throws \InvalidArgumentException on a message talk_reply refuses
	 */
	public function planAgenda(string $userId, string $message): array;

	/**
	 * Posts the agenda in a room as talk_reply does, once.
	 *
	 * @param string $userId the actor, author of the agenda
	 * @param string $token the room the agenda goes to
	 * @param string $message the agenda text
	 * @return MeetingStep applied, failed (refused before sending) or uncertain (the message may exist)
	 * @throws \InvalidArgumentException on arguments talk_reply refuses
	 */
	public function postAgenda(string $userId, string $token, string $message): MeetingStep;
}
