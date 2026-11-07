<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Tests\Integration\Deck;

use OCA\Deck\Service\CardService;
use OCP\IDBConnection;
use OCP\Server;

/** Read-only proof of the native activity stream, filtered to an authorized card and recipient. */
final class CardActivityProbe {
	/** @param string $uid Native reader/affected account. @param int $cardId Card under test. @return array{rows:list<array<string,mixed>>} Persisted native events. */
	public static function read(string $uid, int $cardId): array {
		Server::get(CardService::class)->find($cardId);
		$q = Server::get(IDBConnection::class)->getQueryBuilder();
		$result = $q->select('activity_id', 'subject', 'user', 'affecteduser', 'object_type', 'object_id')->from('activity')
			->where($q->expr()->eq('app', $q->createNamedParameter('deck')))
			->andWhere($q->expr()->eq('object_type', $q->createNamedParameter('deck_card')))
			->andWhere($q->expr()->eq('object_id', $q->createNamedParameter($cardId)))
			->andWhere($q->expr()->eq('affecteduser', $q->createNamedParameter($uid)))
			->orderBy('activity_id', 'ASC')->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$row['activity_id'] = (int)$row['activity_id'];
			$row['object_id'] = (int)$row['object_id'];
			$rows[] = $row;
		}
		$result->closeCursor();
		return ['rows' => $rows];
	}
}
