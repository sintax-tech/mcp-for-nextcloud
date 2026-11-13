<?php

/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Modules\Deck\Integration;

use OCA\Deck\Db\Assignment;
use OCA\Deck\Db\AssignmentMapper;

/**
 * The card ids `AssignmentMapper::findByParticipant` returns for one user assignment.
 *
 * The mapper does not apply the board ACL. A later card read drops a card the actor cannot open.
 */
final class AssignedCards implements AssignedCardLookup {
	/**
	 * @param DeckServiceContext $services lazily resolved Deck services
	 */
	public function __construct(private DeckServiceContext $services) {
	}

	/** {@inheritDoc} */
	public function ids(string $userId, string $participantUid): array {
		if ($participantUid === '') {
			return [];
		}
		$this->services->bindUser($userId);
		/** @var AssignmentMapper $mapper */
		$mapper = $this->services->get(AssignmentMapper::class);
		$ids = [];
		foreach ($mapper->findByParticipant($participantUid, Assignment::TYPE_USER) as $assignment) {
			$id = (int)$assignment->getCardId();
			if ($id > 0 && !in_array($id, $ids, true)) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
