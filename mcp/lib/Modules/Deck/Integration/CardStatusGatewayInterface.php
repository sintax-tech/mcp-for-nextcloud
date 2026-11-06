<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

/** Focused native status port; the existing Deck gateway and Talk read port do not grow. */
interface CardStatusGatewayInterface {
	/**
	 * Read a fresh scalar snapshot only after native READ and EDIT checks.
	 * @param string $userId Authenticated caller bound to the native session.
	 * @param int $cardId Card whose state and possible native done destination are requested.
	 * @return array{card:array<string,mixed>,board:array<string,mixed>,list:array<string,mixed>,autoMove:array<string,mixed>,version:string} Authorized state.
	 * @throws \Throwable On missing, deleted, denied or archived-board source.
	 */
	public function state(string $userId, int $cardId): array;

	/**
	 * Set completion only through CardService::done or undone; the service owns the timestamp and automatic move.
	 * @param string $userId Authenticated native caller.
	 * @param int $cardId Card to change after confirmation and fresh checks.
	 * @param bool $done Desired completion state.
	 * @return void
	 * @throws \Throwable Native failure, potentially after persistence; the handler must read back.
	 */
	public function setDone(string $userId, int $cardId, bool $done): void;

	/**
	 * Set archived only through CardService::archive or unarchive, preserving completion.
	 * @param string $userId Authenticated native caller.
	 * @param int $cardId Card to change after confirmation and fresh checks.
	 * @param bool $archived Desired archive state.
	 * @return void
	 * @throws \Throwable Native failure, potentially after persistence; the handler must read back.
	 */
	public function setArchived(string $userId, int $cardId, bool $archived): void;
}
