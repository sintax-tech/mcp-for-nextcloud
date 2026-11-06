<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Unit\Tools\Deck;

/** Native status signatures present in both supported pins, extending the older shared stub without changing it. */
class StatusNativeCards extends \OCA\Deck\Service\CardService {
	/** @param int $id Native card. @return \OCA\Deck\Db\Card Written card. */
	public function done(int $id): \OCA\Deck\Db\Card { throw new \LogicException('Expected a native service mock'); }
	/** @param int $id Native card. @return \OCA\Deck\Db\Card Written card. */
	public function undone(int $id): \OCA\Deck\Db\Card { throw new \LogicException('Expected a native service mock'); }
	/** @param int $id Native card. @return \OCA\Deck\Db\Card Written card. */
	public function archive(int $id): \OCA\Deck\Db\Card { throw new \LogicException('Expected a native service mock'); }
	/** @param int $id Native card. @return \OCA\Deck\Db\Card Written card. */
	public function unarchive(int $id): \OCA\Deck\Db\Card { throw new \LogicException('Expected a native service mock'); }
}
