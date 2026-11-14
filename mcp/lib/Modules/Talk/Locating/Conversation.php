<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Talk\Locating;

/**
 * A room already resolved for the authenticated user, together with that user's participant.
 *
 * Both are native Talk objects held untyped on purpose: the class may not exist in this installation and the module
 * must compile and be testable without it.
 */
final class Conversation {
	/**
	 * Keeps the native room and participant already resolved for the caller.
	 * @param object $room native room already resolved for the caller
	 * @param object $participant native participant already resolved for the caller
	 * @param string $displayName name the caller knows the room by, read without writing, or '' when it cannot be
	 */
	public function __construct(
		public readonly object $room,
		public readonly object $participant,
		private string $displayName = '',
	) {}

	/**
	 * Public token of the room, the only conversation identifier the user ever sees.
	 * @return string public conversation token
	 */
	public function token(): string {
		return (string)$this->room->getToken();
	}

	/**
	 * Name the user knows this conversation by, read from what Talk already stored.
	 *
	 * A one to one names the other account and a group keeps its stored name. The name is empty when that read
	 * cannot tell the other account apart, or when a group has no stored name: Talk would build that name and
	 * save it, and neither a plan nor a result may do so. A draft and the confirmed call share this answer, so
	 * the name the user approves is the name the result reports.
	 *
	 * @return string room name shown to the caller, or '' when it cannot be read without writing
	 */
	public function displayName(): string {
		return $this->displayName;
	}
}
