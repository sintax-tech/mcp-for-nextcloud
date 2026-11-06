<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);
namespace OCA\Mcp\Access\References;

use OCA\Mcp\Platform\References\ReferenceRead;

/** Read-only card projection, after the caller checks module enablement, read grant and Guests. */
interface ReadableDeckCard {
	/**
	 * @param string $userId authenticated reader
	 * @param int $cardId positive card identity checked by the caller
	 * @param int|null $boardId optional expected board; a mismatch is absence
	 * @return ReferenceRead item shape array{title:string,boardId:int}, absence, or the original lookup failure
	 * @throws \Throwable for unexpected failures outside the guarded native lookups
	 */
	public function read(string $userId, int $cardId, ?int $boardId = null): ReferenceRead;
}
