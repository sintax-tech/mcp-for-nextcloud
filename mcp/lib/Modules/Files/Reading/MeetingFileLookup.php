<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Files\Reading;

/**
 * The user-relative path of one file reference the dossier already holds.
 *
 * A reference that is not a file of this user, or that scope, an alias or a tag refuses, is absent.
 * Nothing is searched by name.
 */
interface MeetingFileLookup {
	/**
	 * @param string $userId the actor
	 * @param string $reference a file id, a file URL or a path
	 * @return string|null the path files_read can open, or null when the reference is not one readable file
	 */
	public function path(string $userId, string $reference): ?string;
}
