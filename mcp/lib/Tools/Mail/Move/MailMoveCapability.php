<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tools\Mail\Move;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * Whether the mail server lets the Mail app's move touch only the message moved, read from the capabilities the Mail
 * app's own IMAP client (Horde) reports for the connection, before anything is asked of the server to move.
 *
 * The Mail app's IMAP MessageMapper::move() is Horde's copy with `move`: UID MOVE when the server has MOVE (RFC 6851);
 * otherwise UID COPY, then an expunge of that UID, which is a UID EXPUNGE of that UID only with UIDPLUS (RFC 4315).
 * Without UIDPLUS, Horde's expunge takes \Deleted off every other message that has it, expunges the folder and puts the
 * flag back afterwards, not in a `finally`: a failure in between leaves other messages changed. So a server with neither
 * MOVE nor UIDPLUS, or whose capabilities cannot be read, is refused before the move with a fixed message; with either
 * one the move stays the Mail app's own.
 */
final class MailMoveCapability {
	/**
	 * Constructor of a class with static members only.
	 *
	 * @return void
	 */
	private function __construct() {
	}

	/**
	 * Refuses a server where the move could change other messages.
	 *
	 * @param object $client Horde_Imap_Client_Socket of the Mail app, logged in
	 * @return void
	 * @throws ToolFailure when the server has neither MOVE nor UIDPLUS, or its capabilities cannot be read
	 */
	public static function assertMoveTouchesOnlyTheMessage(object $client): void {
		try {
			$capabilities = $client->capability;
			$supported = $capabilities->query('MOVE') === true || $capabilities->query('UIDPLUS') === true;
		} catch (\Throwable $e) {
			throw new ToolFailure(MailMoveMessages::serverCannotMove(), 0, $e);
		}
		if (!$supported) {
			throw new ToolFailure(MailMoveMessages::serverCannotMove());
		}
	}
}
