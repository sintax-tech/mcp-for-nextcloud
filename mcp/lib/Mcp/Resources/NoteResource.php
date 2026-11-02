<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Mcp\Resources;

use OCA\Mcp\Mcp\Tools\Results\ToolFailure;

/**
 * The note behind an `nc://notes/{id}` resource, as the Notes module reads it. {@see ResourceRegistry} checks the
 * grant, the app and the URI; the module finds the note and refuses one the user may not see. The Notes module
 * binds it; without that binding the container fails instead of answering with anything permissive.
 */
interface NoteResource {
	/**
	 * Reads one note of the user, after the visibility check.
	 *
	 * @param string $userId authenticated user
	 * @param int $id note id
	 * @return string the Markdown content of the note
	 * @throws ToolFailure when the note is missing, hidden or over the read limit
	 */
	public function read(string $userId, int $id): string;
}
