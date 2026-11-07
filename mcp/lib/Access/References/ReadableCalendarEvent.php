<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Access\References;

use OCA\Mcp\Platform\References\ReferenceRead;

/** Scoped, classified event projection; the caller checks module enablement, read grant and Guests first. */
interface ReadableCalendarEvent {
	/**
	 * @param string $userId authenticated reader
	 * @param string $path external calendar path, resolved through the reader's resource scope
	 * @param string $uid event identity, never an object URI or calendar ID
	 * @return ReferenceRead item shape array{title:string,path:string,uri:string}, absence, or the original lookup failure
	 * @throws \OCA\Mcp\Access\Policy\PolicyUnavailable when resource scope is unreadable
	 * @throws \Throwable for unexpected failures outside the guarded calendar lookups
	 */
	public function read(string $userId, string $path, string $uid): ReferenceRead;
}
