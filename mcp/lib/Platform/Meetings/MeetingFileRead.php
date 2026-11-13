<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Platform\Meetings;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The file read of a meeting dossier. Implemented by the Files module through files_read, so scope, aliases and
 * the actor's own view stay that reader's rules. It resolves one path the caller already holds; it does not search
 * the tree by a person's name, and it does not move a comment read marker. An absent binding omits files from the
 * dossier; it does not fail the container.
 *
 * The call checks again, for the actor, everything files_read is checked for: the grant of its operation and Guests.
 */
interface MeetingFileRead {
	/**
	 * The file files_read would return for this path.
	 *
	 * @param string $userId the actor
	 * @param array<string, mixed> $arguments the arguments of files_read
	 * @return array<string, mixed> the result of files_read
	 * @throws ToolFailure when the actor may not call files_read
	 * @throws \InvalidArgumentException on arguments files_read refuses
	 */
	public function read(string $userId, array $arguments): array;

	/**
	 * The user-relative path of one file id or file link, or null when it is not one readable file of the actor.
	 *
	 * @param string $userId the actor
	 * @param string $reference a file id, a file URL or a path already found on the event or a card
	 * @return string|null the path files_read can open
	 * @throws ToolFailure when the actor may not call files_read
	 */
	public function locate(string $userId, string $reference): ?string;
}
